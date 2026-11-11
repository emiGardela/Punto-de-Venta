<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Ayuda: cómo se usa el sistema, en pasos cortos y sin palabras técnicas.
 */
require_once __DIR__ . '/includes/app.php';

// [icono, título, pasos]
$temas = [
    ['bi-cart3', 'Vender', [
        'Entra a <strong>Vender</strong>. Si la caja está cerrada, escribe cuánto cambio tienes en el cajón y dale a <strong>Abrir caja y empezar a vender</strong>.',
        'Pasa el producto por el lector de códigos. Si no tiene código, escribe su nombre y elígelo de la lista, o tócalo en los botones de abajo.',
        'Para vender varios, cambia el número de la cantidad. Para quitar uno, toca la ✕ roja junto a él.',
        'Dale al botón grande <strong>Cobrar</strong> (o la tecla F9).',
        'Elige cómo paga. Si es en efectivo, escribe con cuánto te pagó: el sistema te dice cuánto cambio dar.',
        'Dale a <strong>Confirmar cobro</strong>. ¡Listo! Sale el ticket.',
    ]],
    ['bi-arrow-counterclockwise', 'Me equivoqué o el cliente regresó algo', [
        'En <strong>Vender</strong>, entra a <strong>Ventas hechas</strong> y toca la venta.',
        'Si el cliente regresa una parte, dale a <strong>Devolver productos</strong>, escribe cuántos regresa y por qué.',
        'Si toda la venta estuvo mal, dale a <strong>Cancelar venta</strong> y escribe el motivo. Los productos regresan solos al inventario.',
    ]],
    ['bi-plus-square', 'Agregar un producto nuevo', [
        'Entra a <strong>Productos</strong> y dale a <strong>Nuevo producto</strong> (o en Inicio, <strong>Agregar producto</strong>).',
        'Escribe el nombre y el precio. Si tiene código de barras, haz clic en "Código de barras" y pásalo por el lector.',
        'Escribe cuántos tienes. Dale a <strong>Guardar producto</strong>.',
        'Para servicios (copias, entregas a domicilio), apaga "Llevar la cuenta de cuántos hay".',
    ]],
    ['bi-box-arrow-in-down', 'Me surtieron mercancía', [
        'En Inicio dale a <strong>Llegó mercancía</strong>.',
        'Busca cada producto que llegó y escribe cuántos te llegaron. Al guardar, se suman solos.',
    ]],
    ['bi-person-vcard', 'Fiado (crédito a clientes)', [
        'Primero da de alta al cliente en <strong>Clientes y fiado</strong>.',
        'Al cobrar, elige <strong>Crédito</strong> y al cliente. El ticket sale con lo que debe.',
        'Cuando te pague, entra a su nombre y dale a <strong>Registrar abono</strong>.',
    ]],
    ['bi-receipt-cutoff', 'El cliente quiere factura', [
        'En <strong>Vender</strong>, entra a <strong>Ventas hechas</strong>, toca la venta y dale a <strong>Pedir factura</strong>.',
        'Escribe sus datos fiscales (vienen en su Constancia de Situación Fiscal): RFC, nombre, código postal, régimen y para qué la usa.',
        'Las facturas pedidas quedan en <strong>Ventas hechas → Facturas</strong>. De ahí descargas la lista para tu contador o para hacerlas en la página del SAT.',
        'Cuando ya esté hecha, márcala como <strong>hecha</strong> y pega su folio fiscal.',
    ]],
    ['bi-cash-coin', 'Cerrar el día', [
        'En Inicio dale a <strong>Cerrar el día</strong>.',
        'Cuenta los billetes y monedas del cajón y escribe cuánto hay.',
        'El sistema te dice si está completo, si sobra o si falta. Dale a <strong>Hacer corte y cerrar caja</strong>.',
    ]],
];

$titulo  = 'Ayuda';
$seccion = 'ayuda';
require __DIR__ . '/includes/encabezado.php';
?>
<p class="lead mb-4">Aquí está cómo hacer cada cosa, paso por paso. Toca un tema para verlo.</p>
<div class="accordion" id="temasAyuda">
    <?php foreach ($temas as $i => [$icono, $tema, $pasos]): ?>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button fs-5 fw-semibold<?= $i ? ' collapsed' : '' ?>" type="button" data-bs-toggle="collapse"
                        data-bs-target="#tema<?= $i ?>" aria-expanded="<?= $i ? 'false' : 'true' ?>" aria-controls="tema<?= $i ?>">
                    <i class="bi <?= e($icono) ?> text-primary me-3 fs-4"></i><?= e($tema) ?>
                </button>
            </h2>
            <div id="tema<?= $i ?>" class="accordion-collapse collapse<?= $i ? '' : ' show' ?>" data-bs-parent="#temasAyuda">
                <div class="accordion-body">
                    <ol class="pasos-ayuda mb-0">
                        <?php foreach ($pasos as $paso): ?><li><?= $paso ?></li><?php endforeach; ?>
                    </ol>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<p class="small text-body-secondary mt-4"><i class="bi bi-lightbulb me-1"></i>Teclas rápidas al vender: F2 buscar · F4 dejar en espera · F9 cobrar.</p>
<?php require __DIR__ . '/includes/pie.php'; ?>
