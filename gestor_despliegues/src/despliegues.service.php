<?php

require_once __DIR__ . '/config.service.php';
require_once __DIR__ . '/ftp.service.php';

/**
 * Despliegue de fronts y backs por zip, definido en config/despliegues.json.
 *
 * Cómo despliega:
 * 1. Comprime en este equipo lo que se va a subir.
 *    - Front: todo el dist, menos lo protegido (.htaccess, .well-known...).
 *    - Back: solo las carpetas y archivos de primer nivel que se marcaron, sin lo excluido (uploads, logs...).
 * 2. Si se pidió respaldo, el servicio del hosting comprime lo que se va a reemplazar y se descarga a este equipo.
 * 3. Sube el zip por FTP a la carpeta entrantes del servicio de despliegues (utilidades.genialisis.com/despliegues).
 * 4. El servicio lo descomprime: en el front primero vacía el destino; en el back primero vacía solo las carpetas que se marcaron,
 *    sin tocar lo excluido. Nunca se borra la carpeta del destino, solo su contenido.
 */
class DesplieguesService
{
    // ---------- Configuración ----------

    private static function config(): array
    {
        $archivo = ConfigService::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'despliegues.json';
        if (!is_file($archivo)) {
            throw new RuntimeException('No existe config/despliegues.json');
        }
        $datos = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($archivo)), true);
        if (!is_array($datos)) {
            throw new RuntimeException('config/despliegues.json no es un JSON válido: ' . json_last_error_msg());
        }
        return $datos;
    }

    /**
     * Lista los destinos sin credenciales. El id es la carpeta destino, que es única.
     */
    public static function listar(): array
    {
        $lista = [];
        foreach (self::config()['proyectos'] ?? [] as $proyecto => $destinos) {
            foreach ($destinos as $d) {
                $lista[] = [
                    'id' => (string) $d['destino'],
                    'proyecto' => (string) $proyecto,
                    'nombre' => (string) ($d['nombre'] ?? $d['destino']),
                    'tipo' => (string) $d['tipo'],
                    'ambiente' => (string) ($d['ambiente'] ?? ''),
                    'origen' => (string) $d['origen'],
                    'destino' => (string) $d['destino'],
                    'origen_existe' => is_dir($d['origen']),
                ];
            }
        }
        return $lista;
    }

    private static function destino(string $id): array
    {
        foreach (self::config()['proyectos'] ?? [] as $proyecto => $destinos) {
            foreach ($destinos as $d) {
                if ((string) $d['destino'] === $id) {
                    $d['proyecto'] = $proyecto;
                    return $d;
                }
            }
        }
        throw new RuntimeException('El destino ' . $id . ' no existe en config/despliegues.json');
    }

    // ---------- Reglas ----------

    private static function coincide(string $nombre, array $patrones): bool
    {
        foreach ($patrones as $p) {
            if (fnmatch($p, $nombre, FNM_CASEFOLD)) {
                return true;
            }
        }
        return false;
    }

    /** Back: algún segmento de la ruta está excluido. Front: algún segmento está protegido. */
    private static function fuera(string $rel, array $d): bool
    {
        $reglas = self::config()['reglas'] ?? [];
        $patrones = $d['tipo'] === 'back' ? ($reglas['back']['excluir'] ?? []) : ($reglas['front']['proteger'] ?? []);
        foreach (explode('/', $rel) as $segmento) {
            if (self::coincide($segmento, $patrones)) {
                return true;
            }
        }
        return false;
    }

    /** Back: la ruta de primer nivel es una carpeta de configuración. */
    private static function esConfig(string $nombre, array $d): bool
    {
        return $d['tipo'] === 'back'
            && self::coincide($nombre, self::config()['reglas']['back']['configuracion'] ?? []);
    }

    private static function origen(array $d): string
    {
        $origen = rtrim($d['origen'], '\\/');
        if (!is_dir($origen)) {
            throw new RuntimeException('No existe la carpeta de origen ' . $origen);
        }
        return $origen;
    }

    // ---------- Contenido local ----------

    /**
     * Archivos de una carpeta o archivo de primer nivel, sin lo excluido o protegido.
     * Devuelve [ruta relativa => ruta absoluta].
     */
    private static function archivos(array $d, string $origen, string $primerNivel): array
    {
        $ruta = $origen . DIRECTORY_SEPARATOR . $primerNivel;
        if (self::fuera($primerNivel, $d)) {
            return [];
        }
        if (is_file($ruta)) {
            return [$primerNivel => $ruta];
        }
        $largo = strlen($origen) + 1;
        $filtro = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
            function ($actual) use ($d, $largo) {
                $rel = str_replace('\\', '/', substr($actual->getPathname(), $largo));
                return !self::fuera($rel, $d);
            }
        );
        $archivos = [];
        foreach (new RecursiveIteratorIterator($filtro) as $archivo) {
            if ($archivo->isFile()) {
                $archivos[str_replace('\\', '/', substr($archivo->getPathname(), $largo))] = $archivo->getPathname();
            }
        }
        return $archivos;
    }

    /**
     * Lo que hay en el origen para mostrar antes de desplegar.
     * - Front: total de archivos y tamaño, porque siempre se sube completo.
     * - Back: carpetas y archivos de primer nivel con la fecha del último cambio que tengan adentro,
     *   del más reciente al más viejo.
     */
    public static function contenido(string $id): array
    {
        $d = self::destino($id);
        $origen = self::origen($d);
        $elementos = [];
        foreach (array_diff(scandir($origen), ['.', '..']) as $nombre) {
            $archivos = self::archivos($d, $origen, $nombre);
            if (!$archivos) {
                continue;
            }
            $fecha = 0;
            $tamano = 0;
            foreach ($archivos as $ruta) {
                $fecha = max($fecha, (int) filemtime($ruta));
                $tamano += (int) filesize($ruta);
            }
            $elementos[] = [
                'nombre' => $nombre,
                'carpeta' => is_dir($origen . DIRECTORY_SEPARATOR . $nombre),
                'archivos' => count($archivos),
                'tamano' => $tamano,
                'fecha' => date('Y-m-d H:i', $fecha),
                'marca' => $fecha,
                'configuracion' => self::esConfig($nombre, $d),
            ];
        }

        if ($d['tipo'] === 'front') {
            return [
                'id' => $d['destino'],
                'tipo' => 'front',
                'archivos' => array_sum(array_column($elementos, 'archivos')),
                'tamano' => array_sum(array_column($elementos, 'tamano')),
                'fecha' => $elementos ? date('Y-m-d H:i', max(array_column($elementos, 'marca'))) : null,
            ];
        }

        usort($elementos, function ($a, $b) {
            return $b['marca'] <=> $a['marca'];
        });
        return ['id' => $d['destino'], 'tipo' => 'back', 'elementos' => $elementos];
    }

    // ---------- Desplegar ----------

    /**
     * Despliega un destino. En el back, $rutas son las carpetas o archivos de primer nivel marcados;
     * en el front se ignoran porque siempre se sube todo.
     */
    public static function ejecutar(string $id, array $rutas, bool $respaldar = false): array
    {
        $inicio = microtime(true);
        $d = self::destino($id);
        $origen = self::origen($d);
        $servidor = self::config()['servidor'] ?? [];
        if (empty($servidor['url']) || empty($servidor['secreto']) || empty($servidor['carpeta_entrantes'])) {
            throw new RuntimeException('Falta la sección "servidor" en config/despliegues.json');
        }

        // Rutas de primer nivel a subir; las que llegan del navegador se vuelven a validar
        $disponibles = array_values(array_filter(array_diff(scandir($origen), ['.', '..']), function ($nombre) use ($d) {
            return !self::fuera($nombre, $d);
        }));
        if ($d['tipo'] === 'front') {
            $rutas = $disponibles;
        } else {
            $rutas = array_values(array_intersect(array_unique(array_map('strval', $rutas)), $disponibles));
            if (!$rutas) {
                throw new RuntimeException('No se marcó nada para subir');
            }
        }

        $carpetaTrabajo = ConfigService::carpeta('carpeta_despliegues');
        $marca = date('Ymd_His');
        $nombreZip = self::nombreSeguro($d['destino']) . '_' . $marca . '_' . bin2hex(random_bytes(4)) . '.zip';
        $zipLocal = $carpetaTrabajo . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $nombreZip;
        $resultado = [
            'ok' => false,
            'destino' => $d['destino'],
            'rutas' => $rutas,
            'archivos' => 0,
            'borrados' => 0,
            'respaldo' => null,
            'error' => null,
        ];

        try {
            // 1. Comprimir en este equipo
            $resultado['archivos'] = self::comprimir($d, $origen, $rutas, $zipLocal);
            if (!$resultado['archivos']) {
                throw new RuntimeException('No hay archivos para subir');
            }

            // 2. Respaldo de lo que hay en el servidor
            if ($respaldar) {
                $archivoRespaldo = $carpetaTrabajo . DIRECTORY_SEPARATOR . 'respaldos' . DIRECTORY_SEPARATOR
                    . self::nombreSeguro($d['destino']) . DIRECTORY_SEPARATOR . $marca . '.zip';
                $resultado['respaldo'] = self::descargarRespaldo($servidor, $d, $rutas, $archivoRespaldo);
            }

            // 3. Subir el zip por FTP
            $ftp = new FtpService(self::config()['ftp'] ?? []);
            $ftp->subir($zipLocal, rtrim($servidor['carpeta_entrantes'], '/') . '/' . $nombreZip);
            unset($ftp);

            // 4. Descomprimir en el servidor
            $respuesta = self::llamar($servidor, ['accion' => 'desplegar', 'base' => $d['destino'], 'archivo' => $nombreZip]);
            $datos = json_decode($respuesta['cuerpo'], true);
            if ($respuesta['codigo'] !== 200 || !is_array($datos)) {
                throw new RuntimeException('El servidor respondió ' . $respuesta['codigo'] . ': ' . self::mensajeError($respuesta['cuerpo']));
            }
            $resultado['borrados'] = (int) ($datos['borrados'] ?? 0);
            $resultado['ok'] = true;
        } catch (Throwable $e) {
            $resultado['error'] = $e->getMessage();
        } finally {
            @unlink($zipLocal);
        }

        $resultado['duracion'] = round(microtime(true) - $inicio, 2);
        self::registrarLog($d, $resultado);
        return $resultado;
    }

    /**
     * Crea el zip con las rutas de primer nivel indicadas. Devuelve cuántos archivos quedaron adentro.
     */
    private static function comprimir(array $d, string $origen, array $rutas, string $zipLocal): int
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Falta activar la extensión zip en el php.ini');
        }
        $carpeta = dirname($zipLocal);
        if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $carpeta);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipLocal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el zip ' . $zipLocal);
        }
        $total = 0;
        foreach ($rutas as $nombre) {
            foreach (self::archivos($d, $origen, $nombre) as $rel => $ruta) {
                $zip->addFile($ruta, $rel);
                $total++;
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('No se pudo terminar el zip');
        }
        return $total;
    }

    /**
     * Pide al servidor un zip con lo que hay hoy en las rutas que se van a reemplazar y lo guarda en este equipo.
     * Devuelve la ruta del respaldo, o null si arriba no había nada.
     */
    private static function descargarRespaldo(array $servidor, array $d, array $rutas, string $archivo): ?string
    {
        $carpeta = dirname($archivo);
        if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $carpeta);
        }
        $respuesta = self::llamar($servidor, ['accion' => 'respaldar', 'base' => $d['destino'], 'rutas' => $rutas], $archivo);
        if ($respuesta['codigo'] !== 200) {
            @unlink($archivo);
            throw new RuntimeException('No se pudo sacar el respaldo: ' . self::mensajeError((string) file_get_contents($archivo)));
        }
        if (stripos($respuesta['tipo'], 'application/zip') === false) {
            // Respuesta JSON: no había nada que respaldar
            @unlink($archivo);
            return null;
        }
        return $archivo;
    }

    // ---------- Servicio del hosting ----------

    /**
     * Hace una petición firmada al servicio de despliegues. Si se da $archivo, guarda ahí la respuesta.
     * Devuelve ['codigo' => int, 'tipo' => string, 'cuerpo' => string].
     */
    private static function llamar(array $servidor, array $datos, ?string $archivo = null): array
    {
        $datos['ts'] = time();
        $datos['nonce'] = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $cuerpo = json_encode($datos);
        $encabezados = [
            'Content-Type: application/json',
            'X-Firma: ' . hash_hmac('sha256', $cuerpo, (string) $servidor['secreto']),
        ];
        if (!empty($servidor['usuario_carpeta'])) {
            $encabezados[] = 'Authorization: Basic ' . base64_encode($servidor['usuario_carpeta'] . ':' . ($servidor['clave_carpeta'] ?? ''));
        }
        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $encabezados),
                'content' => $cuerpo,
                'timeout' => (int) ($servidor['segundos_espera'] ?? 900),
                'ignore_errors' => true,
            ],
            // PHP en Windows no trae los certificados raíz, por eso se usa el archivo de la carpeta config
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => ConfigService::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cacert.pem',
            ],
        ]);

        $origen = @fopen((string) $servidor['url'], 'rb', false, $contexto);
        if ($origen === false) {
            $error = error_get_last();
            throw new RuntimeException('No se pudo conectar con el servicio de despliegues: ' . ($error['message'] ?? 'sin detalle'));
        }
        try {
            $codigo = 0;
            $tipo = '';
            foreach (stream_get_meta_data($origen)['wrapper_data'] ?? [] as $linea) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                    $codigo = (int) $m[1];
                } elseif (stripos($linea, 'Content-Type:') === 0) {
                    $tipo = trim(substr($linea, 13));
                }
            }
            if ($archivo !== null) {
                $destino = fopen($archivo, 'wb');
                stream_copy_to_stream($origen, $destino);
                fclose($destino);
                $cuerpo = '';
            } else {
                $cuerpo = (string) stream_get_contents($origen);
            }
        } finally {
            fclose($origen);
        }
        return ['codigo' => $codigo, 'tipo' => $tipo, 'cuerpo' => $cuerpo];
    }

    private static function mensajeError(string $texto): string
    {
        $json = json_decode($texto, true);
        $mensaje = is_array($json) && isset($json['error']) ? $json['error'] : trim(strip_tags($texto));
        return mb_substr($mensaje, 0, 300);
    }

    // ---------- Utilidades ----------

    private static function nombreSeguro(string $texto): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $texto), '_');
    }

    private static function registrarLog(array $d, array $resultado): void
    {
        $carpeta = ConfigService::carpeta('carpeta_despliegues') . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }
        $lineas = [];
        $lineas[] = '[' . date('Y-m-d H:i:s') . '] ' . $d['destino'] . ' (' . $d['tipo'] . ') - '
            . ($resultado['ok'] ? 'OK' : 'CON ERRORES') . ' - ' . $resultado['archivos'] . ' archivos, '
            . $resultado['borrados'] . ' borrados arriba, ' . $resultado['duracion'] . 's';
        $lineas[] = '    Subido: ' . implode(', ', $resultado['rutas']);
        if ($resultado['respaldo']) {
            $lineas[] = '    Respaldo: ' . $resultado['respaldo'];
        }
        if ($resultado['error']) {
            $lineas[] = '    ! ' . $resultado['error'];
        }
        file_put_contents($carpeta . DIRECTORY_SEPARATOR . date('Ymd') . '_despliegues.log', implode(PHP_EOL, $lineas) . PHP_EOL, FILE_APPEND);
    }
}
