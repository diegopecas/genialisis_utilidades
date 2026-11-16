<?php

/**
 * Enrutador para el servidor embebido de PHP (php -S).
 * /api/* va al API; lo demás se sirve desde public/.
 * Las carpetas config/, logs/ y copias/ nunca se exponen.
 */
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (strpos($ruta, '/api/') === 0) {
    require __DIR__ . '/src/api.routes.php';
    return true;
}

$publica = realpath(__DIR__ . '/public');
$archivo = realpath($publica . ($ruta === '/' ? '/index.html' : $ruta));

if ($archivo === false || strpos($archivo, $publica) !== 0 || !is_file($archivo)) {
    http_response_code(404);
    echo 'No encontrado';
    return true;
}

$tipos = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'application/javascript; charset=utf-8',
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
];
$extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
header('Content-Type: ' . ($tipos[$extension] ?? 'application/octet-stream'));
header('Cache-Control: no-store');
readfile($archivo);
return true;
