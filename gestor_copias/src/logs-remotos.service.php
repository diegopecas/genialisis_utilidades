<?php

require_once __DIR__ . '/config.service.php';

/**
 * Descarga los logs de los APIs desde el servicio de logs del hosting (utilidades.genialisis.com/logs).
 * De cada sitio llegan el error_log de la raíz y los .log de la carpeta cron. El zip queda en copias/logs.
 *
 * La configuración está en config/logs-remotos.json (solo en este equipo, fuera de Git):
 * {
 *   "activo": true,
 *   "url": "https://utilidades.genialisis.com/logs/",
 *   "secreto": "...",                 misma clave de logs/config/config.php del servidor
 *   "usuario_carpeta": "...",         usuario de la contraseña de carpeta de cPanel
 *   "clave_carpeta": "...",
 *   "segundos_espera": 300,
 *   "sitios": { "Producto": [ { "sitio": "api.ejemplo.com", "ambiente": "Prod" } ] }
 * }
 * Los sitios también deben estar en la lista del servidor; si no, el servidor los rechaza.
 */
class LogsRemotosService
{
    const ARCHIVO = 'logs-remotos.json';
    const ARCHIVO_CERTIFICADOS = 'cacert.pem';
    const SUBCARPETA = 'logs';

    private static $config = null;

