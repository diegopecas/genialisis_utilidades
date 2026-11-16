<?php

require_once __DIR__ . '/config.service.php';

/**
 * Lee las conexiones guardadas en DBeaver.
 * - data-sources.json: nombre, carpeta, host, puerto y base de cada conexión (sin claves).
 * - credentials-config.json: usuario y clave, cifrados con AES-128-CBC y la llave por defecto de DBeaver.
 *   Si en DBeaver se activó contraseña maestra o almacenamiento seguro, este archivo no se puede descifrar.
 */
class DbeaverService
{
    // Llave por defecto con la que DBeaver cifra credentials-config.json
    const LLAVE = 'babb4a9f774ab853c96c2d653dfe544a';

    // Proveedores que la herramienta sabe manejar
    const PROVEEDORES = ['mysql', 'mariadb'];

    const SIN_CARPETA = 'Sin carpeta';

    const SIN_PROYECTO = 'Sin proyecto';

    // Orden de los tipos de base dentro de cada proyecto
    const TIPOS = ['Master', 'Tenants', 'Auditoría'];

    /**
     * Lista las conexiones visibles, sin credenciales, con su proyecto, tipo y ambiente.
     * Se ordenan por proyecto, tipo, ambiente y nombre.
     * Las conexiones sin base de datos definida no se listan porque no sirven para ejecutar ni copiar.
     */
    public static function listarConexiones(): array
    {
        $fuentes = self::leerFuentes();
        $ocultarCarpetas = ConfigService::valor('ocultar_carpetas', []);
        $ocultarConexiones = ConfigService::valor('ocultar_conexiones', []);
        $ajustes = ConfigService::valor('agrupacion.conexiones', []);
        $ambientes = ConfigService::valor('agrupacion.ambientes', ['Prod', 'QA', 'Demo', 'Local']);

        $lista = [];
        foreach ($fuentes['connections'] ?? [] as $id => $con) {
            if (!in_array($con['provider'] ?? '', self::PROVEEDORES, true)) {
                continue;
            }
            $item = self::armarConexion($id, $con);
            if ($item['base'] === ''
                || !empty($ajustes[$id]['ocultar'])
                || in_array($item['carpeta'], $ocultarCarpetas, true)
                || in_array($item['nombre'], $ocultarConexiones, true)
                || in_array($id, $ocultarConexiones, true)) {
                continue;
            }
            $lista[] = array_merge($item, self::clasificar($item, $ajustes[$id] ?? [], $ambientes));
        }

        usort($lista, function ($a, $b) use ($ambientes) {
            $orden = strcasecmp($a['proyecto'], $b['proyecto']);
            if ($orden === 0) {
                $orden = array_search($a['tipo'], self::TIPOS, true) - array_search($b['tipo'], self::TIPOS, true);
            }
            if ($orden === 0) {
                $orden = self::posicionAmbiente($a['ambiente'], $ambientes) - self::posicionAmbiente($b['ambiente'], $ambientes);
            }
            return $orden !== 0 ? $orden : strcasecmp($a['nombre'], $b['nombre']);
        });
        return $lista;
    }

    /**
     * Saca proyecto, tipo y ambiente de una conexión:
     * - Ambiente: última palabra de la carpeta de DBeaver (Prod, QA, Demo, Local) o sufijo del nombre de la base.
     * - Proyecto: la carpeta de DBeaver sin la palabra del ambiente.
     * - Tipo: Master si la base dice "master", Auditoría si dice "auditoria", Tenants en otro caso.
     * Lo definido en agrupacion.conexiones del config.json tiene prioridad.
     */
    private static function clasificar(array $item, array $ajuste, array $ambientes): array
    {
        $proyecto = $item['carpeta'] === self::SIN_CARPETA ? '' : $item['carpeta'];
        $ambiente = '';

        $palabras = preg_split('/\s+/', $proyecto);
        $ultima = end($palabras);
        foreach ($ambientes as $amb) {
            if ($ultima !== false && strcasecmp($ultima, $amb) === 0) {
                $ambiente = $amb;
                array_pop($palabras);
                $proyecto = implode(' ', $palabras);
                break;
            }
        }
        if ($ambiente === '') {
            foreach ($ambientes as $amb) {
                if (preg_match('/[_\-]' . preg_quote($amb, '/') . '$/i', $item['base'])) {
                    $ambiente = $amb;
                    break;
                }
            }
        }

        $base = strtolower($item['base']);
        $tipo = strpos($base, 'master') !== false ? 'Master' : (strpos($base, 'auditoria') !== false ? 'Auditoría' : 'Tenants');

        return [
            'proyecto' => $ajuste['proyecto'] ?? ($proyecto !== '' ? $proyecto : self::SIN_PROYECTO),
            'tipo' => $ajuste['tipo'] ?? $tipo,
            'ambiente' => $ajuste['ambiente'] ?? $ambiente,
        ];
    }

