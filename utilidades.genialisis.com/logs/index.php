<?php

/**
 * Descarga de logs para la herramienta Gestor de Copias.
 *
 * Solo lee logs: el error_log de la raíz y los .log de la carpeta cron de los sitios permitidos.
 * Recibe por POST los sitios, firmados con la clave secreta (encabezado X-Firma),
 * y devuelve un zip con un directorio por sitio. El archivo temporal se borra al terminar.
 */

require_once __DIR__ . '/src/seguridad.service.php';
require_once __DIR__ . '/src/logs.service.php';

ini_set('display_errors', '0');
date_default_timezone_set('America/Bogota');
@set_time_limit(0);

function responderError(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE);
}

$config = require __DIR__ . '/config/config.php';
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responderError(405, 'Método no permitido.');
    exit;
}

try {
    SeguridadService::revisarBloqueo($ip, $config);
} catch (RuntimeException $e) {
    responderError(429, $e->getMessage());
    exit;
}

try {
    // El campo "base" lleva los sitios separados por coma (así lo valida el servicio de seguridad)
    $datos = SeguridadService::validar(
        (string) file_get_contents('php://input'),
        (string) ($_SERVER['HTTP_X_FIRMA'] ?? ''),
        $config
    );
} catch (RuntimeException $e) {
    $codigo = $e->getCode() ?: 401;
    if ($codigo !== 500) {
        SeguridadService::registrarFallo($ip, $config);
    }
    responderError($codigo, $e->getMessage());
    exit;
}
SeguridadService::limpiarFallos($ip);

$sitios = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $datos['base'])))));
foreach ($sitios as $sitio) {
    if (!in_array($sitio, (array) $config['sitios'], true)) {
        responderError(404, 'El sitio ' . $sitio . ' no está permitido en el servidor.');
        exit;
    }
}
if (!$sitios) {
    responderError(400, 'No se pidió ningún sitio.');
    exit;
}
if (!class_exists('ZipArchive')) {
    responderError(500, 'El servidor no tiene la extensión zip.');
    exit;
}

$temporal = tempnam(__DIR__ . '/tmp', 'logs_');
if ($temporal === false) {
    responderError(500, 'No se pudo crear el archivo temporal.');
    exit;
}

try {
    $resultado = LogsService::generar($sitios, $config, $temporal);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($temporal));
    header('Cache-Control: no-store');
    header('X-Archivos: ' . $resultado['archivos']);
    header('X-Recortados: ' . $resultado['recortados']);
    header('X-Sin-Logs: ' . implode(',', $resultado['sin_logs']));
    readfile($temporal);
} catch (Throwable $e) {
    responderError(500, 'No se pudieron leer los logs: ' . $e->getMessage());
} finally {
    @unlink($temporal);
}
