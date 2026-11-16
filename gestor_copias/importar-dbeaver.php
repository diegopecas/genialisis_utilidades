<?php

/**
 * Genera config/conexiones.json a partir de las conexiones guardadas en DBeaver.
 * Se usa una sola vez; después la herramienta trabaja solo con config/conexiones.json.
 *
 * Uso:
 *   php importar-dbeaver.php            (no sobrescribe un conexiones.json existente)
 *   php importar-dbeaver.php --forzar   (lo sobrescribe)
 *
 * Toma la ruta de DBeaver y las reglas de agrupación de config/config.json (secciones "dbeaver" y "agrupacion").
 */

require_once __DIR__ . '/src/config.service.php';
require_once __DIR__ . '/src/dbeaver.service.php';
require_once __DIR__ . '/src/conexiones.service.php';

$destino = ConexionesService::archivo();
$forzar = in_array('--forzar', $argv ?? [], true);

if (is_file($destino) && !$forzar) {
    fwrite(STDERR, "Ya existe {$destino}. Usa --forzar para sobrescribirlo.\n");
    exit(1);
}

$estructura = [];
$total = 0;
$sinUsuario = [];
foreach (DbeaverService::listarConexiones() as $item) {
    try {
        $con = DbeaverService::obtenerConexion($item['id']);
    } catch (Throwable $e) {
        $sinUsuario[] = $item['nombre'];
        $con = $item + ['usuario' => '', 'clave' => ''];
    }
    $estructura[$item['proyecto']][$item['tipo']][] = [
        'base' => $item['base'],
        'ambiente' => $item['ambiente'],
        'host' => $item['host'],
        'puerto' => $item['puerto'],
        'usuario' => $con['usuario'],
        'clave' => $con['clave'],
    ];
    $total++;
}

$json = json_encode($estructura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($destino, $json . PHP_EOL);

echo "Se generó {$destino} con {$total} conexiones en " . count($estructura) . " proyectos.\n";
if ($sinUsuario) {
    echo "Sin usuario guardado en DBeaver (quedaron vacías): " . implode(', ', $sinUsuario) . "\n";
}
