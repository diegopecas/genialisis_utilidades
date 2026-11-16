# Gestor de Copias

Herramienta local para ejecutar scripts SQL en varias bases, sacar copias `.sql.gz` de las bases (conexiones en `config/conexiones.json`) y sacar copias `.zip` de los fuentes de los proyectos de este equipo (en `copias/proyectos`).

## Requisitos

- PHP 7.4 o superior con las extensiones `pdo_mysql`, `openssl`, `zlib`, `mbstring` y `zip` activas en el `php.ini`.

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

`config/proyectos.json`: proyectos por producto (nombre, tipo y carpeta raíz), carpetas y archivos que nunca entran al zip (`excluir`) y los de configuración del primer nivel que solo entran si se marca "Incluir configuración" (`configuracion`).

`config/config.json`: carpetas de copias y logs, orden de los ambientes, y filas por INSERT / nivel de compresión de las copias.

## Volver a importar desde DBeaver (opcional)

```
php importar-dbeaver.php --forzar
```
