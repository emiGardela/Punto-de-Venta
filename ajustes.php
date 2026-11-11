<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Configuración, por secciones: mi negocio, ticket (con vista previa),
 * impresora y cajón, correo para facturas y modo avanzado.
 *
 * Es un solo formulario: cambiar de sección no recarga la página y
 * "Guardar cambios" guarda todas las secciones juntas.
 */
require_once __DIR__ . '/includes/app.php';

requerir_dueno();

/** Secciones: clave => [título, ícono, descripción corta]. */
const SECCIONES_AJUSTES = [
    'negocio'   => ['Mi negocio', 'bi-shop', 'Tipo y nombre'],
    'ticket'    => ['Ticket', 'bi-receipt', 'Lo que dice el ticket'],
    'impresora' => ['Impresora y cajón', 'bi-printer', ''],
    'correo'    => ['Correo', 'bi-envelope-at', ''],
    'avanzado'  => ['Modo avanzado', 'bi-sliders', ''],
];
/** Sección en la que está cada campo (para abrir la que tiene un error). */
const CAMPOS_SECCION = [
    'negocio_nombre' => 'negocio', 'negocio_datos' => 'ticket', 'ticket_pie' => 'ticket', 'ticket_factura' => 'ticket',
    'impresora_nombre' => 'impresora', 'impresora_ip' => 'impresora', 'impresora_puerto' => 'impresora',
    'correo_servidor' => 'correo', 'correo_usuario' => 'correo', 'correo_puerto' => 'correo',
];
$seccionPedida = (string) ($_POST['seccion'] ?? $_GET['s'] ?? '');
$seccionActiva = isset(SECCIONES_AJUSTES[$seccionPedida]) ? $seccionPedida : 'negocio';

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $accion = (string) ($_POST['accion'] ?? 'guardar');

    $nuevos = [];
    foreach (['negocio_nombre' => 60, 'negocio_datos' => 300, 'ticket_pie' => 150, 'ticket_factura' => 150, 'impresora_nombre' => 200,
              'correo_servidor' => 120, 'correo_usuario' => 120, 'correo_nombre' => 60] as $campo => $max) {
        $valor = trim(str_replace("\r\n", "\n", (string) ($_POST[$campo] ?? '')));
        if (mb_strlen($valor) > $max) {
            $errores[$campo] = "Máximo $max caracteres.";
        }
        $nuevos[$campo] = $valor;
    }
    foreach (AJUSTES_OPCIONES as $campo => $opciones) {
        $valor = (string) ($_POST[$campo] ?? '');
        $nuevos[$campo] = in_array($valor, $opciones, true) ? $valor : AJUSTES_PREDETERMINADOS[$campo];
    }
    if (!isset($_POST['giro'])) {
        $nuevos['giro'] = ajuste('giro');
    }
    $nuevos['cortar_papel'] = !empty($_POST['cortar_papel']) ? '1' : '0';
    $nuevos['iva_ticket']   = !empty($_POST['iva_ticket']) ? '1' : '0';
    $nuevos['funciones_avanzadas'] = !empty($_POST['funciones_avanzadas']) ? '1' : '0';

    // La contraseña del correo nunca se muestra: si se deja vacía, se conserva la guardada.
    $contrasena = (string) ($_POST['correo_contrasena'] ?? '');
    $nuevos['correo_contrasena'] = $contrasena !== '' ? str_replace(' ', '', $contrasena) : ajuste('correo_contrasena');
    $nuevos['correo_puerto'] = trim((string) ($_POST['correo_puerto'] ?? '')) ?: ($nuevos['correo_seguridad'] === 'tls' ? '587' : '465');
    if ($nuevos['correo_usuario'] !== '' && !filter_var($nuevos['correo_usuario'], FILTER_VALIDATE_EMAIL)) {
        $errores['correo_usuario'] = 'Escribe un correo válido.';
    }
    if (!ctype_digit($nuevos['correo_puerto']) || (int) $nuevos['correo_puerto'] < 1 || (int) $nuevos['correo_puerto'] > 65535) {
        $errores['correo_puerto'] = 'Puerto no válido (465 o 587).';
    }
    if ($accion === 'correo_prueba' && ($nuevos['correo_servidor'] === '' || $nuevos['correo_usuario'] === '')) {
        $errores['correo_servidor'] = 'Escribe el servidor y tu correo (o usa uno de los botones).';
    }

    $nuevos['impresora_ip'] = trim((string) ($_POST['impresora_ip'] ?? ''));
    $nuevos['impresora_puerto'] = trim((string) ($_POST['impresora_puerto'] ?? '9100')) ?: '9100';
    if ($nuevos['impresora_tipo'] === 'red') {
        if (!filter_var($nuevos['impresora_ip'], FILTER_VALIDATE_IP)) {
            $errores['impresora_ip'] = 'Escribe una dirección IP válida, por ejemplo 192.168.1.100.';
        }
        if (!ctype_digit($nuevos['impresora_puerto']) || (int) $nuevos['impresora_puerto'] < 1 || (int) $nuevos['impresora_puerto'] > 65535) {
            $errores['impresora_puerto'] = 'Puerto no válido (normalmente 9100).';
        }
    }
    if ($nuevos['impresora_tipo'] === 'windows' && $nuevos['impresora_nombre'] === '') {
        $errores['impresora_nombre'] = 'Elige la impresora de tickets.';
    }

    if (!$errores) {
        $antes = ajustes();
        guardar_ajustes($nuevos);
        $cambiados = array_keys(array_diff_assoc(array_intersect_key($nuevos, $antes), $antes));
        if ($cambiados) {
            auditar('configuracion', 'Cambió: ' . implode(', ', $cambiados));
        }
        if ($accion === 'correo_prueba') {
            try {
                enviar_correo(ajuste('correo_usuario'), 'Prueba de ' . APP_NOMBRE, "¡Listo! El correo de " . nombre_negocio() . " ya puede mandar facturas.\n");
                flash('exito', 'Se guardó y se mandó un correo de prueba a ' . ajuste('correo_usuario') . '. Revisa tu bandeja.');
            } catch (RuntimeException $ex) {
                flash('error', 'Se guardó la configuración, pero el correo no salió: ' . $ex->getMessage());
            }
            redirigir('ajustes.php?s=correo');
        }
        if ($accion === 'probar' || $accion === 'cajon') {
            try {
                $aj = ajustes();
                enviar_a_impresora($accion === 'cajon' ? escpos_cajon($aj['cajon_pin']) : escpos_prueba($aj), $aj);
                flash('exito', $accion === 'cajon' ? 'Se mandó la orden para abrir el cajón.' : 'Se mandó la hoja de prueba a la impresora.');
            } catch (RuntimeException $ex) {
                flash('error', 'Se guardó la configuración, pero: ' . $ex->getMessage());
            }
            redirigir('ajustes.php?s=impresora');
        }
        flash('exito', 'Configuración guardada.');
        redirigir('ajustes.php?s=' . $seccionActiva);
    }
    flash('error', 'Revisa los campos marcados en rojo.');
    $seccionActiva = CAMPOS_SECCION[array_key_first($errores)] ?? $seccionActiva;
    $valores = array_merge(ajustes(), $nuevos);
} else {
    $valores = ajustes();
}

