(function () {
    'use strict';

    const estado = {
        proyectos: [],
        seleccion: new Set(),
        contraidas: new Set(),
        filtro: '',
        trabajando: false,
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

    function etiquetaTipo(p) {
        return '<span class="etiqueta-tipo">' + (p.tipo === 'back' ? 'BACK' : 'FRONT') + '</span>';
    }

    function tamano(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function aviso(texto) {
        M.toast({ html: escapar(texto), displayLength: 4000 });
    }

    // ---------- Menú principal ----------

    function eventosMenu() {
        document.querySelectorAll('.menu-opcion').forEach((boton) => {
            boton.addEventListener('click', () => {
                document.querySelectorAll('.menu-opcion').forEach((b) => b.classList.toggle('activa', b === boton));
                document.querySelectorAll('.menu-opcion').forEach((b) => $(b.dataset.seccion).classList.toggle('oculto', b !== boton));
                if (boton.dataset.seccion === 'seccion-proyectos' && !estado.proyectos.length) {
                    cargarProyectos();
                }
            });
        });
    }

    // ---------- Árbol de proyectos ----------

    async function cargarProyectos() {
        $('pro-arbol').innerHTML = '<div class="cargando">Cargando proyectos...</div>';
        try {
            const datos = await api('proyectos');
            estado.proyectos = datos.proyectos;
            $('pro-ruta-copias').textContent = datos.carpeta;
            const ids = new Set(estado.proyectos.filter((p) => p.existe).map((p) => p.id));
            estado.seleccion.forEach((id) => { if (!ids.has(id)) estado.seleccion.delete(id); });
            pintarArbol();
        } catch (e) {
            $('pro-arbol').innerHTML = '<div class="cargando">' + escapar(e.message) + '</div>';
        }
    }

    function agrupar(proyectos) {
        const productos = new Map();
        proyectos.forEach((p) => {
            if (!productos.has(p.producto)) productos.set(p.producto, []);
            productos.get(p.producto).push(p);
        });
        return Array.from(productos.entries()).map(([producto, items]) => ({ producto, items }));
    }

    function visibles() {
        const f = estado.filtro.toLowerCase();
        return estado.proyectos.filter((p) =>
            !f || p.nombre.toLowerCase().includes(f) || p.producto.toLowerCase().includes(f));
    }

    // Casilla del producto: queda marcada si todos sus proyectos que existen están seleccionados
    function casillaProducto(producto, items) {
        const habilitados = items.filter((p) => p.existe);
        const marcados = habilitados.filter((p) => estado.seleccion.has(p.id)).length;
        return {
            marcados,
            habilitados: habilitados.length,
            html: '<label><input type="checkbox" class="filled-in pro-chk-producto" data-producto="' + escapar(producto) + '"' +
                (habilitados.length && marcados === habilitados.length ? ' checked' : '') +
                (habilitados.length ? '' : ' disabled') + '><span></span></label>',
        };
    }

    function pintarProyecto(p) {
        const deshabilitado = !p.existe;
        return '<div class="conexion' + (deshabilitado ? ' deshabilitada' : '') + '">' +
            '<label><input type="checkbox" class="filled-in pro-chk-proyecto" data-id="' + escapar(p.id) + '"' +
            (estado.seleccion.has(p.id) ? ' checked' : '') + (deshabilitado ? ' disabled' : '') + '>' +
            '<span>' + escapar(p.nombre) + etiquetaTipo(p) +
            '<span class="conexion-detalle">' + (deshabilitado ? 'No existe la carpeta: ' : '') + escapar(p.ruta) + '</span>' +
            '</span></label></div>';
    }

    function pintarArbol() {
        const productos = agrupar(visibles());
        const arbol = $('pro-arbol');
        if (!productos.length) {
            arbol.innerHTML = '<div class="cargando">No hay proyectos que coincidan.</div>';
            actualizarContador();
            return;
        }
        const buscando = estado.filtro !== '';

        arbol.innerHTML = productos.map((g) => {
            const cas = casillaProducto(g.producto, g.items);
            const contraido = !buscando && estado.contraidas.has(g.producto);
            // Primero los fronts y luego los backs
            const items = g.items.slice().sort((x, y) => (x.tipo === y.tipo ? 0 : x.tipo === 'front' ? -1 : 1));
            return '<div class="carpeta' + (contraido ? ' contraida' : '') + '" data-clave="' + escapar(g.producto) + '">' +
                '<div class="grupo-encabezado carpeta-encabezado">' + cas.html +
                '<i class="material-icons">' + (contraido ? 'folder' : 'folder_open') + '</i>' +
                '<span class="carpeta-nombre">' + escapar(g.producto) + '</span>' +
                '<span class="carpeta-conteo">' + cas.marcados + '/' + cas.habilitados + '</span>' +
                '</div><div class="carpeta-hijos">' + items.map(pintarProyecto).join('') + '</div></div>';
        }).join('');

        actualizarContador();
    }

    function actualizarContador() {
        const n = estado.seleccion.size;
        $('pro-contador').textContent = n + (n === 1 ? ' seleccionado' : ' seleccionados');
        $('pro-btn-copia').disabled = estado.trabajando || n === 0;
    }

    function eventosArbol() {
        const arbol = $('pro-arbol');

        arbol.addEventListener('change', (e) => {
            const objetivo = e.target;
            let afectados = null;
            if (objetivo.classList.contains('pro-chk-proyecto')) {
                afectados = visibles().filter((p) => p.id === objetivo.dataset.id);
            } else if (objetivo.classList.contains('pro-chk-producto')) {
                afectados = visibles().filter((p) => p.producto === objetivo.dataset.producto && p.existe);
            }
            if (!afectados) return;
            afectados.forEach((p) => {
                if (objetivo.checked) estado.seleccion.add(p.id);
                else estado.seleccion.delete(p.id);
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

        $('pro-buscador').addEventListener('input', (e) => {
            estado.filtro = e.target.value.trim();
            pintarArbol();
        });

        $('pro-expandir').addEventListener('click', () => { estado.contraidas.clear(); pintarArbol(); });
        $('pro-contraer').addEventListener('click', () => {
            agrupar(estado.proyectos).forEach((g) => estado.contraidas.add(g.producto));
            pintarArbol();
        });
        $('pro-limpiar').addEventListener('click', () => { estado.seleccion.clear(); pintarArbol(); });
        $('pro-recargar').addEventListener('click', () => cargarProyectos());
    }

    // ---------- Copia ----------

    function bloquear(trabajando) {
        estado.trabajando = trabajando;
        actualizarContador();
        $('pro-opt-config').disabled = trabajando;
    }

    async function sacarCopia() {
        const elegidos = estado.proyectos.filter((p) => estado.seleccion.has(p.id));
        if (!elegidos.length) return;
        const incluirConfig = $('pro-opt-config').checked;

        const li = document.createElement('li');
        li.className = 'resultado';
        li.innerHTML = '<i class="material-icons girando">autorenew</i><div class="resultado-cuerpo">' +
            '<div class="resultado-titulo">' + elegidos.length + (elegidos.length === 1 ? ' proyecto' : ' proyectos') +
            (incluirConfig ? ' · con configuración' : '') + '</div>' +
            '<div class="resultado-detalle">Comprimiendo ' + elegidos.map((p) => escapar(p.nombre)).join(', ') + '...</div></div>';
        $('pro-resultados').prepend(li);
        bloquear(true);

        try {
            const r = await api('proyectos/copia', { ids: elegidos.map((p) => p.id), incluir_config: incluirConfig });
            li.classList.add('ok');
            li.innerHTML = '<i class="material-icons">check_circle</i><div class="resultado-cuerpo">' +
                '<div class="resultado-titulo">' + escapar(r.nombre_archivo) + '</div>' +
                '<div class="resultado-detalle">' + tamano(r.tamano) + ' · ' + r.archivos + ' archivos · ' + r.duracion + 's<br>' +
                r.proyectos.map((p) => escapar(p.nombre) + ' (' + p.archivos + ')').join(', ') + '</div>' +
                '<button class="btn-flat btn-pequeno pro-btn-abrir" data-archivo="' + escapar(r.nombre_archivo) + '">' +
                '<i class="material-icons left">folder_open</i>Abrir carpeta</button></div>';
        } catch (e) {
            li.classList.add('error');
            li.innerHTML = '<i class="material-icons">error</i><div class="resultado-cuerpo">' +
                '<div class="resultado-titulo">No se sacó la copia</div>' +
                '<div class="resultado-detalle">' + escapar(e.message) + '</div></div>';
        }
        bloquear(false);
    }

    function eventosCopia() {
        $('pro-btn-copia').addEventListener('click', sacarCopia);
        $('pro-resultados').addEventListener('click', async (e) => {
            const boton = e.target.closest('.pro-btn-abrir');
            if (!boton) return;
            try {
                await api('proyectos/abrir', { archivo: boton.dataset.archivo });
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
        eventosMenu();
        eventosArbol();
        eventosCopia();
    });
})();
