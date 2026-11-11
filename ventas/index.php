<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Punto de venta: búsqueda (teclado o lector), carrito y cobro.
 * Toda la interacción está en assets/js/ventas.js.
 */
require_once __DIR__ . '/../includes/app.php';

// Accesos rápidos: los más vendidos; sin ventas todavía, primero lo que no tiene código de barras.
$rapidos = db()->query("SELECT p.id, p.nombre, p.codigo_barras, p.precio, p.existencia,
                               p.controlar_stock, p.permite_fraccion, p.activo
                        FROM productos p
                        LEFT JOIN (
                            SELECT producto_id, COUNT(*) AS veces
                            FROM detalle_ventas
                            WHERE producto_id IS NOT NULL
                            GROUP BY producto_id
                        ) u ON u.producto_id = p.id
                        WHERE p.activo = 1
                        ORDER BY COALESCE(u.veces, 0) DESC, p.codigo_barras IS NOT NULL, sin_acentos(p.nombre) ASC
                        LIMIT 18")->fetchAll();

$escalonesRapidos = escalones_de(array_map(fn($p) => (int) $p['id'], $rapidos));
$dispRapidos = disponibilidad(array_map(fn($p) => (int) $p['id'], $rapidos));   // paquetes y kits: según lo que contienen
foreach ($rapidos as &$r) {
    $r['existencia'] = $dispRapidos[(int) $r['id']]['existencia'] ?? $r['existencia'];
    $r['controlar_stock'] = ($dispRapidos[(int) $r['id']]['controla'] ?? $r['controlar_stock']) ? 1 : 0;
}
unset($r);
$rapidosJson = array_map(fn($p) => [
    'id'         => (int) $p['id'],
    'nombre'     => $p['nombre'],
    'codigo'     => $p['codigo_barras'],
    'precio'     => (float) $p['precio'],
    'precios'    => array_map(fn($e) => [$e[0] / 1000, $e[1] / 100], $escalonesRapidos[(int) $p['id']] ?? []),
    'existencia' => (float) $p['existencia'],
    'controla'   => (bool) $p['controlar_stock'],
    'fraccion'   => (bool) $p['permite_fraccion'],
    'activo'     => true,
], $rapidos);

$turno = turno_abierto();
$metodos = array_filter(METODOS_PAGO, fn($k) => $k !== 'credito' || permiso('credito'), ARRAY_FILTER_USE_KEY);
// Cualquier venta puede llevar cliente (sale en el ticket); en crédito es obligatorio.
$clientes = db()->query('SELECT c.id, c.nombre, c.limite_credito, ' . SQL_SALDO_CLIENTE . ' AS saldo
                         FROM clientes c WHERE c.activo = 1 ORDER BY sin_acentos(c.nombre)')->fetchAll();
$clientesJson = array_map(fn($c) => [
    'id' => (int) $c['id'], 'nombre' => $c['nombre'], 'saldo' => round((float) $c['saldo'], 2),
    'limite' => $c['limite_credito'] !== null ? (float) $c['limite_credito'] : null,
], $clientes);

// Listas escolares activas, para cargarlas al carrito con un clic.
$listasEscolares = [];
foreach (!usa_listas_escolares() ? [] : db()->query('SELECT l.id, l.nombre, l.escuela, lp.producto_id, lp.cantidad FROM listas l
                      JOIN lista_productos lp ON lp.lista_id = l.id WHERE l.activo = 1
                      ORDER BY sin_acentos(COALESCE(l.escuela, \'\')), sin_acentos(l.nombre), lp.id') as $f) {
    $listasEscolares[(int) $f['id']] ??= ['id' => (int) $f['id'], 'nombre' => $f['nombre'], 'escuela' => $f['escuela'], 'items' => []];
    $listasEscolares[(int) $f['id']]['items'][] = [(int) $f['producto_id'], (float) $f['cantidad']];
}

$titulo  = 'Punto de venta';
$seccion = 'pos';
$scripts = ['assets/js/ventas.js'];
require __DIR__ . '/../includes/encabezado.php';
?>
<div class="row g-4">
    <!-- ================= Columna izquierda: búsqueda ================= -->
    <div class="col-lg-7 col-xl-8">
        <div class="card mb-4">
            <div class="card-body p-3 p-md-4">
                <label for="buscar" class="form-label"><i class="bi bi-upc-scan me-1 text-primary"></i>Escanea o busca un producto</label>
                <div class="input-group input-group-lg pos-busqueda">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="search" id="buscar" class="form-control" autocomplete="off" autofocus
                           placeholder="Escanea o escribe el nombre" maxlength="100">
                    <button class="btn btn-primary px-4" type="button" id="btnBuscar"><i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline ms-1">Agregar</span></button>
                </div>
                <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                    <a href="<?= e(url('ventas/historial.php')) ?>" class="btn btn-sm btn-light border mt-2"><i class="bi bi-receipt me-1"></i>Ventas hechas</a>
                    <?php if ($listasEscolares && funciones_avanzadas()): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btnLista"><i class="bi bi-card-checklist me-1"></i>Lista escolar</button>
                    <?php endif; ?>
                </div>
            </div>
            <div id="resultados" class="pos-resultados border-top d-none" role="listbox" aria-label="Resultados de búsqueda"></div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-lightning-charge me-2 text-primary"></i>Más vendidos</h2>
                <a href="<?= e(url('productos/crear.php')) ?>" class="btn btn-sm btn-light border"><i class="bi bi-plus-lg me-1"></i>Nuevo</a>
            </div>
            <div class="card-body">
                <?php if (!$rapidos): ?>
                    <p class="text-body-secondary mb-0">Aquí aparecerán tus productos para agregarlos con un clic.</p>
                <?php else: ?>
                    <div class="servicios-rapidos" id="rapidos">
                        <?php foreach ($rapidos as $i => $p): ?>
                            <button type="button" class="btn-servicio" data-indice="<?= (int) $i ?>">
                                <span class="nombre"><?= e($p['nombre']) ?></span>
                                <span class="d-flex justify-content-between align-items-end mt-1">
                                    <span class="precio"><?= dinero($p['precio']) ?></span>
                                    <?php if ($p['controlar_stock']): ?>
                                        <small class="text-body-secondary"><?= e(cantidad_fmt($p['existencia'])) ?> disp.</small>
                                    <?php else: ?>
                                        <small class="text-body-secondary"><i class="bi bi-infinity"></i></small>
                                    <?php endif; ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================= Columna derecha: carrito ================= -->
    <div class="col-lg-5 col-xl-4">
        <div class="card carrito overflow-hidden">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-cart3 me-2 text-primary"></i>Venta actual <span class="badge rounded-pill text-bg-primary ms-1" id="contador">0</span></h2>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-light border" id="btnEspera" title="Dejar esta venta en espera para atender a otro cliente (F4)" disabled><i class="bi bi-pause-circle me-1"></i>Espera</button>
                    <button type="button" class="btn btn-sm btn-light border d-none" id="btnRecuperar" title="Recuperar una venta en espera"><i class="bi bi-play-circle me-1"></i><span class="badge text-bg-warning" id="numEspera">0</span></button>
                    <?php if (impresora_activa() && ajuste('cajon_al_cobrar') !== 'nunca'): ?>
                        <button type="button" class="btn btn-sm btn-light border" id="btnCajon" title="Abrir el cajón de dinero"><i class="bi bi-inbox me-1"></i>Cajón</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-light border text-danger" id="btnVaciar" disabled><i class="bi bi-trash3 me-1"></i>Vaciar</button>
                </div>
            </div>
            <div class="carrito-lista" id="carrito" aria-live="polite">
                <div class="estado-vacio" id="carritoVacio">
                    <i class="bi bi-cart"></i>
                    El carrito está vacío.<br><small>Escanea o busca un producto para empezar.</small>
                </div>
            </div>
            <div class="carrito-total">
                <span class="opacity-75">Total a cobrar</span>
                <span class="monto num" id="total">$0.00</span>
            </div>
            <div class="p-3 bg-white">
                <button type="button" class="btn btn-primary btn-cobrar w-100" id="btnCobrar" disabled>
                    <i class="bi bi-cash-stack me-2"></i>Cobrar <kbd class="ms-2 bg-transparent border border-light-subtle small">F9</kbd>
                </button>
            </div>
        </div>
    </div>
</div>

<?php if (!$turno): ?>
<!-- ================= Caja cerrada: hay que abrirla para vender ================= -->
<div class="modal fade" id="modalAbrirCaja" tabindex="-1" aria-labelledby="tituloAbrirCaja" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 rounded-4" method="post" action="<?= e(url('caja/acciones.php')) ?>">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="abrir">
            <input type="hidden" name="volver" value="pos">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="tituloAbrirCaja"><i class="bi bi-unlock me-2 text-primary"></i>Abrir caja</h2>
            </div>
            <div class="modal-body px-4">
                <p class="text-body-secondary">Antes de vender, cuenta el dinero con el que empieza el cajón (el cambio).</p>
                <label for="fondoInicial" class="form-label">¿Con cuánto cambio empiezas? <span class="text-body-secondary fw-normal">(cuenta el dinero del cajón)</span></label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text">$</span>
                    <input type="text" class="form-control num" id="fondoInicial" name="fondo" inputmode="decimal" placeholder="0.00">
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0">
                <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-unlock me-1"></i>Abrir caja y empezar a vender</button>
                <button type="submit" class="btn btn-light border btn-lg w-100" name="fondo" value="0">Empezar sin cambio</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ================= Modal de cobro ================= -->
<div class="modal fade" id="modalCobro" tabindex="-1" aria-labelledby="tituloCobro" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="tituloCobro"><i class="bi bi-cash-stack me-2 text-primary"></i>Cobrar venta</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body px-4">
                <div class="text-center bg-primary-suave rounded-4 py-3 mb-3">
                    <div class="text-body-secondary small fw-semibold text-uppercase">Total</div>
                    <div class="cobro-total num" id="cobroTotal">$0.00</div>
                    <div class="small text-body-secondary" id="cobroArticulos"></div>
                    <div class="small fw-semibold text-success d-none" id="cobroDescuento"></div>
                </div>

                <?php if (permiso('descuentos')): ?>
                    <div class="mb-3 d-inline-block">
                        <a href="#" class="small" id="lnkDescuento"><i class="bi bi-percent me-1"></i>Agregar descuento</a>
                        <div class="input-group d-none mt-2" id="grupoDescuento">
                            <span class="input-group-text">Descuento</span>
                            <input type="text" class="form-control num" id="descuentoValor" inputmode="decimal" placeholder="0">
                            <select class="form-select flex-grow-0 w-auto" id="descuentoTipo">
                                <option value="monto">$</option>
                                <option value="porcentaje">%</option>
                            </select>
                            <button class="btn btn-light border" type="button" id="btnQuitarDescuento" title="Quitar descuento"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($clientes): ?>
                    <a href="#" class="small d-inline-block mb-3 ms-3" id="lnkCliente"><i class="bi bi-person me-1"></i>Poner nombre del cliente</a>
                <?php endif; ?>
                <div class="mb-3 d-none" id="bloqueCliente">
                    <label for="cliente" class="form-label mb-1">Cliente <span class="text-body-secondary fw-normal small" id="clienteAyuda">(opcional)</span></label>
                    <select class="form-select" id="cliente">
                        <option value="">Público en general</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?><?= (float) $c['saldo'] > 0 ? ' · debe ' . e(dinero($c['saldo'])) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="clienteInfo"></div>
                </div>

                <div class="mb-4">
                    <div class="form-label">Método de pago</div>
                    <div class="metodos-pago" role="radiogroup">
                        <?php $primero = true; foreach ($metodos as $clave => $m): ?>
                            <input type="radio" class="btn-check" name="metodo_pago" id="metodo_<?= e($clave) ?>" value="<?= e($clave) ?>" <?= $primero ? 'checked' : '' ?>>
                            <label class="btn btn-metodo" for="metodo_<?= e($clave) ?>"><i class="bi <?= e($m['icono']) ?>"></i><?= e($m['etiqueta']) ?></label>
                        <?php $primero = false; endforeach; ?>
                        <input type="radio" class="btn-check" name="metodo_pago" id="metodo_mixto" value="mixto">
                        <label class="btn btn-metodo d-none" for="metodo_mixto" id="opcionDividir"><i class="bi bi-intersect"></i>Dividir pago</label>
                    </div>
                    <a href="#" class="small d-inline-block mt-2" id="lnkMasOpciones"><i class="bi bi-three-dots me-1"></i>Más opciones</a>
                </div>

                <div id="seccionEfectivo">
                    <label for="recibido" class="form-label">¿Con cuánto te pagó?</label>
                    <div class="input-group input-group-lg mb-2">
                        <span class="input-group-text fw-bold">$</span>
                        <input type="text" class="form-control input-recibido num" id="recibido" inputmode="decimal" autocomplete="off" placeholder="0.00">
                    </div>
                    <div class="billetes mb-3" id="billetes"></div>
                    <div class="cambio-caja d-flex justify-content-between align-items-center" id="cajaCambio">
                        <span class="fw-semibold" id="etiquetaCambio">Cambio</span>
                        <span class="monto num" id="cambio">$0.00</span>
                    </div>
                </div>

                <div id="seccionDividido" class="d-none">
                    <div class="small text-body-secondary mb-2">Escribe cuánto paga con cada forma. En efectivo, lo que te entrega (el sistema calcula el cambio).</div>
                    <?php foreach ($metodos as $clave => $m): ?>
                        <div class="input-group mb-2">
                            <span class="input-group-text" style="width:150px"><i class="bi <?= e($m['icono']) ?> me-2"></i><?= e($m['etiqueta']) ?></span>
                            <span class="input-group-text">$</span>
                            <input type="text" class="form-control num pago-dividido" data-metodo="<?= e($clave) ?>" inputmode="decimal" placeholder="0.00">
                        </div>
                    <?php endforeach; ?>
                    <div class="cambio-caja d-flex justify-content-between align-items-center" id="cajaDividido">
                        <span class="fw-semibold" id="etiquetaDividido">Falta</span>
                        <span class="monto num" id="montoDividido">—</span>
                    </div>
                </div>

                <div id="seccionOtro" class="alert alert-info d-none mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Confirma que el pago por <strong id="nombreMetodo"></strong> se recibió correctamente.
                    El sistema solo registra el método; no realiza cargos bancarios.
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0 gap-2">
                <button type="button" class="btn btn-light border btn-lg flex-fill" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success btn-lg flex-fill fw-bold" id="btnConfirmar" disabled>
                    <i class="bi bi-check2-circle me-1"></i>Confirmar cobro
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    window.TIENDAWEB_POS = {
        rapidos: <?= json_encode($rapidosJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        impresora: <?= json_encode(['activa' => impresora_activa(), 'imprimir' => ajuste('imprimir_al_cobrar')]) ?>,
        clientes: <?= json_encode($clientesJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        cajaAbierta: <?= $turno ? 'true' : 'false' ?>,
        listas: <?= json_encode(array_values($listasEscolares), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
</script>
<?php require __DIR__ . '/../includes/pie.php'; ?>