// Impresoras instaladas en Windows (solo funciona en la app de escritorio o con FFI activo).
$impresoras = [];
$avisoImpresoras = null;
try {
    $impresoras = impresoras_windows();
} catch (RuntimeException $ex) {
    $avisoImpresoras = $ex->getMessage();
}
if ($valores['impresora_nombre'] !== '' && !in_array($valores['impresora_nombre'], $impresoras, true)) {
    $impresoras[] = $valores['impresora_nombre'];   // conserva la elegida aunque hoy no esté conectada
}

// Cómo está cada sección (se ve en la lista de la izquierda).
$estado = [
    'impresora' => match ($valores['impresora_tipo']) {
        'windows' => 'USB' . ($valores['impresora_nombre'] !== '' ? ': ' . $valores['impresora_nombre'] : ''),
        'red'     => 'Red: ' . $valores['impresora_ip'],
        default   => 'Sin impresora',
    },
    'correo'   => correo_configurado() ? 'Listo' : 'Sin configurar',
    'avanzado' => $valores['funciones_avanzadas'] === '1' ? 'Encendido' : 'Apagado',
];

// Venta de muestra para la vista previa del ticket: dos productos de ejemplo del giro.
$muestra = ['lineas' => [], 'iva' => [], 'total' => '', 'letra' => '', 'articulos' => 0];
$detalleMuestra = [];
$totalCentavos = 0;
foreach (array_slice(giro_actual()['productos'], 0, 2) as $i => $p) {
    $cantidad = $i + 1;
    $subtotal = (int) round($p[2] * 100) * $cantidad;
    $totalCentavos += $subtotal;
    $muestra['articulos'] += $cantidad;
    $muestra['lineas'][] = ['nombre' => $p[0], 'detalle' => '  ' . $cantidad . ' x ' . dinero($p[2]), 'importe' => dinero($subtotal / 100)];
    $detalleMuestra[] = ['tasa_iva' => '16', 'subtotal' => $subtotal / 100, 'descuento' => 0, 'iva' => iva_contenido($subtotal, '16') / 100];
}
foreach (renglones_desglose_iva($detalleMuestra) as [$etiqueta, $centavos]) {
    $muestra['iva'][] = [$etiqueta . ':', dinero($centavos / 100)];
}
$muestra['total'] = dinero($totalCentavos / 100);
$muestra['letra'] = '(' . importe_en_letra($totalCentavos) . ')';
$muestra['fecha'] = fecha_fmt(date('Y-m-d H:i:s'));

