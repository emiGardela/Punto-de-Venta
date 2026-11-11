<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Pestañas de "Ventas hechas": las ventas y las facturas que pidieron los clientes.
 */
if (!puede_administrar_facturas()) {
    return;
}
$pestanaActual = basename(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$porHacer = facturas_pendientes();
$porCancelar = facturas_por_cancelar_en_sat();
?>
<ul class="nav nav-pills gap-1 mb-4 no-imprimir">
    <li class="nav-item"><a class="nav-link<?= $pestanaActual === 'ventas' ? ' active' : '' ?>" href="<?= e(url('ventas/historial.php')) ?>"><i class="bi bi-receipt me-1"></i>Ventas</a></li>
    <li class="nav-item"><a class="nav-link<?= $pestanaActual === 'facturas' ? ' active' : '' ?>" href="<?= e(url('facturas/index.php')) ?>"><i class="bi bi-receipt-cutoff me-1"></i>Facturas
        <?php if ($porHacer): ?><span class="badge rounded-pill text-bg-warning ms-1"><?= $porHacer ?> por hacer</span><?php endif; ?>
        <?php if ($porCancelar): ?><span class="badge rounded-pill text-bg-danger ms-1"><?= $porCancelar ?> por cancelar en el SAT</span><?php endif; ?></a></li>
</ul>
