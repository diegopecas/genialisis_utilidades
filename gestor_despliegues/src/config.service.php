<?php

/**
 * Lee config/config.json y resuelve las rutas que usa la herramienta.
 * Las rutas relativas se toman desde la carpeta raíz de la herramienta.
 * Las variables de entorno con formato %VARIABLE% (ej. %APPDATA%) se expanden.
 */
class ConfigService
{
    private static $config = null;

    public static function raiz(): string
    {
        return dirname(__DIR__);
    }

    public static function obtener(): array
    {
        if (self::$config === null) {
            $archivo = self::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.json';
            if (!is_file($archivo)) {
                throw new RuntimeException('No existe el archivo config/config.json');
            }
            $contenido = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($archivo));
            $config = json_decode($contenido, true);
            if (!is_array($config)) {
                throw new RuntimeException('config/config.json no es un JSON válido: ' . json_last_error_msg());
            }
            self::$config = $config;
        }
        return self::$config;
    }

    public static function valor(string $clave, $defecto = null)
    {
        $actual = self::obtener();
        foreach (explode('.', $clave) as $parte) {
            if (!is_array($actual) || !array_key_exists($parte, $actual)) {
                return $defecto;
            }
            $actual = $actual[$parte];
        }
        return $actual;
    }

    /**
     * Expande %VARIABLE% y convierte rutas relativas en absolutas desde la raíz.
     */
    public static function ruta(string $ruta): string
    {
        $ruta = preg_replace_callback('/%([A-Za-z0-9_]+)%/', function ($m) {
            $valor = getenv($m[1]);
            return $valor === false ? $m[0] : $valor;
        }, $ruta);

        $esAbsoluta = preg_match('/^([A-Za-z]:[\\\\\/]|[\\\\\/])/', $ruta) === 1;
        if (!$esAbsoluta) {
            $ruta = self::raiz() . DIRECTORY_SEPARATOR . $ruta;
        }
        return rtrim($ruta, '\\/');
    }

    /**
     * Devuelve una carpeta de trabajo (copias, logs) y la crea si no existe.
     */
    public static function carpeta(string $clave): string
    {
        $ruta = self::ruta((string) self::valor($clave, ''));
        if (!is_dir($ruta) && !mkdir($ruta, 0775, true)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $ruta);
        }
        return $ruta;
    }
}