$v = fn(string $c) => e($valores[$c] ?? '');
$invalido = fn(string $c) => isset($errores[$c]) ? ' is-invalid' : '';
$mensaje  = fn(string $c) => isset($errores[$c]) ? '<div class="invalid-feedback">' . e($errores[$c]) . '</div>' : '';
$marcado  = fn(string $c, string $valor) => ($valores[$c] ?? '') === $valor ? 'checked' : '';
$activa   = fn(string $s) => $s === $seccionActiva ? ' show active' : '';

$avisoNavegador = aviso_impresora_no_disponible();
$titulo  = 'Configuración';
$seccion = 'ajustes';
require __DIR__ . '/includes/encabezado.php';
require __DIR__ . '/includes/pestanas_ajustes.php';
?>
<form method="post" class="needs-validation" novalidate id="formAjustes">
    <?= csrf_campo() ?>
    <input type="hidden" name="seccion" id="seccionActual" value="<?= e($seccionActiva) ?>">
    <div class="row g-4">
        <!-- ================= Secciones ================= -->
        <div class="col-lg-3">
            <nav class="list-group ajustes-nav" id="navAjustes" role="tablist" aria-label="Secciones de la configuración">
                <?php foreach (SECCIONES_AJUSTES as $clave => [$etiqueta, $icono, $descripcion]): ?>
                    <a class="list-group-item list-group-item-action<?= $clave === $seccionActiva ? ' active' : '' ?>" id="tab-<?= e($clave) ?>"
                       data-bs-toggle="list" href="#sec-<?= e($clave) ?>" data-seccion="<?= e($clave) ?>" role="tab"
                       aria-controls="sec-<?= e($clave) ?>" aria-selected="<?= $clave === $seccionActiva ? 'true' : 'false' ?>">
                        <i class="bi <?= e($icono) ?>"></i>
                        <span>
                            <span class="d-block fw-semibold"><?= e($etiqueta) ?></span>
                            <small class="ajustes-estado"><?= e($estado[$clave] ?? $descripcion) ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="col-lg-9">
            <div class="tab-content">
                <!-- ================= Mi negocio ================= -->
                <section class="tab-pane fade<?= $activa('negocio') ?>" id="sec-negocio" role="tabpanel" aria-labelledby="tab-negocio">
                    <div class="card">
                        <div class="card-header"><h2><i class="bi bi-shop me-2 text-primary"></i>Mi negocio</h2></div>
                        <div class="card-body p-4">
                            <div class="mb-4">
                                <label for="negocio_nombre" class="form-label">Nombre del negocio</label>
                                <input type="text" class="form-control form-control-lg<?= $invalido('negocio_nombre') ?>" id="negocio_nombre"
                                       name="negocio_nombre" value="<?= $v('negocio_nombre') ?>" maxlength="60" placeholder="Ej. La Esperanza">
                                <?= $mensaje('negocio_nombre') ?>
                                <div class="form-text">Aparece en el menú y en el encabezado del ticket.</div>
                            </div>
                            <div class="form-label">Tipo de negocio</div>
                            <div class="giros giros-4" role="radiogroup" aria-label="Tipo de negocio">
                                <?php foreach (GIROS as $clave => $g): ?>
                                    <input type="radio" class="btn-check" name="giro" id="giro_<?= e($clave) ?>" value="<?= e($clave) ?>" <?= $valores['giro'] === $clave ? 'checked' : '' ?>>
                                    <label class="btn btn-metodo" for="giro_<?= e($clave) ?>"><i class="bi <?= e($g['icono']) ?>"></i><?= e($g['etiqueta']) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Cambia los productos de ejemplo y las categorías sugeridas. Tus productos y ventas no se tocan.</div>
                        </div>
                    </div>
                </section>

                <!-- ================= Ticket ================= -->
                <section class="tab-pane fade<?= $activa('ticket') ?>" id="sec-ticket" role="tabpanel" aria-labelledby="tab-ticket">
                    <div class="row g-4">
                        <div class="col-xl-7">
                            <div class="card h-100">
                                <div class="card-header"><h2><i class="bi bi-receipt me-2 text-primary"></i>Lo que dice el ticket</h2></div>
                                <div class="card-body p-4">
                                    <div class="mb-3">
                                        <label for="negocio_datos" class="form-label">Datos del negocio <span class="text-body-secondary fw-normal">(opcional)</span></label>
                                        <textarea class="form-control<?= $invalido('negocio_datos') ?>" id="negocio_datos" name="negocio_datos" rows="3"
                                                  maxlength="300" placeholder="Dirección, teléfono, RFC… (un dato por renglón)"><?= $v('negocio_datos') ?></textarea>
                                        <?= $mensaje('negocio_datos') ?>
                                    </div>
                                    <div class="mb-3">
                                        <label for="ticket_pie" class="form-label">Mensaje al final</label>
                                        <input type="text" class="form-control<?= $invalido('ticket_pie') ?>" id="ticket_pie" name="ticket_pie"
                                               value="<?= $v('ticket_pie') ?>" maxlength="150">
                                        <?= $mensaje('ticket_pie') ?>
                                    </div>
                                    <div class="mb-3">
                                        <label for="ticket_factura" class="form-label">Aviso de factura <span class="text-body-secondary fw-normal">(déjalo vacío para no ponerlo)</span></label>
                                        <input type="text" class="form-control<?= $invalido('ticket_factura') ?>" id="ticket_factura" name="ticket_factura"
                                               value="<?= $v('ticket_factura') ?>" maxlength="150">
                                        <?= $mensaje('ticket_factura') ?>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="iva_ticket" name="iva_ticket" value="1" <?= $valores['iva_ticket'] === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="iva_ticket">Desglosar el IVA (precio sin IVA e IVA de cada tasa)</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-5">
                            <div class="ticket-muestra-caja">
                                <div class="small text-body-secondary mb-2"><i class="bi bi-eye me-1"></i>Así se verá <span id="muestraPapel"></span></div>
                                <pre class="ticket-muestra" id="ticketMuestra" aria-live="polite"></pre>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ================= Impresora y cajón ================= -->
                <section class="tab-pane fade<?= $activa('impresora') ?>" id="sec-impresora" role="tabpanel" aria-labelledby="tab-impresora">
                    <div class="card">
                        <div class="card-header"><h2><i class="bi bi-printer me-2 text-primary"></i>Impresora de tickets y cajón</h2></div>
                        <div class="card-body p-4">
                            <div class="form-label">¿Cómo está conectada la impresora?</div>
                            <div class="giros giros-3 mb-4" role="radiogroup" aria-label="Conexión de la impresora">
                                <input type="radio" class="btn-check" name="impresora_tipo" id="tipo_ninguna" value="ninguna" <?= $marcado('impresora_tipo', 'ninguna') ?>>
                                <label class="btn btn-metodo" for="tipo_ninguna"><i class="bi bi-slash-circle"></i>No tengo</label>
                                <input type="radio" class="btn-check" name="impresora_tipo" id="tipo_windows" value="windows" <?= $marcado('impresora_tipo', 'windows') ?>>
                                <label class="btn btn-metodo" for="tipo_windows"><i class="bi bi-usb-symbol"></i>USB</label>
                                <input type="radio" class="btn-check" name="impresora_tipo" id="tipo_red" value="red" <?= $marcado('impresora_tipo', 'red') ?>>
                                <label class="btn btn-metodo" for="tipo_red"><i class="bi bi-ethernet"></i>Cable de red</label>
                            </div>

                            <p class="text-body-secondary mb-0" id="sinImpresora">Los tickets se pueden imprimir en cualquier impresora desde "Ventas hechas". Si después conectas una de tickets, elígela aquí.</p>

                            <div id="camposWindows" class="mb-3">
                                <?php if ($avisoNavegador): ?>
                                    <div class="alert alert-warning small"><i class="bi bi-info-circle me-1"></i><?= e($avisoNavegador) ?></div>
                                <?php endif; ?>
                                <label for="impresora_nombre" class="form-label">Impresora</label>
                                <select class="form-select<?= $invalido('impresora_nombre') ?>" id="impresora_nombre" name="impresora_nombre">
                                    <option value="">Elige la impresora de tickets…</option>
                                    <?php foreach ($impresoras as $nombre): ?>
                                        <option value="<?= e($nombre) ?>" <?= $valores['impresora_nombre'] === $nombre ? 'selected' : '' ?>><?= e($nombre) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= $mensaje('impresora_nombre') ?>
                                <?php if ($avisoImpresoras): ?>
                                    <div class="form-text text-danger"><?= e($avisoImpresoras) ?></div>
                                <?php else: ?>
                                    <div class="form-text">Elige la <strong>térmica de tickets</strong>. Si eliges una impresora normal (de tinta o láser) saldrán hojas con símbolos raros.</div>
                                <?php endif; ?>
                            </div>

                            <div id="camposRed" class="row g-3 mb-3">
                                <div class="col-8">
                                    <label for="impresora_ip" class="form-label">Dirección IP</label>
                                    <input type="text" class="form-control<?= $invalido('impresora_ip') ?>" id="impresora_ip" name="impresora_ip"
                                           value="<?= $v('impresora_ip') ?>" placeholder="192.168.1.100" inputmode="decimal">
                                    <?= $mensaje('impresora_ip') ?>
                                </div>
                                <div class="col-4">
                                    <label for="impresora_puerto" class="form-label">Puerto</label>
                                    <input type="text" class="form-control<?= $invalido('impresora_puerto') ?>" id="impresora_puerto" name="impresora_puerto"
                                           value="<?= $v('impresora_puerto') ?>" inputmode="numeric">
                                    <?= $mensaje('impresora_puerto') ?>
                                </div>
                            </div>

                            <div id="camposImpresora">
                                <div class="row g-3 mb-3">
                                    <div class="col-sm-6">
                                        <label for="papel" class="form-label">Ancho del papel</label>
                                        <select class="form-select" id="papel" name="papel">
                                            <option value="80" <?= $valores['papel'] === '80' ? 'selected' : '' ?>>80 mm (el más común)</option>
                                            <option value="58" <?= $valores['papel'] === '58' ? 'selected' : '' ?>>58 mm (impresoras pequeñas)</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="caracteres" class="form-label">Acentos y ñ</label>
                                        <select class="form-select" id="caracteres" name="caracteres">
                                            <option value="pc850" <?= $valores['caracteres'] === 'pc850' ? 'selected' : '' ?>>Normal (PC850)</option>
                                            <option value="pc437" <?= $valores['caracteres'] === 'pc437' ? 'selected' : '' ?>>Alternativo (PC437)</option>
                                            <option value="sin_acentos" <?= $valores['caracteres'] === 'sin_acentos' ? 'selected' : '' ?>>Sin acentos</option>
                                        </select>
                                        <div class="form-text">Si en la prueba salen signos raros en lugar de acentos, cambia esta opción.</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch interruptor-tarjeta mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="cortar_papel" name="cortar_papel" value="1" <?= $valores['cortar_papel'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cortar_papel">
                                        <span class="fw-semibold d-block">Cortar el papel al terminar</span>
                                        <small class="text-body-secondary">Desactívalo si tu impresora no tiene cortador.</small>
                                    </label>
                                </div>

                                <hr class="my-4">
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label for="imprimir_al_cobrar" class="form-label">Ticket al cobrar</label>
                                        <select class="form-select" id="imprimir_al_cobrar" name="imprimir_al_cobrar">
                                            <option value="siempre" <?= $valores['imprimir_al_cobrar'] === 'siempre' ? 'selected' : '' ?>>Imprimir siempre</option>
                                            <option value="preguntar" <?= $valores['imprimir_al_cobrar'] === 'preguntar' ? 'selected' : '' ?>>Solo si lo pido</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="cajon_al_cobrar" class="form-label">Abrir el cajón al cobrar</label>
                                        <select class="form-select" id="cajon_al_cobrar" name="cajon_al_cobrar">
                                            <option value="efectivo" <?= $valores['cajon_al_cobrar'] === 'efectivo' ? 'selected' : '' ?>>Solo en pagos en efectivo</option>
                                            <option value="siempre" <?= $valores['cajon_al_cobrar'] === 'siempre' ? 'selected' : '' ?>>En todos los pagos</option>
                                            <option value="nunca" <?= $valores['cajon_al_cobrar'] === 'nunca' ? 'selected' : '' ?>>Nunca (no tengo cajón)</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="cajon_pin" class="form-label">Conector del cajón</label>
                                        <select class="form-select" id="cajon_pin" name="cajon_pin">
                                            <option value="2" <?= $valores['cajon_pin'] === '2' ? 'selected' : '' ?>>Normal (pin 2)</option>
                                            <option value="5" <?= $valores['cajon_pin'] === '5' ? 'selected' : '' ?>>Alternativo (pin 5)</option>
                                        </select>
                                        <div class="form-text">Si el cajón no abre con la prueba, cambia a "Alternativo".</div>
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mt-4">
                                    <button type="submit" name="accion" value="probar" class="btn btn-outline-primary"><i class="bi bi-receipt me-1"></i>Guardar e imprimir prueba</button>
                                    <button type="submit" name="accion" value="cajon" class="btn btn-outline-primary"><i class="bi bi-inbox me-1"></i>Guardar y abrir cajón</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ================= Correo ================= -->
                <section class="tab-pane fade<?= $activa('correo') ?>" id="sec-correo" role="tabpanel" aria-labelledby="tab-correo">
                    <div class="card" id="correo">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h2><i class="bi bi-envelope-at me-2 text-primary"></i>Correo para mandar facturas</h2>
                            <span class="badge rounded-pill <?= correo_configurado() ? 'text-bg-success' : 'text-bg-light border' ?>"><?= correo_configurado() ? 'Listo' : 'Sin configurar' ?></span>
                        </div>
                        <div class="card-body p-4">
                            <p class="text-body-secondary small">Solo lo necesitas si mandas facturas por correo a tus clientes: el sistema las manda desde el correo de tu negocio. Si no facturas, déjalo así.</p>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php foreach (SERVIDORES_CORREO as $clave => [$nombre, $srv, $puerto, $seg, $ayuda]): ?>
                                    <button type="button" class="btn btn-sm btn-light border btn-servidor-correo" data-servidor="<?= e($srv) ?>" data-puerto="<?= e($puerto) ?>"
                                            data-seguridad="<?= e($seg) ?>" data-ayuda="<?= e($ayuda) ?>"><i class="bi bi-envelope me-1"></i>Uso <?= e($nombre) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <div class="alert alert-info small d-none" id="ayudaCorreo"></div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="correo_usuario" class="form-label">Tu correo</label>
                                    <input type="email" class="form-control<?= $invalido('correo_usuario') ?>" id="correo_usuario" name="correo_usuario" value="<?= $v('correo_usuario') ?>" maxlength="120" placeholder="minegocio@gmail.com" autocomplete="off">
                                    <?= $mensaje('correo_usuario') ?>
                                </div>
                                <div class="col-md-6">
                                    <label for="correo_contrasena" class="form-label">Contraseña <span class="text-body-secondary fw-normal">(de aplicación)</span></label>
                                    <input type="password" class="form-control" id="correo_contrasena" name="correo_contrasena" value="" maxlength="200" autocomplete="new-password"
                                           placeholder="<?= ajuste('correo_contrasena') !== '' ? 'Ya está guardada (escribe otra para cambiarla)' : '' ?>">
                                </div>
                                <div class="col-md-5">
                                    <label for="correo_servidor" class="form-label">Servidor</label>
                                    <input type="text" class="form-control<?= $invalido('correo_servidor') ?>" id="correo_servidor" name="correo_servidor" value="<?= $v('correo_servidor') ?>" maxlength="120" placeholder="smtp.gmail.com">
                                    <?= $mensaje('correo_servidor') ?>
                                </div>
                                <div class="col-md-3">
                                    <label for="correo_puerto" class="form-label">Puerto</label>
                                    <input type="text" class="form-control<?= $invalido('correo_puerto') ?>" id="correo_puerto" name="correo_puerto" value="<?= $v('correo_puerto') ?>" inputmode="numeric">
                                    <?= $mensaje('correo_puerto') ?>
                                </div>
                                <div class="col-md-4">
                                    <label for="correo_seguridad" class="form-label">Seguridad</label>
                                    <select class="form-select" id="correo_seguridad" name="correo_seguridad">
                                        <?php foreach (['ssl' => 'SSL (puerto 465)', 'tls' => 'STARTTLS (puerto 587)', 'ninguna' => 'Ninguna'] as $clave => $etiqueta): ?>
                                            <option value="<?= e($clave) ?>" <?= $valores['correo_seguridad'] === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="correo_nombre" class="form-label">Nombre que verá el cliente</label>
                                    <input type="text" class="form-control" id="correo_nombre" name="correo_nombre" value="<?= $v('correo_nombre') ?>" maxlength="60" placeholder="<?= e(nombre_negocio()) ?>">
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-4">
                                <button type="submit" name="accion" value="correo_prueba" class="btn btn-outline-primary"><i class="bi bi-send me-1"></i>Guardar y mandarme un correo de prueba</button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ================= Modo avanzado ================= -->
                <section class="tab-pane fade<?= $activa('avanzado') ?>" id="sec-avanzado" role="tabpanel" aria-labelledby="tab-avanzado">
                    <div class="card">
                        <div class="card-header"><h2><i class="bi bi-sliders me-2 text-primary"></i>Modo avanzado</h2></div>
                        <div class="card-body p-4">
                            <div class="form-check form-switch interruptor-tarjeta mb-4">
                                <input class="form-check-input" type="checkbox" role="switch" id="funciones_avanzadas" name="funciones_avanzadas" value="1" <?= $valores['funciones_avanzadas'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="funciones_avanzadas">
                                    <span class="fw-semibold d-block">Funciones avanzadas</span>
                                    <small class="text-body-secondary">Apagado, el sistema muestra solo lo básico para vender. Préndelo si necesitas:</small>
                                </label>
                            </div>
                            <ul class="lista-avanzadas">
                                <li><i class="bi bi-bag-check"></i><span><strong>Compras a proveedores</strong>: registra lo que te surtieron y lo que debes comprar.</span></li>
                                <li><i class="bi bi-tags"></i><span><strong>Precios de mayoreo</strong>: "desde 12 piezas, a $4.50".</span></li>
                                <li><i class="bi bi-box2"></i><span><strong>Paquetes y kits</strong>: una caja de 12 que descuenta 12 piezas, o un kit de varios productos.</span></li>
                                <?php if (usa_listas_escolares()): ?>
                                    <li><i class="bi bi-card-checklist"></i><span><strong>Listas escolares</strong> que se cargan al carrito con un clic.</span></li>
                                <?php endif; ?>
                                <li><i class="bi bi-input-cursor-text"></i><span><strong>Más datos del producto</strong>: marca, clave corta, proveedor y descripción.</span></li>
                            </ul>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- ================= Guardar (siempre visible) ================= -->
    <div class="barra-guardar">
        <span class="small" id="avisoCambios"><i class="bi bi-check2 me-1"></i>Todo guardado</span>
        <button type="submit" name="accion" value="guardar" class="btn btn-primary btn-lg px-4"><i class="bi bi-check2-circle me-1"></i>Guardar cambios</button>
    </div>
</form>

<script>
(function () {
    const form = document.getElementById('formAjustes');
    const muestra = <?= json_encode($muestra, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    /* --- Secciones: recuerda la abierta (en la URL y al guardar). --- */
    document.querySelectorAll('#navAjustes [data-bs-toggle="list"]').forEach(function (a) {
        a.addEventListener('shown.bs.tab', function () {
            document.getElementById('seccionActual').value = a.dataset.seccion;
            history.replaceState(null, '', '?s=' + a.dataset.seccion);
        });
    });

    /* --- Impresora: muestra solo los campos del tipo elegido. --- */
    function actualizarImpresora() {
        const tipo = form.querySelector('input[name="impresora_tipo"]:checked').value;
        document.getElementById('camposWindows').classList.toggle('d-none', tipo !== 'windows');
        document.getElementById('camposRed').classList.toggle('d-none', tipo !== 'red');
        document.getElementById('camposImpresora').classList.toggle('d-none', tipo === 'ninguna');
        document.getElementById('sinImpresora').classList.toggle('d-none', tipo !== 'ninguna');
    }
    form.querySelectorAll('input[name="impresora_tipo"]').forEach(t => t.addEventListener('change', actualizarImpresora));
    actualizarImpresora();

    /* --- Correo: botones que llenan el servidor. --- */
    document.querySelectorAll('.btn-servidor-correo').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('correo_servidor').value = b.dataset.servidor;
            document.getElementById('correo_puerto').value = b.dataset.puerto;
            document.getElementById('correo_seguridad').value = b.dataset.seguridad;
            const ayuda = document.getElementById('ayudaCorreo');
            ayuda.textContent = b.dataset.ayuda;
            ayuda.classList.remove('d-none');
            document.getElementById('correo_usuario').focus();
            marcarCambios();
        });
    });

    /* --- Vista previa del ticket, con el mismo ancho que el papel. --- */
    function partir(texto, ancho) {
        const renglones = [];
        String(texto).split('\n').forEach(function (parrafo) {
            let actual = '';
            parrafo.split(' ').forEach(function (palabra) {
                while (palabra.length > ancho) { if (actual) { renglones.push(actual); actual = ''; } renglones.push(palabra.slice(0, ancho)); palabra = palabra.slice(ancho); }
                if (!actual) { actual = palabra; }
                else if ((actual + ' ' + palabra).length <= ancho) { actual += ' ' + palabra; }
                else { renglones.push(actual); actual = palabra; }
            });
            renglones.push(actual);
        });
        return renglones;
    }
    function centro(texto, ancho) {
        return partir(texto, ancho).map(r => ' '.repeat(Math.max(0, Math.floor((ancho - r.length) / 2))) + r);
    }
    function columnas(izq, der, ancho) {
        const espacio = ancho - izq.length - der.length;
        return espacio >= 1 ? [izq + ' '.repeat(espacio) + der] : [izq, ' '.repeat(Math.max(0, ancho - der.length)) + der];
    }
    function pintarTicket() {
        const ancho = document.getElementById('papel').value === '58' ? 32 : 48;
        const valor = id => document.getElementById(id).value.trim();
        const sep = '-'.repeat(ancho);
        const renglones = [];   // [texto, clase]
        const agregar = (lineas, clase) => lineas.forEach(l => renglones.push([l, clase || '']));
        // El nombre sale en letra doble (la mitad de letras por renglón), como en la impresora.
        if (valor('negocio_nombre')) { agregar(centro(valor('negocio_nombre'), Math.floor(ancho / 2)), 'doble'); }
        if (valor('negocio_datos')) { agregar(centro(valor('negocio_datos'), ancho)); }
        agregar(centro('Ticket de venta', ancho).concat([sep], columnas('Folio:', '000001', ancho), columnas('Fecha:', muestra.fecha, ancho), [sep]));
        muestra.lineas.forEach(l => agregar(partir(l.nombre, ancho).concat(columnas(l.detalle, l.importe, ancho))));
        agregar([sep].concat(columnas('Artículos:', String(muestra.articulos), ancho)));
        if (document.getElementById('iva_ticket').checked) { muestra.iva.forEach(([e, m]) => agregar(columnas(e, m, ancho))); }
        agregar(columnas('TOTAL:', muestra.total, ancho), 'total');
        agregar(partir(muestra.letra, ancho).concat(columnas('Pago:', 'Efectivo', ancho)));
        if (valor('ticket_factura')) { agregar([sep].concat(centro(valor('ticket_factura'), ancho))); }
        if (valor('ticket_pie')) { agregar([sep].concat(centro(valor('ticket_pie'), ancho))); }

        const pre = document.getElementById('ticketMuestra');
        pre.replaceChildren(...renglones.map(function ([texto, clase]) {
            const span = document.createElement('span');
            span.textContent = texto + '\n';
            if (clase) { span.className = clase; }
            return span;
        }));
        pre.style.setProperty('--ancho', ancho);
        document.getElementById('muestraPapel').textContent = '(papel de ' + document.getElementById('papel').value + ' mm)';
    }
    ['negocio_nombre', 'negocio_datos', 'ticket_pie', 'ticket_factura'].forEach(id => document.getElementById(id).addEventListener('input', pintarTicket));
    ['iva_ticket', 'papel'].forEach(id => document.getElementById(id).addEventListener('change', pintarTicket));
    pintarTicket();

    /* --- Cambios sin guardar. --- */
    let pendientes = false;
    const aviso = document.getElementById('avisoCambios');
    function marcarCambios() {
        if (pendientes) { return; }
        pendientes = true;
        aviso.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Tienes cambios sin guardar';
        aviso.classList.add('text-warning-emphasis', 'fw-semibold');
    }
    form.addEventListener('input', marcarCambios);
    form.addEventListener('change', marcarCambios);
    form.addEventListener('submit', function () { pendientes = false; });
    window.addEventListener('beforeunload', function (ev) { if (pendientes) { ev.preventDefault(); ev.returnValue = ''; } });
    <?php if ($errores): ?>marcarCambios();<?php endif; ?>
})();
</script>
<?php require __DIR__ . '/includes/pie.php'; ?>
