<?php

require_once __DIR__ . '/config.service.php';

/**
 * Pide la copia de una base al servicio de copias del hosting (utilidades.genialisis.com/copias)
 * y la descarga. La copia se genera allá, al lado de la base, así que tarda segundos.
 *
 * La configuración está en config/copias-remotas.json (solo en este equipo, fuera de Git):
 * {
 *   "activo": true,
 *   "url": "https://utilidades.genialisis.com/copias/",
 *   "secreto": "...",                 misma clave de config/config.php del servidor
 *   "usuario_carpeta": "...",         usuario de la contraseña de carpeta de cPanel
 *   "clave_carpeta": "...",
 *   "hosts": ["132.148.181.209"],     las bases de estos servidores se copian allá
 *   "segundos_espera": 600
 * }
 * Las claves de las bases no viajan: el servicio tiene su propia configuración.
 */
class CopiasRemotasService
{
    const ARCHIVO = 'copias-remotas.json';
    const ARCHIVO_CERTIFICADOS = 'cacert.pem';

    private static $config = false;

    /**
     * Indica si la base de esta conexión se copia en el servidor.
     */
    public static function aplica(array $con): bool
    {
        $config = self::config();
        return $config !== null && in_array($con['host'], (array) ($config['hosts'] ?? []), true);
    }

    /**
     * Descarga la copia de la base en $archivo (.sql.gz). Devuelve tablas, vistas y duración en el servidor.
     */
    public static function descargar(array $con, string $archivo): array
    {
        $config = self::config();
        if ($config === null) {
            throw new RuntimeException('No hay configuración de copias en el servidor');
        }

        $cuerpo = json_encode([
            'base' => $con['base'],
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
                'timeout' => (int) ($config['segundos_espera'] ?? 600),
                'ignore_errors' => true,
            ],
            // PHP en Windows no trae los certificados raíz, por eso se usa el archivo de la carpeta config
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => self::archivoCertificados(),
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

            $destino = fopen($archivo, 'wb');
            if ($destino === false) {
                throw new RuntimeException('No se pudo crear el archivo ' . $archivo);
            }
            $bytes = stream_copy_to_stream($origen, $destino);
            fclose($destino);
        } finally {
            fclose($origen);
        }

        // Se valida que llegó completo y que es un gzip
        $esperado = $respuesta['content-length'] ?? null;
        $inicio = (string) file_get_contents($archivo, false, null, 0, 2);
        if ($bytes === false || ($esperado !== null && (int) $esperado !== $bytes) || $inicio !== "\x1f\x8b") {
            @unlink($archivo);
            throw new RuntimeException('La copia llegó incompleta o dañada');
        }

        return [
            'tablas' => (int) ($respuesta['x-tablas'] ?? 0),
            'vistas' => (int) ($respuesta['x-vistas'] ?? 0),
            'duracion_servidor' => (float) ($respuesta['x-duracion'] ?? 0),
        ];
    }

    /**
     * Lee config/copias-remotas.json. Devuelve null si no existe o está desactivado.
     */
    private static function config(): ?array
    {
        if (self::$config === false) {
            self::$config = null;
            $archivo = ConfigService::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . self::ARCHIVO;
            if (is_file($archivo)) {
                $contenido = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($archivo));
                $config = json_decode($contenido, true);
                if (!is_array($config)) {
                    throw new RuntimeException('config/' . self::ARCHIVO . ' no es un JSON válido: ' . json_last_error_msg());
                }
                if (($config['activo'] ?? true) && !empty($config['url']) && !empty($config['secreto'])) {
                    self::$config = $config;
                }
            }
        }
        return self::$config;
    }

    private static function archivoCertificados(): string
    {
        return ConfigService::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . self::ARCHIVO_CERTIFICADOS;
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
