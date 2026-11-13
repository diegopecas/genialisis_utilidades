<?php

/**
 * Configuración del servicio de despliegues.
 * La clave secreta debe ser la misma de "servidor.secreto" en config/despliegues.json de la herramienta gestor-bd.
 */
return [
    'secreto' => 'V40j2td75V1SEBLkFr-pXzGraF0ap0YPyeW9DTF3cdQ-VrsjweQZhXG_g5rle0qi',

    // Segundos de vigencia de una petición firmada
    'ventana_segundos' => 120,

    // Intentos fallidos permitidos antes de bloquear la IP, y minutos de bloqueo
    'intentos_maximos' => 5,
    'bloqueo_minutos' => 30,

    // Carpeta donde están los sitios (public_html): tres niveles arriba de esta carpeta config
    'raiz_sitios' => dirname(__DIR__, 3),

    // Back: nunca se descomprime ni se respalda lo que coincida con estos nombres
    'excluir_back' => ['uploads', 'logs', 'cache', 'galeria_privada', 'DB', '.git', '.vscode', '*.log', 'error_log'],

    // Front: al reemplazar nunca se borra ni se sobrescribe lo que coincida con estos nombres
    'proteger_front' => ['.htaccess', '.well-known', '.user.ini', 'error_log'],

    // Únicas carpetas de public_html donde se puede desplegar, con su tipo
    'destinos' => [
        // Breakeat
        'app.breakeat.com' => 'front',
        'ec.breakeat.com' => 'front',
        'portal.breakeat.com' => 'front',
        'demo.app.breakeat.com' => 'front',
        'demo.ec.breakeat.com' => 'front',
        'demo.portal.breakeat.com' => 'front',
        'breakeat.com' => 'front',
        'admin.breakeat.com' => 'front',
        'api.breakeat.com' => 'back',
        'qa.api.breakeat.com' => 'back',
        'demo.api.breakeat.com' => 'back',
        'admin.api.breakeat.com' => 'back',
        // Genialisis Academico
        'app.genialisis.com' => 'front',
        'qa.app.genialisis.com' => 'front',
        'demo.genialisis.com' => 'front',
        'padres.genialisis.com' => 'front',
        'qa.padres.genialisis.com' => 'front',
        'demo.padres.genialisis.com' => 'front',
        'api.genialisis.com' => 'back',
        'qa.api.genialisis.com' => 'back',
        'demo.api.genialisis.com' => 'back',
        // Genialisis Admin
        'clientes.genialisis.com' => 'front',
        'api.clientes.genialisis.com' => 'back',
        // Genialisis Portales
        'portales.genialisis.com/sporttennis' => 'front',
        'qa.portales.genialisis.com/sporttennis' => 'front',
        'api.portales.genialisis.com' => 'back',
        'qa.api.portales.genialisis.com' => 'back',
        // Genialisis Portal
        'genialisis.com' => 'front',
        'api-portal.genialisis.com' => 'back',
        // Genialisis Expertos
        'api.expertos.genialisis.com' => 'back',
        // Psycronia
        'app.psyncronia.com' => 'front',
        'api.psyncronia.com' => 'back',
    ],
];