    private static function posicionAmbiente(string $ambiente, array $ambientes): int
    {
        $posicion = array_search($ambiente, $ambientes, true);
        return $posicion === false ? count($ambientes) : $posicion;
    }

    /**
     * Devuelve una conexión con su usuario y clave, lista para abrir.
     */
    public static function obtenerConexion(string $id): array
    {
        $fuentes = self::leerFuentes();
        if (!isset($fuentes['connections'][$id])) {
            throw new RuntimeException('La conexión ' . $id . ' no existe en DBeaver');
        }
        $con = self::armarConexion($id, $fuentes['connections'][$id]);

        $credenciales = self::leerCredenciales();
        $datos = $credenciales[$id]['#connection'] ?? null;
        if ($datos === null || !isset($datos['user'])) {
            throw new RuntimeException('La conexión "' . $con['nombre'] . '" no tiene usuario guardado en DBeaver');
        }
        $con['usuario'] = $datos['user'];
        $con['clave'] = $datos['password'] ?? '';
        return $con;
    }

    /**
     * Revisa que los archivos de DBeaver existan y que las credenciales se puedan descifrar.
     */
    public static function diagnostico(): array
    {
        $resultado = [
            'carpeta' => self::carpeta(),
            'conexiones' => false,
            'credenciales' => false,
            'mensaje' => null,
        ];
        try {
            self::leerFuentes();
            $resultado['conexiones'] = true;
            self::leerCredenciales();
            $resultado['credenciales'] = true;
        } catch (Throwable $e) {
            $resultado['mensaje'] = $e->getMessage();
        }
        return $resultado;
    }

    private static function armarConexion(string $id, array $con): array
    {
        $cfg = $con['configuration'] ?? [];
        $carpeta = trim((string) ($con['folder'] ?? ''));
        return [
            'id' => $id,
            'nombre' => (string) ($con['name'] ?? $id),
            'carpeta' => $carpeta === '' ? self::SIN_CARPETA : $carpeta,
            'host' => (string) ($cfg['host'] ?? 'localhost'),
            'puerto' => (int) ($cfg['port'] ?? 3306),
            'base' => (string) ($cfg['database'] ?? ''),
        ];
    }

    private static function carpeta(): string
    {
        return ConfigService::ruta((string) ConfigService::valor('dbeaver.carpeta', ''));
    }

    private static function leerFuentes(): array
    {
        $archivo = self::carpeta() . DIRECTORY_SEPARATOR . ConfigService::valor('dbeaver.archivo_conexiones', 'data-sources.json');
        if (!is_file($archivo)) {
            throw new RuntimeException('No se encontró ' . $archivo);
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($archivo));
        $datos = json_decode($contenido, true);
        if (!is_array($datos)) {
            throw new RuntimeException('El archivo de conexiones de DBeaver no es un JSON válido');
        }
        return $datos;
    }

    private static function leerCredenciales(): array
    {
        $archivo = self::carpeta() . DIRECTORY_SEPARATOR . ConfigService::valor('dbeaver.archivo_credenciales', 'credentials-config.json');
        if (!is_file($archivo)) {
            throw new RuntimeException('No se encontró ' . $archivo);
        }
        $binario = file_get_contents($archivo);
        if (strlen($binario) <= 16) {
            throw new RuntimeException('El archivo de credenciales de DBeaver está vacío');
        }
        // Los primeros 16 bytes son el IV, el resto es el contenido cifrado
        $iv = substr($binario, 0, 16);
        $cifrado = substr($binario, 16);
        $texto = openssl_decrypt($cifrado, 'AES-128-CBC', hex2bin(self::LLAVE), OPENSSL_RAW_DATA, $iv);
        $datos = $texto === false ? null : json_decode($texto, true);
        if (!is_array($datos)) {
            throw new RuntimeException('No se pudieron descifrar las credenciales de DBeaver. Revisa que no tengas activada la contraseña maestra ni el almacenamiento seguro.');
        }
        return $datos;
    }
}
