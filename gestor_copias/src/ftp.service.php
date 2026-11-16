<?php

/**
 * Operaciones FTP/FTPS sobre el hosting.
 * Usa FTPS explícito (TLS) cuando la configuración lo indica, en modo pasivo.
 * Las rutas remotas son absolutas y con "/" (ej. /public_html/app.genialisis.com/index.html).
 */
class FtpService
{
    private $conexion;

    public function __construct(array $cfg)
    {
        if (!function_exists('ftp_connect')) {
            throw new RuntimeException('Falta activar la extensión ftp en el php.ini');
        }
        // Un reintento con conexión nueva: el FTP de GoDaddy a veces corta el inicio de sesión por un momento
        $error = '';
        for ($intento = 1; $intento <= 2; $intento++) {
            if ($intento > 1) {
                sleep(5);
            }
            $error = $this->conectar($cfg);
            if ($error === '') {
                break;
            }
        }
        if ($error !== '') {
            throw new RuntimeException($error);
        }
        ftp_pasv($this->conexion, !isset($cfg['pasivo']) || $cfg['pasivo']);
        ftp_set_option($this->conexion, FTP_TIMEOUT_SEC, 120);
    }

    /**
     * Abre la conexión e inicia sesión.
     * Devuelve '' si todo salió bien, o el mensaje de error con lo que respondió el servidor.
     */
    private function conectar(array $cfg): string
    {
        $host = (string) $cfg['host'];
        $puerto = (int) ($cfg['puerto'] ?? 21);
        error_clear_last();
        $this->conexion = !empty($cfg['tls'])
            ? @ftp_ssl_connect($host, $puerto, 30)
            : @ftp_connect($host, $puerto, 30);
        if (!$this->conexion) {
            $this->conexion = null;
            return 'No se pudo conectar al FTP ' . $host . ':' . $puerto . $this->detalleError();
        }
        error_clear_last();
        if (!@ftp_login($this->conexion, (string) $cfg['usuario'], (string) $cfg['clave'])) {
            $detalle = $this->detalleError();
            @ftp_close($this->conexion);
            $this->conexion = null;
            return 'El FTP no aceptó el inicio de sesión' . $detalle;
        }
        return '';
    }

    /**
     * Mensaje del último aviso de PHP (ahí queda la respuesta real del servidor FTP).
     */
    private function detalleError(): string
    {
        $ultimo = error_get_last();
        if (!$ultimo || empty($ultimo['message'])) {
            return '';
        }
        $mensaje = trim(preg_replace('/^ftp_\w+\(\):\s*/', '', (string) $ultimo['message']));
        return $mensaje === '' ? '' : ' (' . $mensaje . ')';
    }

    public function __destruct()
    {
        if ($this->conexion) {
            @ftp_close($this->conexion);
        }
    }

    /**
     * Lista recursivamente una carpeta remota.
     * Devuelve ['archivos' => [rel => ['tamano' => int, 'fecha' => string]], 'carpetas' => [rel => true], 'existe' => bool]
     * $omitir recibe la ruta relativa de una carpeta y devuelve true si no se debe recorrer.
     */
    public function listar(string $raiz, callable $omitir = null): array
    {
        $resultado = ['archivos' => [], 'carpetas' => [], 'existe' => true];
        $entradas = @ftp_mlsd($this->conexion, $raiz);
        if ($entradas === false) {
            $resultado['existe'] = false;
            return $resultado;
        }
        $pendientes = [['', $entradas]];
        while ($pendientes) {
            [$relBase, $lista] = array_pop($pendientes);
            foreach ($lista as $e) {
                $nombre = $e['name'] ?? '';
                $tipo = strtolower($e['type'] ?? '');
                if ($nombre === '' || $tipo === 'cdir' || $tipo === 'pdir' || $nombre === '.' || $nombre === '..') {
                    continue;
                }
                $rel = $relBase === '' ? $nombre : $relBase . '/' . $nombre;
                if ($tipo === 'dir') {
                    if ($omitir && $omitir($rel)) {
                        continue;
                    }
                    $resultado['carpetas'][$rel] = true;
                    $hijos = @ftp_mlsd($this->conexion, $raiz . '/' . $rel);
                    if ($hijos !== false) {
                        $pendientes[] = [$rel, $hijos];
                    }
                } elseif ($tipo === 'file') {
                    $resultado['archivos'][$rel] = [
                        'tamano' => (int) ($e['size'] ?? -1),
                        'fecha' => (string) ($e['modify'] ?? ''),
                    ];
                }
            }
        }
        return $resultado;
    }

    public function crearCarpeta(string $ruta): void
    {
        if (!@ftp_mkdir($this->conexion, $ruta)) {
            // Puede que ya exista
            if (@ftp_mlsd($this->conexion, $ruta) === false) {
                throw new RuntimeException('No se pudo crear la carpeta remota ' . $ruta);
            }
        }
    }

    public function subir(string $local, string $remoto): void
    {
        if (!@ftp_put($this->conexion, $remoto, $local, FTP_BINARY)) {
            throw new RuntimeException('No se pudo subir ' . $remoto);
        }
    }

    public function descargar(string $remoto, string $local): void
    {
        $carpeta = dirname($local);
        if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
            throw new RuntimeException('No se pudo crear la carpeta local ' . $carpeta);
        }
        if (!@ftp_get($this->conexion, $local, $remoto, FTP_BINARY)) {
            throw new RuntimeException('No se pudo descargar ' . $remoto);
        }
    }

    public function borrar(string $remoto): void
    {
        if (!@ftp_delete($this->conexion, $remoto)) {
            throw new RuntimeException('No se pudo borrar ' . $remoto);
        }
    }

    /**
     * Borra una carpeta remota si quedó vacía. No falla si tiene contenido.
     */
    public function borrarCarpetaVacia(string $remoto): bool
    {
        return @ftp_rmdir($this->conexion, $remoto);
    }
}