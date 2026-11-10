<?php
/**
 * Caja: abrir turno, entradas y salidas de efectivo, corte en vivo y cierre.
 */
require_once __DIR__ . '/../includes/app.php';

requerir_permiso('caja');

$turno = turno_abierto();
$r     = $turno ? resumen_turno($turno) : null;
$movs  = $turno ? movimientos_turno((int) $turno['id']) : [];
$cortes = db()->query('SELECT * FROM turnos WHERE cerrado_en IS NOT NULL ORDER BY id DESC LIMIT 10')->fetchAll();

$titulo  = 'Caja y cortes';
$seccion = 'caja';
require __DIR__ . '/../includes/encabezado.php';
$d = fn(int $centavos) => dinero($centavos / 100);
?>
<?php if (!$turno): ?>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h2><i class="bi bi-unlock me-2 text-primary"></i>Abrir caja</h2></div>
                <div class="card-body p-4">
                    <p>La caja está <strong>cerrada</strong>. Para empezar a vender, cuenta el dinero con el que empieza el cajón (el cambio) y escríbelo.</p>
                    <form method="post" action="<?= e(url('caja/acciones.php')) ?>">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="abrir">
                        <label for="fondo" class="form-label">¿Con cuánto cambio empiezas? <span class="text-body-secondary fw-normal">(cuenta el dinero del cajón)</span></label>
                        <div class="input-group input-group-lg mb-3">
                            <span class="input-group-text">$</span>
                            <input type="text" class="form-control num" id="fondo" name="fondo" inputmode="decimal" placeholder="0.00" autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-unlock me-1"></i>Abrir caja</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
