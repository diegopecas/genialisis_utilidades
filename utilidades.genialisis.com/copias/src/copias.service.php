<?php

/**
 * Genera la copia de una base en un archivo .sql.gz, sin depender de mysqldump.
 * Es el mismo procedimiento de la herramienta gestor-bd, pero corre en el hosting,
 * al lado de la base, por eso tarda segundos.
 * Incluye tablas con datos, vistas, procedimientos, funciones y triggers.
 * Quita los DEFINER para que la copia se pueda restaurar con cualquier usuario.
 * No incluye CREATE DATABASE ni USE: se restaura sobre la base que se elija.
 * No incluye eventos programados.
 */
class CopiasService
{
    // Tipos que se exportan en hexadecimal para no dañar datos binarios
    const TIPOS_BINARIOS = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring',
        'multipolygon', 'geometrycollection', 'geomcollection',
    ];

    // Tamaño máximo aproximado de cada INSERT
    const BYTES_POR_INSERT = 1048576;

    /**
     * $con: ['host', 'puerto', 'base', 'usuario', 'clave']
     */
    public static function generar(array $con, string $archivo, int $nivel, int $filasPorInsert): array
    {
        $inicio = microtime(true);
        $filasPorInsert = max(1, $filasPorInsert);

        $meta = self::abrir($con, true);
        $datos = self::abrir($con, false);
        $meta->exec("SET time_zone = '+00:00'");
        $datos->exec("SET time_zone = '+00:00'");

        $gz = gzopen($archivo, 'wb' . max(1, min(9, $nivel)));
        if ($gz === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal de la copia.');
        }

        $tablas = 0;
        $vistas = [];
        try {
            $version = $meta->query('SELECT VERSION()')->fetchColumn();
            gzwrite($gz, "-- Copia de `{$con['base']}`\n");
            gzwrite($gz, "-- Generada en el servidor - versión {$version}\n");
            gzwrite($gz, '-- Fecha: ' . date('Y-m-d H:i:s') . "\n\n");
            gzwrite($gz, "SET NAMES utf8mb4;\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 0;\n");
            gzwrite($gz, "SET UNIQUE_CHECKS = 0;\n");
            gzwrite($gz, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            gzwrite($gz, "SET time_zone = '+00:00';\n\n");

            $objetos = $meta->prepare('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
            $objetos->execute([$con['base']]);
            $baseTablas = [];
            foreach ($objetos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                if ($fila['TABLE_TYPE'] === 'VIEW') {
                    $vistas[] = $fila['TABLE_NAME'];
                } else {
                    $baseTablas[] = $fila['TABLE_NAME'];
                }
            }

            // Las columnas de todas las tablas se leen en una sola consulta
            $columnasPorTabla = [];
            $columnas = $meta->prepare('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION');
            $columnas->execute([$con['base']]);
            foreach ($columnas->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columnasPorTabla[$col['TABLE_NAME']][] = $col;
            }

            foreach ($baseTablas as $tabla) {
                self::escribirTabla($gz, $meta, $datos, $tabla, $columnasPorTabla[$tabla] ?? [], $filasPorInsert);
                $tablas++;
            }
            self::escribirVistas($gz, $meta, $vistas);
            self::escribirRutinas($gz, $meta, $con['base']);
            self::escribirTriggers($gz, $meta, $con['base']);

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
            gzwrite($gz, "SET UNIQUE_CHECKS = 1;\n");
            gzclose($gz);
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($archivo);
            throw $e;
        }

        return [
            'tablas' => $tablas,
            'vistas' => count($vistas),
            'version' => (string) $version,
            'duracion' => round(microtime(true) - $inicio, 2),
        ];
    }

    private static function abrir(array $con, bool $conBuffer): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $con['host'], (int) $con['puerto'], $con['base']);
        return new PDO($dsn, $con['usuario'], $con['clave'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 20,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $conBuffer,
        ]);
    }

    private static function escribirTabla($gz, PDO $meta, PDO $datos, string $tabla, array $columnas, int $filasPorInsert): void
    {
        $nombre = self::id($tabla);
        $crear = $meta->query('SHOW CREATE TABLE ' . $nombre)->fetch(PDO::FETCH_NUM);
        gzwrite($gz, "-- Tabla {$nombre}\n");
        gzwrite($gz, "DROP TABLE IF EXISTS {$nombre};\n");
        gzwrite($gz, $crear[1] . ";\n\n");

        $seleccion = [];
        $nombres = [];
        $binarias = [];
        foreach ($columnas as $col) {
            $extra = strtoupper((string) $col['EXTRA']);
            // Las columnas generadas no se insertan
            if (strpos($extra, 'VIRTUAL GENERATED') !== false || strpos($extra, 'STORED GENERATED') !== false) {
                continue;
            }
            $id = self::id($col['COLUMN_NAME']);
            $esBinaria = in_array(strtolower($col['DATA_TYPE']), self::TIPOS_BINARIOS, true);
            $seleccion[] = $esBinaria ? 'HEX(' . $id . ')' : $id;
            $nombres[] = $id;
            $binarias[] = $esBinaria;
        }
        if (!$seleccion) {
            return;
        }

        $encabezado = 'INSERT INTO ' . $nombre . ' (' . implode(', ', $nombres) . ') VALUES' . "\n";
        $st = $datos->query('SELECT ' . implode(', ', $seleccion) . ' FROM ' . $nombre);
        $filas = [];
        $bytes = 0;
        while (($fila = $st->fetch(PDO::FETCH_NUM)) !== false) {
            $valores = [];
            foreach ($fila as $k => $valor) {
                if ($valor === null) {
                    $valores[] = 'NULL';
                } elseif ($binarias[$k]) {
                    $valores[] = $valor === '' ? "''" : '0x' . $valor;
                } else {
                    $valores[] = $meta->quote((string) $valor);
                }
            }
            $texto = '(' . implode(', ', $valores) . ')';
            $filas[] = $texto;
            $bytes += strlen($texto);
            if (count($filas) >= $filasPorInsert || $bytes >= self::BYTES_POR_INSERT) {
                gzwrite($gz, $encabezado . implode(",\n", $filas) . ";\n");
                $filas = [];
                $bytes = 0;
            }
        }
        $st->closeCursor();
        if ($filas) {
            gzwrite($gz, $encabezado . implode(",\n", $filas) . ";\n");
        }
        gzwrite($gz, "\n");
    }

    /**
     * Escribe las vistas ordenadas para que una vista quede después de las vistas que usa.
     */
    private static function escribirVistas($gz, PDO $meta, array $vistas): void
    {
        if (!$vistas) {
            return;
        }
        $definiciones = [];
        foreach ($vistas as $vista) {
            $fila = $meta->query('SHOW CREATE VIEW ' . self::id($vista))->fetch(PDO::FETCH_NUM);
            $definiciones[$vista] = self::quitarDefiner($fila[1]);
        }

        $ordenadas = [];
        $pendientes = $definiciones;
        while ($pendientes) {
            $avance = false;
            foreach ($pendientes as $vista => $sql) {
                $depende = false;
                foreach ($pendientes as $otra => $_) {
                    if ($otra !== $vista && strpos($sql, '`' . $otra . '`') !== false) {
                        $depende = true;
                        break;
                    }
                }
                if (!$depende) {
                    $ordenadas[$vista] = $sql;
                    unset($pendientes[$vista]);
                    $avance = true;
                }
            }
            // Si hay referencias circulares, se escriben las restantes como estén
            if (!$avance) {
                $ordenadas += $pendientes;
                break;
            }
        }

        foreach ($ordenadas as $vista => $sql) {
            $nombre = self::id($vista);
            gzwrite($gz, "-- Vista {$nombre}\n");
            gzwrite($gz, "DROP VIEW IF EXISTS {$nombre};\n");
            gzwrite($gz, $sql . ";\n\n");
        }
    }

    private static function escribirRutinas($gz, PDO $meta, string $base): void
    {
        $st = $meta->prepare('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME');
        $st->execute([$base]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rutina) {
            $tipo = $rutina['ROUTINE_TYPE'] === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $nombre = self::id($rutina['ROUTINE_NAME']);
            $fila = $meta->query('SHOW CREATE ' . $tipo . ' ' . $nombre)->fetch(PDO::FETCH_ASSOC);
            $columna = $tipo === 'FUNCTION' ? 'Create Function' : 'Create Procedure';
            if (empty($fila[$columna])) {
                gzwrite($gz, "-- {$tipo} {$nombre}: el usuario no tiene permiso para leer su definición\n\n");
                continue;
            }
            gzwrite($gz, "-- {$tipo} {$nombre}\n");
            gzwrite($gz, "DROP {$tipo} IF EXISTS {$nombre};\n");
            gzwrite($gz, "DELIMITER ;;\n");
            gzwrite($gz, self::quitarDefiner($fila[$columna]) . ";;\n");
            gzwrite($gz, "DELIMITER ;\n\n");
        }
    }

    private static function escribirTriggers($gz, PDO $meta, string $base): void
    {
        $st = $meta->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER');
        $st->execute([$base]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
            $nombre = self::id($trigger);
            $fila = $meta->query('SHOW CREATE TRIGGER ' . $nombre)->fetch(PDO::FETCH_ASSOC);
            if (empty($fila['SQL Original Statement'])) {
                continue;
            }
            gzwrite($gz, "-- Trigger {$nombre}\n");
            gzwrite($gz, "DROP TRIGGER IF EXISTS {$nombre};\n");
            gzwrite($gz, "DELIMITER ;;\n");
            gzwrite($gz, self::quitarDefiner($fila['SQL Original Statement']) . ";;\n");
            gzwrite($gz, "DELIMITER ;\n\n");
        }
    }

    private static function quitarDefiner(string $sql): string
    {
        return preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql, 1);
    }

    private static function id(string $nombre): string
    {
        return '`' . str_replace('`', '``', $nombre) . '`';
    }
}
