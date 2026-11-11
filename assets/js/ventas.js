/* SPDX-License-Identifier: GPL-3.0-only */
/* =====================================================================
   TiendaWeb - Punto de venta
   - Búsqueda por nombre o código (compatible con lectores USB tipo teclado)
   - Carrito con cantidades validadas contra la existencia
   - Modal de cobro con cálculo de cambio
   Las cantidades se manejan en milésimas y el dinero en centavos (enteros)
   para evitar errores de redondeo. El servidor recalcula todo al cobrar.
   ===================================================================== */
(function () {
    'use strict';

    const CLAVE_ALMACEN = 'tiendaweb_carrito_v1';
    const UNIDAD = 1000;                       // 1 unidad = 1000 milésimas

    const $ = (sel) => document.querySelector(sel);
    const el = {
        buscar: $('#buscar'),
        btnBuscar: $('#btnBuscar'),
        resultados: $('#resultados'),
        rapidos: $('#rapidos'),
        carrito: $('#carrito'),
        vacio: $('#carritoVacio'),
        contador: $('#contador'),
        total: $('#total'),
        btnCobrar: $('#btnCobrar'),
        btnVaciar: $('#btnVaciar'),
        modal: $('#modalCobro'),
        cobroTotal: $('#cobroTotal'),
        cobroArticulos: $('#cobroArticulos'),
        recibido: $('#recibido'),
        billetes: $('#billetes'),
        cajaCambio: $('#cajaCambio'),
        etiquetaCambio: $('#etiquetaCambio'),
        cambio: $('#cambio'),
        seccionEfectivo: $('#seccionEfectivo'),
        seccionOtro: $('#seccionOtro'),
        nombreMetodo: $('#nombreMetodo'),
        btnConfirmar: $('#btnConfirmar')
    };

    const modalCobro = new bootstrap.Modal(el.modal, { focus: false });
    const NOMBRES_METODO = { efectivo: 'efectivo', transferencia: 'transferencia', tarjeta: 'tarjeta', credito: 'crédito' };
    const nombreMetodo = m => { const n = NOMBRES_METODO[m] || m; return n.charAt(0).toUpperCase() + n.slice(1); };
    const POS = window.TIENDAWEB_POS || {};
    const IMPRESORA = POS.impresora || { activa: false };
    const CLIENTES = {};
    (POS.clientes || []).forEach(c => { CLIENTES[c.id] = c; });
    const cobro = {
        descuentoValor: document.getElementById('descuentoValor'),
        descuentoTipo: document.getElementById('descuentoTipo'),
        grupoDescuento: document.getElementById('grupoDescuento'),
        lnkDescuento: document.getElementById('lnkDescuento'),
        cobroDescuento: document.getElementById('cobroDescuento'),
        cliente: document.getElementById('cliente'),
        clienteInfo: document.getElementById('clienteInfo'),
        clienteAyuda: document.getElementById('clienteAyuda')
    };

    let carrito = [];          // [{id, nombre, codigo, precio, existencia, controla, fraccion, cantidad(milésimas)}]
    let tokenOperacion = null; // identifica un intento de cobro; evita ventas duplicadas
    let enviando = false;
    let clienteAMano = false;   // el cajero pidió poner el nombre del cliente en el ticket
    let resultadosActuales = [];

    /* ------------------------------------------------------------------
       Utilidades numéricas
       ------------------------------------------------------------------ */
    const aCentavos = (pesos) => Math.round(Number(pesos) * 100);
    const aMilesimas = (cant) => Math.round(Number(cant) * 1000);
    const importe = (precio, milesimas) => Math.round(aCentavos(precio) * milesimas / 1000);

    /** Convierte texto del usuario a número; acepta coma decimal. Devuelve null si no es válido. */
    function leerNumero(texto, decimales, enteros) {
        let t = String(texto == null ? '' : texto).trim().replace(/\s|\$/g, '');
        if ((t.match(/,/g) || []).length === 1 && t.indexOf('.') === -1) {
            t = t.replace(',', '.');
        } else {
            t = t.replace(/,/g, '');
        }
        const patron = new RegExp('^\\d{1,' + enteros + '}(\\.\\d{0,' + decimales + '})?$');
        return patron.test(t) ? Number(t) : null;
    }

    function nuevoToken() {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }

    function disponibleTexto(p) {
        if (!p.controla) { return 'Servicio'; }
        if (p.existencia <= 0) { return 'Agotado'; }
        return TW.cantidad(p.existencia) + ' disponibles';
    }

    /* ------------------------------------------------------------------
       Persistencia del carrito (sobrevive a recargar la página)
       ------------------------------------------------------------------ */
    function guardar() {
        try { localStorage.setItem(CLAVE_ALMACEN, JSON.stringify(carrito)); } catch (e) { /* sin almacenamiento */ }
    }

    function cargar() {
        try {
            const datos = JSON.parse(localStorage.getItem(CLAVE_ALMACEN) || '[]');
            if (Array.isArray(datos)) {
                carrito = datos.filter(i => i && Number.isInteger(i.id) && Number.isInteger(i.cantidad) && i.cantidad > 0);
            }
        } catch (e) {
            carrito = [];
        }
    }

    /** Actualiza precios y existencias del carrito con los datos reales del servidor. */
    async function refrescarCarrito() {
        if (!carrito.length) { return; }
        try {
            const r = await TW.json('ventas/buscar.php?ids=' + carrito.map(i => i.id).join(','));
            const porId = new Map(r.productos.map(p => [p.id, p]));
            const avisos = [];
            carrito = carrito.filter(function (linea) {
                const p = porId.get(linea.id);
                if (!p || !p.activo) {
                    avisos.push('«' + linea.nombre + '» ya no está disponible y se quitó del carrito.');
                    return false;
                }
                Object.assign(linea, { nombre: p.nombre, codigo: p.codigo, precio: p.precio, precios: p.precios || [], existencia: p.existencia, controla: p.controla, fraccion: p.fraccion });
                if (!linea.fraccion && linea.cantidad % UNIDAD !== 0) {
                    linea.cantidad = Math.max(UNIDAD, Math.round(linea.cantidad / UNIDAD) * UNIDAD);
                }
                if (linea.controla && linea.cantidad > aMilesimas(linea.existencia)) {
                    if (aMilesimas(linea.existencia) <= 0) {
                        avisos.push('«' + linea.nombre + '» se agotó y se quitó del carrito.');
                        return false;
                    }
                    linea.cantidad = aMilesimas(linea.existencia);
                    if (!linea.fraccion) { linea.cantidad = Math.floor(linea.cantidad / UNIDAD) * UNIDAD; }
                    avisos.push('Solo quedan ' + TW.cantidad(linea.existencia) + ' de «' + linea.nombre + '»; se ajustó la cantidad.');
                }
                return linea.cantidad > 0;
            });
            carritoCambio();
            if (avisos.length) {
                Swal.fire({ icon: 'warning', titleText: 'El carrito se actualizó', html: avisos.map(TW.escapar).join('<br>'), confirmButtonColor: '#1d5fd1' });
            }
        } catch (e) {
            TW.toast('warning', 'No se pudo actualizar el carrito: ' + e.message);
        }
    }

    /* ------------------------------------------------------------------
       Operaciones del carrito
       ------------------------------------------------------------------ */
    /** Precio unitario según la cantidad (mayoreo): el escalón más alto alcanzado. Igual que en el servidor. */
    function precioDe(linea) {
        let precio = linea.precio;
        let mejor = 0;
        (linea.precios || []).forEach(function (e) {
            const desde = aMilesimas(e[0]);
            if (linea.cantidad >= desde && desde > mejor) { mejor = desde; precio = e[1]; }
        });
        return precio;
    }

    /** Siguiente escalón de mayoreo que todavía no se alcanza (para avisar al cajero), o null. */
    function siguienteEscalon(linea) {
        return (linea.precios || []).filter(e => aMilesimas(e[0]) > linea.cantidad).sort((a, b) => a[0] - b[0])[0] || null;
    }

    function totalCentavos() {
        return carrito.reduce((s, i) => s + importe(precioDe(i), i.cantidad), 0);
    }

    /** Descuento escrito en el cobro, en centavos (0 si no hay o no es válido). Igual al cálculo del servidor. */
    function descuentoCentavos() {
        if (!cobro.descuentoValor || cobro.grupoDescuento.classList.contains('d-none')) { return 0; }
        const valor = leerNumero(cobro.descuentoValor.value, 2, 8);
        if (valor === null) { return 0; }
        const subtotal = totalCentavos();
        const d = cobro.descuentoTipo.value === 'porcentaje'
            ? Math.round(subtotal * Math.min(aCentavos(valor), 10000) / 10000)
            : aCentavos(valor);
        return Math.min(d, subtotal);
    }

    /** Lo que se cobra: subtotal menos descuento. */
    function totalACobrar() {
        return totalCentavos() - descuentoCentavos();
    }

    function carritoCambio() {
        tokenOperacion = null;   // el contenido cambió: un cobro nuevo necesita otro identificador
        guardar();
        pintarCarrito();
    }

    function agregar(producto, milesimas) {
        milesimas = milesimas || UNIDAD;
        if (!producto || !producto.activo) {
            TW.alerta('error', 'No disponible', 'Este producto está desactivado.');
            return false;
        }
        const linea = carrito.find(i => i.id === producto.id);
        const nueva = (linea ? linea.cantidad : 0) + milesimas;
        if (producto.controla && nueva > aMilesimas(producto.existencia)) {
            TW.alerta('warning', 'Existencia insuficiente',
                producto.existencia > 0
                    ? 'De «' + producto.nombre + '» solo hay ' + TW.cantidad(producto.existencia) + ' disponibles' + (linea ? ' y ya están en el carrito ' + TW.cantidad(linea.cantidad / UNIDAD) + '.' : '.')
                    : '«' + producto.nombre + '» está agotado.');
            return false;
        }
        if (linea) {
            linea.cantidad = nueva;
            Object.assign(linea, { precio: producto.precio, precios: producto.precios || [], existencia: producto.existencia, controla: producto.controla, fraccion: producto.fraccion });
            carrito.splice(carrito.indexOf(linea), 1);
            carrito.unshift(linea);   // el último agregado queda arriba
        } else {
            carrito.unshift({
                id: producto.id, nombre: producto.nombre, codigo: producto.codigo, precio: producto.precio, precios: producto.precios || [],
                existencia: producto.existencia, controla: producto.controla, fraccion: producto.fraccion,
                cantidad: milesimas
            });
        }
        carritoCambio();
        resaltar(producto.id);
        TW.toast('success', 'Agregado: ' + producto.nombre);
        return true;
    }

    function fijarCantidad(id, milesimas) {
        const linea = carrito.find(i => i.id === id);
        if (!linea) { return; }
        if (!Number.isInteger(milesimas) || milesimas <= 0) {
            TW.toast('error', 'La cantidad debe ser mayor que cero.');
        } else if (!linea.fraccion && milesimas % UNIDAD !== 0) {
            TW.alerta('warning', 'Cantidad no válida', '«' + linea.nombre + '» solo se vende en cantidades enteras.');
        } else if (linea.controla && milesimas > aMilesimas(linea.existencia)) {
            TW.alerta('warning', 'Existencia insuficiente', 'De «' + linea.nombre + '» solo hay ' + TW.cantidad(linea.existencia) + ' disponibles.');
        } else {
            linea.cantidad = milesimas;
            carritoCambio();
            return;
        }
        pintarCarrito();   // restaura el valor anterior en pantalla
    }

    function quitar(id) {
        carrito = carrito.filter(i => i.id !== id);
        carritoCambio();
    }

    function resaltar(id) {
        const fila = el.carrito.querySelector('[data-id="' + id + '"]');
        if (fila) {
            fila.classList.add('bg-primary-suave');
            setTimeout(() => fila.classList.remove('bg-primary-suave'), 700);
        }
    }

    function pintarCarrito() {
        el.carrito.querySelectorAll('.carrito-item').forEach(n => n.remove());
        el.vacio.classList.toggle('d-none', carrito.length > 0);

        const html = carrito.map(function (i) {
            const pu = precioDe(i);
            const sub = importe(pu, i.cantidad) / 100;
            const cant = i.cantidad / UNIDAD;
            const siguiente = siguienteEscalon(i);
            const puedeMenos = i.cantidad > UNIDAD;
            const puedeMas = !i.controla || i.cantidad + UNIDAD <= aMilesimas(i.existencia);
            return '<div class="carrito-item" data-id="' + i.id + '">' +
                '<div class="d-flex justify-content-between gap-2">' +
                    '<div class="nombre">' + TW.escapar(i.nombre) + '</div>' +
                    '<button type="button" class="btn btn-sm btn-link text-danger p-0 accion-quitar" title="Quitar" aria-label="Quitar ' + TW.escapar(i.nombre) + '"><i class="bi bi-x-circle fs-5"></i></button>' +
                '</div>' +
                '<div class="d-flex justify-content-between align-items-center mt-2 gap-2">' +
                    '<div class="control-cantidad">' +
                        '<button type="button" class="accion-menos" aria-label="Disminuir"' + (puedeMenos ? '' : ' disabled') + '><i class="bi bi-dash"></i></button>' +
                        '<input type="text" inputmode="decimal" class="campo-cantidad num" value="' + TW.escapar(String(Number(cant.toFixed(3)))) + '" aria-label="Cantidad">' +
                        '<button type="button" class="accion-mas" aria-label="Aumentar"' + (puedeMas ? '' : ' disabled') + '><i class="bi bi-plus"></i></button>' +
                    '</div>' +
                    '<div class="text-end">' +
                        (pu !== i.precio
                            ? '<small class="text-success d-block num" title="Precio por cantidad">' + TW.dinero(pu) + ' c/u <i class="bi bi-tags"></i></small>'
                            : '<small class="text-body-secondary d-block num">' + TW.dinero(i.precio) + ' c/u</small>') +
                        '<span class="fw-bold num fs-5">' + TW.dinero(sub) + '</span>' +
                    '</div>' +
                '</div>' +
                (i.controla ? '<small class="text-body-secondary">Disponibles: ' + TW.cantidad(i.existencia) + '</small>' : '') +
                (siguiente ? '<small class="d-block text-primary">Desde ' + TW.cantidad(siguiente[0]) + ': ' + TW.dinero(siguiente[1]) + ' c/u</small>' : '') +
            '</div>';
        }).join('');
        el.carrito.insertAdjacentHTML('beforeend', html);

        const total = totalCentavos() / 100;
        el.total.textContent = TW.dinero(total);
        const piezas = carrito.reduce((s, i) => s + i.cantidad, 0) / UNIDAD;
        el.contador.textContent = TW.cantidad(piezas);
        el.btnCobrar.disabled = carrito.length === 0;
        el.btnVaciar.disabled = carrito.length === 0;
        document.getElementById('btnEspera').disabled = carrito.length === 0;
    }

    /* ------------------------------------------------------------------
       Ventas en espera: la cuenta de un cliente se aparta para atender a
       otro y después se recupera. Se guardan en esta computadora.
       ------------------------------------------------------------------ */
    const CLAVE_ESPERA = 'tiendaweb_espera_v1';
    const btnEspera = document.getElementById('btnEspera');
    const btnRecuperar = document.getElementById('btnRecuperar');

    function leerEspera() {
        try {
            const d = JSON.parse(localStorage.getItem(CLAVE_ESPERA) || '[]');
            return Array.isArray(d) ? d.filter(v => v && Array.isArray(v.items) && v.items.length) : [];
        } catch (e) { return []; }
    }
    function escribirEspera(lista) {
        try { localStorage.setItem(CLAVE_ESPERA, JSON.stringify(lista)); } catch (e) { /* sin almacenamiento */ }
        btnRecuperar.classList.toggle('d-none', lista.length === 0);
        document.getElementById('numEspera').textContent = lista.length;
    }

    async function ponerEnEspera() {
        if (!carrito.length) { return; }
        const r = await Swal.fire({
            titleText: 'Dejar venta en espera', input: 'text', inputLabel: '¿De quién es? (opcional)',
            inputPlaceholder: 'Ej. Señora del vestido azul', inputAttributes: { maxlength: 40 },
            showCancelButton: true, confirmButtonText: 'Poner en espera', cancelButtonText: 'Cancelar', confirmButtonColor: '#1d5fd1'
        });
        if (!r.isConfirmed) { return; }
        const lista = leerEspera();
        lista.push({
            id: Date.now(), nombre: (r.value || '').trim() || 'Cliente ' + (lista.length + 1),
            hora: new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }),
            total: totalCentavos(), items: carrito
        });
        escribirEspera(lista);
        carrito = [];
        carritoCambio();
        TW.toast('success', 'Venta en espera. Ya puedes atender a otro cliente.');
        el.buscar.focus();
    }

    async function recuperarEspera() {
        const lista = leerEspera();
        if (!lista.length) { return; }
        const html = '<div class="list-group text-start">' + lista.map((v, k) =>
            '<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between" data-k="' + k + '">' +
            '<span><strong>' + TW.escapar(v.nombre) + '</strong><br><small class="text-body-secondary">' + TW.escapar(v.hora) + ' · ' +
            v.items.length + ' producto(s)</small></span><strong class="num">' + TW.dinero(v.total / 100) + '</strong></button>').join('') + '</div>' +
            (carrito.length ? '<div class="small text-body-secondary mt-2">La venta actual se pondrá en espera.</div>' : '');
        let elegido = null;
        await Swal.fire({
            titleText: 'Ventas en espera', html: html, showConfirmButton: false, showCancelButton: true, cancelButtonText: 'Cerrar',
            didOpen: popup => popup.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => { elegido = Number(b.dataset.k); Swal.close(); }))
        });
        if (elegido === null) { return; }
        const venta = lista.splice(elegido, 1)[0];
        if (carrito.length) {
            lista.push({ id: Date.now(), nombre: 'Venta apartada', hora: new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }),
                         total: totalCentavos(), items: carrito });
        }
        escribirEspera(lista);
        carrito = venta.items;
        carritoCambio();
        await refrescarCarrito();   // precios y existencias pudieron cambiar mientras esperaba
        el.buscar.focus();
    }

    /* ------------------------------------------------------------------
       Listas escolares: se cargan completas al carrito; el cajero quita
       lo que el cliente ya tiene. Lo agotado se avisa al final.
       ------------------------------------------------------------------ */
    async function elegirLista() {
        const listas = POS.listas || [];
        if (!listas.length) { return; }
        const html = '<input type="search" class="form-control mb-2" id="filtroListas" placeholder="Buscar escuela o grado">' +
            '<div class="list-group text-start" style="max-height:50vh;overflow:auto">' + listas.map((l, k) =>
            '<button type="button" class="list-group-item list-group-item-action" data-k="' + k + '" data-texto="' +
            TW.escapar((l.nombre + ' ' + (l.escuela || '')).toLowerCase()) + '"><strong>' + TW.escapar(l.nombre) + '</strong>' +
            (l.escuela ? '<br><small class="text-body-secondary">' + TW.escapar(l.escuela) + '</small>' : '') +
            '<small class="float-end text-body-secondary">' + l.items.length + ' artículos</small></button>').join('') + '</div>';
        let elegida = null;
        await Swal.fire({
            titleText: 'Cargar lista escolar', html: html, showConfirmButton: false, showCancelButton: true, cancelButtonText: 'Cerrar',
            didOpen: function (popup) {
                popup.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => { elegida = listas[Number(b.dataset.k)]; Swal.close(); }));
                const filtro = popup.querySelector('#filtroListas');
                filtro.addEventListener('input', function () {
                    const t = filtro.value.trim().toLowerCase();
                    popup.querySelectorAll('[data-k]').forEach(b => b.classList.toggle('d-none', t !== '' && !b.dataset.texto.includes(t)));
                });
                filtro.focus();
            }
        });
        if (elegida) { await cargarLista(elegida); }
    }

    async function cargarLista(lista) {
        let r;
        try {
            r = await TW.json('ventas/buscar.php?ids=' + lista.items.map(i => i[0]).join(','));
        } catch (e) { TW.alerta('error', 'No se pudo cargar la lista', e.message); return; }
        const porId = new Map(r.productos.map(p => [p.id, p]));
        const faltan = [];
        let agregados = 0;
        lista.items.forEach(function ([id, cantidad]) {
            const p = porId.get(id);
            if (!p || !p.activo) { faltan.push('«' + (p ? p.nombre : 'producto #' + id) + '» ya no está disponible'); return; }
            const linea = carrito.find(i => i.id === id);
            const pedida = aMilesimas(cantidad);
            const yaEnCarrito = linea ? linea.cantidad : 0;
            let cabe = pedida;
            if (p.controla) {
                cabe = Math.max(0, Math.min(pedida, aMilesimas(p.existencia) - yaEnCarrito));
                if (!p.fraccion) { cabe = Math.floor(cabe / UNIDAD) * UNIDAD; }
                if (cabe < pedida) { faltan.push('«' + p.nombre + '»: ' + (cabe ? 'solo ' + TW.cantidad(cabe / UNIDAD) + ' de ' : 'agotado, faltan ') + TW.cantidad(cantidad)); }
            }
            if (cabe <= 0) { return; }
            if (linea) {
                linea.cantidad += cabe;
                Object.assign(linea, { precio: p.precio, precios: p.precios || [], existencia: p.existencia, controla: p.controla, fraccion: p.fraccion });
            } else {
                carrito.push({ id: p.id, nombre: p.nombre, codigo: p.codigo, precio: p.precio, precios: p.precios || [],
                    existencia: p.existencia, controla: p.controla, fraccion: p.fraccion, cantidad: cabe });
            }
            agregados++;
        });
        carritoCambio();
        if (faltan.length) {
            TW.alerta('warning', 'Lista cargada con faltantes', faltan.join(' · '));
        } else {
            TW.toast('success', 'Lista «' + lista.nombre + '» cargada: ' + agregados + ' artículos. Quita lo que ya tenga el cliente.');
        }
        el.buscar.focus();
    }

    const btnLista = document.getElementById('btnLista');
    if (btnLista) { btnLista.addEventListener('click', elegirLista); }

    btnEspera.addEventListener('click', ponerEnEspera);
    btnRecuperar.addEventListener('click', recuperarEspera);
    escribirEspera(leerEspera());

    // Eventos dentro del carrito (delegación).
    el.carrito.addEventListener('click', function (ev) {
        const fila = ev.target.closest('.carrito-item');
        if (!fila) { return; }
        const id = Number(fila.dataset.id);
        const linea = carrito.find(i => i.id === id);
        if (!linea) { return; }
        if (ev.target.closest('.accion-quitar')) {
            quitar(id);
        } else if (ev.target.closest('.accion-mas')) {
            fijarCantidad(id, linea.cantidad + UNIDAD);
        } else if (ev.target.closest('.accion-menos')) {
            fijarCantidad(id, Math.max(linea.cantidad - UNIDAD, linea.fraccion ? linea.cantidad : UNIDAD));
        }
    });
    el.carrito.addEventListener('change', function (ev) {
        if (!ev.target.classList.contains('campo-cantidad')) { return; }
        const id = Number(ev.target.closest('.carrito-item').dataset.id);
        const valor = leerNumero(ev.target.value, 3, 9);
        if (valor === null) {
            TW.toast('error', 'Escribe una cantidad válida (máximo 3 decimales).');
            pintarCarrito();
            return;
        }
        fijarCantidad(id, aMilesimas(valor));
    });
    el.carrito.addEventListener('keydown', function (ev) {
        if (ev.target.classList.contains('campo-cantidad') && ev.key === 'Enter') {
            ev.preventDefault();
            ev.target.blur();
            el.buscar.focus();
        }
    });

    el.btnVaciar.addEventListener('click', function () {
        Swal.fire({
            icon: 'warning', titleText: '¿Vaciar el carrito?', text: 'Se quitarán todos los productos de esta venta.',
            showCancelButton: true, confirmButtonText: 'Sí, vaciar', cancelButtonText: 'No', confirmButtonColor: '#dc3545', reverseButtons: true
        }).then(function (r) {
            if (r.isConfirmed) {
                carrito = [];
                carritoCambio();
                el.buscar.focus();
            }
        });
    });

    /* ------------------------------------------------------------------
       Búsqueda y lector de códigos de barras
       ------------------------------------------------------------------ */
    let temporizador = null;
    let consultaActual = 0;
    const cola = [];          // entradas pendientes (un lector puede escanear varias seguidas)
    let procesando = false;

    function ocultarResultados() {
        el.resultados.classList.add('d-none');
        el.resultados.innerHTML = '';
        resultadosActuales = [];
    }

    function pintarResultados(lista, texto) {
        resultadosActuales = lista;
        el.resultados.classList.remove('d-none');
        if (!lista.length) {
            const pareceCodigo = /^[A-Za-z0-9\-_.]+$/.test(texto);
            el.resultados.innerHTML = '<div class="estado-vacio py-4"><i class="bi bi-search"></i>Sin resultados para «' + TW.escapar(texto) + '».' +
                '<div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="' + TW.url('productos/crear.php' + (pareceCodigo ? '?codigo=' + encodeURIComponent(texto) : '')) + '">' +
                '<i class="bi bi-plus-lg me-1"></i>Registrar producto</a></div></div>';
            return;
        }
        el.resultados.innerHTML = lista.map(function (p, idx) {
            const agotado = p.controla && p.existencia <= 0;
            return '<button type="button" class="resultado-item" role="option" data-indice="' + idx + '"' + (agotado ? ' disabled' : '') + '>' +
                '<span class="flex-grow-1">' +
                    '<span class="fw-semibold d-block">' + TW.escapar(p.nombre) + '</span>' +
                    '<small class="text-body-secondary">' + (p.codigo ? '<i class="bi bi-upc me-1"></i>' + TW.escapar(p.codigo) + ' · ' : '') +
                    '<span class="' + (agotado ? 'text-danger fw-semibold' : '') + '">' + TW.escapar(disponibleTexto(p)) + '</span></small>' +
                '</span>' +
                '<span class="precio num">' + TW.dinero(p.precio) + '</span>' +
                '<i class="bi bi-plus-circle-fill text-primary fs-4"></i>' +
            '</button>';
        }).join('');
    }

    async function buscarTexto(texto) {
        const mio = ++consultaActual;
        try {
            const r = await TW.json('ventas/buscar.php?q=' + encodeURIComponent(texto));
            if (mio === consultaActual && el.buscar.value.trim() === texto) {
                pintarResultados(r.productos, texto);
            }
        } catch (e) {
            if (mio === consultaActual) { TW.toast('error', e.message); }
        }
    }

    function noRegistrado(texto) {
        const pareceCodigo = /^[A-Za-z0-9\-_.]+$/.test(texto);
        return Swal.fire({
            icon: 'warning',
            titleText: 'Producto no registrado',
            text: 'No hay ningún producto activo con ' + (pareceCodigo ? 'el código' : 'el nombre') + ' «' + texto + '».',
            showDenyButton: true,
            confirmButtonText: 'Entendido',
            denyButtonText: 'Registrar producto',
            confirmButtonColor: '#1d5fd1',
            denyButtonColor: '#6c757d'
        }).then(function (r) {
            if (r.isDenied) {
                window.location.href = TW.url('productos/crear.php' + (pareceCodigo ? '?codigo=' + encodeURIComponent(texto) : ''));
            } else {
                el.buscar.focus();
            }
        });
    }

    /** Procesa lo escrito + Enter: primero como código exacto, luego como nombre. */
    async function procesarEntrada(texto) {
        const r = await TW.json('ventas/buscar.php?codigo=' + encodeURIComponent(texto));
        if (r.producto) {
            agregar(r.producto);
            return;
        }
        const r2 = await TW.json('ventas/buscar.php?q=' + encodeURIComponent(texto));
        const vendibles = r2.productos.filter(p => !p.controla || p.existencia > 0);
        if (r2.productos.length === 1) {
            agregar(r2.productos[0]);
        } else if (r2.productos.length > 1) {
            if (!cola.length) {
                el.buscar.value = texto;
                pintarResultados(r2.productos, texto);
                const primero = el.resultados.querySelector('.resultado-item:not([disabled])');
                if (primero && vendibles.length) { primero.focus(); }
            }
        } else {
            await noRegistrado(texto);
        }
    }

    async function procesarCola() {
        if (procesando) { return; }
        procesando = true;
        try {
            while (cola.length) {
                const texto = cola.shift();
                try {
                    await procesarEntrada(texto);
                } catch (e) {
                    TW.alerta('error', 'Error al buscar', e.message);
                }
            }
        } finally {
            procesando = false;
        }
    }

    function enviarBusqueda() {
        const texto = el.buscar.value.trim();
        clearTimeout(temporizador);
        consultaActual++;              // descarta búsquedas en vuelo
        if (!texto) {
            return;   // algunos lectores envían Enter doble: se ignora el vacío
        }
        el.buscar.value = '';
        ocultarResultados();
        cola.push(texto);
        procesarCola();
    }

    el.buscar.addEventListener('input', function () {
        clearTimeout(temporizador);
        const texto = el.buscar.value.trim();
        if (texto.length < 2) {
            consultaActual++;
            ocultarResultados();
            return;
        }
        temporizador = setTimeout(() => buscarTexto(texto), 250);
    });

    el.buscar.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            enviarBusqueda();
        } else if (ev.key === 'ArrowDown') {
            const primero = el.resultados.querySelector('.resultado-item:not([disabled])');
            if (primero) { ev.preventDefault(); primero.focus(); }
        } else if (ev.key === 'Escape') {
            el.buscar.value = '';
            ocultarResultados();
        }
    });

    el.btnBuscar.addEventListener('click', function () {
        if (el.buscar.value.trim()) {
            enviarBusqueda();
        } else {
            el.buscar.focus();
        }
    });

    el.resultados.addEventListener('click', function (ev) {
        const boton = ev.target.closest('.resultado-item');
        if (!boton || boton.disabled) { return; }
        const p = resultadosActuales[Number(boton.dataset.indice)];
        if (p && agregar(p)) {
            el.buscar.value = '';
            ocultarResultados();
        }
        el.buscar.focus();
    });

    el.resultados.addEventListener('keydown', function (ev) {
        const items = Array.from(el.resultados.querySelectorAll('.resultado-item:not([disabled])'));
        const pos = items.indexOf(document.activeElement);
        if (ev.key === 'ArrowDown' && pos < items.length - 1) {
            ev.preventDefault(); items[pos + 1].focus();
        } else if (ev.key === 'ArrowUp') {
            ev.preventDefault();
            if (pos > 0) { items[pos - 1].focus(); } else { el.buscar.focus(); }
        } else if (ev.key === 'Escape') {
            ocultarResultados(); el.buscar.focus();
        }
    });

    if (el.rapidos) {
        el.rapidos.addEventListener('click', function (ev) {
            const boton = ev.target.closest('.btn-servicio');
            if (!boton) { return; }
            const p = (window.TIENDAWEB_POS.rapidos || [])[Number(boton.dataset.indice)];
            if (p) { agregar(p); }
            el.buscar.focus();
        });
    }

    /* ------------------------------------------------------------------
       Cobro
       ------------------------------------------------------------------ */
    function metodoSeleccionado() {
        const r = document.querySelector('input[name="metodo_pago"]:checked');
        return r ? r.value : 'efectivo';
    }

    function pintarBilletes(total) {
        const opciones = [total];
        const redondeo = Math.ceil(total / 1000) * 1000;           // siguiente múltiplo de $10
        if (redondeo > total) { opciones.push(redondeo); }
        [2000, 5000, 10000, 20000, 50000, 100000].forEach(function (b) {
            if (b > total && opciones.indexOf(b) === -1) { opciones.push(b); }
        });
        const elegidas = opciones.sort((a, b) => a - b).slice(0, 5);
        el.billetes.innerHTML = elegidas.map(function (c, i) {
            return '<button type="button" class="btn btn-outline-primary" data-centavos="' + c + '">' +
                (i === 0 ? 'Exacto' : TW.dinero(c / 100).replace('.00', '')) + '</button>';
        }).join('');
    }

    /** Revisa el cliente: obligatorio si algo va a crédito, sin pasar su límite. credito = centavos a crédito. */
    function revisarCliente(credito) {
        const c = CLIENTES[cobro.cliente.value];
        // El cliente solo se pide cuando hace falta (fiado) o si el cajero lo quiere en el ticket.
        const verCliente = credito > 0 || clienteAMano || !!c;
        document.getElementById('bloqueCliente').classList.toggle('d-none', !verCliente);
        const lnk = document.getElementById('lnkCliente');
        if (lnk) { lnk.classList.toggle('d-none', verCliente); }
        cobro.clienteAyuda.textContent = credito > 0 ? '(obligatorio para crédito)' : '(opcional)';
        cobro.cliente.classList.remove('is-invalid');
        let info = '';
        let error = '';
        if (c) {
            const saldo = Math.round(c.saldo * 100);
            info = 'Debe ' + TW.dinero(saldo / 100) + (c.limite !== null ? ' · límite ' + TW.dinero(c.limite) : ' · sin límite');
            if (credito > 0) {
                info += ' · quedará debiendo ' + TW.dinero((saldo + credito) / 100);
                if (c.limite !== null && saldo + credito > Math.round(c.limite * 100)) {
                    error = 'Se pasa de su límite de crédito.';
                }
            }
        } else if (credito > 0) {
            error = Object.keys(CLIENTES).length ? 'Elige al cliente que se lleva fiado.' : 'Primero da de alta al cliente en "Clientes y crédito".';
        }
        cobro.clienteInfo.textContent = error || info;
        cobro.clienteInfo.classList.toggle('text-danger', error !== '');
        if (error && credito > 0) { cobro.cliente.classList.add('is-invalid'); }
        return error;
    }

    /** Montos escritos en "Dividir pago": {metodo: centavos} (solo los mayores que cero). */
    function pagosDivididos() {
        const pagos = {};
        document.querySelectorAll('.pago-dividido').forEach(function (inp) {
            const v = leerNumero(inp.value, 2, 10);
            inp.classList.toggle('is-invalid', inp.value.trim() !== '' && v === null);
            if (v !== null && aCentavos(v) > 0) { pagos[inp.dataset.metodo] = aCentavos(v); }
        });
        return pagos;
    }

    /** Pago dividido: lo que no es efectivo se cobra tal cual; el efectivo cubre el resto y da cambio. */
    function revisarDividido(total) {
        const pagos = pagosDivididos();
        const caja = document.getElementById('cajaDividido');
        const etiqueta = document.getElementById('etiquetaDividido');
        const monto = document.getElementById('montoDividido');
        const noEfectivo = Object.keys(pagos).filter(m => m !== 'efectivo').reduce((s, m) => s + pagos[m], 0);
        const falta = total - noEfectivo - (pagos.efectivo || 0);
        let error = '';
        if (Object.keys(pagos).length < 2) {
            error = 'Escribe al menos dos formas de pago.';
        } else if (noEfectivo > total) {
            error = 'Tarjeta, transferencia y crédito suman más que el total.';
        } else if (pagos.efectivo && noEfectivo === total) {
            error = 'Los otros pagos ya cubren el total: borra el efectivo.';
        } else if (falta > 0) {
            error = 'Falta';
        }
        caja.classList.toggle('falta', error !== '');
        etiqueta.textContent = error === '' ? (falta < 0 ? 'Cambio a entregar' : 'Completo') : error;
        monto.textContent = error === 'Falta' ? TW.dinero(falta / 100) : (error === '' ? TW.dinero(Math.max(0, -falta) / 100) : '');
        return { pagos: pagos, error: error };
    }

    function actualizarCobro() {
        const metodo = metodoSeleccionado();
        const subtotal = totalCentavos();
        const descuento = descuentoCentavos();
        const total = subtotal - descuento;
        const efectivo = metodo === 'efectivo';
        const dividido = metodo === 'mixto';
        el.seccionEfectivo.classList.toggle('d-none', !efectivo);
        el.seccionOtro.classList.toggle('d-none', efectivo || dividido);
        document.getElementById('seccionDividido').classList.toggle('d-none', !dividido);
        el.nombreMetodo.textContent = NOMBRES_METODO[metodo] || metodo;
        el.cobroTotal.textContent = TW.dinero(total / 100);
        cobro.cobroDescuento.classList.toggle('d-none', descuento === 0);
        cobro.cobroDescuento.textContent = 'Subtotal ' + TW.dinero(subtotal / 100) + ' · descuento −' + TW.dinero(descuento / 100);

        if (dividido) {
            const d = revisarDividido(total);
            const errorCliente = revisarCliente(d.pagos.credito || 0);
            el.btnConfirmar.disabled = enviando || carrito.length === 0 || d.error !== '' || errorCliente !== '';
            return;
        }
        const errorCliente = revisarCliente(metodo === 'credito' ? total : 0);

        if (!efectivo) {
            el.btnConfirmar.disabled = enviando || carrito.length === 0 || errorCliente !== '';
            return;
        }
        const recibido = leerNumero(el.recibido.value, 2, 10);
        el.recibido.classList.toggle('is-invalid', el.recibido.value.trim() !== '' && recibido === null);
        if (recibido === null) {
            el.cajaCambio.classList.remove('falta');
            el.etiquetaCambio.textContent = 'Cambio';
            el.cambio.textContent = '—';
            el.btnConfirmar.disabled = true;
            return;
        }
        const diferencia = aCentavos(recibido) - total;
        el.cajaCambio.classList.toggle('falta', diferencia < 0);
        el.etiquetaCambio.textContent = diferencia < 0 ? 'Falta' : 'Cambio a entregar';
        el.cambio.textContent = TW.dinero(Math.abs(diferencia) / 100);
        el.btnConfirmar.disabled = enviando || diferencia < 0 || carrito.length === 0 || errorCliente !== '';
    }

    function abrirCobro() {
        if (!carrito.length || enviando || el.modal.classList.contains('show')) { return; }
        if (!POS.cajaAbierta) { mostrarAbrirCaja(); return; }
        if (!tokenOperacion) { tokenOperacion = nuevoToken(); }
        const piezas = carrito.reduce((s, i) => s + i.cantidad, 0) / UNIDAD;
        el.cobroArticulos.textContent = carrito.length + ' producto(s) · ' + TW.cantidad(piezas) + ' artículo(s)';
        document.getElementById('metodo_efectivo').checked = true;
        document.getElementById('opcionDividir').classList.add('d-none');
        document.getElementById('lnkMasOpciones').classList.remove('d-none');
        el.recibido.value = '';
        document.querySelectorAll('.pago-dividido').forEach(inp => { inp.value = ''; inp.classList.remove('is-invalid'); });
        cobro.cliente.value = '';
        clienteAMano = false;
        quitarDescuento();
        pintarBilletes(totalACobrar());
        actualizarCobro();
        modalCobro.show();
    }

    function quitarDescuento() {
        if (!cobro.grupoDescuento) { return; }
        cobro.descuentoValor.value = '';
        cobro.grupoDescuento.classList.add('d-none');
        cobro.lnkDescuento.classList.remove('d-none');
    }

    /** Al cambiar el descuento cambia el total: se recalculan billetes y cambio. */
    function descuentoCambio() {
        pintarBilletes(totalACobrar());
        actualizarCobro();
    }

    function mostrarAbrirCaja() {
        const m = document.getElementById('modalAbrirCaja');
        if (!m) { return; }
        bootstrap.Modal.getOrCreateInstance(m).show();
        m.addEventListener('shown.bs.modal', () => document.getElementById('fondoInicial').focus(), { once: true });
    }

    /* ------------------------------------------------------------------
       Impresora de tickets y cajón (si están configurados)
       ------------------------------------------------------------------ */
    /** accion: 'cobro' | 'ticket' | 'cajon'. Un fallo solo avisa: la venta ya quedó guardada. */
    /** Manda a la impresora. Con silencioso, en vez de avisar devuelve el mensaje de error (o ''). */
    async function usarImpresora(accion, id, silencioso) {
        if (!IMPRESORA.activa) { return ''; }
        try {
            await TW.json('ventas/impresora.php', { method: 'POST', body: { accion: accion, id: id } });
            return '';
        } catch (e) {
            if (!silencioso) { TW.toast('warning', 'Impresora: ' + e.message); }
            return e.message;
        }
    }

    async function confirmarCobro() {
        if (enviando || el.btnConfirmar.disabled) { return; }
        const metodo = metodoSeleccionado();
        const recibido = metodo === 'efectivo' ? leerNumero(el.recibido.value, 2, 10) : null;
        if (metodo === 'efectivo' && (recibido === null || aCentavos(recibido) < totalACobrar())) {
            actualizarCobro();
            return;
        }
        const descuento = descuentoCentavos() > 0
            ? { tipo: cobro.descuentoTipo.value, valor: leerNumero(cobro.descuentoValor.value, 2, 8) }
            : null;
        let pagos = null;
        if (metodo === 'mixto') {
            const d = revisarDividido(totalACobrar());
            if (d.error !== '') { actualizarCobro(); return; }
            pagos = Object.keys(d.pagos).map(m => ({ metodo: m, monto: (d.pagos[m] / 100).toFixed(2) }));
        }
        enviando = true;
        const textoBoton = el.btnConfirmar.innerHTML;
        el.btnConfirmar.disabled = true;
        el.btnConfirmar.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';

        try {
            const r = await TW.json('ventas/cobrar.php', {
                method: 'POST',
                body: {
                    token: tokenOperacion,
                    metodo_pago: metodo,
                    recibido: recibido === null ? null : (aCentavos(recibido) / 100).toFixed(2),
                    pagos: pagos,
                    cliente_id: cobro.cliente.value ? Number(cobro.cliente.value) : null,
                    descuento: descuento,
                    items: carrito.map(i => ({ id: i.id, cantidad: (i.cantidad / UNIDAD).toFixed(3) }))
                }
            });
            const v = r.venta;
            carrito = [];
            carritoCambio();
            modalCobro.hide();
            // El cajón se abre (y el ticket sale) mientras se muestra el cambio. Un reenvío no vuelve a abrirlo.
            // Si la impresora falla, el aviso va dentro del mensaje de la venta (otro aviso lo taparía).
            const impresion = r.repetida ? Promise.resolve('') : usarImpresora('cobro', v.id, true);

            const fila = (a, b) => '<div class="d-flex justify-content-between"><span>' + a + '</span><strong>' + b + '</strong></div>';
            let html = '<div class="text-start mx-auto" style="max-width:280px">' + fila('Folio', TW.escapar(v.folio));
            if (v.descuento > 0) {
                html += fila('Subtotal', TW.dinero(v.subtotal)) + fila('Descuento', '−' + TW.dinero(v.descuento));
            }
            html += fila('Total', TW.dinero(v.total));
            if (v.cliente) { html += fila('Cliente', TW.escapar(v.cliente)); }
            const conEfectivo = (v.pagos || []).some(p => p.metodo === 'efectivo') || v.metodo_pago === 'efectivo';
            if ((v.pagos || []).length > 1) {
                v.pagos.forEach(p => { html += fila(TW.escapar(nombreMetodo(p.metodo)), TW.dinero(p.monto)); });
            } else if (!conEfectivo && v.metodo_pago !== 'credito') {
                html += fila('Pago', TW.escapar(nombreMetodo(v.metodo_pago)));
            }
            if (conEfectivo) {
                html += fila('Efectivo recibido', TW.dinero(v.recibido)) +
                    '<div class="cambio-caja mt-3 text-center"><div class="small fw-semibold">CAMBIO</div><div class="monto">' + TW.dinero(v.cambio) + '</div></div>';
            }
            if (v.saldo !== null && v.saldo !== undefined) {
                html += '<div class="cambio-caja falta mt-3 text-center"><div class="small fw-semibold">QUEDA A DEBER</div><div class="monto">' + TW.dinero(v.saldo) + '</div></div>';
            }
            html += '</div>';

            const ofrecerImpresion = IMPRESORA.activa && IMPRESORA.imprimir === 'preguntar';
            impresion.then(function (error) {
                if (!error) { return; }
                const contenedor = Swal.isVisible() ? Swal.getHtmlContainer() : null;
                if (contenedor) {
                    contenedor.insertAdjacentHTML('beforeend', '<div class="alert alert-warning small mt-3 mb-0 text-start"><i class="bi bi-printer me-1"></i>'
                        + 'La venta se guardó, pero no se imprimió: ' + TW.escapar(error) + '</div>');
                } else {
                    TW.alerta('warning', 'No se imprimió el ticket', error);
                }
            });
            const res = await Swal.fire({
                icon: 'success',
                titleText: r.repetida ? 'Esta venta ya estaba registrada' : '¡Venta registrada!',
                html: html,
                showDenyButton: true,
                showCancelButton: ofrecerImpresion,
                confirmButtonText: '<i class="bi bi-cart-plus me-1"></i>Nueva venta',
                denyButtonText: '<i class="bi bi-receipt me-1"></i>Ver ticket',
                cancelButtonText: '<i class="bi bi-printer me-1"></i>Imprimir ticket',
                confirmButtonColor: '#1d5fd1',
                denyButtonColor: '#6c757d',
                cancelButtonColor: '#198754',
                allowOutsideClick: false,
                allowEscapeKey: !ofrecerImpresion
            });
            if (res.isDenied) {
                window.location.href = TW.url('ventas/detalle.php?id=' + encodeURIComponent(v.id));
                return;
            }
            if (res.dismiss === Swal.DismissReason.cancel) {
                await usarImpresora('ticket', v.id);
            }
            el.buscar.focus();
        } catch (e) {
            const datos = e.datos || {};
            await Swal.fire({
                icon: 'error',
                titleText: 'No se registró la venta',
                html: TW.escapar(e.message).replace(/\n/g, '<br>'),
                confirmButtonColor: '#1d5fd1'
            });
            if (datos.refrescar) {
                modalCobro.hide();
                await refrescarCarrito();
            }
            if (datos.caja_cerrada) {
                POS.cajaAbierta = false;
                modalCobro.hide();
                window.location.reload();   // muestra el aviso para abrir caja
            }
        } finally {
            enviando = false;
            el.btnConfirmar.innerHTML = textoBoton;
            actualizarCobro();
        }
    }

    const btnCajon = document.getElementById('btnCajon');
    if (btnCajon) {
        btnCajon.addEventListener('click', async function () {
            btnCajon.disabled = true;
            await usarImpresora('cajon');
            btnCajon.disabled = false;
            el.buscar.focus();
        });
    }

    if (cobro.lnkDescuento) {
        cobro.lnkDescuento.addEventListener('click', function (ev) {
            ev.preventDefault();
            cobro.lnkDescuento.classList.add('d-none');
            cobro.grupoDescuento.classList.remove('d-none');
            cobro.descuentoValor.focus();
        });
        document.getElementById('btnQuitarDescuento').addEventListener('click', function () { quitarDescuento(); descuentoCambio(); });
        cobro.descuentoValor.addEventListener('input', descuentoCambio);
        cobro.descuentoTipo.addEventListener('change', descuentoCambio);
    }
    cobro.cliente.addEventListener('change', actualizarCobro);
    const lnkCliente = document.getElementById('lnkCliente');
    if (lnkCliente) {
        lnkCliente.addEventListener('click', function (ev) {
            ev.preventDefault();
            clienteAMano = true;
            actualizarCobro();
            cobro.cliente.focus();
        });
    }

    el.btnCobrar.addEventListener('click', abrirCobro);
    el.btnConfirmar.addEventListener('click', confirmarCobro);
    el.recibido.addEventListener('input', actualizarCobro);
    document.querySelectorAll('.pago-dividido').forEach(function (inp) {
        inp.addEventListener('input', actualizarCobro);
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); confirmarCobro(); }
        });
    });
    el.recibido.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); confirmarCobro(); }
    });
    document.querySelectorAll('input[name="metodo_pago"]').forEach(function (r) {
        r.addEventListener('change', function () {
            actualizarCobro();
            if (r.value === 'efectivo') { el.recibido.focus(); }
            else if (r.value === 'mixto') { document.querySelector('.pago-dividido').focus(); }
            else { el.btnConfirmar.focus(); }
        });
    });
    document.getElementById('lnkMasOpciones').addEventListener('click', function (ev) {
        ev.preventDefault();
        document.getElementById('opcionDividir').classList.remove('d-none');
        ev.currentTarget.classList.add('d-none');
    });
    el.billetes.addEventListener('click', function (ev) {
        const b = ev.target.closest('[data-centavos]');
        if (!b) { return; }
        el.recibido.value = (Number(b.dataset.centavos) / 100).toFixed(2);
        actualizarCobro();
        el.btnConfirmar.focus();
    });
    el.modal.addEventListener('shown.bs.modal', function () { el.recibido.focus(); });
    el.modal.addEventListener('hidden.bs.modal', function () { el.buscar.focus(); });

    /* ------------------------------------------------------------------
       Atajos de teclado
       ------------------------------------------------------------------ */
    document.addEventListener('keydown', function (ev) {
        // Un aviso modal abierto tiene prioridad; los avisos pequeños ("Agregado…") no bloquean los atajos.
        const popup = Swal.isVisible() ? Swal.getPopup() : null;
        if (popup && !popup.classList.contains('swal2-toast')) { return; }
        if (ev.key === 'F2') {
            ev.preventDefault();
            if (!el.modal.classList.contains('show')) { el.buscar.focus(); el.buscar.select(); }
        } else if (ev.key === 'F4') {
            ev.preventDefault();
            if (!el.modal.classList.contains('show')) { ponerEnEspera(); }
        } else if (ev.key === 'F9') {
            ev.preventDefault();
            if (el.modal.classList.contains('show')) { confirmarCobro(); } else { abrirCobro(); }
        }
    });

    // Inicio
    cargar();
    pintarCarrito();
    refrescarCarrito();
    el.buscar.focus();
    if (!POS.cajaAbierta) { mostrarAbrirCaja(); }
})();
