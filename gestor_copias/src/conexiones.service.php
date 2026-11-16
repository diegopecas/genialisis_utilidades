<?php

require_once __DIR__ . '/config.service.php';

/**
 * Lee las conexiones desde config/conexiones.json, organizado por proyecto y tipo:
 * {
 *   "Proyecto": {
 *     "Master": [ { "base": "...", "ambiente": "Prod", "host": "...", "puerto": 3306, "usuario": "...", "clave": "..." } ],
 *     "Tenants": [ ... ],
 *     "Auditoría": [ ... ]
 *   }
 * }
 * El orden de proyectos, tipos y bases es el mismo del archivo.
 * El campo "nombre" es opcional; si no está, se usa el nombre de la base.
 */
class ConexionesService
{
    const ARCHIVO = 'conexiones.json';

    public static function archivo(): string
    {
        return ConfigService::raiz() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . self::ARCHIVO;
    }

    /**
     * Lista las conexiones sin usuario ni clave.
     */
    public static function listarConexiones(): array
    {
        return array_map(function ($con) {
            unset($con['usuario'], $con['clave']);
            return $con;
        }, self::leer());
    }

    /**
     * Devuelve una conexión con usuario y clave, lista para abrir.
     */
    public static function obtenerConexion(string $id): array
    {
        foreach (self::leer() as $con) {
            if ($con['id'] === $id) {
                return $con;
            }
        }
        throw new RuntimeException('La conexión ' . $id . ' no existe en config/conexiones.json');
    }

    public static function diagnostico(): array
    {
        $resultado = ['archivo' => self::archivo(), 'ok' => false, 'mensaje' => null];
        try {
            self::leer();
            $resultado['ok'] = true;
        } catch (Throwable $e) {
            $resultado['mensaje'] = $e->getMessage();
        }
        return $resultado;
    }

    /**
     * Lee el archivo y lo aplana en una lista de conexiones con proyecto y tipo.
     * El id es base@host:puerto, que es único aunque dos conexiones se llamen igual.
     */
    private static function leer(): array
    {
        $archivo = self::archivo();
        if (!is_file($archivo)) {
            throw new RuntimeException('No existe config/conexiones.json. Genéralo con: php importar-dbeaver.php');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($archivo));
        $proyectos = json_decode($contenido, true);
        if (!is_array($proyectos)) {
            throw new RuntimeException('config/conexiones.json no es un JSON válido: ' . json_last_error_msg());
        }

        $lista = [];
        foreach ($proyectos as $proyecto => $tipos) {
            if (!is_array($tipos)) {
                continue;
            }
            foreach ($tipos as $tipo => $bases) {
                if (!is_array($bases)) {
                    continue;
                }
                foreach ($bases as $b) {
                    if (empty($b['base']) || empty($b['host'])) {
                        throw new RuntimeException('En config/conexiones.json hay una conexión sin "base" o sin "host" en ' . $proyecto . ' / ' . $tipo);
                    }
                    $puerto = (int) ($b['puerto'] ?? 3306);
                    $lista[] = [
                        'id' => $b['base'] . '@' . $b['host'] . ':' . $puerto,
                        'nombre' => (string) ($b['nombre'] ?? $b['base']),
                        'proyecto' => (string) $proyecto,
                        'tipo' => (string) $tipo,
                        'ambiente' => (string) ($b['ambiente'] ?? ''),
                        'host' => (string) $b['host'],
                        'puerto' => $puerto,
                        'base' => (string) $b['base'],
                        'usuario' => (string) ($b['usuario'] ?? ''),
                        'clave' => (string) ($b['clave'] ?? ''),
                    ];
                }
            }
        }
        return $lista;
    }
}
