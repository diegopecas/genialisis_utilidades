(function () {
    'use strict';

    const ORDEN_AMBIENTES = ['Prod', 'QA', 'Demo'];

    const estado = {
        sitios: [],
        seleccion: new Set(),
        contraidas: new Set(),
        ambientes: new Set(),
        filtro: '',
        trabajando: false,
        cargado: false,
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

    function etiquetaAmbiente(s) {
        return '<span class="etiqueta-ambiente amb-' + escapar(s.ambiente.toLowerCase()) + '">' + escapar(s.ambiente.toUpperCase()) + '</span>';
    }

    function tamano(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function aviso(texto) {
        M.toast({ html: escapar(texto), displayLength: 4000 });
    }

    // ---------- Carga ----------

    async function cargarSitios() {
        $('log-arbol').innerHTML = '<div class="cargando">Cargando APIs...</div>';
        try {
            const datos = await api('logs');
            estado.sitios = datos.sitios;
            estado.cargado = true;
            $('log-ruta').textContent = datos.carpeta;
            const ids = new Set(estado.sitios.map((s) => s.id));
            estado.seleccion.forEach((id) => { if (!ids.has(id)) estado.seleccion.delete(id); });
            pintarFiltroAmbientes();
            pintarArbol();
        } catch (e) {
            $('log-arbol').innerHTML = '<div class="cargando">' + escapar(e.message) + '</div>';
        }
    }

    // ---------- Filtro por ambiente ----------

    function pintarFiltroAmbientes() {
        const usados = new Set(estado.sitios.map((s) => s.ambiente));
        const disponibles = ORDEN_AMBIENTES.filter((a) => usados.has(a));
        usados.forEach((a) => { if (!disponibles.includes(a)) disponibles.push(a); });
        $('log-filtro-ambientes').innerHTML = '<span class="filtro-titulo">Ambiente:</span>' +
            '<span class="chip-ambiente' + (estado.ambientes.size ? '' : ' activo') + '" data-ambiente="">Todos</span>' +
            disponibles.map((a) => '<span class="chip-ambiente' + (estado.ambientes.has(a) ? ' activo' : '') + '" data-ambiente="' + escapar(a) + '">' + escapar(a) + '</span>').join('');
    }

    // ---------- Árbol ----------

    function visibles() {
        const f = estado.filtro.toLowerCase();
        return estado.sitios.filter((s) =>
            (!estado.ambientes.size || estado.ambientes.has(s.ambiente)) &&
            (!f || s.sitio.toLowerCase().includes(f) || s.producto.toLowerCase().includes(f)));
    }

    function agrupar(sitios) {
        const productos = new Map();
        sitios.forEach((s) => {
            if (!productos.has(s.producto)) productos.set(s.producto, []);
            productos.get(s.producto).push(s);
        });
        return Array.from(productos.entries()).map(([producto, items]) => ({ producto, items }));
    }

    function pintarSitio(s) {
        return '<div class="conexion">' +
            '<label><input type="checkbox" class="filled-in log-chk-sitio" data-id="' + escapar(s.id) + '"' +
            (estado.seleccion.has(s.id) ? ' checked' : '') + '>' +
            '<span>' + escapar(s.sitio) + etiquetaAmbiente(s) + '</span></label></div>';
    }

    function pintarArbol() {
        const productos = agrupar(visibles());
        const arbol = $('log-arbol');
        if (!productos.length) {
            arbol.innerHTML = '<div class="cargando">No hay APIs que coincidan.</div>';
            actualizarContador();
            return;
        }
        const buscando = estado.filtro !== '';

        arbol.innerHTML = productos.map((g) => {
            const marcados = g.items.filter((s) => estado.seleccion.has(s.id)).length;
            const contraido = !buscando && estado.contraidas.has(g.producto);
            // En el orden Prod, QA, Demo
            const items = g.items.slice().sort((x, y) => ORDEN_AMBIENTES.indexOf(x.ambiente) - ORDEN_AMBIENTES.indexOf(y.ambiente));
            return '<div class="carpeta' + (contraido ? ' contraida' : '') + '" data-clave="' + escapar(g.producto) + '">' +
                '<div class="grupo-encabezado carpeta-encabezado">' +
                '<label><input type="checkbox" class="filled-in log-chk-producto" data-producto="' + escapar(g.producto) + '"' +
                (marcados === g.items.length ? ' checked' : '') + '><span></span></label>' +
                '<i class="material-icons">' + (contraido ? 'folder' : 'folder_open') + '</i>' +
                '<span class="carpeta-nombre">' + escapar(g.producto) + '</span>' +
                '<span class="carpeta-conteo">' + marcados + '/' + g.items.length + '</span>' +
                '</div><div class="carpeta-hijos">' + items.map(pintarSitio).join('') + '</div></div>';
        }).join('');

        actualizarContador();
    }

    function actualizarContador() {
        const n = estado.seleccion.size;
        $('log-contador').textContent = n + (n === 1 ? ' seleccionado' : ' seleccionados');
        $('log-btn-descargar').disabled = estado.trabajando || n === 0;
    }

    function eventosArbol() {
        const arbol = $('log-arbol');

        arbol.addEventListener('change', (e) => {
            const objetivo = e.target;
            let afectados = null;
            if (objetivo.classList.contains('log-chk-sitio')) {
                afectados = visibles().filter((s) => s.id === objetivo.dataset.id);
            } else if (objetivo.classList.contains('log-chk-producto')) {
                afectados = visibles().filter((s) => s.producto === objetivo.dataset.producto);
            }
            if (!afectados) return;
            afectados.forEach((s) => {
                if (objetivo.checked) estado.seleccion.add(s.id);
                else estado.seleccion.delete(s.id);
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

        $('log-filtro-ambientes').addEventListener('click', (e) => {
            const chip = e.target.closest('.chip-ambiente');
            if (!chip) return;
            const ambiente = chip.dataset.ambiente;
            if (!ambiente) estado.ambientes.clear();
            else if (estado.ambientes.has(ambiente)) estado.ambientes.delete(ambiente);
            else estado.ambientes.add(ambiente);
            // Lo que queda oculto por el filtro deja de estar seleccionado
            const ids = new Set(visibles().map((s) => s.id));
            estado.seleccion.forEach((id) => { if (!ids.has(id)) estado.seleccion.delete(id); });
            pintarFiltroAmbientes();
            pintarArbol();
        });

        $('log-buscador').addEventListener('input', (e) => {
            estado.filtro = e.target.value.trim();
            pintarArbol();
        });

        $('log-expandir').addEventListener('click', () => { estado.contraidas.clear(); pintarArbol(); });
        $('log-contraer').addEventListener('click', () => {
            agrupar(estado.sitios).forEach((g) => estado.contraidas.add(g.producto));
            pintarArbol();
        });
        $('log-limpiar').addEventListener('click', () => { estado.seleccion.clear(); pintarArbol(); });
        $('log-recargar').addEventListener('click', () => cargarSitios());
    }

    // ---------- Descarga ----------

    async function descargar() {
        const elegidos = estado.sitios.filter((s) => estado.seleccion.has(s.id));
        if (!elegidos.length) return;

        const li = document.createElement('li');
        li.className = 'resultado';
        li.innerHTML = '<i class="material-icons girando">autorenew</i><div class="resultado-cuerpo">' +
            '<div class="resultado-titulo">' + elegidos.length + (elegidos.length === 1 ? ' API' : ' APIs') + '</div>' +
            '<div class="resultado-detalle">Descargando logs de ' + elegidos.map((s) => escapar(s.sitio)).join(', ') + '...</div></div>';
        $('log-resultados').prepend(li);
        estado.trabajando = true;
        actualizarContador();

        try {
            const r = await api('logs/descarga', { ids: elegidos.map((s) => s.id) });
            li.classList.add('ok');
            li.innerHTML = '<i class="material-icons">check_circle</i><div class="resultado-cuerpo">' +
                '<div class="resultado-titulo">' + escapar(r.nombre_archivo) + '</div>' +
                '<div class="resultado-detalle">' + tamano(r.tamano) + ' · ' + r.archivos + ' logs · ' + r.duracion + 's' +
                (r.recortados ? '<br><span class="aviso-copia">' + r.recortados + ' log(s) muy pesados: llegó solo la parte final, lo más reciente.</span>' : '') +
                (r.sin_logs.length ? '<br>Sin logs: ' + r.sin_logs.map(escapar).join(', ') : '') + '</div>' +
                '<button class="btn-flat btn-pequeno log-btn-abrir" data-archivo="' + escapar(r.nombre_archivo) + '">' +
                '<i class="material-icons left">folder_open</i>Abrir carpeta</button></div>';
        } catch (e) {
            li.classList.add('error');
            li.innerHTML = '<i class="material-icons">error</i><div class="resultado-cuerpo">' +
                '<div class="resultado-titulo">No se descargaron los logs</div>' +
                '<div class="resultado-detalle">' + escapar(e.message) + '</div></div>';
        }
        estado.trabajando = false;
        actualizarContador();
    }

    function eventosDescarga() {
        $('log-btn-descargar').addEventListener('click', descargar);
        $('log-resultados').addEventListener('click', async (e) => {
            const boton = e.target.closest('.log-btn-abrir');
            if (!boton) return;
            try {
                await api('logs/abrir', { archivo: boton.dataset.archivo });
            } catch (err) {
                aviso(err.message);
            }
        });
    }

    window.addEventListener('beforeunload', (e) => {
        if (estado.trabajando) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // ---------- Inicio ----------

    document.addEventListener('DOMContentLoaded', () => {
        eventosArbol();
        eventosDescarga();
        // Los logs se cargan la primera vez que se abre la pestaña
        document.querySelector('.menu-opcion[data-seccion="seccion-logs"]').addEventListener('click', () => {
            if (!estado.cargado) cargarSitios();
        });
    });
})();
