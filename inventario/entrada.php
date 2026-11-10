<?php
/**
 * Entrada de mercancía (lo que llegó del proveedor) o conteo físico (ajuste).
 *
 *   modo=entrada: suma la cantidad a la existencia; si se escribe costo, lo actualiza.
 *   modo=conteo:  la existencia queda igual a lo contado; la diferencia se anota como ajuste.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('inventario');

$modo = ($_REQUEST['modo'] ?? '') === 'conteo' ? 'conteo' : 'entrada';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 150);
    $items = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $lineas = [];
    $errores = [];

    foreach ($items as $item) {
        $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cantidad = normalizar_decimal($item['cantidad'] ?? '', 3, 9);
        $costoTxt = trim((string) ($item['costo'] ?? ''));
        $costo = $costoTxt === '' ? null : normalizar_decimal($costoTxt, 2, 8);
        if (!$id || $cantidad === null || ($modo === 'entrada' && a_milesimas($cantidad) <= 0) || ($costoTxt !== '' && $costo === null)) {
            $errores[] = 'Hay una cantidad o un costo no válido.';
            continue;
        }
        $lineas[] = ['id' => $id, 'milesimas' => a_milesimas($cantidad), 'costo' => $costo];
    }
    if (!$lineas && !$errores) {
        $errores[] = 'Agrega al menos un producto.';
    }

    if (!$errores) {
        transaccion_iniciar();
        try {
            $buscar = db()->prepare('SELECT id, nombre, existencia, controlar_stock, permite_fraccion FROM productos WHERE id = :id');
            $cambios = 0;
            foreach ($lineas as $l) {
                $buscar->execute([':id' => $l['id']]);
                $p = $buscar->fetch();
                if (!$p || !(int) $p['controlar_stock']) {
                    throw new RuntimeException('Un producto ya no existe o no controla inventario.');
                }
                if (!(int) $p['permite_fraccion'] && $l['milesimas'] % 1000 !== 0) {
                    throw new RuntimeException("«{$p['nombre']}» solo acepta cantidades enteras.");
                }
                if ($modo === 'entrada') {
                    mover_existencia($l['id'], 'entrada', $l['milesimas'], $l['costo'], $nota !== '' ? $nota : null);
                    if ($l['costo'] !== null) {
                        db()->prepare('UPDATE productos SET costo = :costo WHERE id = :id')->execute([':costo' => $l['costo'], ':id' => $l['id']]);
                    }
                    $cambios++;
                } else {
                    $diferencia = $l['milesimas'] - a_milesimas($p['existencia']);
                    if ($diferencia !== 0) {
                        mover_existencia($l['id'], 'ajuste', $diferencia, null, 'Conteo físico' . ($nota !== '' ? ': ' . $nota : ''));
                        $cambios++;
                    }
                }
            }
            transaccion_confirmar();
        } catch (RuntimeException $ex) {
            transaccion_revertir();
            if ($ex instanceof PDOException) {
                throw $ex;
            }
            $errores[] = $ex->getMessage();
        }
        if (!$errores) {
            auditar('inventario', ($modo === 'entrada' ? 'Entrada de mercancía' : 'Conteo físico') . ": $cambios producto(s)"
                . ($nota !== '' ? '. ' . $nota : ''));
            flash('exito', $modo === 'entrada'
                ? "Entrada registrada: $cambios producto(s) actualizados."
                : ($cambios ? "Conteo guardado: se ajustaron $cambios producto(s)." : 'Conteo guardado: todo coincidía, no hubo ajustes.'));
            redirigir('inventario/index.php');
        }
    }
    flash('error', implode("\n", array_unique($errores)));
}

$titulo  = $modo === 'entrada' ? 'Entrada de mercancía' : 'Conteo de inventario';
$seccion = 'inventario';
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="?modo=entrada" class="btn <?= $modo === 'entrada' ? 'btn-primary' : 'btn-light border' ?>"><i class="bi bi-box-arrow-in-down me-1"></i>Entrada de mercancía</a>
    <a href="?modo=conteo" class="btn <?= $modo === 'conteo' ? 'btn-primary' : 'btn-light border' ?>"><i class="bi bi-clipboard-check me-1"></i>Conteo físico (ajuste)</a>
</div>
<p class="text-body-secondary">
    <?= $modo === 'entrada'
        ? 'Escanea o busca lo que llegó y escribe cuántos. Se <strong>suma</strong> a lo que ya hay. Si cambió el costo, escríbelo para actualizarlo.'
        : 'Cuenta lo que hay en el anaquel y escríbelo. La existencia queda <strong>igual a lo contado</strong> y la diferencia se guarda como ajuste.' ?>
</p>

<form method="post" id="formEntrada">
    <?= csrf_campo() ?>
    <input type="hidden" name="modo" value="<?= e($modo) ?>">
    <div class="card mb-3">
        <div class="card-body">
            <div class="input-group input-group-lg pos-busqueda">
                <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                <input type="search" id="buscarProducto" class="form-control" placeholder="Código de barras o nombre y Enter" autocomplete="off" autofocus>
            </div>
            <div id="resultadosEntrada" class="list-group mt-2"></div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th>Producto</th><th class="text-end">Hay ahora</th>
                    <th style="width:150px"><?= $modo === 'entrada' ? 'Llegaron' : 'Contados' ?></th>
                    <?php if ($modo === 'entrada'): ?><th style="width:160px">Costo unitario</th><?php else: ?><th class="text-end">Diferencia</th><?php endif; ?>
                    <th></th>
                </tr></thead>
                <tbody id="lineas">
                    <tr id="sinLineas"><td colspan="5" class="text-center text-body-secondary py-4">Todavía no agregas productos.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
            <input type="text" class="form-control flex-grow-1" name="nota" maxlength="150" style="max-width:420px"
                   placeholder="<?= $modo === 'entrada' ? 'Nota: proveedor, número de factura…' : 'Nota (opcional)' ?>">
            <button type="submit" class="btn btn-primary btn-lg ms-auto" id="btnGuardar" disabled>
                <i class="bi bi-check2-circle me-1"></i><?= $modo === 'entrada' ? 'Registrar entrada' : 'Guardar conteo' ?>
            </button>
        </div>
    </div>
</form>

<script>
(function () {
    const modo = <?= json_encode($modo) ?>;
    const buscar = document.getElementById('buscarProducto');
    const resultados = document.getElementById('resultadosEntrada');
    const tbody = document.getElementById('lineas');
    const sinLineas = document.getElementById('sinLineas');
    const btnGuardar = document.getElementById('btnGuardar');
    let n = 0;

    function revisar() {
        const hay = tbody.querySelectorAll('tr[data-id]').length > 0;
        sinLineas.classList.toggle('d-none', hay);
        btnGuardar.disabled = !hay;
    }

    function agregar(p) {
        resultados.innerHTML = '';
        buscar.value = '';
        if (!p.controla) { TW.alerta('info', 'Sin inventario', '«' + p.nombre + '» es un servicio: no lleva existencias.'); return; }
        const existente = tbody.querySelector('tr[data-id="' + p.id + '"]');
        if (existente) {
            const inp = existente.querySelector('.cant');
            if (modo === 'entrada') { inp.value = (Number(inp.value) || 0) + 1; }
            inp.focus(); inp.select();
            return;
        }
        const i = n++;
        const paso = p.fraccion ? '0.001' : '1';
        const tr = document.createElement('tr');
        tr.dataset.id = p.id;
        tr.innerHTML =
            '<td><input type="hidden" name="items[' + i + '][id]" value="' + p.id + '"><div class="fw-semibold">' + TW.escapar(p.nombre) + '</div>' +
            '<small class="text-body-secondary">' + TW.escapar(p.codigo || '') + '</small></td>' +
            '<td class="text-end num">' + TW.cantidad(p.existencia) + '</td>' +
            '<td><input type="number" class="form-control cant" name="items[' + i + '][cantidad]" min="0" step="' + paso + '" value="' + (modo === 'entrada' ? 1 : '') + '" required></td>' +
            (modo === 'entrada'
                ? '<td><div class="input-group"><span class="input-group-text">$</span><input type="number" class="form-control" name="items[' + i + '][costo]" min="0" step="0.01" placeholder="' + (p.costo !== null ? p.costo.toFixed(2) : 'igual') + '"></div></td>'
                : '<td class="text-end num dif">—</td>') +
            '<td class="text-end"><button type="button" class="btn btn-sm btn-light border text-danger quitar" title="Quitar"><i class="bi bi-x-lg"></i></button></td>';
        tbody.appendChild(tr);
        const cant = tr.querySelector('.cant');
        if (modo === 'conteo') {
            cant.addEventListener('input', function () {
                const d = Math.round((Number(cant.value) - p.existencia) * 1000) / 1000;
                const celda = tr.querySelector('.dif');
                celda.textContent = cant.value === '' ? '—' : (d > 0 ? '+' : '') + TW.cantidad(d);
                celda.className = 'text-end num dif ' + (d < 0 ? 'text-danger' : d > 0 ? 'text-success' : '');
            });
        }
        cant.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); buscar.focus(); } });
        tr.querySelector('.quitar').addEventListener('click', () => { tr.remove(); revisar(); buscar.focus(); });
        revisar();
        cant.focus(); cant.select();
    }

    async function buscarTexto(texto) {
        texto = texto.trim();
        if (!texto) { return; }
        try {
            const r = await TW.json('ventas/buscar.php?codigo=' + encodeURIComponent(texto));
            if (r.producto) { agregar(Object.assign({ costo: null }, r.producto)); return; }
            const r2 = await TW.json('ventas/buscar.php?q=' + encodeURIComponent(texto));
            if (r2.productos.length === 1) { agregar(r2.productos[0]); return; }
            resultados.innerHTML = r2.productos.length
                ? r2.productos.map((p, k) => '<button type="button" class="list-group-item list-group-item-action" data-k="' + k + '">' +
                    TW.escapar(p.nombre) + ' <small class="text-body-secondary">· hay ' + TW.cantidad(p.existencia) + '</small></button>').join('')
                : '<div class="list-group-item text-body-secondary">No se encontró «' + TW.escapar(texto) + '».</div>';
            resultados.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => agregar(r2.productos[Number(b.dataset.k)])));
        } catch (e) { TW.alerta('error', 'No se pudo buscar', e.message); }
    }

    buscar.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); buscarTexto(buscar.value); }
    });
    document.getElementById('formEntrada').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && ev.target.type !== 'submit') { ev.preventDefault(); }
    });
    revisar();
})();
</script>
<?php require __DIR__ . '/../includes/pie.php'; ?>
