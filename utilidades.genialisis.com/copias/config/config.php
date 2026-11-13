<?php

/**
 * Configuración del servicio de copias.
 * La clave secreta debe ser la misma de config/copias-remotas.json en la herramienta gestor-bd.
 */
return [
    'secreto' => 'jgNzLtNFEm5990eF37MNLCxtt22JbhsImexQ8vtx7zRSlBRFwV4ICrUPHjMJqpqn',

    // Segundos de vigencia de una petición firmada
    'ventana_segundos' => 120,

    // Intentos fallidos permitidos antes de bloquear la IP, y minutos de bloqueo
    'intentos_maximos' => 5,
    'bloqueo_minutos' => 30,

    // Servidor de bases de datos visto desde el hosting
    'host_bd' => 'localhost',
    'puerto_bd' => 3306,

    'nivel_gzip' => 6,
    'filas_por_insert' => 500,
];
