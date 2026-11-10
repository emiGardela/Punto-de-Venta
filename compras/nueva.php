<?php
/**
 * Registrar una compra a un proveedor: lo que llegó, a qué costo y si se
 * pagó con dinero de la caja. Suma al inventario y actualiza el costo.
 *
 * ?sugerir=ID de proveedor (o 0 = todos): precarga los productos por
 * agotarse de ese proveedor con una cantidad sugerida.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('inventario');

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $proveedorId = filter_var($_POST['proveedor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $folio = mb_substr(trim((string) ($_POST['folio_proveedor'] ?? '')), 0, 40);
    $notas = mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 300);
    $deCaja = !empty($_POST['pagada_de_caja']);
    $actualizarCosto = !empty($_POST['actualizar_costo']);

    $lineas = [];
    foreach ((array) ($_POST['items'] ?? []) as $item) {
        $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cantidad = normalizar_decimal($item['cantidad'] ?? '', 3, 9);
        $costo = normalizar_decimal(($item['costo'] ?? '') === '' ? '0' : $item['costo'], 2, 8);
        if (!$id || $cantidad === null || a_milesimas($cantidad) <= 0 || $costo === null) {
            $errores[] = 'Hay una cantidad o un costo no válido.';
            continue;
        }
        $lineas[] = ['id' => $id, 'milesimas' => a_milesimas($cantidad), 'costo' => a_centavos($costo)];
    }
    if (!$lineas && !$errores) {
        $errores[] = 'Agrega al menos un producto.';
    }
    if ($proveedorId) {
        $st = db()->prepare('SELECT nombre FROM proveedores WHERE id = :id');
        $st->execute([':id' => $proveedorId]);
        $proveedor = $st->fetchColumn();
        if ($proveedor === false) {
            $errores[] = 'El proveedor elegido no existe.';
        }
    }

    if (!$errores) {
        transaccion_iniciar();
        try {
            $turno = turno_abierto();
            if ($deCaja && !$turno) {
                throw new RuntimeException('La caja está cerrada: ábrela para pagar la compra con dinero del cajón, o quita esa opción.');
            }
            $total = array_sum(array_map(fn($l) => importe_centavos($l['costo'], $l['milesimas']), $lineas));
            db()->prepare('INSERT INTO compras (proveedor_id, fecha, folio_proveedor, total, pagada_de_caja, turno_id, notas, usuario_id)
                           VALUES (:proveedor, :fecha, :folio, :total, :caja, :turno, :notas, :usuario)')
                ->execute([':proveedor' => $proveedorId, ':fecha' => date('Y-m-d H:i:s'), ':folio' => $folio !== '' ? $folio : null,
                           ':total' => centavos_a_decimal($total), ':caja' => $deCaja ? 1 : 0, ':turno' => $deCaja ? $turno['id'] : null,
                           ':notas' => $notas !== '' ? $notas : null, ':usuario' => usuario_id()]);
            $compraId = (int) db()->lastInsertId();

            $buscar = db()->prepare('SELECT id, nombre, tipo, controlar_stock, permite_fraccion FROM productos WHERE id = :id');
            $insLinea = db()->prepare('INSERT INTO compra_productos (compra_id, producto_id, cantidad, costo_unitario, importe)
                                       VALUES (:compra, :producto, :cantidad, :costo, :importe)');
            $nota = 'Compra #' . $compraId . ($folio !== '' ? " (nota $folio)" : '');
            foreach ($lineas as $l) {
                $buscar->execute([':id' => $l['id']]);
                $p = $buscar->fetch();
                if (!$p || $p['tipo'] !== 'normal' || !(int) $p['controlar_stock']) {
                    throw new RuntimeException('Un producto de la compra no lleva inventario propio (servicio, paquete o kit). Compra el producto base.');
                }
                if (!(int) $p['permite_fraccion'] && $l['milesimas'] % 1000 !== 0) {
                    throw new RuntimeException("«{$p['nombre']}» solo acepta cantidades enteras.");
                }
                $insLinea->execute([':compra' => $compraId, ':producto' => $l['id'], ':cantidad' => number_format($l['milesimas'] / 1000, 3, '.', ''),
                                    ':costo' => centavos_a_decimal($l['costo']), ':importe' => centavos_a_decimal(importe_centavos($l['costo'], $l['milesimas']))]);
                mover_existencia($l['id'], 'entrada', $l['milesimas'], centavos_a_decimal($l['costo']), $nota);
                if ($actualizarCosto && $l['costo'] > 0) {
                    db()->prepare('UPDATE productos SET costo = :costo WHERE id = :id')->execute([':costo' => centavos_a_decimal($l['costo']), ':id' => $l['id']]);
                }
            }
            if ($deCaja && $total > 0) {
                registrar_movimiento_caja('salida', $total, 'Compra #' . $compraId . ($proveedorId ? ' a ' . $proveedor : ''));
            }
            auditar('inventario', 'Compra #' . $compraId . ' por ' . dinero($total / 100) . ($proveedorId ? ' a ' . $proveedor : '')
                . ', ' . count($lineas) . ' producto(s)' . ($deCaja ? ', pagada con dinero de la caja' : ''), 'compra', $compraId);
            transaccion_confirmar();
        } catch (RuntimeException $e) {
            transaccion_revertir();
            if ($e instanceof PDOException) {
                throw $e;
            }
            $errores[] = $e->getMessage();
        }
        if (!$errores) {
            flash('exito', 'Compra registrada: ' . dinero($total / 100) . '. El inventario ya se actualizó.');
            redirigir('compras/detalle.php?id=' . $compraId);
        }
    }
    flash('error', implode("\n", array_unique($errores)));
}

// Sugerido de compra: productos por agotarse (del proveedor indicado, o de todos).
$sugeridos = [];
$sugerirProveedor = null;
if (isset($_GET['sugerir'])) {
    $sugerirProveedor = filter_var($_GET['sugerir'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
    $sql = "SELECT id, nombre, codigo_barras, existencia, stock_minimo, costo, permite_fraccion FROM productos
            WHERE activo = 1 AND tipo = 'normal' AND controlar_stock = 1 AND existencia <= stock_minimo"
        . ($sugerirProveedor ? ' AND proveedor_id = :p' : '') . ' ORDER BY sin_acentos(nombre)';
    $st = db()->prepare($sql);
    $st->execute($sugerirProveedor ? [':p' => $sugerirProveedor] : []);
    foreach ($st->fetchAll() as $p) {
        // Sugerido: llegar al doble del mínimo (al menos 1).
        $falta = max(1, (float) $p['stock_minimo'] * 2 - (float) $p['existencia']);
        $sugeridos[] = ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'codigo' => $p['codigo_barras'], 'existencia' => (float) $p['existencia'],
                        'controla' => true, 'fraccion' => (bool) $p['permite_fraccion'], 'costo' => $p['costo'] !== null ? (float) $p['costo'] : null,
                        'cantidad' => $p['permite_fraccion'] ? round($falta, 3) : ceil($falta)];
    }
}
$proveedores = db()->query('SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY sin_acentos(nombre)')->fetchAll(PDO::FETCH_KEY_PAIR);

$titulo  = 'Nueva compra';
$seccion = 'compras';
require __DIR__ . '/../includes/encabezado.php';
?>
<nav aria-label="Ruta" class="mb-3"><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= e(url('compras/index.php')) ?>">Compras</a></li>
    <li class="breadcrumb-item active" aria-current="page">Nueva compra</li>
</ol></nav>

<form method="post" id="formCompra">
    <?= csrf_campo() ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="proveedor_id" class="form-label">Proveedor</label>
                        <select class="form-select" id="proveedor_id" name="proveedor_id">
                            <option value="">Sin proveedor</option>
                            <?php foreach ($proveedores as $pid => $pn): ?>
                                <option value="<?= (int) $pid ?>" <?= $sugerirProveedor === (int) $pid ? 'selected' : '' ?>><?= e($pn) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><a href="<?= e(url('proveedores/index.php')) ?>" target="_blank">Dar de alta un proveedor</a></div>
                    </div>
                    <div class="mb-3">
                        <label for="folio_proveedor" class="form-label">Factura o nota del proveedor <span class="text-body-secondary fw-normal">(opcional)</span></label>
                        <input type="text" class="form-control" id="folio_proveedor" name="folio_proveedor" maxlength="40" placeholder="Ej. A-1234">
                    </div>
                    <div class="mb-3">
                        <label for="notas" class="form-label">Notas</label>
                        <textarea class="form-control" id="notas" name="notas" rows="2" maxlength="300"></textarea>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="pagada_de_caja" name="pagada_de_caja" value="1">
                        <label class="form-check-label" for="pagada_de_caja">La pagué con dinero de la caja<br><small class="text-body-secondary">Se registra la salida de efectivo para que el corte cuadre.</small></label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="actualizar_costo" name="actualizar_costo" value="1" checked>
                        <label class="form-check-label" for="actualizar_costo">Actualizar el costo de los productos</label>
                    </div>
                    <div class="bg-primary-suave rounded-4 text-center py-3">
                        <div class="small text-body-secondary">Total de la compra</div>
                        <div class="fs-3 fw-bold num" id="totalCompra">$0.00</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                        <input type="search" id="buscarProducto" class="form-control" placeholder="Escanea o busca lo que llegó y Enter" autocomplete="off" autofocus>
                    </div>
                    <div id="resultados" class="list-group mt-2"></div>
                    <?php if ($sugerirProveedor === null): ?>
                        <div class="small mt-2"><a href="?sugerir=0"><i class="bi bi-magic me-1"></i>Cargar todo lo que está por agotarse</a></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Producto</th><th class="text-end">Hay</th><th style="width:120px">Llegaron</th><th style="width:150px">Costo c/u</th><th class="text-end">Importe</th><th></th></tr></thead>
                        <tbody id="lineas"><tr id="sinLineas"><td colspan="6" class="text-center text-body-secondary py-4">Todavía no agregas productos.</td></tr></tbody>
                    </table>
                </div>
                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary btn-lg" id="btnGuardar" disabled><i class="bi bi-check2-circle me-1"></i>Registrar compra</button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.getElementById('lineas');
    const buscar = document.getElementById('buscarProducto');
    const resultados = document.getElementById('resultados');
    let n = 0;

    function recalcular() {
        let total = 0;
        tbody.querySelectorAll('tr[data-id]').forEach(function (tr) {
            const cant = Number(tr.querySelector('.cant').value) || 0;
            const costo = Number(tr.querySelector('.costo').value) || 0;
            const imp = Math.round(Math.round(costo * 100) * Math.round(cant * 1000) / 1000);
            tr.querySelector('.importe').textContent = TW.dinero(imp / 100);
            total += imp;
        });
        document.getElementById('totalCompra').textContent = TW.dinero(total / 100);
        const hay = tbody.querySelectorAll('tr[data-id]').length > 0;
        document.getElementById('sinLineas').classList.toggle('d-none', hay);
        document.getElementById('btnGuardar').disabled = !hay;
    }

    function agregar(p, cantidad) {
        resultados.innerHTML = '';
        buscar.value = '';
        if (!p.controla || (p.tipo && p.tipo !== 'normal')) {
            TW.alerta('info', 'No lleva inventario propio', '«' + p.nombre + '» es un servicio, paquete o kit. Registra la compra del producto base.');
            return;
        }
        const existente = tbody.querySelector('tr[data-id="' + p.id + '"]');
        if (existente) { const c = existente.querySelector('.cant'); c.value = (Number(c.value) || 0) + 1; recalcular(); c.focus(); c.select(); return; }
        const i = n++;
        const tr = document.createElement('tr');
        tr.dataset.id = p.id;
        tr.innerHTML = '<td><input type="hidden" name="items[' + i + '][id]" value="' + p.id + '"><div class="fw-semibold">' + TW.escapar(p.nombre) + '</div>' +
            '<small class="text-body-secondary">' + TW.escapar(p.codigo || '') + '</small></td>' +
            '<td class="text-end num">' + TW.cantidad(p.existencia) + '</td>' +
            '<td><input type="number" class="form-control cant" name="items[' + i + '][cantidad]" min="0" step="' + (p.fraccion ? '0.001' : '1') + '" value="' + (cantidad || 1) + '" required></td>' +
            '<td><div class="input-group"><span class="input-group-text">$</span><input type="number" class="form-control costo" name="items[' + i + '][costo]" min="0" step="0.01" value="' +
            (p.costo !== null && p.costo !== undefined ? Number(p.costo).toFixed(2) : '') + '"></div></td>' +
            '<td class="text-end num importe"></td>' +
            '<td class="text-end"><button type="button" class="btn btn-sm btn-light border text-danger quitar" title="Quitar"><i class="bi bi-x-lg"></i></button></td>';
        tbody.appendChild(tr);
        tr.querySelectorAll('input').forEach(inp => {
            inp.addEventListener('input', recalcular);
            inp.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); buscar.focus(); } });
        });
        tr.querySelector('.quitar').addEventListener('click', () => { tr.remove(); recalcular(); });
        recalcular();
    }

    async function buscarTexto(texto) {
        texto = texto.trim();
        if (!texto) { return; }
        try {
            const r = await TW.json('ventas/buscar.php?codigo=' + encodeURIComponent(texto));
            if (r.producto) { agregar(r.producto); return; }
            const r2 = await TW.json('ventas/buscar.php?q=' + encodeURIComponent(texto));
            if (r2.productos.length === 1) { agregar(r2.productos[0]); return; }
            resultados.innerHTML = r2.productos.length
                ? r2.productos.map((p, k) => '<button type="button" class="list-group-item list-group-item-action" data-k="' + k + '">' + TW.escapar(p.nombre) +
                    ' <small class="text-body-secondary">· hay ' + TW.cantidad(p.existencia) + '</small></button>').join('')
                : '<div class="list-group-item text-body-secondary">No se encontró «' + TW.escapar(texto) + '».</div>';
            resultados.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => agregar(r2.productos[Number(b.dataset.k)])));
        } catch (e) { TW.alerta('error', 'No se pudo buscar', e.message); }
    }

    buscar.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); buscarTexto(buscar.value); } });
    document.getElementById('formCompra').addEventListener('keydown', ev => {
        if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && ev.target.type !== 'submit') { ev.preventDefault(); }
    });
    (<?= json_encode($sugeridos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>).forEach(p => agregar(p, p.cantidad));
    recalcular();
});
</script>
<?php require __DIR__ . '/../includes/pie.php'; ?>
