<?php

require_once __DIR__ . '/config.service.php';
require_once __DIR__ . '/conexiones.service.php';
require_once __DIR__ . '/conexion.service.php';
require_once __DIR__ . '/ejecucion.service.php';
require_once __DIR__ . '/copias.service.php';
require_once __DIR__ . '/proyectos.service.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');
ini_set('display_errors', '0');
date_default_timezone_set('America/Bogota');

/**
 * Rutas del API:
 * GET  /api/diagnostico  Estado de PHP, archivo de conexiones y carpetas
 * GET  /api/conexiones   Conexiones de config/conexiones.json (sin credenciales)
 * POST /api/probar       { id }
 * POST /api/analizar     { script }
 * POST /api/ejecutar     { id, script, lote, nombre_script }
 * POST /api/copia        { id, sufijo }
 * POST /api/copia/abrir  { archivo }  Abre el Explorador con la copia seleccionada
 * GET  /api/proyectos   Proyectos de config/proyectos.json
 * POST /api/proyectos/copia  { ids: [producto|nombre], incluir_config: bool }  Un zip en copias/proyectos
 * POST /api/proyectos/abrir  { archivo }  Abre el Explorador con el zip seleccionado
 */
function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function cuerpo(): array
{
    $datos = json_decode(file_get_contents('php://input'), true);
    return is_array($datos) ? $datos : [];
}

function requerido(array $datos, string $campo): string
{
    if (!isset($datos[$campo]) || $datos[$campo] === '') {
        throw new InvalidArgumentException('Falta el campo ' . $campo);
    }
    return (string) $datos[$campo];
}

function diagnostico(): array
{
    $extensiones = [];
    foreach (['pdo_mysql', 'openssl', 'zlib', 'mbstring', 'zip'] as $ext) {
        $extensiones[$ext] = extension_loaded($ext);
    }
    $carpetas = [];
    foreach (['carpeta_copias', 'carpeta_logs'] as $clave) {
        try {
            $ruta = ConfigService::carpeta($clave);
            $carpetas[$clave] = ['ruta' => $ruta, 'escritura' => is_writable($ruta)];
        } catch (Throwable $e) {
            $carpetas[$clave] = ['ruta' => null, 'escritura' => false];
        }
    }
    return [
        'php' => PHP_VERSION,
        'extensiones' => $extensiones,
        'conexiones' => ConexionesService::diagnostico(),
        'carpetas' => $carpetas,
        'limite_post' => ini_get('post_max_size'),
    ];
}

$ruta = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo . ' ' . $ruta) {
        case 'GET /api/diagnostico':
            responder(200, diagnostico());
            break;

        case 'GET /api/conexiones':
            responder(200, [
                'conexiones' => ConexionesService::listarConexiones(),
                'ambientes' => ConfigService::valor('agrupacion.ambientes', ['Prod', 'QA', 'Demo', 'Local']),
            ]);
            break;

        case 'POST /api/probar':
            $datos = cuerpo();
            $con = ConexionesService::obtenerConexion(requerido($datos, 'id'));
            responder(200, ConexionService::probar($con));
            break;

        case 'POST /api/analizar':
            $datos = cuerpo();
            responder(200, EjecucionService::analizar(requerido($datos, 'script')));
            break;

        case 'POST /api/ejecutar':
            $datos = cuerpo();
            responder(200, EjecucionService::ejecutar(
                requerido($datos, 'id'),
                requerido($datos, 'script'),
                requerido($datos, 'lote'),
                requerido($datos, 'nombre_script')
            ));
            break;

        case 'GET /api/proyectos':
            responder(200, ['proyectos' => ProyectosService::listar(), 'carpeta' => ProyectosService::carpetaCopias()]);
            break;

        case 'POST /api/proyectos/copia':
            $datos = cuerpo();
            responder(200, ProyectosService::copiar(
                array_map('strval', (array) ($datos['ids'] ?? [])),
                !empty($datos['incluir_config'])
            ));
            break;

        case 'POST /api/proyectos/abrir':
            $datos = cuerpo();
            responder(200, ProyectosService::abrirCarpeta(requerido($datos, 'archivo')));
            break;

        case 'POST /api/copia':
            $datos = cuerpo();
            responder(200, CopiasService::generar(requerido($datos, 'id'), (string) ($datos['sufijo'] ?? '')));
            break;

        case 'POST /api/copia/abrir':
            $datos = cuerpo();
            responder(200, CopiasService::abrirCarpeta(requerido($datos, 'archivo')));
            break;

        default:
            responder(404, ['error' => 'Ruta no encontrada: ' . $metodo . ' ' . $ruta]);
    }
} catch (InvalidArgumentException $e) {
    responder(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    responder(500, ['error' => $e->getMessage()]);
}
