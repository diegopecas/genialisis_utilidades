# Gestor de Despliegues

Herramienta local para ejecutar scripts SQL en varias bases y sacar copias `.sql.gz`, con las conexiones definidas en `config/conexiones.json`.

## Requisitos

- PHP 7.4 o superior con las extensiones `pdo_mysql`, `openssl`, `zlib` y `mbstring` activas en el `php.ini`.

## Correr en local

Desde la terminal de VS Code (Ctrl + ñ), en la carpeta de la herramienta:

```
.\iniciar.bat
```

O directamente:

```
php -d post_max_size=256M -d memory_limit=1024M -d max_execution_time=0 -S localhost:8473 router.php
```

Abrir `http://localhost:8473` en el navegador. Para detener el servidor: Ctrl + C en la terminal.

## Configuración

`config/conexiones.json`: conexiones organizadas por proyecto y tipo (Master, Tenants, Auditoría), con base, ambiente, host, puerto, usuario y clave. Solo debe estar en este equipo.

`config/despliegues.json`: conexión FTP, reglas de exclusión y destinos (carpeta local de origen → carpeta en el servidor). Solo debe estar en este equipo. Requiere la extensión `ftp` activa en el `php.ini`.

`config/config.json`: carpetas de copias y logs, orden de los ambientes, y filas por INSERT / nivel de compresión de las copias.

## Volver a importar desde DBeaver (opcional)

```
php importar-dbeaver.php --forzar
```
