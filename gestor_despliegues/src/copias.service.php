<?php

require_once __DIR__ . '/config.service.php';
require_once __DIR__ . '/conexiones.service.php';
require_once __DIR__ . '/conexion.service.php';
require_once __DIR__ . '/ejecucion.service.php';
require_once __DIR__ . '/copias-remotas.service.php';

/**
 * Genera la copia de una base en un archivo .sql.gz, sin depender de mysqldump.
 * Si la base está en un servidor de config/copias-remotas.json, la copia se pide al servicio
 * del hosting, que la genera allá en segundos. Si ese servicio falla, se saca desde este equipo.
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

    public static function generar(string $idConexion, string $sufijo = ''): array
    {
        $inicio = microtime(true);
        $con = ConexionesService::obtenerConexion($idConexion);

        $carpeta = ConfigService::carpeta('carpeta_copias');
        $nombre = EjecucionService::limpiarNombre($con['base']) . '_' . date('Ymd_His')
            . ($sufijo !== '' ? '_' . EjecucionService::limpiarNombre($sufijo) : '') . '.sql.gz';
        $archivo = $carpeta . DIRECTORY_SEPARATOR . $nombre;
        $temporal = $archivo . '.part';

        $aviso = null;
        if (CopiasRemotasService::aplica($con)) {
            try {
                $remota = CopiasRemotasService::descargar($con, $temporal);
                if (!rename($temporal, $archivo)) {
                    throw new RuntimeException('No se pudo renombrar la copia a ' . $archivo);
                }
                return [
                    'conexion' => $con['nombre'],
                    'base' => $con['base'],
                    'archivo' => $archivo,
                    'nombre_archivo' => $nombre,
                    'tamano' => filesize($archivo),
                    'tablas' => $remota['tablas'],
                    'vistas' => $remota['vistas'],
                    'duracion' => round(microtime(true) - $inicio, 2),
                    'modo' => 'servidor',
                    'aviso' => null,
                ];
            } catch (Throwable $e) {
                @unlink($temporal);
                $aviso = 'El servidor no pudo sacar la copia (' . $e->getMessage() . '). Se sacó desde este equipo.';
            }
        }

        $nivel = (int) ConfigService::valor('copias.nivel_gzip', 6);
        $filasPorInsert = max(1, (int) ConfigService::valor('copias.filas_por_insert', 500));

        $meta = ConexionService::abrir($con, true);
        $datos = ConexionService::abrir($con, false);
        $meta->exec("SET time_zone = '+00:00'");
        $datos->exec("SET time_zone = '+00:00'");

        $gz = gzopen($temporal, 'wb' . max(1, min(9, $nivel)));
        if ($gz === false) {
            throw new RuntimeException('No se pudo crear el archivo ' . $temporal);
        }

        $tablas = 0;
        try {
            $version = $meta->query('SELECT VERSION()')->fetchColumn();
            gzwrite($gz, "-- Copia de `{$con['base']}` ({$con['nombre']})\n");
            gzwrite($gz, "-- Servidor: {$con['host']}:{$con['puerto']} - versión {$version}\n");
            gzwrite($gz, '-- Fecha: ' . date('Y-m-d H:i:s') . "\n\n");
            gzwrite($gz, "SET NAMES utf8mb4;\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 0;\n");
            gzwrite($gz, "SET UNIQUE_CHECKS = 0;\n");
            gzwrite($gz, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            gzwrite($gz, "SET time_zone = '+00:00';\n\n");

            $objetos = $meta->prepare('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
            $objetos->execute([$con['base']]);
            $baseTablas = [];
            $vistas = [];
            foreach ($objetos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                if ($fila['TABLE_TYPE'] === 'VIEW') {
                    $vistas[] = $fila['TABLE_NAME'];
                } else {
                    $baseTablas[] = $fila['TABLE_NAME'];
                }
            }

            // Las columnas de todas las tablas se leen en una sola consulta: en hostings compartidos
            // information_schema es lento y consultarlo tabla por tabla multiplica el tiempo de la copia
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
            @unlink($temporal);
            throw $e;
        }

        if (!rename($temporal, $archivo)) {
            throw new RuntimeException('No se pudo renombrar la copia a ' . $archivo);
        }

        return [
            'conexion' => $con['nombre'],
            'base' => $con['base'],
            'archivo' => $archivo,
            'nombre_archivo' => $nombre,
            'tamano' => filesize($archivo),
            'tablas' => $tablas,
            'vistas' => count($vistas),
            'duracion' => round(microtime(true) - $inicio, 2),
            'modo' => 'local',
            'aviso' => $aviso,
        ];
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
