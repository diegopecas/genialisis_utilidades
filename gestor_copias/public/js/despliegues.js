(function () {
    'use strict';

    const estado = {
        destinos: [],
        seleccion: new Set(),
        contraidas: new Set(),
        ambientes: new Set(),
        filtro: '',
        planes: [],
        trabajando: false,
    };

    const ORDEN_AMBIENTES = ['Prod', 'QA', 'Demo', 'Local'];

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

    function etiquetaAmbiente(d) {
        if (!d.ambiente) return '';
        return '<span class="etiqueta-ambiente amb-' + escapar(d.ambiente.toLowerCase()) + '">' + escapar(d.ambiente.toUpperCase()) + '</span>';
    }

    function etiquetaTipo(d) {
        return '<span class="etiqueta-tipo">' + (d.tipo === 'back' ? 'BACK' : 'FRONT') + '</span>';
    }

    function aviso(texto) {
        M.toast({ html: escapar(texto), displayLength: 4000 });
    }

    // ---------- Menú principal ----------

    function eventosMenu() {
        document.querySelectorAll('.menu-opcion').forEach((boton) => {
            boton.addEventListener('click', () => {
                document.querySelectorAll('.menu-opcion').forEach((b) => b.classList.toggle('activa', b === boton));
                ['seccion-bd', 'seccion-despliegues'].forEach((id) => $(id).classList.toggle('oculto', id !== boton.dataset.seccion));
                if (boton.dataset.seccion === 'seccion-despliegues' && !estado.destinos.length) {
                    cargarDestinos();
                }
            });
        });
    }

    // ---------- Árbol de destinos ----------

    async function cargarDestinos() {
        $('dep-arbol').innerHTML = '<div class="cargando">Cargando destinos...</div>';
        try {
            const datos = await api('despliegues');
            estado.destinos = datos.despliegues;
            const ids = new Set(estado.destinos.map((d) => d.id));
            estado.seleccion.forEach((id) => { if (!ids.has(id)) estado.seleccion.delete(id); });
            if (!estado.contraidas.size) {
                agrupar(estado.destinos).forEach((p) => estado.contraidas.add(claveProyecto(p.proyecto)));
            }
            pintarFiltro();
            pintarArbol();
        } catch (e) {
            $('dep-arbol').innerHTML = '<div class="cargando">' + escapar(e.message) + '</div>';
        }
    }

    function agrupar(destinos) {
        const proyectos = new Map();
        destinos.forEach((d) => {
            if (!proyectos.has(d.proyecto)) proyectos.set(d.proyecto, []);
            proyectos.get(d.proyecto).push(d);
        });
        return Array.from(proyectos.entries()).map(([proyecto, items]) => ({ proyecto, items }));
    }

    function visibles() {
        const f = estado.filtro.toLowerCase();
        return estado.destinos.filter((d) =>
            (!estado.ambientes.size || estado.ambientes.has(d.ambiente)) &&
            (!f || d.nombre.toLowerCase().includes(f) || d.proyecto.toLowerCase().includes(f)));
    }

    function pintarFiltro() {
        const usados = new Set(estado.destinos.map((d) => d.ambiente).filter((a) => a));
        const disponibles = ORDEN_AMBIENTES.filter((a) => usados.has(a));
        $('dep-filtro-ambientes').innerHTML = '<span class="filtro-titulo">Ambiente:</span>' +
            '<span class="chip-ambiente' + (estado.ambientes.size ? '' : ' activo') + '" data-ambiente="">Todos</span>' +
            disponibles.map((a) => '<span class="chip-ambiente' + (estado.ambientes.has(a) ? ' activo' : '') + '" data-ambiente="' + escapar(a) + '">' + escapar(a) + '</span>').join('');
    }

    // Claves para recordar qué grupos están contraídos
    const claveProyecto = (proyecto) => 'p|' + proyecto;
    const claveAmbiente = (proyecto, ambiente) => 'a|' + proyecto + '|' + ambiente;

    // Agrupa los destinos de un proyecto por ambiente (Prod, QA, Demo...) y dentro de cada uno pone primero los fronts
    function porAmbiente(items) {
        const grupos = new Map();
        items.forEach((d) => {
            const ambiente = d.ambiente || 'Sin ambiente';
            if (!grupos.has(ambiente)) grupos.set(ambiente, []);
            grupos.get(ambiente).push(d);
        });
        const orden = (a) => { const i = ORDEN_AMBIENTES.indexOf(a); return i < 0 ? ORDEN_AMBIENTES.length : i; };
        return Array.from(grupos.entries())
            .sort((x, y) => orden(x[0]) - orden(y[0]))
            .map(([ambiente, lista]) => ({
                ambiente,
                items: lista.slice().sort((x, y) => (x.tipo === y.tipo ? 0 : x.tipo === 'front' ? -1 : 1)),
            }));
    }

    // Casilla de un grupo: queda marcada si todos sus destinos habilitados están seleccionados
    function casillaGrupo(clase, items, datos) {
        const habilitados = items.filter((d) => d.origen_existe);
        const marcados = habilitados.filter((d) => estado.seleccion.has(d.id)).length;
        return {
            marcados,
            habilitados: habilitados.length,
            html: '<label><input type="checkbox" class="filled-in ' + clase + '"' + datos +
                (habilitados.length && marcados === habilitados.length ? ' checked' : '') +
                (habilitados.length ? '' : ' disabled') + '><span></span></label>',
        };
    }

    function pintarDestino(d) {
        const deshabilitado = !d.origen_existe;
        return '<div class="conexion' + (deshabilitado ? ' deshabilitada' : '') + '">' +
            '<label><input type="checkbox" class="filled-in dep-chk-destino" data-id="' + escapar(d.id) + '"' +
            (estado.seleccion.has(d.id) ? ' checked' : '') + (deshabilitado ? ' disabled' : '') + '>' +
            '<span>' + escapar(d.nombre) + etiquetaTipo(d) + etiquetaAmbiente(d) +
            '<span class="conexion-detalle">' + (deshabilitado ? 'No existe la carpeta de origen: ' : '') + escapar(d.origen) + '</span>' +
            '</span></label></div>';
    }

    function pintarArbol() {
        const proyectos = agrupar(visibles());
        const arbol = $('dep-arbol');
        if (!proyectos.length) {
            arbol.innerHTML = '<div class="cargando">No hay destinos que coincidan.</div>';
            actualizarContador();
            return;
        }
        const buscando = estado.filtro !== '';

        arbol.innerHTML = proyectos.map((p) => {
            const cas = casillaGrupo('dep-chk-proyecto', p.items, ' data-proyecto="' + escapar(p.proyecto) + '"');
            const contraido = !buscando && estado.contraidas.has(claveProyecto(p.proyecto));
            const ambientes = porAmbiente(p.items);

            // Si el proyecto tiene un solo ambiente, los destinos van directo bajo el proyecto
            const hijos = ambientes.length === 1
                ? ambientes[0].items.map(pintarDestino).join('')
                : ambientes.map((a) => {
                    const clave = claveAmbiente(p.proyecto, a.ambiente);
                    const casAmb = casillaGrupo('dep-chk-ambiente', a.items, ' data-proyecto="' + escapar(p.proyecto) + '" data-ambiente="' + escapar(a.ambiente) + '"');
                    const ambContraido = !buscando && estado.contraidas.has(clave);
                    return '<div class="grupo-tipo' + (ambContraido ? ' contraida' : '') + '" data-clave="' + escapar(clave) + '">' +
                        '<div class="grupo-encabezado tipo-encabezado">' + casAmb.html +
                        '<i class="material-icons">' + (ambContraido ? 'chevron_right' : 'expand_more') + '</i>' +
                        '<span class="grupo-nombre">' + escapar(a.ambiente) + '</span>' +
                        '<span class="grupo-conteo">' + casAmb.marcados + '/' + casAmb.habilitados + '</span>' +
                        '</div><div class="grupo-hijos">' + a.items.map(pintarDestino).join('') + '</div></div>';
                }).join('');

            return '<div class="carpeta' + (contraido ? ' contraida' : '') + '" data-clave="' + escapar(claveProyecto(p.proyecto)) + '">' +
                '<div class="grupo-encabezado carpeta-encabezado">' + cas.html +
                '<i class="material-icons">' + (contraido ? 'folder' : 'folder_open') + '</i>' +
                '<span class="carpeta-nombre">' + escapar(p.proyecto) + '</span>' +
                '<span class="carpeta-conteo">' + cas.marcados + '/' + cas.habilitados + '</span>' +
                '</div><div class="carpeta-hijos">' + hijos + '</div></div>';
        }).join('');

        actualizarContador();
    }

    function actualizarContador() {
        const n = estado.seleccion.size;
        $('dep-contador').textContent = n + (n === 1 ? ' seleccionado' : ' seleccionados');
        $('dep-btn-revisar').disabled = estado.trabajando || n === 0;
    }

    function eventosArbol() {
        const arbol = $('dep-arbol');

        arbol.addEventListener('change', (e) => {
            const objetivo = e.target;
            let afectados = null;
            if (objetivo.classList.contains('dep-chk-destino')) {
                afectados = visibles().filter((d) => d.id === objetivo.dataset.id);
            } else if (objetivo.classList.contains('dep-chk-proyecto')) {
                afectados = visibles().filter((d) => d.proyecto === objetivo.dataset.proyecto && d.origen_existe);
            } else if (objetivo.classList.contains('dep-chk-ambiente')) {
                afectados = visibles().filter((d) => d.proyecto === objetivo.dataset.proyecto &&
                    (d.ambiente || 'Sin ambiente') === objetivo.dataset.ambiente && d.origen_existe);
            }
            if (!afectados) return;
            afectados.forEach((d) => {
                if (objetivo.checked) estado.seleccion.add(d.id);
                else estado.seleccion.delete(d.id);
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

        $('dep-filtro-ambientes').addEventListener('click', (e) => {
            const chip = e.target.closest('.chip-ambiente');
            if (!chip) return;
            const ambiente = chip.dataset.ambiente;
            if (!ambiente) estado.ambientes.clear();
            else if (estado.ambientes.has(ambiente)) estado.ambientes.delete(ambiente);
            else estado.ambientes.add(ambiente);
            // Se quitan de la selección los destinos que dejan de verse
            const ids = new Set(visibles().map((d) => d.id));
            estado.seleccion.forEach((id) => { if (!ids.has(id)) estado.seleccion.delete(id); });
            pintarFiltro();
            pintarArbol();
        });

        $('dep-buscador').addEventListener('input', (e) => {
            estado.filtro = e.target.value.trim();
            pintarArbol();
        });

        $('dep-expandir').addEventListener('click', () => { estado.contraidas.clear(); pintarArbol(); });
        $('dep-contraer').addEventListener('click', () => {
            agrupar(estado.destinos).forEach((p) => estado.contraidas.add(claveProyecto(p.proyecto)));
            pintarArbol();
        });
        $('dep-limpiar').addEventListener('click', () => { estado.seleccion.clear(); pintarArbol(); });
        $('dep-recargar').addEventListener('click', () => cargarDestinos());
    }

    // ---------- Preparación ----------

    function seleccionados() {
        return estado.destinos.filter((d) => estado.seleccion.has(d.id));
    }

    function bloquear(trabajando) {
        estado.trabajando = trabajando;
        actualizarContador();
        $('dep-btn-desplegar').disabled = trabajando;
    }

    function tamano(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    async function preparar() {
        const destinos = seleccionados();
        const contenedor = $('dep-revision');
        contenedor.innerHTML = '';
        $('dep-btn-desplegar').classList.add('oculto');
        estado.planes = [];
        bloquear(true);

        for (const d of destinos) {
            const tarjeta = document.createElement('div');
            tarjeta.className = 'revision-destino';
            tarjeta.dataset.id = d.id;
            tarjeta.innerHTML = encabezadoDestino(d, '<i class="material-icons girando">autorenew</i> Preparando...');
            contenedor.appendChild(tarjeta);
            try {
                const plan = await api('despliegue/contenido', { id: d.id });
                plan.destino = d;
                estado.planes.push(plan);
                pintarPlan(tarjeta, plan);
            } catch (e) {
                tarjeta.classList.add('error');
                tarjeta.innerHTML = encabezadoDestino(d, '<i class="material-icons">error</i> ' + escapar(e.message));
            }
        }

        bloquear(false);
        $('dep-btn-desplegar').classList.toggle('oculto', !estado.planes.length);
    }

    function encabezadoDestino(d, estadoHtml) {
        return '<div class="revision-encabezado"><b>' + escapar(d.nombre) + '</b>' + etiquetaTipo(d) + etiquetaAmbiente(d) +
            '<span class="revision-estado">' + estadoHtml + '</span></div>';
    }

    function pintarPlan(tarjeta, plan) {
        const d = plan.destino;
        if (plan.tipo === 'front') {
            tarjeta.innerHTML = encabezadoDestino(d, plan.archivos + ' archivos · ' + tamano(plan.tamano) + (plan.fecha ? ' · compilado ' + escapar(plan.fecha) : '')) +
                '<p class="texto-suave nota-front"><i class="material-icons">sync</i>Se reemplaza todo el contenido del sitio. Se conservan .htaccess y .well-known.</p>';
            return;
        }
        const filas = plan.elementos.map((e) =>
            '<label class="elemento-back"><input type="checkbox" class="filled-in dep-chk-elemento" data-nombre="' + escapar(e.nombre) + '">' +
            '<span><i class="material-icons">' + (e.carpeta ? 'folder' : 'description') + '</i>' + escapar(e.nombre) +
            (e.configuracion ? '<span class="etiqueta-config">CONFIG</span>' : '') +
            '<span class="archivo-motivo">' + escapar(e.fecha) + ' · ' + e.archivos + (e.archivos === 1 ? ' archivo' : ' archivos') + '</span></span></label>').join('');
        tarjeta.innerHTML = encabezadoDestino(d, 'Marca lo que quieres subir') +
            '<div class="lista-elementos">' + (filas || '<span class="texto-suave">No hay nada para subir.</span>') + '</div>';
    }

    function eventosRevision() {
        $('dep-btn-revisar').addEventListener('click', preparar);
    }

    // ---------- Despliegue ----------

    // Rutas de primer nivel marcadas en un back; el front siempre sube todo
    function marcadosDe(plan) {
        if (plan.tipo === 'front') return { rutas: [], todo: true };
        const tarjeta = document.querySelector('.revision-destino[data-id="' + CSS.escape(plan.id) + '"]');
        const rutas = tarjeta ? Array.from(tarjeta.querySelectorAll('.dep-chk-elemento:checked')).map((c) => c.dataset.nombre) : [];
        return { rutas, todo: false };
    }

    function confirmar() {
        const pendientes = estado.planes
            .map((plan) => ({ plan, ...marcadosDe(plan) }))
            .filter((p) => p.todo || p.rutas.length);
        if (!pendientes.length) {
            aviso('No hay nada marcado para desplegar.');
            return;
        }
        const prod = pendientes.filter((p) => p.plan.destino.ambiente === 'Prod').length;
        const respaldo = $('dep-opt-respaldo').checked;
        $('dep-confirmar-resumen').innerHTML = 'Se desplegarán <b>' + pendientes.length + '</b> destino(s)' +
            (prod ? ', de los cuales <b>' + prod + (prod === 1 ? ' es de producción' : ' son de producción') + '</b>' : '') + '.' +
            (respaldo ? ' Antes de reemplazar, se guarda en este equipo una copia de lo que hay en el servidor.' : '');

        // Si hay producción y no se pidió respaldo, se pregunta antes de desplegar
        const preguntar = prod > 0 && !respaldo;
        $('dep-confirmar-pregunta').classList.toggle('oculto', !preguntar);
        $('dep-btn-sin-respaldo').classList.toggle('oculto', !preguntar);
        $('dep-btn-confirmar').textContent = preguntar ? 'Sí, respaldar y desplegar' : 'Desplegar';

        const ambientes = Array.from(new Set(pendientes.map((p) => p.plan.destino.ambiente)));
        const conConfig = pendientes.filter((p) => p.rutas.some((r) => p.plan.elementos.some((e) => e.nombre === r && e.configuracion)));
        const avisos = [];
        if (ambientes.length > 1) avisos.push('Vas a desplegar en ambientes distintos (' + ambientes.map(escapar).join(', ') + ').');
        if (conConfig.length) avisos.push('Vas a subir configuración en: ' + conConfig.map((p) => escapar(p.plan.destino.nombre)).join(', ') + '.');
        $('dep-confirmar-mezcla').innerHTML = avisos.length ? '<i class="material-icons">warning</i><span>' + avisos.join('<br>') + '</span>' : '';
        $('dep-confirmar-mezcla').classList.toggle('oculto', !avisos.length);

        $('dep-confirmar-lista').innerHTML = pendientes.map((p) =>
            '<li><b>' + escapar(p.plan.destino.nombre) + '</b>' + etiquetaTipo(p.plan.destino) + etiquetaAmbiente(p.plan.destino) +
            ' · ' + (p.todo ? 'se reemplaza todo' : p.rutas.map(escapar).join(', ')) + '</li>').join('');

        estado.pendientes = pendientes;
        M.Modal.getInstance($('modal-desplegar')).open();
    }

    async function desplegar(respaldar) {
        M.Modal.getInstance($('modal-desplegar')).close();
        bloquear(true);
        let errores = 0;

        for (const p of estado.pendientes) {
            const tarjeta = document.querySelector('.revision-destino[data-id="' + CSS.escape(p.plan.id) + '"]');
            const estadoEl = tarjeta.querySelector('.revision-estado');
            estadoEl.innerHTML = '<i class="material-icons girando">autorenew</i> Desplegando...';
            try {
                const r = await api('despliegue/ejecutar', { id: p.plan.id, rutas: p.rutas, respaldar });
                if (r.ok) {
                    tarjeta.classList.add('ok');
                    estadoEl.innerHTML = '<i class="material-icons">check_circle</i> ' + r.archivos + ' archivos subidos' +
                        (r.borrados ? ' · ' + r.borrados + ' borrados arriba' : '') +
                        (r.respaldo ? ' · respaldo guardado' : '') + ' · ' + r.duracion + 's';
                } else {
                    errores++;
                    tarjeta.classList.add('error');
                    estadoEl.innerHTML = '<i class="material-icons">error</i> No se desplegó';
                    const detalle = document.createElement('pre');
                    detalle.textContent = r.error;
                    tarjeta.appendChild(detalle);
                }
            } catch (e) {
                errores++;
                tarjeta.classList.add('error');
                estadoEl.innerHTML = '<i class="material-icons">error</i> ' + escapar(e.message);
            }
            tarjeta.querySelectorAll('input').forEach((i) => { i.disabled = true; });
        }

        bloquear(false);
        $('dep-btn-desplegar').classList.add('oculto');
        aviso(errores ? 'Terminó con ' + errores + ' destino(s) con error. Revisa el log en despliegues/logs.' : 'Despliegue terminado.');
    }

    window.addEventListener('beforeunload', (e) => {
        if (estado.trabajando) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // ---------- Inicio ----------

    document.addEventListener('DOMContentLoaded', () => {
        M.Modal.init($('modal-desplegar'));
        eventosMenu();
        eventosArbol();
        eventosRevision();
        $('dep-btn-desplegar').addEventListener('click', confirmar);
        // "Desplegar" o "Sí, respaldar y desplegar": respalda si el switch está encendido o si se está preguntando por producción
        $('dep-btn-confirmar').addEventListener('click', () => {
            desplegar($('dep-opt-respaldo').checked || !$('dep-btn-sin-respaldo').classList.contains('oculto'));
        });
        $('dep-btn-sin-respaldo').addEventListener('click', () => desplegar(false));
    });
})();
