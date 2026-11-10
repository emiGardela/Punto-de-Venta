/* =====================================================================
   TiendaWeb - Comportamiento común a todas las páginas
   ===================================================================== */
(function () {
    'use strict';

    const cfg = window.TIENDAWEB || {};

    const TW = {
        url(ruta) {
            return (cfg.base || '') + '/' + String(ruta).replace(/^\/+/, '');
        },

        dinero(n) {
            const v = Number(n) || 0;
            return (v < 0 ? '-' : '') + (cfg.moneda || '$') +
                Math.abs(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        cantidad(n) {
            return (Number(n) || 0).toLocaleString('es-MX', { maximumFractionDigits: 3 });
        },

        escapar(texto) {
            const div = document.createElement('div');
            div.textContent = texto == null ? '' : String(texto);
            return div.innerHTML;
        },

        toast(icono, titulo) {
            return Swal.fire({
                toast: true, position: 'top-end', icon: icono, titleText: titulo,
                showConfirmButton: false, timer: 2200, timerProgressBar: true
            });
        },

        alerta(icono, titulo, texto) {
            return Swal.fire({ icon: icono, titleText: titulo, text: texto || '', confirmButtonColor: '#1d5fd1' });
        },

        /** Petición JSON con token CSRF; lanza Error con el mensaje del servidor. */
        async json(ruta, opciones = {}) {
            const op = Object.assign({ method: 'GET', headers: {} }, opciones);
            op.headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': cfg.csrf || ''
            }, op.headers);
            if (op.body && typeof op.body !== 'string') {
                op.body = JSON.stringify(op.body);
                op.headers['Content-Type'] = 'application/json';
            }
            let resp;
            try {
                resp = await fetch(TW.url(ruta), op);
            } catch (e) {
                throw new Error('No hay comunicación con el sistema. Cierra el Punto de venta y vuelve a abrirlo.');
            }
            let datos = null;
            try { datos = await resp.json(); } catch (e) { datos = null; }
            if (!resp.ok || !datos || datos.ok === false) {
                const err = new Error((datos && datos.mensaje) || 'El servidor respondió con un error (' + resp.status + ').');
                err.datos = datos;
                err.status = resp.status;
                throw err;
            }
            return datos;
        }
    };
    window.TW = TW;

    // Avisos pendientes enviados desde PHP (flash).
    (cfg.avisos || []).forEach(function (a, i) {
        const icono = { exito: 'success', error: 'error', aviso: 'warning', info: 'info' }[a.tipo] || 'info';
        setTimeout(function () {
            if (icono === 'error' || icono === 'warning') {
                TW.alerta(icono, icono === 'error' ? 'Atención' : 'Aviso', a.mensaje);
            } else {
                TW.toast(icono, a.mensaje);
            }
        }, i * 400);
    });

    // Formularios que piden confirmación: <form data-confirmar="¿Seguro?" data-confirmar-detalle="...">
    document.addEventListener('submit', function (ev) {
        const form = ev.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirmar || form.dataset.confirmado === '1') {
            return;
        }
        ev.preventDefault();
        Swal.fire({
            icon: form.dataset.confirmarIcono || 'question',
            titleText: form.dataset.confirmar,
            text: form.dataset.confirmarDetalle || '',
            showCancelButton: true,
            confirmButtonText: form.dataset.confirmarBoton || 'Sí, continuar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: form.dataset.confirmarColor || '#1d5fd1',
            reverseButtons: true,
            focusCancel: true
        }).then(function (r) {
            if (r.isConfirmed) {
                form.dataset.confirmado = '1';
                form.querySelectorAll('button[type=submit]').forEach(b => b.disabled = true);
                form.submit();
            }
        });
    });

    // Evita doble envío en formularios normales.
    document.addEventListener('submit', function (ev) {
        const form = ev.target;
        if (ev.defaultPrevented || !(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') {
            return;
        }
        if (form.dataset.enviando === '1') {
            ev.preventDefault();
            return;
        }
        form.dataset.enviando = '1';
        setTimeout(() => form.querySelectorAll('button[type=submit]').forEach(b => b.disabled = true), 0);
    });

    // Filas de tabla que funcionan como enlace: <tr data-href="...">
    document.addEventListener('click', function (ev) {
        const fila = ev.target.closest('tr[data-href]');
        if (!fila || ev.target.closest('a, button, input, form, select, label')) {
            return;
        }
        window.location.href = fila.dataset.href;
    });

    // Reloj de la barra superior (hora de la computadora, solo informativo).
    const reloj = document.getElementById('relojSistema');
    if (reloj) {
        const pintar = function () {
            const ahora = new Date();
            reloj.innerHTML = '<i class="bi bi-calendar3 me-1"></i>' + ahora.toLocaleDateString('es-MX', {
                weekday: 'short', day: '2-digit', month: 'short', year: 'numeric'
            }) + ' · ' + ahora.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
        };
        pintar();
        setInterval(pintar, 30000);
    }

    // Validación de Bootstrap en formularios con class="needs-validation".
    document.querySelectorAll('form.needs-validation').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!form.checkValidity()) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                const primero = form.querySelector(':invalid');
                if (primero) { primero.focus(); }
            }
            form.classList.add('was-validated');
        }, true);
    });
})();
