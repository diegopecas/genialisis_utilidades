# Gestor de Copias

Herramienta local para ejecutar scripts SQL en varias bases, sacar copias `.sql.gz` de las bases (conexiones en `config/conexiones.json`), sacar copias `.zip` de los fuentes de los proyectos de este equipo (en `copias/proyectos`) y descargar los logs de los APIs del hosting (en `copias/logs`).

## Requisitos

- PHP 7.4 o superior con las extensiones `pdo_mysql`, `openssl`, `zlib`, `mbstring` y `zip` activas en el `php.ini`.

## Correr en local

Doble clic en `abrir-gestor.vbs` (o en su acceso directo del escritorio, con el ícono `public/gestor.ico`): arranca el servidor sin consola y abre `http://localhost:8473`. Para apagarlo: `detener-gestor.vbs`.

Con consola, desde la terminal de VS Code (Ctrl + ñ), en la carpeta de la herramienta:

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

`config/proyectos.json`: proyectos por producto (nombre, tipo y carpeta raíz), carpetas y archivos que nunca entran al zip (`excluir`) y los de configuración del primer nivel que solo entran si se marca "Incluir configuración" (`configuracion`).

`config/logs-remotos.json`: url, secreto y contraseña de carpeta del servicio `utilidades.genialisis.com/logs`, y los APIs por producto y ambiente. Solo debe estar en este equipo. Cada API también debe estar en la lista de `logs/config/config.php` del servidor.

`config/config.json`: carpetas de copias y logs, orden de los ambientes, y filas por INSERT / nivel de compresión de las copias.

## Volver a importar desde DBeaver (opcional)

```
php importar-dbeaver.php --forzar
```
