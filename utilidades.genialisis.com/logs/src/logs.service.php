<?php

/**
 * Arma un zip con los logs de los sitios pedidos.
 * De cada sitio solo se leen el error_log de la raíz (log del API) y los .log de la carpeta cron.
 * No lee ningún otro archivo ni borra nada.
 */
class LogsService
{
    /**
     * Genera el zip en $archivo. Devuelve cuántos archivos se incluyeron y cuántos se recortaron.
     */
    public static function generar(array $sitios, array $config, string $archivo): array
    {
        $raiz = realpath((string) $config['raiz_sitios']);
        if ($raiz === false) {
            throw new RuntimeException('No existe la carpeta de los sitios.');
        }
        $maximo = max(1, (int) ($config['mb_maximos_por_archivo'] ?? 20)) * 1048576;

        $zip = new ZipArchive();
        if ($zip->open($archivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el zip.');
        }

        $archivos = 0;
        $recortados = 0;
        $sinLogs = [];
        foreach ($sitios as $sitio) {
            $carpeta = realpath($raiz . DIRECTORY_SEPARATOR . $sitio);
            // La carpeta debe existir y estar justo dentro de la raíz de los sitios
            if ($carpeta === false || dirname($carpeta) !== $raiz) {
                $sinLogs[] = $sitio;
                continue;
            }

            $logs = [];
            if (is_file($carpeta . '/error_log')) {
                $logs['error_log'] = $carpeta . '/error_log';
            }
            foreach (glob($carpeta . '/cron/*.log') ?: [] as $log) {
                if (is_file($log) && !is_link($log)) {
                    $logs['cron/' . basename($log)] = $log;
                }
            }
            if (!$logs) {
                $sinLogs[] = $sitio;
                continue;
            }

            foreach ($logs as $nombre => $ruta) {
                $tamano = filesize($ruta);
                if ($tamano > $maximo) {
                    // Solo la parte final, que es lo más reciente
                    $manejador = fopen($ruta, 'rb');
                    fseek($manejador, -$maximo, SEEK_END);
                    $contenido = "[... recortado: solo los últimos " . ($maximo / 1048576) . " MB de " . round($tamano / 1048576, 1) . " MB ...]\n" . stream_get_contents($manejador);
                    fclose($manejador);
                    $zip->addFromString($sitio . '/' . $nombre, $contenido);
                    $recortados++;
                } else {
                    $zip->addFile($ruta, $sitio . '/' . $nombre);
                }
                $archivos++;
            }
        }

        if ($archivos === 0) {
            $zip->addFromString('LEAME.txt', "Los sitios pedidos no tienen logs.\n");
        }
        if (!$zip->close()) {
            throw new RuntimeException('No se pudo terminar el zip.');
        }

        return ['archivos' => $archivos, 'recortados' => $recortados, 'sin_logs' => $sinLogs];
    }
}
