<?php

/**
 * Copias de bases de datos para la herramienta gestor-bd.
 *
 * Solo saca copias: no ejecuta SQL ni modifica nada.
 * Recibe por POST el nombre de la base, firmado con la clave secreta (encabezado X-Firma),
 * genera la copia aquí mismo y la devuelve comprimida. El archivo temporal se borra al terminar.
 */

require_once __DIR__ . '/src/seguridad.service.php';
require_once __DIR__ . '/src/copias.service.php';

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

$conexiones = require __DIR__ . '/config/conexiones.php';
$base = (string) $datos['base'];
if (!isset($conexiones[$base])) {
    responderError(404, 'La base ' . $base . ' no está configurada en el servidor.');
    exit;
}

$con = [
    'host' => $config['host_bd'] ?? 'localhost',
    'puerto' => $config['puerto_bd'] ?? 3306,
    'base' => $base,
    'usuario' => $conexiones[$base]['usuario'],
    'clave' => $conexiones[$base]['clave'],
];

$temporal = tempnam(__DIR__ . '/tmp', 'copia_');
if ($temporal === false) {
    responderError(500, 'No se pudo crear el archivo temporal.');
    exit;
}

try {
    $resultado = CopiasService::generar(
        $con,
        $temporal,
        (int) ($config['nivel_gzip'] ?? 6),
        (int) ($config['filas_por_insert'] ?? 500)
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/gzip');
    header('Content-Length: ' . filesize($temporal));
    header('Cache-Control: no-store');
    header('X-Tablas: ' . $resultado['tablas']);
    header('X-Vistas: ' . $resultado['vistas']);
    header('X-Duracion: ' . $resultado['duracion']);
    header('X-Version: ' . preg_replace('/[^A-Za-z0-9._-]/', '', $resultado['version']));
    readfile($temporal);
} catch (Throwable $e) {
    responderError(500, 'No se pudo sacar la copia: ' . $e->getMessage());
} finally {
    @unlink($temporal);
}
