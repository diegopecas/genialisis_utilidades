<?php

require_once __DIR__ . '/config.service.php';
require_once __DIR__ . '/ejecucion.service.php';

/**
 * Copias en zip de los fuentes de los proyectos de este equipo, listas para compartir.
 * Los proyectos se definen en config/proyectos.json (producto → carpetas raíz).
 * Solo lee las carpetas de los proyectos: el zip queda en la carpeta de copias de la herramienta,
 * dentro de la subcarpeta "proyectos". No se conecta a ningún servidor.
 *
 * Reglas:
 * - "excluir" se aplica en cualquier nivel (node_modules, dist, assets, *.log...).
 * - "configuracion" se aplica solo en el primer nivel del proyecto (config, config_*, .env)
 *   y únicamente se incluye si se pide; así no se pierden carpetas de código llamadas config
 *   dentro del front (ej. src/app/config).
 */
class ProyectosService
{
    const SUBCARPETA = 'proyectos';

    private static $config = null;

    private static function config(): array
    {
        if (self::$config === null) {
            $archivo = ConfigService::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'proyectos.json';
            if (!is_file($archivo)) {
                throw new RuntimeException('No existe el archivo config/proyectos.json');
            }
            $contenido = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($archivo));
            $config = json_decode($contenido, true);
            if (!is_array($config)) {
                throw new RuntimeException('config/proyectos.json no es un JSON válido: ' . json_last_error_msg());
            }
            self::$config = $config;
        }
        return self::$config;
    }

    /**
     * Carpeta donde quedan los zip de los proyectos (copias/proyectos). Se crea si no existe.
     */
    public static function carpetaCopias(): string
    {
        $ruta = ConfigService::carpeta('carpeta_copias') . DIRECTORY_SEPARATOR . self::SUBCARPETA;
        if (!is_dir($ruta) && !mkdir($ruta, 0775, true)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $ruta);
        }
        return $ruta;
    }

    /**
     * Proyectos de config/proyectos.json con su id (producto|nombre) y si la carpeta existe.
     */
    public static function listar(): array
    {
        $lista = [];
        $nombres = [];
        foreach ((array) (self::config()['proyectos'] ?? []) as $producto => $proyectos) {
            foreach ((array) $proyectos as $p) {
                $nombre = trim((string) ($p['nombre'] ?? ''));
                $ruta = trim((string) ($p['ruta'] ?? ''));
                if ($nombre === '' || $ruta === '') {
                    continue;
                }
                // El nombre es la carpeta del proyecto dentro del zip: no se puede repetir
                if (isset($nombres[strtolower($nombre)])) {
                    throw new RuntimeException('El proyecto ' . $nombre . ' está repetido en config/proyectos.json');
                }
                $nombres[strtolower($nombre)] = true;
                $lista[] = [
                    'id' => $producto . '|' . $nombre,
                    'producto' => (string) $producto,
                    'nombre' => $nombre,
                    'tipo' => ($p['tipo'] ?? '') === 'back' ? 'back' : 'front',
                    'ruta' => rtrim($ruta, '\\/'),
                    'existe' => is_dir($ruta),
                ];
            }
        }
        return $lista;
    }

    /**
     * Genera un solo zip con los proyectos pedidos, cada uno en su carpeta.
     */
    public static function copiar(array $ids, bool $incluirConfig): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Falta activar la extensión zip en el php.ini');
        }
        $inicio = microtime(true);

        $porId = [];
        foreach (self::listar() as $p) {
            $porId[$p['id']] = $p;
        }
        $elegidos = [];
        foreach (array_unique($ids) as $id) {
            if (!isset($porId[$id])) {
                throw new InvalidArgumentException('El proyecto ' . $id . ' no está en config/proyectos.json');
            }
            if (!$porId[$id]['existe']) {
                throw new RuntimeException('No existe la carpeta del proyecto ' . $porId[$id]['nombre'] . ': ' . $porId[$id]['ruta']);
            }
            $elegidos[] = $porId[$id];
        }
        if (!$elegidos) {
            throw new InvalidArgumentException('No se eligió ningún proyecto');
        }

        $carpeta = self::carpetaCopias();
        $base = count($elegidos) === 1 ? $elegidos[0]['nombre'] : 'proyectos';
        $nombreArchivo = EjecucionService::limpiarNombre($base) . '_' . date('Ymd_His')
            . ($incluirConfig ? '_con_config' : '') . '.zip';
        $archivo = $carpeta . DIRECTORY_SEPARATOR . $nombreArchivo;
        $temporal = $archivo . '.part';

        $zip = new ZipArchive();
        if ($zip->open($temporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ' . $temporal);
        }

        // Nunca se mete en el zip la propia carpeta de copias (por si un proyecto la contiene)
        $carpetaCopias = self::normalizar(ConfigService::carpeta('carpeta_copias'));
        $detalle = [];
        $total = 0;
        try {
            foreach ($elegidos as $p) {
                $archivos = self::agregarProyecto($zip, $p, $incluirConfig, $carpetaCopias);
                $detalle[] = ['nombre' => $p['nombre'], 'archivos' => $archivos];
                $total += $archivos;
            }
            if ($total === 0) {
                throw new RuntimeException('Los proyectos elegidos no tienen archivos para copiar');
            }
            if (!$zip->close()) {
                throw new RuntimeException('No se pudo terminar el zip: ' . $zip->getStatusString());
            }
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($temporal);
            throw $e;
        }

        if (!rename($temporal, $archivo)) {
            @unlink($temporal);
            throw new RuntimeException('No se pudo renombrar el archivo ' . $temporal);
        }

        return [
            'nombre_archivo' => $nombreArchivo,
            'ruta' => $archivo,
            'tamano' => filesize($archivo),
            'archivos' => $total,
            'proyectos' => $detalle,
            'incluye_config' => $incluirConfig,
            'duracion' => round(microtime(true) - $inicio, 2),
        ];
    }

    /**
     * Abre el Explorador de Windows con el zip seleccionado.
     */
    public static function abrirCarpeta(string $nombreArchivo): array
    {
        // Solo se aceptan zip de la carpeta de copias de proyectos, sin rutas ni comillas
        if ($nombreArchivo !== basename($nombreArchivo) || !preg_match('/^[A-Za-z0-9_\-\.]+\.zip$/', $nombreArchivo)) {
            throw new InvalidArgumentException('Nombre de archivo no válido');
        }
        $archivo = self::carpetaCopias() . DIRECTORY_SEPARATOR . $nombreArchivo;
        if (!is_file($archivo)) {
            throw new RuntimeException('No existe el archivo ' . $archivo);
        }
        if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
            throw new RuntimeException('Abrir la carpeta solo funciona en Windows. El archivo está en ' . $archivo);
        }
        pclose(popen('start "" explorer.exe /select,"' . $archivo . '"', 'r'));
        return ['ok' => true];
    }

    private static function agregarProyecto(ZipArchive $zip, array $p, bool $incluirConfig, string $carpetaCopias): int
    {
        $reglas = self::config();
        $excluir = (array) ($reglas['excluir'] ?? []);
        $configuracion = (array) ($reglas['configuracion'] ?? []);
        $raiz = $p['ruta'];
        $prefijo = $p['nombre'] . '/';
        $archivos = 0;

        $pendientes = [''];
        while ($pendientes) {
            $relativa = array_pop($pendientes);
            $carpeta = $relativa === '' ? $raiz : $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
            $entradas = @scandir($carpeta);
            if ($entradas === false) {
                throw new RuntimeException('No se pudo leer la carpeta ' . $carpeta);
            }
            foreach ($entradas as $nombre) {
                if ($nombre === '.' || $nombre === '..') {
                    continue;
                }
                $completa = $carpeta . DIRECTORY_SEPARATOR . $nombre;
                $rel = $relativa === '' ? $nombre : $relativa . '/' . $nombre;
                if (is_link($completa) || self::coincide($nombre, $excluir)) {
                    continue;
                }
                if ($relativa === '' && !$incluirConfig && self::coincide($nombre, $configuracion)) {
                    continue;
                }
                if (is_dir($completa)) {
                    if (self::normalizar($completa) === $carpetaCopias) {
                        continue;
                    }
                    $pendientes[] = $rel;
                } elseif (is_file($completa)) {
                    if (!$zip->addFile($completa, $prefijo . $rel)) {
                        throw new RuntimeException('No se pudo agregar al zip ' . $completa);
                    }
                    $archivos++;
                }
            }
        }
        return $archivos;
    }

    // Compara el nombre con patrones tipo "config_*" o "*.log", sin distinguir mayúsculas (como Windows)
    private static function coincide(string $nombre, array $patrones): bool
    {
        foreach ($patrones as $patron) {
            if (fnmatch(strtolower((string) $patron), strtolower($nombre))) {
                return true;
            }
        }
        return false;
    }

    private static function normalizar(string $ruta): string
    {
        $real = realpath($ruta);
        return strtolower(rtrim(str_replace('\\', '/', $real === false ? $ruta : $real), '/'));
    }
}
