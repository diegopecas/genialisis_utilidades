(function () {
    'use strict';

    const estado = {
        conexiones: [],
        seleccion: new Set(),
        contraidas: new Set(),
        ambientes: new Set(),
        ordenAmbientes: [],
        filtro: '',
        script: null,
        nombreScript: '',
        sentencias: 0,
        trabajando: false,
        // Después de ejecutar, el botón queda bloqueado hasta que se vuelva a cargar el script (evita correrlo dos veces)
        yaEjecutado: false,
    };

    const $ = (id) => document.getElementById(id);

    // ---------- Utilidades ----------

    async function api(ruta, datos) {
        const opciones = datos === undefined
            ? { method: 'GET' }
            : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos) };
        const respuesta = await fetch('/api/' + ruta, opciones);
        let cuerpo;
        try {
            cuerpo = await respuesta.json();
        } catch (e) {
            throw new Error('Respuesta inválida del servidor (' + respuesta.status + ')');
        }
        if (!respuesta.ok) {
            throw new Error(cuerpo.error || 'Error ' + respuesta.status);
        }
        return cuerpo;
    }

    function escapar(texto) {
        const div = document.createElement('div');
        div.textContent = texto == null ? '' : String(texto);
        return div.innerHTML;
    }

    function tamano(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function esProduccion(con) {
        return con.ambiente === 'Prod';
    }

    function marcaTiempo() {
        const d = new Date();
        const p = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '_' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
    }

    function aviso(texto) {
        M.toast({ html: escapar(texto), displayLength: 4000 });
    }

    // ---------- Diagnóstico ----------

    async function cargarDiagnostico() {
        const alerta = $('alerta-diagnostico');
        try {
            const d = await api('diagnostico');
            const problemas = [];
            Object.keys(d.extensiones).forEach((ext) => {
                if (!d.extensiones[ext]) problemas.push('Falta activar la extensión <b>' + ext + '</b> en el php.ini');
            });
            if (!d.conexiones.ok) {
                problemas.push(escapar(d.conexiones.mensaje || 'No se pudo leer el archivo de conexiones') + '<br><small>Archivo: ' + escapar(d.conexiones.archivo) + '</small>');
            }
            Object.keys(d.carpetas).forEach((clave) => {
                if (!d.carpetas[clave].escritura) problemas.push('No se puede escribir en la carpeta ' + escapar(d.carpetas[clave].ruta || clave));
            });
            if (d.carpetas.carpeta_copias && d.carpetas.carpeta_copias.ruta) {
                $('ruta-copias').textContent = d.carpetas.carpeta_copias.ruta;
            }
            if (problemas.length) {
                alerta.innerHTML = '<b>Revisa lo siguiente:</b><ul>' + problemas.map((p) => '<li>' + p + '</li>').join('') + '</ul>';
                alerta.classList.remove('oculto');
            } else {
                alerta.classList.add('oculto');
            }
        } catch (e) {
            alerta.innerHTML = '<b>No se pudo hacer el diagnóstico:</b> ' + escapar(e.message);
            alerta.classList.remove('oculto');
        }
    }

    // ---------- Árbol de conexiones ----------

    async function cargarConexiones() {
        $('arbol').innerHTML = '<div class="cargando">Cargando conexiones...</div>';
        try {
            const datos = await api('conexiones');
            estado.conexiones = datos.conexiones;
            estado.ordenAmbientes = datos.ambientes || [];
            const ids = new Set(estado.conexiones.map((c) => c.id));
            estado.seleccion.forEach((id) => { if (!ids.has(id)) estado.seleccion.delete(id); });
            if (!estado.contraidas.size) {
                agrupar(estado.conexiones).forEach((p) => contraerProyecto(p));
            }
            pintarFiltroAmbientes();
            pintarArbol();
        } catch (e) {
            $('arbol').innerHTML = '<div class="cargando">' + escapar(e.message) + '</div>';
        }
    }

    function claveProyecto(proyecto) {
        return 'p:' + proyecto;
    }

    function claveTipo(proyecto, tipo) {
        return 't:' + proyecto + '|' + tipo;
    }

    function contraerProyecto(p) {
        estado.contraidas.add(claveProyecto(p.proyecto));
        p.tipos.forEach((t) => estado.contraidas.add(claveTipo(p.proyecto, t.tipo)));
    }

    /**
     * Agrupa las conexiones por proyecto y, dentro de cada proyecto, por tipo (Master, Tenants, Auditoría).
     * Las conexiones ya vienen ordenadas desde el API.
     */
    function agrupar(conexiones) {
        const proyectos = new Map();
        conexiones.forEach((c) => {
            if (!proyectos.has(c.proyecto)) proyectos.set(c.proyecto, new Map());
            const tipos = proyectos.get(c.proyecto);
            if (!tipos.has(c.tipo)) tipos.set(c.tipo, []);
            tipos.get(c.tipo).push(c);
        });
        return Array.from(proyectos.entries()).map(([proyecto, tipos]) => ({
            proyecto,
            tipos: Array.from(tipos.entries()).map(([tipo, items]) => ({ tipo, items })),
        }));
    }

    function visibles() {
        const f = estado.filtro.toLowerCase();
        return estado.conexiones.filter((c) =>
            (!estado.ambientes.size || estado.ambientes.has(c.ambiente)) &&
            (!f || c.nombre.toLowerCase().includes(f) || c.base.toLowerCase().includes(f) || c.proyecto.toLowerCase().includes(f)));
    }

    function etiquetaAmbiente(con) {
        if (!con.ambiente) return '';
        return '<span class="etiqueta-ambiente amb-' + escapar(con.ambiente.toLowerCase()) + '">' + escapar(con.ambiente.toUpperCase()) + '</span>';
    }

    // ---------- Filtro por ambiente ----------

    function pintarFiltroAmbientes() {
        // Solo los ambientes que tienen bases, en el orden definido en config.json
        const usados = new Set(estado.conexiones.map((c) => c.ambiente).filter((a) => a));
        const disponibles = estado.ordenAmbientes.filter((a) => usados.has(a));
        usados.forEach((a) => { if (!disponibles.includes(a)) disponibles.push(a); });
        $('filtro-ambientes').innerHTML = '<span class="filtro-titulo">Ambiente:</span>' +
            '<span class="chip-ambiente' + (estado.ambientes.size ? '' : ' activo') + '" data-ambiente="">Todos</span>' +
            disponibles.map((a) => '<span class="chip-ambiente' + (estado.ambientes.has(a) ? ' activo' : '') + '" data-ambiente="' + escapar(a) + '">' + escapar(a) + '</span>').join('');
    }

    /**
     * Al cambiar el filtro se quitan de la selección las bases que dejan de verse,
     * para no ejecutar sobre bases ocultas.
     */
    function depurarSeleccion() {
        const ids = new Set(visibles().map((c) => c.id));
        estado.seleccion.forEach((id) => { if (!ids.has(id)) estado.seleccion.delete(id); });
    }

    // ---------- Pintado del árbol ----------

    function casilla(clase, items, datos) {
        const marcadas = items.filter((c) => estado.seleccion.has(c.id)).length;
        return {
            marcadas,
            html: '<label><input type="checkbox" class="filled-in ' + clase + '"' + datos +
                (items.length && marcadas === items.length ? ' checked' : '') + '><span></span></label>',
        };
    }

    function pintarConexion(c) {
        return '<div class="conexion">' +
            '<label><input type="checkbox" class="filled-in chk-conexion" data-id="' + escapar(c.id) + '"' +
            (estado.seleccion.has(c.id) ? ' checked' : '') + '>' +
            '<span>' + escapar(c.base) + etiquetaAmbiente(c) +
            '<span class="conexion-detalle">' + (c.nombre !== c.base ? escapar(c.nombre) + ' · ' : '') + escapar(c.host) + '</span>' +
            '</span></label></div>';
    }

    function pintarArbol() {
        const proyectos = agrupar(visibles());
        const arbol = $('arbol');
        if (!proyectos.length) {
            arbol.innerHTML = '<div class="cargando">No hay conexiones que coincidan.</div>';
            actualizarContador();
            return;
        }
        const buscando = estado.filtro !== '';

        arbol.innerHTML = proyectos.map((p) => {
            const todas = p.tipos.reduce((acc, t) => acc.concat(t.items), []);
            const cas = casilla('chk-proyecto', todas, ' data-proyecto="' + escapar(p.proyecto) + '"');
            const contraido = !buscando && estado.contraidas.has(claveProyecto(p.proyecto));

            // Si el proyecto tiene un solo tipo de base, las bases van directo bajo el proyecto
            const hijos = p.tipos.length === 1
                ? todas.map(pintarConexion).join('')
                : p.tipos.map((t) => {
                    const casTipo = casilla('chk-tipo', t.items, ' data-proyecto="' + escapar(p.proyecto) + '" data-tipo="' + escapar(t.tipo) + '"');
                    const tipoContraido = !buscando && estado.contraidas.has(claveTipo(p.proyecto, t.tipo));
                    return '<div class="grupo-tipo' + (tipoContraido ? ' contraida' : '') + '" data-clave="' + escapar(claveTipo(p.proyecto, t.tipo)) + '">' +
                        '<div class="grupo-encabezado tipo-encabezado">' + casTipo.html +
                        '<i class="material-icons">' + (tipoContraido ? 'chevron_right' : 'expand_more') + '</i>' +
                        '<span class="grupo-nombre">' + escapar(t.tipo) + '</span>' +
                        '<span class="grupo-conteo">' + casTipo.marcadas + '/' + t.items.length + '</span>' +
                        '</div><div class="grupo-hijos">' + t.items.map(pintarConexion).join('') + '</div></div>';
                }).join('');

            return '<div class="carpeta' + (contraido ? ' contraida' : '') + '" data-clave="' + escapar(claveProyecto(p.proyecto)) + '">' +
                '<div class="grupo-encabezado carpeta-encabezado">' + cas.html +
                '<i class="material-icons">' + (contraido ? 'folder' : 'folder_open') + '</i>' +
                '<span class="grupo-nombre carpeta-nombre">' + escapar(p.proyecto) + '</span>' +
                '<span class="grupo-conteo carpeta-conteo">' + cas.marcadas + '/' + todas.length + '</span>' +
                '</div><div class="carpeta-hijos">' + hijos + '</div></div>';
        }).join('');

        actualizarContador();
    }

    function actualizarContador() {
        const n = estado.seleccion.size;
        $('contador-seleccion').textContent = n + (n === 1 ? ' seleccionada' : ' seleccionadas');
        actualizarBotones();
    }

    function actualizarBotones() {
        const haySeleccion = estado.seleccion.size > 0;
        $('btn-ejecutar').disabled = estado.trabajando || estado.yaEjecutado || !haySeleccion || !estado.script || estado.sentencias === 0;
        $('btn-ejecutar').innerHTML = estado.yaEjecutado
            ? '<i class="material-icons left">done_all</i>Ya se ejecutó · vuelve a cargar el script'
            : '<i class="material-icons left">play_arrow</i>Ejecutar en las bases seleccionadas';
        $('btn-copias').disabled = estado.trabajando || !haySeleccion;
    }

    function seleccionadasOrdenadas() {
        return estado.conexiones.filter((c) => estado.seleccion.has(c.id));
    }

    function eventosArbol() {
        const arbol = $('arbol');

        arbol.addEventListener('change', (e) => {
            const objetivo = e.target;
            let afectadas = null;
            if (objetivo.classList.contains('chk-conexion')) {
                afectadas = visibles().filter((c) => c.id === objetivo.dataset.id);
            } else if (objetivo.classList.contains('chk-proyecto')) {
                afectadas = visibles().filter((c) => c.proyecto === objetivo.dataset.proyecto);
            } else if (objetivo.classList.contains('chk-tipo')) {
                afectadas = visibles().filter((c) => c.proyecto === objetivo.dataset.proyecto && c.tipo === objetivo.dataset.tipo);
            }
            if (!afectadas) return;
            afectadas.forEach((c) => {
                if (objetivo.checked) estado.seleccion.add(c.id);
                else estado.seleccion.delete(c.id);
            });
            pintarArbol();
        });

        arbol.addEventListener('click', (e) => {
            const encabezado = e.target.closest('.grupo-encabezado');
            if (!encabezado || e.target.closest('label')) return;
            const clave = encabezado.parentElement.dataset.clave;
            if (estado.contraidas.has(clave)) estado.contraidas.delete(clave);
            else estado.contraidas.add(clave);
            pintarArbol();
        });

        $('filtro-ambientes').addEventListener('click', (e) => {
            const chip = e.target.closest('.chip-ambiente');
            if (!chip) return;
            const ambiente = chip.dataset.ambiente;
            if (!ambiente) estado.ambientes.clear();
            else if (estado.ambientes.has(ambiente)) estado.ambientes.delete(ambiente);
            else estado.ambientes.add(ambiente);
            depurarSeleccion();
            pintarFiltroAmbientes();
            pintarArbol();
        });

        $('buscador').addEventListener('input', (e) => {
            estado.filtro = e.target.value.trim();
            pintarArbol();
        });

        $('btn-expandir').addEventListener('click', () => { estado.contraidas.clear(); pintarArbol(); });
        $('btn-contraer').addEventListener('click', () => {
            agrupar(estado.conexiones).forEach((p) => contraerProyecto(p));
            pintarArbol();
        });
        $('btn-limpiar').addEventListener('click', () => { estado.seleccion.clear(); pintarArbol(); });
        $('btn-recargar').addEventListener('click', () => { cargarDiagnostico(); cargarConexiones(); });
    }

    // ---------- Resultados ----------

    function itemResultado(lista, con, texto) {
        const li = document.createElement('li');
        li.className = 'resultado';
        li.innerHTML = '<i class="material-icons">schedule</i><div class="resultado-cuerpo">' +
            '<div class="resultado-titulo">' + escapar(con.base) + etiquetaAmbiente(con) + '</div>' +
            '<div class="resultado-detalle">' + escapar(texto) + '</div></div>';
        lista.appendChild(li);
        return li;
    }

    function marcarResultado(li, tipo, detalleHtml) {
        const iconos = { trabajando: 'autorenew', ok: 'check_circle', error: 'error', omitida: 'remove_circle_outline' };
        li.className = 'resultado ' + tipo;
        const icono = li.querySelector('.material-icons');
        icono.textContent = iconos[tipo];
        icono.classList.toggle('girando', tipo === 'trabajando');
        li.querySelector('.resultado-detalle').innerHTML = detalleHtml;
    }

    function bloquear(trabajando) {
        estado.trabajando = trabajando;
        $('archivo-script').disabled = trabajando;
        actualizarBotones();
    }

    window.addEventListener('beforeunload', (e) => {
        if (estado.trabajando) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // ---------- Ejecutar script ----------

    function eventosEjecucion() {
        $('archivo-script').addEventListener('change', (e) => {
            const archivo = e.target.files[0];
            estado.yaEjecutado = false;
            estado.script = null;
            estado.sentencias = 0;
            estado.nombreScript = '';
            $('info-script').textContent = '';
            actualizarBotones();
            if (!archivo) return;

            const lector = new FileReader();
            lector.onload = async () => {
                estado.script = lector.result;
                estado.nombreScript = archivo.name;
                try {
                    const analisis = await api('analizar', { script: estado.script });
                    estado.sentencias = analisis.sentencias;
                    $('info-script').textContent = archivo.name + ': ' + analisis.sentencias + ' sentencias, ' + tamano(archivo.size) + '.';
                } catch (err) {
                    $('info-script').textContent = 'No se pudo analizar el script: ' + err.message;
                }
                actualizarBotones();
            };
            lector.readAsText(archivo, 'UTF-8');
        });

        $('btn-ejecutar').addEventListener('click', () => {
            const bases = seleccionadasOrdenadas();
            const prod = bases.filter(esProduccion).length;
            $('confirmar-resumen').innerHTML = 'Se ejecutará <b>' + escapar(estado.nombreScript) + '</b> (' + estado.sentencias +
                ' sentencias) en <b>' + bases.length + '</b> base(s)' + (prod ? ', de las cuales <b>' + prod + (prod === 1 ? ' es de producción</b>' : ' son de producción</b>') : '') + '.' +
                ($('opt-respaldo').checked ? ' Antes se saca una copia de cada base.' : '');
            // Lista agrupada por proyecto y tipo, para ver de un vistazo qué se está mezclando
            const grupos = new Map();
            bases.forEach((c) => {
                const clave = c.proyecto + ' / ' + c.tipo;
                if (!grupos.has(clave)) grupos.set(clave, []);
                grupos.get(clave).push(c);
            });
            $('confirmar-lista').innerHTML = Array.from(grupos.entries()).map(([clave, items]) =>
                '<li class="grupo-confirmar"><b>' + escapar(clave) + '</b><ul>' +
                items.map((c) => '<li>' + escapar(c.base) + etiquetaAmbiente(c) + '</li>').join('') +
                '</ul></li>').join('');

            // Aviso no bloqueante cuando el script va a bases de distintos proyectos o tipos
            const proyectos = Array.from(new Set(bases.map((c) => c.proyecto)));
            const tipos = Array.from(new Set(bases.map((c) => c.tipo)));
            const mezclas = [];
            if (proyectos.length > 1) mezclas.push('proyectos distintos (' + proyectos.map(escapar).join(', ') + ')');
            if (tipos.length > 1) {
                mezclas.push('tipos de base distintos (' + tipos.map((t) => bases.filter((c) => c.tipo === t).length + ' ' + escapar(t)).join(', ') + ')');
            }
            $('confirmar-mezcla').innerHTML = mezclas.length
                ? '<i class="material-icons">warning</i><span>Vas a ejecutar este script en ' + mezclas.join(' y en ') + '. Un mismo script rara vez sirve para todas. ¿Seguro?</span>'
                : '';
            $('confirmar-mezcla').classList.toggle('oculto', mezclas.length === 0);
            // Si hay bases de producción y no se pidió copia, se pregunta antes de ejecutar
            const preguntar = prod > 0 && !$('opt-respaldo').checked;
            $('confirmar-pregunta').classList.toggle('oculto', !preguntar);
            $('btn-sin-copia').classList.toggle('oculto', !preguntar);
            $('btn-confirmar').textContent = preguntar ? 'Sí, sacar copia y ejecutar' : 'Ejecutar';
            M.Modal.getInstance($('modal-confirmar')).open();
        });

        $('btn-confirmar').addEventListener('click', () => {
            const preguntar = !$('confirmar-pregunta').classList.contains('oculto');
            M.Modal.getInstance($('modal-confirmar')).close();
            ejecutar(preguntar || $('opt-respaldo').checked);
        });

        $('btn-sin-copia').addEventListener('click', () => {
            M.Modal.getInstance($('modal-confirmar')).close();
            ejecutar(false);
        });
    }

    async function ejecutar(respaldo) {
        const bases = seleccionadasOrdenadas();
        const detener = $('opt-detener').checked;
        const lote = marcaTiempo() + '_' + estado.nombreScript.replace(/\.sql$/i, '');
        const lista = $('resultados-ejecucion');
        lista.innerHTML = '';

        const items = bases.map((c) => ({ con: c, li: itemResultado(lista, c, 'En espera') }));
        bloquear(true);
        let errores = 0;
        let detenido = false;

        for (const item of items) {
            if (detenido) {
                marcarResultado(item.li, 'omitida', 'No se ejecutó porque una base anterior falló.');
                continue;
            }

            let copia = null;
            if (respaldo) {
                marcarResultado(item.li, 'trabajando', 'Sacando copia...');
                try {
                    copia = await api('copia', { id: item.con.id, sufijo: 'antes_script' });
                } catch (e) {
                    errores++;
                    marcarResultado(item.li, 'error', 'No se ejecutó porque falló la copia: ' + escapar(e.message));
                    if (detener) detenido = true;
                    continue;
                }
            }

            marcarResultado(item.li, 'trabajando', 'Ejecutando script...');
            try {
                const r = await api('ejecutar', { id: item.con.id, script: estado.script, lote, nombre_script: estado.nombreScript });
                const textoCopia = copia ? '<br>Copia: ' + escapar(copia.nombre_archivo) + ' (' + tamano(copia.tamano) + ')' + textoModoCopia(copia) + botonAbrir(copia) : '';
                if (r.ok) {
                    marcarResultado(item.li, 'ok', r.sentencias_ok + '/' + r.sentencias_total + ' sentencias · ' + r.filas_afectadas +
                        ' filas afectadas · ' + r.duracion + 's' + textoCopia);
                } else {
                    errores++;
                    const err = r.error || {};
                    marcarResultado(item.li, 'error',
                        (err.sentencia ? 'Falló la sentencia ' + err.sentencia + ' (línea ' + err.linea + '). ' + r.sentencias_ok + ' sentencias alcanzaron a ejecutarse.' : 'No se pudo ejecutar.') +
                        '<pre>' + escapar(err.mensaje) + (err.sql ? '\n\n' + escapar(err.sql) : '') + '</pre>' + textoCopia);
                    if (detener) detenido = true;
                }
            } catch (e) {
                errores++;
                marcarResultado(item.li, 'error', escapar(e.message));
                if (detener) detenido = true;
            }
        }

        // Se bloquea el botón y se limpia el selector, para que el navegador detecte si se vuelve a elegir el mismo archivo
        estado.yaEjecutado = true;
        $('archivo-script').value = '';
        bloquear(false);
        aviso(errores ? 'Terminó con ' + errores + ' error(es). Revisa el log ' + lote + '.log' : 'Script ejecutado en ' + bases.length + ' base(s).');
    }

    // ---------- Copias ----------

    // Indica si la copia se sacó en el servidor y muestra el aviso cuando tuvo que sacarse desde este equipo
    function textoModoCopia(r) {
        let texto = r.modo === 'servidor' ? ' · desde el servidor' : '';
        if (r.aviso) {
            texto += '<br><span class="aviso-copia">' + escapar(r.aviso) + '</span>';
        }
        return texto;
    }

    // Botón para abrir el Explorador con la copia seleccionada
    function botonAbrir(r) {
        return '<br><button class="btn-flat btn-pequeno btn-abrir-copia" data-archivo="' + escapar(r.nombre_archivo) + '">' +
            '<i class="material-icons left">folder_open</i>Abrir carpeta</button>';
    }

    async function abrirCarpeta(e) {
        const boton = e.target.closest('.btn-abrir-copia');
        if (!boton) return;
        try {
            await api('copia/abrir', { archivo: boton.dataset.archivo });
        } catch (err) {
            aviso(err.message);
        }
    }

    function eventosCopias() {
        $('resultados-copias').addEventListener('click', abrirCarpeta);
        $('resultados-ejecucion').addEventListener('click', abrirCarpeta);
        $('btn-copias').addEventListener('click', async () => {
            const bases = seleccionadasOrdenadas();
            const lista = $('resultados-copias');
            lista.innerHTML = '';
            const items = bases.map((c) => ({ con: c, li: itemResultado(lista, c, 'En espera') }));
            bloquear(true);
            let errores = 0;

            for (const item of items) {
                marcarResultado(item.li, 'trabajando', 'Sacando copia...');
                try {
                    const r = await api('copia', { id: item.con.id });
                    marcarResultado(item.li, 'ok', escapar(r.nombre_archivo) + ' · ' + tamano(r.tamano) + ' · ' +
                        r.tablas + ' tablas · ' + r.duracion + 's' + textoModoCopia(r) + botonAbrir(r));
                } catch (e) {
                    errores++;
                    marcarResultado(item.li, 'error', escapar(e.message));
                }
            }

            bloquear(false);
            aviso(errores ? 'Terminó con ' + errores + ' error(es).' : 'Copias generadas: ' + bases.length + '.');
        });
    }

    // ---------- Inicio ----------

    document.addEventListener('DOMContentLoaded', () => {
        M.Tabs.init($('pestanas'));
        M.Modal.init($('modal-confirmar'));
        eventosArbol();
        eventosEjecucion();
        eventosCopias();
        cargarDiagnostico();
        cargarConexiones();
    });
})();