    private static function config(): array
    {
        if (self::$config === null) {
            $archivo = ConfigService::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . self::ARCHIVO;
            if (!is_file($archivo)) {
                throw new RuntimeException('No existe el archivo config/' . self::ARCHIVO);
            }
            $contenido = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($archivo));
            $config = json_decode($contenido, true);
            if (!is_array($config)) {
                throw new RuntimeException('config/' . self::ARCHIVO . ' no es un JSON válido: ' . json_last_error_msg());
            }
            if (!($config['activo'] ?? true) || empty($config['url']) || empty($config['secreto'])) {
                throw new RuntimeException('La descarga de logs está desactivada o le falta la url o el secreto en config/' . self::ARCHIVO);
            }
            self::$config = $config;
        }
        return self::$config;
    }

    /**
     * Carpeta donde quedan los zip de logs (copias/logs). Se crea si no existe.
     */
    public static function carpetaLogs(): string
    {
        $ruta = ConfigService::carpeta('carpeta_copias') . DIRECTORY_SEPARATOR . self::SUBCARPETA;
        if (!is_dir($ruta) && !mkdir($ruta, 0775, true)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $ruta);
        }
        return $ruta;
    }

    /**
     * Sitios de config/logs-remotos.json con su producto y ambiente (sin credenciales).
     */
    public static function listar(): array
    {
        $lista = [];
        foreach ((array) (self::config()['sitios'] ?? []) as $producto => $sitios) {
            foreach ((array) $sitios as $s) {
                $sitio = trim((string) ($s['sitio'] ?? ''));
                if ($sitio === '') {
                    continue;
                }
                $lista[] = [
                    'id' => $sitio,
                    'producto' => (string) $producto,
                    'sitio' => $sitio,
                    'ambiente' => (string) ($s['ambiente'] ?? 'Prod'),
                ];
            }
        }
        return $lista;
    }

    /**
     * Pide al servidor los logs de los sitios y guarda el zip en copias/logs.
     */
    public static function descargar(array $ids): array
    {
        $inicio = microtime(true);
        $config = self::config();

        $validos = array_column(self::listar(), 'sitio');
        $sitios = [];
        foreach (array_unique($ids) as $id) {
            if (!in_array($id, $validos, true)) {
                throw new InvalidArgumentException('El sitio ' . $id . ' no está en config/' . self::ARCHIVO);
            }
            $sitios[] = $id;
        }
        if (!$sitios) {
            throw new InvalidArgumentException('No se eligió ningún sitio');
        }

        $base = count($sitios) === 1 ? $sitios[0] : 'logs';
        $nombreArchivo = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $base) . '_' . date('Ymd_His') . '.zip';
        $archivo = self::carpetaLogs() . DIRECTORY_SEPARATOR . $nombreArchivo;
        $temporal = $archivo . '.part';

        $cuerpo = json_encode([
            // El servidor valida el campo "base": aquí lleva los sitios separados por coma
            'base' => implode(',', $sitios),
            'ts' => time(),
            'nonce' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
        ]);
        $encabezados = [
            'Content-Type: application/json',
            'X-Firma: ' . hash_hmac('sha256', $cuerpo, (string) $config['secreto']),
        ];
        if (!empty($config['usuario_carpeta'])) {
            $encabezados[] = 'Authorization: Basic ' . base64_encode($config['usuario_carpeta'] . ':' . ($config['clave_carpeta'] ?? ''));
        }

        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $encabezados),
                'content' => $cuerpo,
                'timeout' => (int) ($config['segundos_espera'] ?? 300),
                'ignore_errors' => true,
            ],
            // PHP en Windows no trae los certificados raíz, por eso se usa el archivo de la carpeta config
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => ConfigService::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . self::ARCHIVO_CERTIFICADOS,
            ],
        ]);

        $origen = @fopen((string) $config['url'], 'rb', false, $contexto);
        if ($origen === false) {
            $error = error_get_last();
            throw new RuntimeException('No se pudo conectar con el servidor: ' . ($error['message'] ?? 'sin detalle'));
        }

        try {
            $meta = stream_get_meta_data($origen);
            $respuesta = self::encabezados($meta['wrapper_data'] ?? []);

            if ($respuesta['codigo'] !== 200) {
                $texto = (string) stream_get_contents($origen);
                $json = json_decode($texto, true);
                $mensaje = is_array($json) && isset($json['error']) ? $json['error'] : trim(strip_tags($texto));
                throw new RuntimeException('El servidor respondió ' . $respuesta['codigo'] . ': ' . mb_substr($mensaje, 0, 300));
            }

            $destino = fopen($temporal, 'wb');
            if ($destino === false) {
                throw new RuntimeException('No se pudo crear el archivo ' . $temporal);
            }
            $bytes = stream_copy_to_stream($origen, $destino);
            fclose($destino);
        } finally {
            fclose($origen);
        }

        // Se valida que llegó completo y que es un zip
        $esperado = $respuesta['content-length'] ?? null;
        $marca = (string) file_get_contents($temporal, false, null, 0, 2);
        if ($bytes === false || ($esperado !== null && (int) $esperado !== $bytes) || $marca !== 'PK') {
            @unlink($temporal);
            throw new RuntimeException('Los logs llegaron incompletos o dañados');
        }
        if (!rename($temporal, $archivo)) {
            @unlink($temporal);
            throw new RuntimeException('No se pudo renombrar el archivo ' . $temporal);
        }

        $sinLogs = trim((string) ($respuesta['x-sin-logs'] ?? ''));
        return [
            'nombre_archivo' => $nombreArchivo,
            'ruta' => $archivo,
            'tamano' => filesize($archivo),
            'archivos' => (int) ($respuesta['x-archivos'] ?? 0),
            'recortados' => (int) ($respuesta['x-recortados'] ?? 0),
            'sin_logs' => $sinLogs === '' ? [] : explode(',', $sinLogs),
            'duracion' => round(microtime(true) - $inicio, 2),
        ];
    }

    /**
     * Abre el Explorador de Windows con el zip de logs seleccionado.
     */
    public static function abrirCarpeta(string $nombreArchivo): array
    {
        // Solo se aceptan zip de la carpeta de logs, sin rutas ni comillas
        if ($nombreArchivo !== basename($nombreArchivo) || !preg_match('/^[A-Za-z0-9_\-\.]+\.zip$/', $nombreArchivo)) {
            throw new InvalidArgumentException('Nombre de archivo no válido');
        }
        $archivo = self::carpetaLogs() . DIRECTORY_SEPARATOR . $nombreArchivo;
        if (!is_file($archivo)) {
            throw new RuntimeException('No existe el archivo ' . $archivo);
        }
        if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
            throw new RuntimeException('Abrir la carpeta solo funciona en Windows. El archivo está en ' . $archivo);
        }
        pclose(popen('start "" explorer.exe /select,"' . $archivo . '"', 'r'));
        return ['ok' => true];
    }

    /**
     * Convierte los encabezados de la respuesta en un arreglo con el código y los campos en minúscula.
     * Si hubo redirecciones, se toman los del último bloque.
     */
    private static function encabezados(array $lineas): array
    {
        $resultado = ['codigo' => 0];
        foreach ($lineas as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                $resultado = ['codigo' => (int) $m[1]];
                continue;
            }
            $partes = explode(':', $linea, 2);
            if (count($partes) === 2) {
                $resultado[strtolower(trim($partes[0]))] = trim($partes[1]);
            }
        }
        return $resultado;
    }
}
