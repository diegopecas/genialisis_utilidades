<?php

/**
 * Despliegues por zip para la herramienta gestor-bd.
 *
 * Recibe por POST un JSON firmado con la clave secreta (encabezado X-Firma):
 * - { accion: "respaldar", destino, rutas: [...] }  Devuelve un zip con lo que hay hoy en el servidor.
 * - { accion: "desplegar", destino, archivo }        Descomprime en el destino el zip que la herramienta
 *                                                    subió por FTP a la carpeta entrantes, y lo borra.
 */

require_once __DIR__ . '/src/seguridad.service.php';
require_once __DIR__ . '/src/despliegues.service.php';

ini_set('display_errors', '0');
date_default_timezone_set('America/Bogota');
@set_time_limit(0);

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
}

$config = require __DIR__ . '/config/config.php';
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['error' => 'Método no permitido.']);
    exit;
}

try {
    SeguridadService::revisarBloqueo($ip, $config);
} catch (RuntimeException $e) {
    responder(429, ['error' => $e->getMessage()]);
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
    responder($codigo, ['error' => $e->getMessage()]);
    exit;
}
SeguridadService::limpiarFallos($ip);

// La firma exige el campo "base"; aquí lleva el destino
$destino = (string) $datos['base'];
$accion = (string) ($datos['accion'] ?? '');

try {
    $servicio = new DesplieguesService($config);

    if ($accion === 'respaldar') {
        $temporal = tempnam(__DIR__ . '/tmp', 'respaldo_');
        try {
            $zip = $servicio->respaldar($destino, (array) ($datos['rutas'] ?? []), $temporal);
            if ($zip === null) {
                responder(200, ['vacio' => true]);
                exit;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/zip');
            header('Content-Length: ' . filesize($zip));
            header('Cache-Control: no-store');
            readfile($zip);
        } finally {
            @unlink($temporal);
        }
        exit;
    }

    if ($accion === 'desplegar') {
        $nombre = basename((string) ($datos['archivo'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.-]+\.zip$/', $nombre)) {
            throw new RuntimeException('Nombre de archivo inválido.', 400);
        }
        $archivo = __DIR__ . '/entrantes/' . $nombre;
        if (!is_file($archivo)) {
            throw new RuntimeException('No llegó el zip al servidor.', 404);
        }
        try {
            responder(200, $servicio->desplegar($destino, $archivo));
        } finally {
            @unlink($archivo);
        }
        exit;
    }

    throw new RuntimeException('Acción no válida.', 400);
} catch (Throwable $e) {
    $codigo = (int) $e->getCode();
    responder($codigo >= 400 && $codigo < 600 ? $codigo : 500, ['error' => $e->getMessage()]);
}
