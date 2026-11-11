<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Panel principal (dashboard). Todos los indicadores salen de la base de datos.
 */
require_once __DIR__ . '/includes/app.php';

$hoy = date('Y-m-d');
[$inicioHoy, $finHoy] = rango_sql($hoy, $hoy);
[$inicioAyer, $finAyer] = rango_sql(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')));

$st = db()->prepare("SELECT COUNT(*) AS ventas, COALESCE(SUM(total), 0) AS total
                     FROM ventas WHERE estado = 'completada' AND fecha >= :inicio AND fecha < :fin");
$st->execute([':inicio' => $inicioHoy, ':fin' => $finHoy]);
$deHoy = $st->fetch();
$st->execute([':inicio' => $inicioAyer, ':fin' => $finAyer]);
$deAyer = $st->fetch();

$productosActivos = (int) db()->query('SELECT COUNT(*) FROM productos WHERE activo = 1')->fetchColumn();

$bajos = db()->query('SELECT id, nombre, existencia, stock_minimo
                      FROM productos
                      WHERE activo = 1 AND controlar_stock = 1 AND existencia <= stock_minimo
                      ORDER BY (existencia / NULLIF(stock_minimo, 0)) ASC, existencia ASC, sin_acentos(nombre)
                      LIMIT 10')->fetchAll();
$totalBajos = (int) db()->query('SELECT COUNT(*) FROM productos
                                 WHERE activo = 1 AND controlar_stock = 1 AND existencia <= stock_minimo')->fetchColumn();

$ultimas = db()->query('SELECT id, fecha, total, metodo_pago, estado FROM ventas ORDER BY id DESC LIMIT 8')->fetchAll();

// Ventas de los últimos 7 días para la gráfica.
[$inicio7, $fin7] = rango_sql(date('Y-m-d', strtotime('-6 days')), $hoy);
$st = db()->prepare("SELECT DATE(fecha) AS dia, COALESCE(SUM(total), 0) AS total
                     FROM ventas WHERE estado = 'completada' AND fecha >= :inicio AND fecha < :fin
                     GROUP BY DATE(fecha)");
$st->execute([':inicio' => $inicio7, ':fin' => $fin7]);
$porDia = array_column($st->fetchAll(), 'total', 'dia');
$dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$grafica = ['etiquetas' => [], 'valores' => []];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $grafica['etiquetas'][] = $dias[(int) date('w', strtotime($d))] . ' ' . date('d', strtotime($d));
    $grafica['valores'][]   = round((float) ($porDia[$d] ?? 0), 2);
}

$ticketPromedio = $deHoy['ventas'] > 0 ? $deHoy['total'] / $deHoy['ventas'] : 0;
$variacion = null;
if ((float) $deAyer['total'] > 0) {
    $variacion = (($deHoy['total'] - $deAyer['total']) / $deAyer['total']) * 100;
}

$titulo  = 'Inicio';
$seccion = 'inicio';
$scripts = ['assets/vendor/chartjs/chart.umd.min.js'];
$turnoAbierto = turno_abierto();
$teDeben = permiso('credito') ? (float) db()->query('SELECT COALESCE(SUM(' . SQL_SALDO_CLIENTE . '), 0) FROM clientes c')->fetchColumn() : 0;
require __DIR__ . '/includes/encabezado.php';

// Botones grandes: lo que se hace todos los días, según los permisos de quien entró.
$botones = [];
$botones[] = ['ventas/index.php', 'bi-cart3', 'Vender', 'Cobrar a un cliente', 'principal'];
if (permiso('productos')) {
    $botones[] = ['productos/crear.php', 'bi-plus-square', 'Agregar producto', 'Algo nuevo que vas a vender', ''];
}
if (permiso('inventario')) {
    $botones[] = ['inventario/entrada.php?modo=entrada', 'bi-box-arrow-in-down', 'Llegó mercancía', 'Sumar lo que te surtieron', ''];
}
if (permiso('caja')) {
    $botones[] = ['caja/index.php', 'bi-cash-coin', $turnoAbierto ? 'Cerrar el día' : 'Caja', $turnoAbierto ? 'Contar el dinero y hacer el corte' : 'Abrir o ver los cortes', ''];
}

// Primeros pasos: se esconden solos cuando ya se hicieron todos.
$pasos = [
    ['Ponle nombre a tu negocio', ajuste('negocio_nombre') !== APP_NEGOCIO, 'ajustes.php', es_dueno()],
    ['Agrega tus productos', $productosActivos > 0, 'productos/crear.php', permiso('productos')],
    ['Abre la caja con el cambio que tienes', (int) db()->query('SELECT COUNT(*) FROM turnos')->fetchColumn() > 0, 'ventas/index.php', true],
    ['Haz tu primera venta', (int) db()->query("SELECT COUNT(*) FROM ventas")->fetchColumn() > 0, 'ventas/index.php', true],
    ['Al final del día, haz el corte', (int) db()->query('SELECT COUNT(*) FROM turnos WHERE cerrado_en IS NOT NULL')->fetchColumn() > 0, 'caja/index.php', permiso('caja')],
];
$pasos = array_values(array_filter($pasos, fn($p) => $p[3]));
$pendientes = array_filter($pasos, fn($p) => !$p[1]);
$siguiente = $pendientes ? array_key_first($pendientes) : null;
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php if ($turnoAbierto): ?>
        <span class="badge rounded-pill text-bg-success-subtle text-success-emphasis border px-3 py-2">
            <i class="bi bi-unlock me-1"></i>Caja abierta desde las <?= e(date('h:i a', strtotime($turnoAbierto['abierto_en']))) ?>
        </span>
    <?php else: ?>
        <a href="<?= e(url('ventas/index.php')) ?>" class="badge rounded-pill text-bg-warning-subtle text-warning-emphasis border px-3 py-2 text-decoration-none">
            <i class="bi bi-lock me-1"></i>La caja está cerrada · se abre al entrar a Vender
        </a>
    <?php endif; ?>
    <?php if ($teDeben > 0.004): ?>
        <a href="<?= e(url('clientes/index.php?filtro=deben')) ?>" class="badge rounded-pill text-bg-danger-subtle text-danger-emphasis border px-3 py-2 text-decoration-none">
            <i class="bi bi-wallet2 me-1"></i>Te deben <?= e(dinero($teDeben)) ?>
        </a>
    <?php endif; ?>
    <?php if (puede_administrar_facturas() && ($porFacturar = facturas_pendientes())): ?>
        <a href="<?= e(url('facturas/index.php')) ?>" class="badge rounded-pill text-bg-warning-subtle text-warning-emphasis border px-3 py-2 text-decoration-none">
            <i class="bi bi-receipt-cutoff me-1"></i><?= $porFacturar ?> factura(s) por hacer
        </a>
    <?php endif; ?>
    <?php if (es_dueno() && !hay_usuarios()): ?>
        <a href="<?= e(url('usuarios.php')) ?>" class="badge rounded-pill text-bg-light border px-3 py-2 text-decoration-none">
            <i class="bi bi-person-lock me-1"></i>Protege el sistema con un PIN
        </a>
    <?php endif; ?>
</div>

<h2 class="h4 fw-bold mb-3">¿Qué quieres hacer?</h2>
<div class="row g-3 mb-4">
    <?php foreach ($botones as $i => [$ruta, $icono, $etiqueta, $ayuda, $clase]): ?>
        <div class="<?= $i === 0 ? 'col-12' : 'col-sm' ?>">
            <a href="<?= e(url($ruta)) ?>" class="boton-grande <?= $clase ?>">
                <i class="bi <?= e($icono) ?>"></i>
                <span class="boton-grande-texto"><?= e($etiqueta) ?></span>
                <small><?= e($ayuda) ?></small>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($pendientes): ?>
    <div class="card mb-4 border-primary-subtle">
        <div class="card-header"><h2><i class="bi bi-signpost-split me-2 text-primary"></i>Primeros pasos</h2>
            <span class="small text-body-secondary"><?= count($pasos) - count($pendientes) ?> de <?= count($pasos) ?> listos</span></div>
        <ol class="list-group list-group-flush list-group-numbered">
            <?php foreach ($pasos as $i => [$texto, $hecho, $ruta]): ?>
                <li class="list-group-item d-flex align-items-center gap-2 <?= $hecho ? 'text-body-secondary' : '' ?>">
                    <span class="flex-grow-1 <?= $hecho ? 'text-decoration-line-through' : ($i === $siguiente ? 'fw-semibold' : '') ?>"><?= e($texto) ?></span>
                    <?php if ($hecho): ?>
                        <i class="bi bi-check-circle-fill text-success fs-5"></i>
                    <?php elseif ($i === $siguiente): ?>
                        <a href="<?= e(url($ruta)) ?>" class="btn btn-primary btn-sm">Hacerlo ahora <i class="bi bi-arrow-right"></i></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card tarjeta-stat h-100"><div class="card-body">
            <span class="stat-icono icono-azul mb-3"><i class="bi bi-cash-stack"></i></span>
            <div class="stat-valor num"><?= dinero($deHoy['total']) ?></div>
            <div class="stat-etiqueta">Vendiste hoy
                <?php if ($variacion !== null): ?><span class="<?= $variacion >= 0 ? 'text-success' : 'text-danger' ?>">· <?= $variacion >= 0 ? 'más' : 'menos' ?> que ayer</span><?php endif; ?></div>
        </div></div>
    </div>
    <div class="col-sm-4">
        <div class="card tarjeta-stat h-100"><div class="card-body">
            <span class="stat-icono icono-verde mb-3"><i class="bi bi-receipt"></i></span>
            <div class="stat-valor num"><?= (int) $deHoy['ventas'] ?></div>
            <div class="stat-etiqueta">Ventas de hoy</div>
        </div></div>
    </div>
    <div class="col-sm-4">
        <a href="<?= e(url('productos/index.php?filtro=bajo')) ?>" class="text-decoration-none text-reset">
        <div class="card tarjeta-stat h-100"><div class="card-body">
            <span class="stat-icono <?= $bajos ? 'icono-naranja' : 'icono-verde' ?> mb-3"><i class="bi bi-exclamation-triangle"></i></span>
            <div class="stat-valor num"><?= $totalBajos ?></div>
            <div class="stat-etiqueta"><?= $totalBajos ? 'Se están acabando' : 'Nada se está acabando' ?></div>
        </div></div>
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-graph-up me-2 text-primary"></i>Ventas de los últimos 7 días</h2>
                <a href="<?= e(url('reportes/index.php')) ?>" class="small">Ver reportes</a>
            </div>
            <div class="card-body"><div style="height:260px"><canvas id="graficaSemana" aria-label="Ventas por día"></canvas></div></div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-clock-history me-2 text-primary"></i>Últimas ventas</h2>
                <a href="<?= e(url('ventas/historial.php')) ?>" class="small">Ver historial</a>
            </div>
            <?php if (!$ultimas): ?>
                <div class="estado-vacio"><i class="bi bi-receipt"></i>Aún no hay ventas. ¡Haz la primera desde el punto de venta!</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Folio</th><th>Fecha</th><th>Método</th><th>Estado</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($ultimas as $v): ?>
                            <tr class="fila-enlace<?= $v['estado'] === 'cancelada' ? ' fila-inactiva' : '' ?>" data-href="<?= e(url('ventas/detalle.php?id=' . (int) $v['id'])) ?>">
                                <td class="fw-semibold num">#<?= e(folio($v['id'])) ?></td>
                                <td class="num"><?= e(fecha_fmt($v['fecha'])) ?></td>
                                <td><?= metodo_pago_badge($v['metodo_pago']) ?></td>
                                <td><?= estado_venta_badge($v['estado']) ?></td>
                                <td class="text-end fw-bold num"><?= dinero($v['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Existencias bajas</h2>
                <a href="<?= e(url('productos/index.php?filtro=bajo')) ?>" class="small">Ver todos</a>
            </div>
            <?php if (!$bajos): ?>
                <div class="estado-vacio"><i class="bi bi-check2-circle text-success"></i>Todo en orden: ningún producto está por debajo de su mínimo.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($bajos as $b): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-3">
                            <a href="<?= e(url('productos/editar.php?id=' . (int) $b['id'])) ?>" class="text-reset text-decoration-none">
                                <span class="fw-semibold d-block"><?= e($b['nombre']) ?></span>
                                <small class="text-body-secondary">Mínimo: <?= e(cantidad_fmt($b['stock_minimo'])) ?></small>
                            </a>
                            <span class="badge <?= (float) $b['existencia'] <= 0 ? 'text-bg-danger' : 'text-bg-warning' ?> fs-6 num"><?= e(cantidad_fmt($b['existencia'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const datos = <?= json_encode($grafica, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    if (!window.Chart) { return; }
    new Chart(document.getElementById('graficaSemana'), {
        type: 'bar',
        data: {
            labels: datos.etiquetas,
            datasets: [{ label: 'Ventas', data: datos.valores, backgroundColor: '#1d5fd1', borderRadius: 8, maxBarThickness: 48 }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => TW.dinero(c.parsed.y) } } },
            scales: {
                y: { beginAtZero: true, ticks: { callback: (v) => TW.dinero(v).replace('.00', '') }, grid: { color: '#eef2f7' } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>

<?php require __DIR__ . '/includes/pie.php'; ?>
