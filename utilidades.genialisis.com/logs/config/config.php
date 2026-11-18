<?php

/**
 * Configuración del servicio de logs.
 * La clave secreta debe ser la misma de config/logs-remotos.json en la herramienta Gestor de Copias.
 */
return [
    'secreto' => '6fBeCxpCGyBE243a3_FlSWZCW9IG7buP06qhtscw7okaNxDPbEx-3ANAV-UxOQ7E',

    // Segundos de vigencia de una petición firmada
    'ventana_segundos' => 120,

    // Intentos fallidos permitidos antes de bloquear la IP, y minutos de bloqueo
    'intentos_maximos' => 5,
    'bloqueo_minutos' => 30,

    // Carpeta donde están los sitios (public_html): logs/config → logs → utilidades.genialisis.com → public_html
    'raiz_sitios' => dirname(__DIR__, 3),

    // Si un log pesa más que esto, solo se envía su parte final (lo más reciente)
    'mb_maximos_por_archivo' => 20,

    // Únicos sitios de los que se pueden leer logs: el error_log de la raíz y los .log de la carpeta cron
    'sitios' => [
        'api.breakeat.com',
        'qa.api.breakeat.com',
        'demo.api.breakeat.com',
        'api.genialisis.com',
        'qa.api.genialisis.com',
        'demo.api.genialisis.com',
        'api.clientes.genialisis.com',
        'api.portales.genialisis.com',
        'qa.api.portales.genialisis.com',
        'api-portal.genialisis.com',
        'api.psyncronia.com',
    ],
];
