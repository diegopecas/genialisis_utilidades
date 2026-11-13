<?php

/**
 * Despliegue por zip en el hosting, para la herramienta gestor-bd.
 *
 * - Front: borra el contenido del destino (menos lo protegido y las subcarpetas que sean otro destino)
 *   y descomprime el zip completo.
 * - Back: descomprime encima, sin borrar nada, así lo que solo existe en el servidor (logs, uploads) queda intacto.
 * - Respaldo: comprime lo que se va a reemplazar para que la herramienta lo descargue.
 *
 * Solo trabaja sobre los destinos de config/config.php; nunca sobre otras carpetas del hosting.
 */
class DesplieguesService
{
    private $config;
    private $raiz;

    public function __construct(array $config)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('El servidor no tiene la extensión zip de PHP.', 500);
        }
        $this->config = $config;
        $this->raiz = rtrim((string) $config['raiz_sitios'], '/');
    }

    // ---------- Validaciones ----------

    /**
     * Devuelve el tipo (front o back) del destino, o falla si no está configurado.
     */
    public function tipo(string $destino): string
    {
        $tipo = $this->config['destinos'][$destino] ?? null;
        if ($tipo !== 'front' && $tipo !== 'back') {
            throw new RuntimeException('El destino ' . $destino . ' no está configurado en el servidor.', 404);
        }
        return $tipo;
    }

    private function carpetaDestino(string $destino): string
    {
        return $this->raiz . '/' . trim($destino, '/');
    }

    private function coincide(string $nombre, array $patrones): bool
    {
        foreach ($patrones as $p) {
            if (fnmatch($p, $nombre, FNM_CASEFOLD)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Valida una ruta relativa: sin "..", sin rutas absolutas y sin partes excluidas o protegidas.
     */
    private function rutaValida(string $rel, string $tipo): bool
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || $rel[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $rel) || strpos($rel, ':') !== false) {
            return false;
        }
        $patrones = $tipo === 'back'
            ? ($this->config['excluir_back'] ?? [])
            : ($this->config['proteger_front'] ?? []);
        foreach (explode('/', rtrim($rel, '/')) as $segmento) {
            if ($this->coincide($segmento, $patrones)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Subcarpetas de este destino que son a su vez otro destino: el front nunca las borra.
     */
    private function destinosAnidados(string $destino): array
    {
        $prefijo = trim($destino, '/') . '/';
        $anidados = [];
        foreach (array_keys($this->config['destinos']) as $otro) {
            if (strpos($otro, $prefijo) === 0) {
                $anidados[] = substr($otro, strlen($prefijo));
            }
        }
        return $anidados;
    }

    // ---------- Respaldo ----------

    /**
     * Comprime lo que hay en el servidor y que se va a reemplazar. Devuelve la ruta del zip temporal, o null si no hay nada.
     * En el front respalda todo el destino; en el back, solo las rutas indicadas.
     */
    public function respaldar(string $destino, array $rutas, string $archivo): ?string
    {
        $tipo = $this->tipo($destino);
        $base = $this->carpetaDestino($destino);
        if (!is_dir($base)) {
            return null;
        }

        if ($tipo === 'front') {
            // Todo el destino, menos las subcarpetas que son otro destino
            $anidados = $this->destinosAnidados($destino);
            $rutas = array_filter(array_diff(scandir($base), ['.', '..']), function ($nombre) use ($anidados) {
                return !in_array($nombre, $anidados, true);
            });
        }

        $zip = new ZipArchive();
        if ($zip->open($archivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el respaldo.', 500);
        }
        $agregados = 0;
        foreach ($rutas as $rel) {
            $rel = trim(str_replace('\\', '/', (string) $rel), '/');
            if (!$this->rutaValida($rel, $tipo)) {
                continue;
            }
            $agregados += $this->agregarAlZip($zip, $base, $rel, $tipo, $tipo === 'front' ? $this->destinosAnidados($destino) : []);
        }
        $zip->close();

        if (!$agregados) {
            @unlink($archivo);
            return null;
        }
        return $archivo;
    }

    private function agregarAlZip(ZipArchive $zip, string $base, string $rel, string $tipo, array $anidados = []): int
    {
        $ruta = $base . '/' . $rel;
        if (in_array($rel, $anidados, true)) {
            return 0;
        }
        if (is_file($ruta)) {
            $zip->addFile($ruta, $rel);
            return 1;
        }
        if (!is_dir($ruta)) {
            return 0;
        }
        $agregados = 0;
        foreach (array_diff(scandir($ruta), ['.', '..']) as $hijo) {
            $relHijo = $rel . '/' . $hijo;
            if ($this->rutaValida($relHijo, $tipo)) {
                $agregados += $this->agregarAlZip($zip, $base, $relHijo, $tipo, $anidados);
            }
        }
        return $agregados;
    }

    // ---------- Desplegar ----------

    /**
     * Descomprime en el destino el zip que la herramienta subió por FTP a la carpeta entrantes.
     */
    public function desplegar(string $destino, string $archivoZip): array
    {
        $tipo = $this->tipo($destino);
        $base = $this->carpetaDestino($destino);

        $zip = new ZipArchive();
        if ($zip->open($archivoZip) !== true) {
            throw new RuntimeException('El zip subido no se pudo abrir.', 400);
        }

        try {
            // Se valida todo el contenido antes de tocar el destino. Una ruta insegura cancela todo;
            // lo excluido (back) o protegido (front) simplemente no se descomprime
            $entradas = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = str_replace('\\', '/', $zip->getNameIndex($i));
                if ($nombre === '' || $nombre[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $nombre) || strpos($nombre, ':') !== false) {
                    throw new RuntimeException('El zip trae una ruta no permitida: ' . $nombre, 400);
                }
                if ($this->rutaValida($nombre, $tipo)) {
                    $entradas[] = $nombre;
                }
            }
            if (!$entradas) {
                throw new RuntimeException('El zip está vacío.', 400);
            }

            if (!is_dir($base) && !mkdir($base, 0755, true)) {
                throw new RuntimeException('No se pudo crear la carpeta del destino.', 500);
            }

            $borrados = 0;
            if ($tipo === 'front') {
                $borrados = $this->vaciarFront($destino, $base);
            }

            if (!$zip->extractTo($base, $entradas)) {
                throw new RuntimeException('No se pudo descomprimir en el destino.', 500);
            }
            $archivos = count(array_filter($entradas, function ($e) {
                return substr($e, -1) !== '/';
            }));
        } finally {
            $zip->close();
        }

        return ['archivos' => $archivos, 'borrados' => $borrados];
    }

    /**
     * Borra el contenido del front, menos lo protegido y las subcarpetas que son otro destino.
     */
    private function vaciarFront(string $destino, string $base): int
    {
        $anidados = $this->destinosAnidados($destino);
        $proteger = $this->config['proteger_front'] ?? [];
        $borrados = 0;
        foreach (array_diff(scandir($base), ['.', '..']) as $nombre) {
            if ($this->coincide($nombre, $proteger) || in_array($nombre, $anidados, true)) {
                continue;
            }
            $borrados += $this->borrar($base . '/' . $nombre, $nombre, $anidados);
        }
        return $borrados;
    }

    private function borrar(string $ruta, string $rel, array $anidados): int
    {
        if (is_link($ruta) || is_file($ruta)) {
            return @unlink($ruta) ? 1 : 0;
        }
        $borrados = 0;
        $vacia = true;
        foreach (array_diff(scandir($ruta), ['.', '..']) as $hijo) {
            $relHijo = $rel . '/' . $hijo;
            if (in_array($relHijo, $anidados, true)) {
                $vacia = false;
                continue;
            }
            $borrados += $this->borrar($ruta . '/' . $hijo, $relHijo, $anidados);
        }
        if ($vacia) {
            @rmdir($ruta);
        }
        return $borrados;
    }
}
