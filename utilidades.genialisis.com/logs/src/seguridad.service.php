<?php

/**
 * Valida que cada petición venga de la herramienta Gestor de Copias.
 *
 * La herramienta firma el cuerpo con HMAC-SHA256 usando la clave secreta compartida.
 * El cuerpo lleva la hora y un número único, así una firma vista por alguien
 * no se puede reutilizar: vence a los pocos minutos y cada número se acepta una sola vez.
 * Después de varios intentos fallidos desde una misma IP, esa IP queda bloqueada un tiempo.
 */
class SeguridadService
{
    const ARCHIVO_INTENTOS = 'intentos.json';
    const ARCHIVO_NONCES = 'nonces.json';

    /**
     * Lanza excepción si la IP está bloqueada por intentos fallidos.
     */
    public static function revisarBloqueo(string $ip, array $config): void
    {
        $intentos = self::leer(self::ARCHIVO_INTENTOS);
        $registro = $intentos[$ip] ?? null;
        if ($registro && ($registro['bloqueada_hasta'] ?? 0) > time()) {
            throw new RuntimeException('Demasiados intentos fallidos. Intenta más tarde.', 429);
        }
    }

    /**
     * Valida la firma, la vigencia y que el número único no se haya usado. Devuelve los datos del cuerpo.
     */
    public static function validar(string $cuerpo, string $firma, array $config): array
    {
        $secreto = (string) ($config['secreto'] ?? '');
        if (strlen($secreto) < 32) {
            throw new RuntimeException('La clave secreta del servidor no está configurada.', 500);
        }
        if ($firma === '' || !hash_equals(hash_hmac('sha256', $cuerpo, $secreto), $firma)) {
            throw new RuntimeException('Firma inválida.', 401);
        }

        $datos = json_decode($cuerpo, true);
        if (!is_array($datos) || empty($datos['base']) || empty($datos['ts']) || empty($datos['nonce'])) {
            throw new RuntimeException('Petición incompleta.', 400);
        }

        $ventana = (int) ($config['ventana_segundos'] ?? 120);
        if (abs(time() - (int) $datos['ts']) > $ventana) {
            throw new RuntimeException('La petición está vencida. Revisa que la hora del equipo esté bien.', 401);
        }

        self::usarNonce((string) $datos['nonce'], $ventana);
        return $datos;
    }

    /**
     * Suma un intento fallido a la IP y la bloquea si pasa del máximo.
     */
    public static function registrarFallo(string $ip, array $config): void
    {
        $maximo = (int) ($config['intentos_maximos'] ?? 5);
        $bloqueo = (int) ($config['bloqueo_minutos'] ?? 30) * 60;

        self::modificar(self::ARCHIVO_INTENTOS, function (array $intentos) use ($ip, $maximo, $bloqueo) {
            $ahora = time();
            // Se limpian los registros viejos para que el archivo no crezca
            foreach ($intentos as $clave => $registro) {
                if (($registro['ultimo'] ?? 0) < $ahora - $bloqueo && ($registro['bloqueada_hasta'] ?? 0) < $ahora) {
                    unset($intentos[$clave]);
                }
            }
            $registro = $intentos[$ip] ?? ['fallos' => 0, 'ultimo' => 0, 'bloqueada_hasta' => 0];
            $registro['fallos']++;
            $registro['ultimo'] = $ahora;
            if ($registro['fallos'] >= $maximo) {
                $registro['bloqueada_hasta'] = $ahora + $bloqueo;
                $registro['fallos'] = 0;
            }
            $intentos[$ip] = $registro;
            return $intentos;
        });
    }

    /**
     * Borra los intentos fallidos de la IP después de una petición válida.
     */
    public static function limpiarFallos(string $ip): void
    {
        self::modificar(self::ARCHIVO_INTENTOS, function (array $intentos) use ($ip) {
            unset($intentos[$ip]);
            return $intentos;
        });
    }

    private static function usarNonce(string $nonce, int $ventana): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            throw new RuntimeException('Petición inválida.', 400);
        }
        $repetido = false;
        self::modificar(self::ARCHIVO_NONCES, function (array $nonces) use ($nonce, $ventana, &$repetido) {
            $limite = time() - ($ventana * 2);
            foreach ($nonces as $clave => $hora) {
                if ($hora < $limite) {
                    unset($nonces[$clave]);
                }
            }
            if (isset($nonces[$nonce])) {
                $repetido = true;
                return $nonces;
            }
            $nonces[$nonce] = time();
            return $nonces;
        });
        if ($repetido) {
            throw new RuntimeException('Esta petición ya se había usado.', 401);
        }
    }

    private static function ruta(string $archivo): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $archivo;
    }

    private static function leer(string $archivo): array
    {
        $ruta = self::ruta($archivo);
        if (!is_file($ruta)) {
            return [];
        }
        $datos = json_decode((string) file_get_contents($ruta), true);
        return is_array($datos) ? $datos : [];
    }

    /**
     * Lee, modifica y guarda un archivo JSON con bloqueo, para que dos peticiones al tiempo no se pisen.
     */
    private static function modificar(string $archivo, callable $cambio): void
    {
        $manejador = fopen(self::ruta($archivo), 'c+');
        if ($manejador === false) {
            throw new RuntimeException('No se pudo abrir el registro de seguridad.', 500);
        }
        try {
            flock($manejador, LOCK_EX);
            $contenido = stream_get_contents($manejador);
            $datos = json_decode((string) $contenido, true);
            $datos = $cambio(is_array($datos) ? $datos : []);
            ftruncate($manejador, 0);
            rewind($manejador);
            fwrite($manejador, json_encode($datos));
            fflush($manejador);
        } finally {
            flock($manejador, LOCK_UN);
            fclose($manejador);
        }
    }
}
