<?php
/**
 * Configuración: datos del negocio, impresora de tickets ESC/POS y cajón.
 */
require_once __DIR__ . '/includes/app.php';

requerir_dueno();

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
            redirigir('ajustes.php#correo');
        }
        if ($accion === 'probar' || $accion === 'cajon') {
            try {
                $aj = ajustes();
                enviar_a_impresora($accion === 'cajon' ? escpos_cajon($aj['cajon_pin']) : escpos_prueba($aj), $aj);
                flash('exito', $accion === 'cajon' ? 'Se mandó la orden para abrir el cajón.' : 'Se mandó la hoja de prueba a la impresora.');
            } catch (RuntimeException $ex) {
                flash('error', 'Se guardó la configuración, pero: ' . $ex->getMessage());
            }
        } else {
            flash('exito', 'Configuración guardada.');
        }
        redirigir('ajustes.php');
    }
    flash('error', 'Revisa los campos marcados en rojo.');
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

$v = fn(string $c) => e($valores[$c] ?? '');
$invalido = fn(string $c) => isset($errores[$c]) ? ' is-invalid' : '';
$mensaje  = fn(string $c) => isset($errores[$c]) ? '<div class="invalid-feedback">' . e($errores[$c]) . '</div>' : '';
$marcado  = fn(string $c, string $valor) => ($valores[$c] ?? '') === $valor ? 'checked' : '';

