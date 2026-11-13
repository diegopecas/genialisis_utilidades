<?php

/**
 * Abre conexiones PDO a MySQL/MariaDB a partir de una conexión de config/conexiones.json.
 */
class ConexionService
{
    public static function abrir(array $con, bool $conBuffer = true): PDO
    {
        if ($con['base'] === '') {
            throw new RuntimeException('La conexión "' . $con['nombre'] . '" no tiene base de datos definida');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $con['host'], $con['puerto'], $con['base']);
        $pdo = new PDO($dsn, $con['usuario'], $con['clave'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 20,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $conBuffer,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            // Comprime el tráfico con el servidor: las copias desde el hosting viajan mucho más rápido
            PDO::MYSQL_ATTR_COMPRESS => true,
        ]);
        return $pdo;
    }

    /**
     * Prueba la conexión y devuelve la versión del servidor.
     */
    public static function probar(array $con): array
    {
        $inicio = microtime(true);
        $pdo = self::abrir($con);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        return [
            'version' => $version,
            'duracion' => round(microtime(true) - $inicio, 2),
        ];
    }
}