<?php else: ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="text-body-secondary">
            <span class="badge text-bg-success me-1"><i class="bi bi-unlock me-1"></i>Caja abierta</span>
            Turno #<?= (int) $turno['id'] ?> desde <?= e(fecha_fmt($turno['abierto_en'])) ?>
            <?= $turno['abierto_por'] ? ' · abrió ' . e(nombre_usuario((int) $turno['abierto_por'])) : '' ?>
        </div>
        <div class="d-flex gap-2">
            <?php if (impresora_activa()): ?>
                <button type="button" class="btn btn-light border" id="btnCorteParcial"><i class="bi bi-printer me-1"></i>Imprimir corte parcial</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icono icono-verde"><i class="bi bi-cash-stack"></i></span>
                <div><div class="stat-valor num"><?= $d($r['esperado']) ?></div><div class="stat-etiqueta">Debe haber en el cajón</div></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icono icono-azul"><i class="bi bi-receipt"></i></span>
                <div><div class="stat-valor num"><?= $d($r['vendido']) ?></div><div class="stat-etiqueta"><?= (int) $r['operaciones'] ?> venta(s) en el turno</div></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icono icono-azul"><i class="bi bi-box-arrow-in-down"></i></span>
                <div><div class="stat-valor num"><?= $d($r['entradas'] + $r['abonos']['efectivo']) ?></div><div class="stat-etiqueta">Entradas y abonos en efectivo</div></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card tarjeta-stat h-100"><div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icono icono-rojo"><i class="bi bi-box-arrow-up"></i></span>
                <div><div class="stat-valor num"><?= $d($r['salidas']) ?></div><div class="stat-etiqueta">Salidas de efectivo</div></div>
            </div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header"><h2><i class="bi bi-calculator me-2 text-primary"></i>Así va la caja</h2></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td>Cambio con el que abriste</td><td class="text-end num"><?= $d($r['fondo']) ?></td></tr>
                        <tr><td>+ Ventas en efectivo (<?= (int) $r['ventas']['efectivo']['operaciones'] ?>)</td><td class="text-end num"><?= $d($r['ventas']['efectivo']['total']) ?></td></tr>
                        <tr><td>+ Abonos de clientes en efectivo</td><td class="text-end num"><?= $d($r['abonos']['efectivo']) ?></td></tr>
                        <tr><td>+ Entradas de efectivo</td><td class="text-end num"><?= $d($r['entradas']) ?></td></tr>
                        <tr><td>− Salidas de efectivo</td><td class="text-end num"><?= $d($r['salidas']) ?></td></tr>
                        <tr class="fw-bold"><td>= Debe haber en el cajón</td><td class="text-end num"><?= $d($r['esperado']) ?></td></tr>
                    </table>
                    <hr>
                    <div class="small text-body-secondary mb-1">Ventas que no son efectivo (no entran al cajón):</div>
                    <table class="table table-sm mb-0">
                        <?php foreach (['tarjeta', 'transferencia', 'credito'] as $m): ?>
                            <tr><td><?= e(METODOS_PAGO[$m]['etiqueta']) ?> (<?= (int) $r['ventas'][$m]['operaciones'] ?>)</td><td class="text-end num"><?= $d($r['ventas'][$m]['total']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if ($r['abonos']['tarjeta'] + $r['abonos']['transferencia'] > 0): ?>
                            <tr><td>Abonos con tarjeta o transferencia</td><td class="text-end num"><?= $d($r['abonos']['tarjeta'] + $r['abonos']['transferencia']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($r['descuentos'] > 0): ?>
                            <tr><td>Descuentos dados</td><td class="text-end num"><?= $d($r['descuentos']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($r['canceladas'] > 0): ?>
                            <tr><td>Ventas canceladas</td><td class="text-end"><?= (int) $r['canceladas'] ?></td></tr>
                        <?php endif; ?>
                        <?php if ($r['devuelto'] > 0): ?>
                            <tr><td>Devoluciones<?= $r['devoluciones']['efectivo'] > 0 ? ' <span class="small text-body-secondary">(las de efectivo ya van en salidas)</span>' : '' ?></td><td class="text-end num text-danger">−<?= $d($r['devuelto']) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="card border-primary-subtle">
                <div class="card-header"><h2><i class="bi bi-lock me-2 text-primary"></i>Cerrar caja (corte)</h2></div>
                <div class="card-body p-4">
                    <form method="post" action="<?= e(url('caja/acciones.php')) ?>" data-confirmar="¿Cerrar la caja?"
                          data-confirmar-detalle="Se guardará el corte del turno. Después de esto, para seguir vendiendo hay que abrir la caja de nuevo.">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="cerrar">
                        <label for="contado" class="form-label">Cuenta el efectivo del cajón: ¿cuánto hay?</label>
                        <div class="input-group input-group-lg mb-2">
                            <span class="input-group-text">$</span>
                            <input type="text" class="form-control num" id="contado" name="contado" inputmode="decimal" placeholder="0.00" required>
                        </div>
                        <div class="cambio-caja d-flex justify-content-between align-items-center mb-3" id="cajaDiferencia">
                            <span class="fw-semibold" id="etiquetaDiferencia">Diferencia</span>
                            <span class="monto num" id="diferencia">—</span>
                        </div>
                        <textarea class="form-control mb-3" name="notas" rows="2" maxlength="255" placeholder="Notas (opcional): por qué sobra o falta…"></textarea>
                        <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-lock me-1"></i>Hacer corte y cerrar caja</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header"><h2><i class="bi bi-arrow-down-up me-2 text-primary"></i>Entrada o salida de efectivo</h2></div>
                <div class="card-body p-4">
                    <form method="post" action="<?= e(url('caja/acciones.php')) ?>" class="row g-2 align-items-end">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="movimiento">
                        <div class="col-12 d-flex gap-2" role="radiogroup">
                            <input type="radio" class="btn-check" name="tipo" id="tipo_salida" value="salida" checked>
                            <label class="btn btn-outline-danger flex-fill" for="tipo_salida"><i class="bi bi-box-arrow-up me-1"></i>Salida (retiro, pago, gasto)</label>
                            <input type="radio" class="btn-check" name="tipo" id="tipo_entrada" value="entrada">
                            <label class="btn btn-outline-success flex-fill" for="tipo_entrada"><i class="bi bi-box-arrow-in-down me-1"></i>Entrada (más cambio)</label>
                        </div>
                        <div class="col-sm-4">
                            <label for="monto" class="form-label small mb-1">Monto</label>
                            <div class="input-group"><span class="input-group-text">$</span>
                                <input type="text" class="form-control num" id="monto" name="monto" inputmode="decimal" required></div>
                        </div>
                        <div class="col-sm-5">
                            <label for="concepto" class="form-label small mb-1">Concepto</label>
                            <input type="text" class="form-control" id="concepto" name="concepto" maxlength="120" required placeholder="Ej. Pago al proveedor de refrescos" list="conceptosComunes">
                            <datalist id="conceptosComunes">
                                <option value="Retiro del dueño"><option value="Pago a proveedor"><option value="Compra de insumos">
                                <option value="Pago de servicios"><option value="Más cambio para la caja">
                            </datalist>
                        </div>
                        <div class="col-sm-3"><button type="submit" class="btn btn-primary w-100">Registrar</button></div>
                    </form>
                </div>
                <?php if ($movs): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Hora</th><th>Concepto</th><th>Usuario</th><th class="text-end">Monto</th></tr></thead>
                            <tbody>
                            <?php foreach ($movs as $mv): ?>
                                <tr>
                                    <td class="num"><?= e(date('h:i a', strtotime($mv['fecha']))) ?></td>
                                    <td><?= e($mv['concepto']) ?></td>
                                    <td class="small text-body-secondary"><?= e(nombre_usuario($mv['usuario_id'] !== null ? (int) $mv['usuario_id'] : null)) ?></td>
                                    <td class="text-end num fw-semibold <?= $mv['tipo'] === 'entrada' ? 'text-success' : 'text-danger' ?>">
                                        <?= $mv['tipo'] === 'entrada' ? '+' : '−' ?><?= dinero($mv['monto']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
<?php endif; ?>

            <div class="card">
                <div class="card-header"><h2><i class="bi bi-clock-history me-2 text-primary"></i>Cortes anteriores</h2></div>
                <?php if (!$cortes): ?>
                    <div class="card-body text-body-secondary">Todavía no hay cortes.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Turno</th><th>Cerró</th><th class="text-end">Debía haber</th><th class="text-end">Contado</th><th>Resultado</th></tr></thead>
                            <tbody>
                            <?php foreach ($cortes as $c):
                                $dif = a_centavos($c['efectivo_contado']) - a_centavos($c['efectivo_esperado']); ?>
                                <tr class="fila-enlace" data-href="<?= e(url('caja/corte.php?id=' . (int) $c['id'])) ?>">
                                    <td class="fw-semibold">#<?= (int) $c['id'] ?></td>
                                    <td class="num small"><?= e(fecha_fmt($c['cerrado_en'])) ?></td>
                                    <td class="text-end num"><?= dinero($c['efectivo_esperado']) ?></td>
                                    <td class="text-end num"><?= dinero($c['efectivo_contado']) ?></td>
                                    <td><span class="badge <?= $dif === 0 ? 'text-bg-success' : ($dif > 0 ? 'text-bg-info' : 'text-bg-danger') ?>"><?= e(diferencia_texto($dif)) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php if ($turno): ?>
<script>
(function () {
    const esperado = <?= (int) $r['esperado'] ?>;
    const contado = document.getElementById('contado');
    const caja = document.getElementById('cajaDiferencia');
    const etiqueta = document.getElementById('etiquetaDiferencia');
    const dif = document.getElementById('diferencia');
    contado.addEventListener('input', function () {
        const t = contado.value.trim().replace(/[\s$,]/g, '');
        if (t === '' || isNaN(Number(t))) { dif.textContent = '—'; etiqueta.textContent = 'Diferencia'; caja.classList.remove('falta'); return; }
        const d = Math.round(Number(t) * 100) - esperado;
        etiqueta.textContent = d === 0 ? 'Cuadra exacto' : (d > 0 ? 'Sobran' : 'Faltan');
        dif.textContent = TW.dinero(Math.abs(d) / 100);
        caja.classList.toggle('falta', d < 0);
    });
    const btn = document.getElementById('btnCorteParcial');
    if (btn) {
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            try {
                await TW.json('ventas/impresora.php', { method: 'POST', body: { accion: 'corte', id: <?= (int) $turno['id'] ?> } });
                TW.toast('success', 'Corte parcial enviado a la impresora.');
            } catch (e) { TW.alerta('error', 'No se pudo imprimir', e.message); }
            btn.disabled = false;
        });
    }
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/pie.php'; ?>