$avisoNavegador = aviso_impresora_no_disponible();
$titulo  = 'Configuración';
$seccion = 'ajustes';
require __DIR__ . '/includes/encabezado.php';
require __DIR__ . '/includes/pestanas_ajustes.php';
?>
<form method="post" class="needs-validation" novalidate id="formAjustes">
    <?= csrf_campo() ?>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h2><i class="bi bi-shop me-2 text-primary"></i>Datos del negocio</h2></div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="giro" class="form-label">Tipo de negocio</label>
                        <select class="form-select" id="giro" name="giro">
                            <?php foreach (GIROS as $clave => $g): ?>
                                <option value="<?= e($clave) ?>" <?= $valores['giro'] === $clave ? 'selected' : '' ?>><?= e($g['etiqueta']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Cambia las sugerencias. Tus productos y ventas no se tocan.</div>
                    </div>
                    <div class="mb-3">
                        <label for="negocio_nombre" class="form-label">Nombre del negocio</label>
                        <input type="text" class="form-control form-control-lg<?= $invalido('negocio_nombre') ?>" id="negocio_nombre"
                               name="negocio_nombre" value="<?= $v('negocio_nombre') ?>" maxlength="60" placeholder="Ej. La Esperanza">
                        <?= $mensaje('negocio_nombre') ?>
                        <div class="form-text">Aparece en el menú y en el encabezado del ticket.</div>
                    </div>
                    <div class="mb-3">
                        <label for="negocio_datos" class="form-label">Datos para el ticket <span class="text-body-secondary fw-normal">(opcional)</span></label>
                        <textarea class="form-control<?= $invalido('negocio_datos') ?>" id="negocio_datos" name="negocio_datos" rows="3"
                                  maxlength="300" placeholder="Dirección, teléfono, RFC… (un dato por renglón)"><?= $v('negocio_datos') ?></textarea>
                        <?= $mensaje('negocio_datos') ?>
                    </div>
                    <div class="mb-3">
                        <label for="ticket_pie" class="form-label">Mensaje al final del ticket</label>
                        <input type="text" class="form-control<?= $invalido('ticket_pie') ?>" id="ticket_pie" name="ticket_pie"
                               value="<?= $v('ticket_pie') ?>" maxlength="150">
                        <?= $mensaje('ticket_pie') ?>
                    </div>
                    <div class="mb-3">
                        <label for="ticket_factura" class="form-label">Aviso de factura en el ticket <span class="text-body-secondary fw-normal">(déjalo vacío para no ponerlo)</span></label>
                        <input type="text" class="form-control<?= $invalido('ticket_factura') ?>" id="ticket_factura" name="ticket_factura"
                               value="<?= $v('ticket_factura') ?>" maxlength="150">
                        <?= $mensaje('ticket_factura') ?>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="iva_ticket" name="iva_ticket" value="1" <?= $valores['iva_ticket'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="iva_ticket">Desglosar el IVA en el ticket (precio sin IVA e IVA de cada tasa)</label>
                    </div>
                    <hr class="my-4">
                    <div class="form-check form-switch interruptor-tarjeta">
                        <input class="form-check-input" type="checkbox" role="switch" id="funciones_avanzadas" name="funciones_avanzadas" value="1" <?= $valores['funciones_avanzadas'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="funciones_avanzadas">
                            <span class="fw-semibold d-block">Funciones avanzadas</span>
                            <small class="text-body-secondary">Compras a proveedores, <?= usa_listas_escolares() ? 'listas escolares, ' : '' ?>paquetes y kits, precios de mayoreo y más datos del producto.
                                Apagado, el sistema muestra solo lo básico para vender.</small>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h2><i class="bi bi-printer me-2 text-primary"></i>Impresora de tickets y cajón</h2></div>
                <div class="card-body p-4">
                    <div class="form-label">¿Cómo está conectada la impresora?</div>
                    <div class="d-flex flex-wrap gap-2 mb-3" role="radiogroup">
                        <input type="radio" class="btn-check" name="impresora_tipo" id="tipo_ninguna" value="ninguna" <?= $marcado('impresora_tipo', 'ninguna') ?>>
                        <label class="btn btn-outline-secondary" for="tipo_ninguna"><i class="bi bi-slash-circle me-1"></i>No tengo impresora</label>
                        <input type="radio" class="btn-check" name="impresora_tipo" id="tipo_windows" value="windows" <?= $marcado('impresora_tipo', 'windows') ?>>
                        <label class="btn btn-outline-primary" for="tipo_windows"><i class="bi bi-usb-symbol me-1"></i>USB (instalada en Windows)</label>
                        <input type="radio" class="btn-check" name="impresora_tipo" id="tipo_red" value="red" <?= $marcado('impresora_tipo', 'red') ?>>
                        <label class="btn btn-outline-primary" for="tipo_red"><i class="bi bi-ethernet me-1"></i>Cable de red</label>
                    </div>

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
        </div>
    </div>


    <div class="card mt-4" id="correo">
        <div class="card-header"><h2><i class="bi bi-envelope-at me-2 text-primary"></i>Correo para mandar facturas</h2>
            <span class="small <?= correo_configurado() ? 'text-success' : 'text-body-secondary' ?>"><?= correo_configurado() ? 'Listo' : 'Sin configurar' ?></span></div>
        <div class="card-body p-4">
            <p class="text-body-secondary small">Con esto el sistema manda las facturas al correo del cliente desde el correo de tu negocio.</p>
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
    <div class="d-flex justify-content-end mt-4">
        <button type="submit" name="accion" value="guardar" class="btn btn-primary btn-lg px-4"><i class="bi bi-check2-circle me-1"></i>Guardar configuración</button>
    </div>
</form>

<script>
(function () {
    const tipos = document.querySelectorAll('input[name="impresora_tipo"]');
    function actualizar() {
        const tipo = document.querySelector('input[name="impresora_tipo"]:checked').value;
        document.getElementById('camposWindows').classList.toggle('d-none', tipo !== 'windows');
        document.getElementById('camposRed').classList.toggle('d-none', tipo !== 'red');
        document.getElementById('camposImpresora').classList.toggle('d-none', tipo === 'ninguna');
    }
    tipos.forEach(t => t.addEventListener('change', actualizar));
    actualizar();
    document.querySelectorAll('.btn-servidor-correo').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('correo_servidor').value = b.dataset.servidor;
            document.getElementById('correo_puerto').value = b.dataset.puerto;
            document.getElementById('correo_seguridad').value = b.dataset.seguridad;
            const ayuda = document.getElementById('ayudaCorreo');
            ayuda.textContent = b.dataset.ayuda;
            ayuda.classList.remove('d-none');
            document.getElementById('correo_usuario').focus();
        });
    });
})();
</script>
<?php require __DIR__ . '/includes/pie.php'; ?>
