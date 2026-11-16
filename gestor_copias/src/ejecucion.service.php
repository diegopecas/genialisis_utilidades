<?php

require_once __DIR__ . '/config.service.php';
require_once __DIR__ . '/conexiones.service.php';
require_once __DIR__ . '/conexion.service.php';
require_once __DIR__ . '/sql-splitter.service.php';

/**
 * Ejecuta un script SQL en una base, sentencia por sentencia.
 * Si una sentencia falla, se detiene en esa base y reporta la sentencia y la línea.
 * Al cerrarse la conexión, el servidor revierte una transacción que haya quedado abierta;
 * los cambios de estructura (CREATE, ALTER, DROP) no se revierten porque MySQL hace commit implícito.
 */
class EjecucionService
{
    public static function analizar(string $script): array
    {
        $sentencias = SqlSplitterService::dividir($script);
        return ['sentencias' => count($sentencias)];
    }

    public static function ejecutar(string $idConexion, string $script, string $lote, string $nombreScript): array
    {
        $inicio = microtime(true);
        $con = ConexionesService::obtenerConexion($idConexion);
        $sentencias = SqlSplitterService::dividir($script);

        $resultado = [
            'ok' => true,
            'conexion' => $con['nombre'],
            'base' => $con['base'],
            'sentencias_total' => count($sentencias),
            'sentencias_ok' => 0,
            'filas_afectadas' => 0,
            'error' => null,
            'duracion' => 0,
        ];

        try {
            $pdo = ConexionService::abrir($con);
            foreach ($sentencias as $n => $sentencia) {
                try {
                    $resultado['filas_afectadas'] += self::ejecutarSentencia($pdo, $sentencia['sql']);
                    $resultado['sentencias_ok']++;
                } catch (PDOException $e) {
                    $resultado['ok'] = false;
                    $resultado['error'] = [
                        'sentencia' => $n + 1,
                        'linea' => $sentencia['linea'],
                        'mensaje' => $e->getMessage(),
                        'sql' => mb_substr($sentencia['sql'], 0, 400),
                    ];
                    break;
                }
            }
            $pdo = null;
        } catch (Throwable $e) {
            $resultado['ok'] = false;
            $resultado['error'] = ['sentencia' => null, 'linea' => null, 'mensaje' => $e->getMessage(), 'sql' => null];
        }

        $resultado['duracion'] = round(microtime(true) - $inicio, 2);
        self::registrarLog($lote, $nombreScript, $script, $resultado);
        return $resultado;
    }

    /**
     * Ejecuta una sentencia y devuelve las filas afectadas.
     * Recorre todos los resultados para que la conexión quede libre (SELECT, CALL).
     */
    private static function ejecutarSentencia(PDO $pdo, string $sql): int
    {
        $afectadas = 0;
        $st = $pdo->query($sql);
        do {
            if ($st->columnCount() > 0) {
                $st->fetchAll();
            } else {
                $afectadas += max(0, $st->rowCount());
            }
        } while ($st->nextRowset());
        $st->closeCursor();
        return $afectadas;
    }

    /**
     * Guarda en logs/ una copia del script ejecutado (una vez por lote)
     * y una línea por base con el resultado.
     */
    private static function registrarLog(string $lote, string $nombreScript, string $script, array $resultado): void
    {
        $carpeta = ConfigService::carpeta('carpeta_logs');
        $lote = self::limpiarNombre($lote);

        $archivoScript = $carpeta . DIRECTORY_SEPARATOR . $lote . '.sql';
        if (!is_file($archivoScript)) {
            file_put_contents($archivoScript, $script);
        }

        $linea = sprintf(
            "[%s] %s | %s (%s) | %s | %d/%d sentencias | %d filas | %ss",
            date('Y-m-d H:i:s'),
            $nombreScript,
            $resultado['conexion'],
            $resultado['base'],
            $resultado['ok'] ? 'OK' : 'ERROR',
            $resultado['sentencias_ok'],
            $resultado['sentencias_total'],
            $resultado['filas_afectadas'],
            $resultado['duracion']
        );
        if (!$resultado['ok'] && $resultado['error']) {
            $error = $resultado['error'];
            $linea .= PHP_EOL . '    Sentencia ' . ($error['sentencia'] ?? '-') . ', línea ' . ($error['linea'] ?? '-') . ': ' . $error['mensaje'];
        }
        file_put_contents($carpeta . DIRECTORY_SEPARATOR . $lote . '.log', $linea . PHP_EOL, FILE_APPEND);
    }

    public static function limpiarNombre(string $texto): string
    {
        $texto = preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $texto);
        return trim($texto, '_.') ?: 'script';
    }
}
