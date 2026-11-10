<?php
/**
 * Factura de una venta, de principio a fin:
 *   pedir (datos fiscales) → corregir → "ya la hice" (sube el XML y el PDF; el folio
 *   fiscal se lee del XML) → mandar por correo → cancelar con el motivo del SAT
 *   (01 = se reemplaza por otra) → marcar "ya la cancelé en el SAT".
 */
require_once __DIR__ . '/../includes/app.php';

$id = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$st = db()->prepare('SELECT * FROM ventas WHERE id = :id');
$st->execute([':id' => $id]);
$venta = $st->fetch();
if (!$venta) {
    flash('error', 'La venta no existe.');
    redirigir('ventas/historial.php');
}
$factura = factura_de_venta($id);
$vigente = $factura && $factura['estado'] !== 'cancelada' ? $factura : null;
$errores = [];
$datos = ['rfc' => '', 'razon_social' => '', 'cp_fiscal' => '', 'regimen_fiscal' => '', 'uso_cfdi' => 'G03', 'correo' => ''];
$clienteId = $venta['cliente_id'] ? (int) $venta['cliente_id'] : 0;
$volver = fn() => redirigir('ventas/factura.php?id=' . $id);
$uuidValido = fn(string $u) => (bool) preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $u);

/** Lee un archivo subido; null si no se subió. Lanza RuntimeException si viene mal. */
$archivoSubido = function (string $campo, string $tipo): ?array {
    $f = $_FILES[$campo] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('No se pudo subir el ' . strtoupper($tipo) . '. Intenta de nuevo.');
    }
    $limite = $tipo === 'xml' ? 2 : 10;
    if ($f['size'] > $limite * 1024 * 1024) {
        throw new RuntimeException('El ' . strtoupper($tipo) . " pesa demasiado (máximo $limite MB).");
    }
    $contenido = (string) file_get_contents($f['tmp_name']);
    if ($tipo === 'pdf' && !str_starts_with($contenido, '%PDF')) {
        throw new RuntimeException('El archivo PDF no es un PDF.');
    }
    $nombre = (string) $f['name'];
    if (!str_ends_with(mb_strtolower($nombre), '.' . $tipo)) {
        $nombre .= '.' . $tipo;
    }
    return ['nombre' => $nombre, 'contenido' => $contenido];
};

/** Guarda XML/PDF subidos de una factura hecha; devuelve el folio fiscal leído del XML (o null). */
$guardarArchivos = function (array $fac, ?string $uuidEscrito) use ($archivoSubido): ?string {
    $uuid = null;
    $xml = $archivoSubido('xml', 'xml');
    $pdf = $archivoSubido('pdf', 'pdf');
    if ($xml) {
        $cfdi = leer_xml_cfdi($xml['contenido']);
        if ($cfdi['rfc_receptor'] !== '' && $cfdi['rfc_receptor'] !== $fac['rfc']) {
            throw new RuntimeException("Ese XML es para el RFC {$cfdi['rfc_receptor']}, pero esta factura es para {$fac['rfc']}. Revisa que sea el archivo correcto.");
        }
        if ($uuidEscrito && $uuidEscrito !== $cfdi['uuid']) {
            throw new RuntimeException('El folio fiscal que escribiste no es el del XML. Deja el campo vacío y se toma del XML.');
        }
        $uuid = $cfdi['uuid'];
        guardar_archivo_factura((int) $fac['id'], 'xml', $xml['nombre'], $xml['contenido']);
    }
    if ($pdf) {
        guardar_archivo_factura((int) $fac['id'], 'pdf', $pdf['nombre'], $pdf['contenido']);
    }
    return $uuid;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $accion = (string) ($_POST['accion'] ?? 'pedir');
    $administrativa = in_array($accion, ['hecha', 'archivos', 'enviar', 'cancelar', 'cancelada_sat'], true);
    if ($administrativa && !puede_administrar_facturas()) {
        flash('error', 'Tu usuario no puede hacer esto. Pídeselo al dueño.');
        $volver();
    }

    if ($accion === 'pedir' || $accion === 'corregir') {
        if ($accion === 'pedir' && $vigente) {
            flash('aviso', 'Esta venta ya tiene su factura pedida.');
            $volver();
        }
        if ($accion === 'corregir' && ($vigente['estado'] ?? '') !== 'pendiente') {
            flash('error', 'Solo se corrigen los datos de una factura que todavía no se hace. Si ya está hecha, cancélala con el motivo 01.');
            $volver();
        }
        if ($venta['estado'] !== 'completada') {
            flash('error', 'No se puede facturar una venta cancelada.');
            redirigir('ventas/detalle.php?id=' . $id);
        }
        [$datos, $errores] = validar_datos_fiscales($_POST);
        $clienteId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($clienteId && !cliente_por_id($clienteId)) {
            $clienteId = 0;
        }
        if (!$errores) {
            transaccion_iniciar();
            try {
                $params = [':rfc' => $datos['rfc'], ':razon' => $datos['razon_social'], ':cp' => $datos['cp_fiscal'], ':regimen' => $datos['regimen_fiscal'],
                           ':uso' => $datos['uso_cfdi'], ':correo' => $datos['correo'] ?: null];
                if ($accion === 'pedir') {
                    db()->prepare('INSERT INTO facturas (venta_id, cliente_id, rfc, razon_social, cp_fiscal, regimen_fiscal, uso_cfdi, correo, solicitada_en, usuario_id)
                                   VALUES (:venta, :cliente, :rfc, :razon, :cp, :regimen, :uso, :correo, :fecha, :usuario)')
                        ->execute($params + [':venta' => $id, ':cliente' => $clienteId ?: null, ':fecha' => date('Y-m-d H:i:s'), ':usuario' => usuario_id()]);
                    auditar('factura', 'Pidió factura de la venta ' . folio($id) . ' para ' . $datos['rfc'], 'venta', $id);
                } else {
                    db()->prepare('UPDATE facturas SET rfc = :rfc, razon_social = :razon, cp_fiscal = :cp, regimen_fiscal = :regimen, uso_cfdi = :uso,
                                                       correo = :correo, cliente_id = COALESCE(:cliente, cliente_id) WHERE id = :id')
                        ->execute($params + [':cliente' => $clienteId ?: null, ':id' => $vigente['id']]);
                    auditar('factura', 'Corrigió los datos de la factura de la venta ' . folio($id) . ' (' . $datos['rfc'] . ')', 'venta', $id);
                }
                // Se guardan en el cliente para la próxima vez.
                if ($clienteId && !empty($_POST['guardar_en_cliente'])) {
                    db()->prepare('UPDATE clientes SET rfc = :rfc, razon_social = :razon, cp_fiscal = :cp, regimen_fiscal = :regimen,
                                                       uso_cfdi = :uso, correo = :correo WHERE id = :id')->execute($params + [':id' => $clienteId]);
                }
                transaccion_confirmar();
            } catch (Throwable $e) {
                transaccion_revertir();
                throw $e;
            }
            flash('exito', $accion === 'pedir' ? 'Factura pedida. Queda en "Facturas por hacer".' : 'Datos corregidos.');
            $volver();
        }
        flash('error', 'Revisa los datos marcados en rojo.');
    } elseif ($accion === 'hecha' || $accion === 'archivos') {
        if (!$vigente || ($accion === 'hecha' && $vigente['estado'] !== 'pendiente') || ($accion === 'archivos' && $vigente['estado'] !== 'hecha')) {
            flash('aviso', 'La factura cambió mientras tanto; revisa cómo quedó.');
            $volver();
        }
        $uuid = mb_strtoupper(trim((string) ($_POST['uuid'] ?? '')));
        if ($uuid !== '' && !$uuidValido($uuid)) {
            flash('error', 'El folio fiscal tiene la forma XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX (viene en la factura). Puedes dejarlo vacío si subes el XML.');
            $volver();
        }
        transaccion_iniciar();
        try {
            $uuidXml = $guardarArchivos($vigente, $uuid ?: ($accion === 'archivos' ? $vigente['uuid'] : null));
            $uuidFinal = $uuidXml ?? ($uuid ?: $vigente['uuid']);
            db()->prepare("UPDATE facturas SET estado = 'hecha', uuid = :uuid, hecha_en = COALESCE(hecha_en, :fecha) WHERE id = :id")
                ->execute([':uuid' => $uuidFinal ?: null, ':fecha' => date('Y-m-d H:i:s'), ':id' => $vigente['id']]);
            if ($accion === 'hecha') {
                auditar('factura', 'Marcó como hecha la factura de la venta ' . folio($id) . ($uuidFinal ? " (folio fiscal $uuidFinal)" : ''), 'venta', $id);
            }
            transaccion_confirmar();
        } catch (RuntimeException $e) {
            transaccion_revertir();
            if ($e instanceof PDOException) {
                throw $e;
            }
            flash('error', $e->getMessage());
            $volver();
        }
        $mensajeOk = $accion === 'hecha' ? 'Factura marcada como hecha.' : 'Archivos guardados.';
        $para = mb_strtolower(trim((string) ($_POST['correo_envio'] ?? '')));
        if (!empty($_POST['mandar']) && $para !== '') {
            try {
                enviar_factura_por_correo(factura_por_id((int) $vigente['id']), $para);
                $mensajeOk .= " Se mandó por correo a $para.";
            } catch (RuntimeException $e) {
                flash('aviso', $mensajeOk . ' Pero el correo no salió: ' . $e->getMessage());
                $volver();
            }
        }
        flash('exito', $mensajeOk);
        $volver();
    } elseif ($accion === 'enviar') {
        $para = mb_strtolower(trim((string) ($_POST['correo_envio'] ?? '')));
        if (($vigente['estado'] ?? '') !== 'hecha') {
            flash('error', 'Solo se manda una factura que ya está hecha.');
            $volver();
        }
        try {
            enviar_factura_por_correo($vigente, $para);
            if ($para !== '' && !$vigente['correo']) {
                db()->prepare('UPDATE facturas SET correo = :c WHERE id = :id')->execute([':c' => $para, ':id' => $vigente['id']]);
            }
            flash('exito', "Factura mandada a $para.");
        } catch (RuntimeException $e) {
            flash('error', 'No se mandó: ' . $e->getMessage());
        }
        $volver();
    } elseif ($accion === 'cancelar') {
        if (!$vigente) {
            flash('aviso', 'Esta factura ya estaba cancelada.');
            $volver();
        }
        $ahora = date('Y-m-d H:i:s');
        if ($vigente['estado'] === 'pendiente') {
            // Todavía no existe en el SAT: solo se cancela la solicitud.
            $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 255);
            db()->prepare("UPDATE facturas SET estado = 'cancelada', cancelada_en = :f, notas = :n WHERE id = :id")
                ->execute([':f' => $ahora, ':n' => $nota ?: null, ':id' => $vigente['id']]);
            auditar('factura', 'Canceló la solicitud de factura de la venta ' . folio($id) . ($nota ? ". Motivo: $nota" : ''), 'venta', $id);
            flash('exito', 'Se canceló la solicitud. Si hace falta, puedes pedirla otra vez con los datos correctos.');
            $volver();
        }
        $motivo = (string) ($_POST['motivo'] ?? '');
        if (!isset(MOTIVOS_CANCELACION[$motivo])) {
            flash('error', 'Elige por qué se cancela la factura (el SAT lo pide).');
            $volver();
        }
        $sustituto = mb_strtoupper(trim((string) ($_POST['uuid_sustituto'] ?? '')));
        if ($motivo === '01') {
            [$datos, $errores] = validar_datos_fiscales($_POST);
            if (!$uuidValido($sustituto)) {
                $errores['uuid_sustituto'] = 'Pega el folio fiscal de la factura nueva (la que la reemplaza).';
            } elseif ($sustituto === $vigente['uuid']) {
                $errores['uuid_sustituto'] = 'Ese es el folio de la factura que vas a cancelar; pega el de la nueva.';
            }
        }
        if (!$errores) {
            transaccion_iniciar();
            try {
                db()->prepare("UPDATE facturas SET estado = 'cancelada', motivo_cancelacion = :m, uuid_sustituto = :s, cancelada_en = :f WHERE id = :id")
                    ->execute([':m' => $motivo, ':s' => $motivo === '01' ? $sustituto : null, ':f' => $ahora, ':id' => $vigente['id']]);
                if ($motivo === '01') {
                    db()->prepare("INSERT INTO facturas (venta_id, cliente_id, rfc, razon_social, cp_fiscal, regimen_fiscal, uso_cfdi, correo, estado, uuid,
                                                         solicitada_en, hecha_en, usuario_id, sustituye_id)
                                   VALUES (:venta, :cliente, :rfc, :razon, :cp, :regimen, :uso, :correo, 'hecha', :uuid, :f, :f, :usuario, :sustituye)")
                        ->execute([':venta' => $id, ':cliente' => $vigente['cliente_id'], ':rfc' => $datos['rfc'], ':razon' => $datos['razon_social'],
                                   ':cp' => $datos['cp_fiscal'], ':regimen' => $datos['regimen_fiscal'], ':uso' => $datos['uso_cfdi'],
                                   ':correo' => $datos['correo'] ?: $vigente['correo'], ':uuid' => $sustituto, ':f' => $ahora, ':usuario' => usuario_id(),
                                   ':sustituye' => $vigente['id']]);
                }
                auditar('factura', 'Canceló la factura de la venta ' . folio($id) . ($vigente['uuid'] ? " ({$vigente['uuid']})" : '') . " con motivo $motivo"
                    . ($motivo === '01' ? ", reemplazada por $sustituto" : ''), 'venta', $id);
                transaccion_confirmar();
            } catch (Throwable $e) {
                transaccion_revertir();
                throw $e;
            }
            flash('exito', 'Factura cancelada aquí. Ahora cancélala también en el portal del SAT con el motivo ' . $motivo . ' (abajo te dice cómo).');
            $volver();
        }
        flash('error', 'Revisa los datos marcados en rojo.');
    } elseif ($accion === 'cancelada_sat') {
        $fid = filter_var($_POST['factura_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $st = db()->prepare("UPDATE facturas SET cancelada_sat = 1 WHERE id = :id AND venta_id = :venta AND estado = 'cancelada' AND motivo_cancelacion IS NOT NULL");
        $st->execute([':id' => $fid, ':venta' => $id]);
        if ($st->rowCount()) {
            auditar('factura', 'Confirmó que canceló en el SAT la factura de la venta ' . folio($id), 'venta', $id);
            flash('exito', 'Listo, quedó como cancelada también en el SAT.');
        }
        $volver();
    }
}

// Clientes registrados, para llenar los datos con un clic.
$clientes = db()->query('SELECT id, nombre, rfc, razon_social, cp_fiscal, regimen_fiscal, uso_cfdi, correo FROM clientes WHERE activo = 1 ORDER BY sin_acentos(nombre)')->fetchAll();
if (!$errores && $vigente) {
    $datos = array_intersect_key($vigente, $datos) + $datos;     // corregir o reemplazar parte de los datos actuales
    $datos['correo'] ??= '';
} elseif (!$errores && $clienteId && ($c = cliente_por_id($clienteId)) && $c['rfc']) {
    $datos = array_merge($datos, array_intersect_key(array_filter($c, fn($x) => $x !== null), $datos));
}
$st = db()->prepare("SELECT * FROM facturas WHERE venta_id = :id AND estado = 'cancelada' ORDER BY id DESC");
$st->execute([':id' => $id]);
$canceladas = $st->fetchAll();
$archivos = $vigente ? archivos_factura((int) $vigente['id']) : [];
$conceptos = conceptos_factura($id);
$tot = totales_factura($conceptos);
$mesAnterior = substr($venta['fecha'], 0, 7) !== date('Y-m');
$administra = puede_administrar_facturas();
$correoListo = correo_configurado();
$abrirCancelar = isset($errores['uuid_sustituto']) || (($_POST['accion'] ?? '') === 'cancelar' && $errores);

$v = fn(string $c) => e($datos[$c] ?? '');
$invalido = fn(string $c) => isset($errores[$c]) ? ' is-invalid' : '';
$mensaje  = fn(string $c) => isset($errores[$c]) ? '<div class="invalid-feedback">' . e($errores[$c]) . '</div>' : '';

/** Campos de los datos fiscales (se usan al pedir, corregir y reemplazar). */
$camposFiscales = function () use ($datos, $v, $invalido, $mensaje): void { ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="rfc" class="form-label">RFC <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-lg num text-uppercase<?= $invalido('rfc') ?>" id="rfc" name="rfc" value="<?= $v('rfc') ?>" maxlength="13" autocomplete="off" placeholder="GOMA800101AB1">
            <?= $mensaje('rfc') ?>
        </div>
        <div class="col-md-6">
            <label for="cp_fiscal" class="form-label">Código postal <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-lg num<?= $invalido('cp_fiscal') ?>" id="cp_fiscal" name="cp_fiscal" value="<?= $v('cp_fiscal') ?>" maxlength="5" inputmode="numeric" placeholder="64000">
            <?= $mensaje('cp_fiscal') ?>
        </div>
        <div class="col-12">
            <label for="razon_social" class="form-label">Nombre o razón social <span class="text-danger">*</span></label>
            <input type="text" class="form-control text-uppercase<?= $invalido('razon_social') ?>" id="razon_social" name="razon_social" value="<?= $v('razon_social') ?>" maxlength="254" placeholder="Como viene en la constancia, sin &quot;S.A. de C.V.&quot;">
            <?= $mensaje('razon_social') ?>
        </div>
        <div class="col-md-6">
            <label for="regimen_fiscal" class="form-label">Régimen fiscal <span class="text-danger">*</span></label>
            <select class="form-select<?= $invalido('regimen_fiscal') ?>" id="regimen_fiscal" name="regimen_fiscal">
                <option value="">Elige…</option>
                <?php foreach (REGIMENES_FISCALES as $clave => $etiqueta): ?>
                    <option value="<?= e($clave) ?>" <?= (string) $datos['regimen_fiscal'] === (string) $clave ? 'selected' : '' ?>><?= e($clave . ' · ' . $etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $mensaje('regimen_fiscal') ?>
        </div>
        <div class="col-md-6">
            <label for="uso_cfdi" class="form-label">¿Para qué la usa? <span class="text-danger">*</span></label>
            <select class="form-select<?= $invalido('uso_cfdi') ?>" id="uso_cfdi" name="uso_cfdi">
                <?php foreach (USOS_CFDI as $clave => $etiqueta): ?>
                    <option value="<?= e($clave) ?>" <?= $datos['uso_cfdi'] === $clave ? 'selected' : '' ?>><?= e($clave . ' · ' . $etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $mensaje('uso_cfdi') ?>
            <div class="form-text">Si no sabe, casi siempre es "Gastos en general".</div>
        </div>
        <div class="col-12">
            <label for="correo" class="form-label">Correo para mandarle la factura <span class="text-body-secondary fw-normal">(opcional)</span></label>
            <input type="email" class="form-control<?= $invalido('correo') ?>" id="correo" name="correo" value="<?= $v('correo') ?>" maxlength="120">
            <?= $mensaje('correo') ?>
        </div>
    </div>
<?php };

$titulo  = 'Factura · venta ' . folio($id);
$seccion = 'historial';
require __DIR__ . '/../includes/encabezado.php';
?>
<nav aria-label="Ruta" class="mb-3"><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= e(url('ventas/historial.php')) ?>">Ventas hechas</a></li>
    <li class="breadcrumb-item"><a href="<?= e(url('ventas/detalle.php?id=' . $id)) ?>">Folio <?= e(folio($id)) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page">Factura</li>
</ol></nav>

<div class="row g-4">
    <div class="col-lg-7">
        <?php if ($vigente): [$etiqueta, $color] = ESTADOS_FACTURA[$vigente['estado']]; $hecha = $vigente['estado'] === 'hecha'; ?>
            <div class="card mb-4">
                <div class="card-header"><h2><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Factura</h2>
                    <span class="badge rounded-pill text-bg-<?= e($color) ?> fs-6"><?= e($etiqueta) ?></span></div>
                <div class="card-body p-4">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">RFC</dt><dd class="col-sm-8 num"><?= e($vigente['rfc']) ?></dd>
                        <dt class="col-sm-4">Nombre o razón social</dt><dd class="col-sm-8"><?= e($vigente['razon_social']) ?></dd>
                        <dt class="col-sm-4">Código postal</dt><dd class="col-sm-8 num"><?= e($vigente['cp_fiscal']) ?></dd>
                        <dt class="col-sm-4">Régimen</dt><dd class="col-sm-8"><?= e($vigente['regimen_fiscal'] . ' · ' . (REGIMENES_FISCALES[$vigente['regimen_fiscal']] ?? '')) ?></dd>
                        <dt class="col-sm-4">Uso</dt><dd class="col-sm-8"><?= e($vigente['uso_cfdi'] . ' · ' . (USOS_CFDI[$vigente['uso_cfdi']] ?? '')) ?></dd>
                        <?php if ($vigente['correo']): ?><dt class="col-sm-4">Correo</dt><dd class="col-sm-8"><?= e($vigente['correo']) ?></dd><?php endif; ?>
                        <dt class="col-sm-4">Pedida</dt><dd class="col-sm-8"><?= e(fecha_fmt($vigente['solicitada_en'])) ?></dd>
                        <?php if ($hecha): ?>
                            <dt class="col-sm-4">Hecha</dt><dd class="col-sm-8"><?= e(fecha_fmt($vigente['hecha_en'])) ?></dd>
                            <dt class="col-sm-4">Folio fiscal</dt><dd class="col-sm-8 num small"><?= e($vigente['uuid'] ?: '—') ?></dd>
                            <?php if ($vigente['sustituye_id']): ?><dt class="col-sm-4">Reemplaza a</dt><dd class="col-sm-8 small">la factura cancelada de abajo (motivo 01)</dd><?php endif; ?>
                            <?php if ($vigente['enviada_en']): ?><dt class="col-sm-4">Mandada</dt><dd class="col-sm-8"><?= e(fecha_fmt($vigente['enviada_en'])) ?> a <?= e($vigente['enviada_a']) ?></dd><?php endif; ?>
                        <?php endif; ?>
                    </dl>
                    <?php if ($venta['estado'] === 'cancelada'): ?>
                        <div class="alert alert-danger mt-3 mb-0">La venta se canceló.<?= $hecha ? ' Cancela también esta factura con el motivo <strong>03</strong> (la venta no se llevó a cabo).' : '' ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$hecha): ?>
                <?php if ($administra): ?>
                    <form method="post" enctype="multipart/form-data" class="card mb-4">
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="hecha">
                        <div class="card-header"><h2><i class="bi bi-check2-circle me-2 text-success"></i>¿Ya la hiciste en el SAT?</h2></div>
                        <div class="card-body p-4">
                            <p class="small text-body-secondary">Sube el XML y el PDF que te dio el SAT o tu contador. El folio fiscal se lee solo del XML.</p>
                            <div class="row g-3">
                                <div class="col-md-6"><label for="xml" class="form-label">XML</label><input type="file" class="form-control" id="xml" name="xml" accept=".xml,text/xml,application/xml"></div>
                                <div class="col-md-6"><label for="pdf" class="form-label">PDF</label><input type="file" class="form-control" id="pdf" name="pdf" accept=".pdf,application/pdf"></div>
                                <div class="col-12">
                                    <label for="uuid" class="form-label small">Folio fiscal <span class="text-body-secondary">(solo si no subes el XML)</span></label>
                                    <input type="text" class="form-control num text-uppercase" id="uuid" name="uuid" maxlength="36" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX">
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="mandar" name="mandar" value="1" <?= $correoListo && $vigente['correo'] ? 'checked' : '' ?> <?= $correoListo ? '' : 'disabled' ?>>
                                        <label class="form-check-label" for="mandar">Mandarla por correo al cliente</label>
                                    </div>
                                    <input type="email" class="form-control mt-2" name="correo_envio" value="<?= e($vigente['correo'] ?? '') ?>" placeholder="correo@del-cliente.com" <?= $correoListo ? '' : 'disabled' ?>>
                                    <?php if (!$correoListo): ?><div class="form-text">Para mandar correos, configúralo en <a href="<?= e(url('ajustes.php#correo')) ?>">Configuración → Correo</a>.</div><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end"><button class="btn btn-success btn-lg" type="submit"><i class="bi bi-check2-circle me-1"></i>Ya la hice</button></div>
                    </form>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header"><h2><i class="bi bi-pencil me-2 text-primary"></i>¿Algo está mal?</h2></div>
                    <div class="card-body p-4">
                        <button class="btn btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#formCorregir" aria-expanded="<?= $errores ? 'true' : 'false' ?>">
                            <i class="bi bi-pencil-square me-1"></i>Corregir los datos
                        </button>
                        <form method="post" novalidate id="formCorregir" class="collapse<?= $errores ? ' show' : '' ?> mt-3">
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="corregir">
                            <?php $camposFiscales(); ?>
                            <div class="d-flex justify-content-end mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Guardar cambios</button></div>
                        </form>
                        <?php if ($administra): ?>
                            <hr>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-end" data-confirmar="¿Cancelar esta solicitud de factura?"
                                  data-confirmar-detalle="Todavía no se hace en el SAT, así que solo se quita de la lista." data-confirmar-boton="Sí, cancelar" data-confirmar-color="#dc3545">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cancelar">
                                <div class="flex-grow-1"><label for="nota" class="form-label small mb-1">Motivo <span class="text-body-secondary">(opcional)</span></label>
                                    <input type="text" class="form-control" id="nota" name="nota" maxlength="255" placeholder="Ej. El cliente ya no la quiere"></div>
                                <button class="btn btn-outline-danger" type="submit"><i class="bi bi-x-circle me-1"></i>Cancelar solicitud</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card mb-4">
                    <div class="card-header"><h2><i class="bi bi-paperclip me-2 text-primary"></i>Archivos y correo</h2></div>
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <?php foreach (['xml' => 'bi-filetype-xml', 'pdf' => 'bi-filetype-pdf'] as $tipo => $icono): ?>
                                <?php if (isset($archivos[$tipo])): ?>
                                    <a class="btn btn-light border" href="<?= e(url('facturas/archivo.php?id=' . (int) $vigente['id'] . '&tipo=' . $tipo)) ?>"><i class="bi <?= $icono ?> me-1"></i><?= e($archivos[$tipo]['nombre']) ?></a>
                                <?php else: ?>
                                    <span class="btn btn-light border disabled"><i class="bi <?= $icono ?> me-1"></i>Sin <?= strtoupper($tipo) ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($administra): ?>
                            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-4">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="archivos">
                                <div class="col-md-5"><label for="xml" class="form-label small mb-1"><?= isset($archivos['xml']) ? 'Cambiar' : 'Subir' ?> XML</label><input type="file" class="form-control form-control-sm" id="xml" name="xml" accept=".xml"></div>
                                <div class="col-md-5"><label for="pdf" class="form-label small mb-1"><?= isset($archivos['pdf']) ? 'Cambiar' : 'Subir' ?> PDF</label><input type="file" class="form-control form-control-sm" id="pdf" name="pdf" accept=".pdf"></div>
                                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit">Subir</button></div>
                            </form>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="enviar">
                                <div class="flex-grow-1"><label for="correo_envio" class="form-label small mb-1">Mandar por correo a</label>
                                    <input type="email" class="form-control" id="correo_envio" name="correo_envio" value="<?= e($vigente['correo'] ?? '') ?>" placeholder="correo@del-cliente.com" required></div>
                                <button class="btn btn-primary" type="submit" <?= $correoListo && $archivos ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i><?= $vigente['enviada_en'] ? 'Volver a mandar' : 'Mandar' ?></button>
                            </form>
                            <?php if (!$correoListo): ?>
                                <div class="form-text">Para mandar correos, configúralo en <a href="<?= e(url('ajustes.php#correo')) ?>">Configuración → Correo</a>.</div>
                            <?php elseif (!$archivos): ?>
                                <div class="form-text">Primero sube el XML y el PDF.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($administra): ?>
                    <div class="card mb-4 border-danger-subtle">
                        <div class="card-header"><h2><i class="bi bi-x-octagon me-2 text-danger"></i>Cancelar la factura</h2>
                            <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#formCancelar" aria-expanded="<?= $abrirCancelar ? 'true' : 'false' ?>">Cancelar factura</button></div>
                        <form method="post" novalidate id="formCancelar" class="collapse<?= $abrirCancelar ? ' show' : '' ?>" data-confirmar="¿Cancelar esta factura?"
                              data-confirmar-detalle="Después tienes que cancelarla también en el portal del SAT." data-confirmar-boton="Sí, cancelarla" data-confirmar-color="#dc3545">
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cancelar">
                            <div class="card-body p-4">
                                <div class="form-label">¿Por qué se cancela?</div>
                                <?php $motivoElegido = (string) ($_POST['motivo'] ?? ($venta['estado'] === 'cancelada' ? '03' : '02')); ?>
                                <?php foreach (MOTIVOS_CANCELACION as $clave => $etiqueta): ?>
                                    <div class="form-check mb-1">
                                        <input class="form-check-input motivo-cancelacion" type="radio" name="motivo" id="motivo<?= $clave ?>" value="<?= $clave ?>" <?= $motivoElegido === (string) $clave ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="motivo<?= $clave ?>"><strong><?= $clave ?></strong> · <?= e($etiqueta) ?></label>
                                    </div>
                                <?php endforeach; ?>
                                <div id="camposSustitucion" class="mt-3">
                                    <div class="alert alert-info small">Primero haz la factura nueva (con los datos bien) en el SAT. Luego pega aquí su folio fiscal y corrige los datos.</div>
                                    <label for="uuid_sustituto" class="form-label">Folio fiscal de la factura nueva</label>
                                    <input type="text" class="form-control num text-uppercase mb-3<?= $invalido('uuid_sustituto') ?>" id="uuid_sustituto" name="uuid_sustituto"
                                           value="<?= e($_POST['uuid_sustituto'] ?? '') ?>" maxlength="36" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX">
                                    <?= $mensaje('uuid_sustituto') ?>
                                    <?php $camposFiscales(); ?>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-end"><button class="btn btn-danger" type="submit"><i class="bi bi-x-circle me-1"></i>Cancelar factura</button></div>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$administra): ?>
                <p class="small text-body-secondary"><i class="bi bi-lock me-1"></i>Marcarla como hecha, mandarla o cancelarla lo hace el dueño o quien tenga permiso.</p>
            <?php endif; ?>
        <?php elseif ($venta['estado'] !== 'completada'): ?>
            <div class="alert alert-warning">Esta venta está cancelada: no se puede facturar.</div>
        <?php else: ?>
            <form method="post" novalidate class="card mb-4">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="pedir">
                <div class="card-header"><h2><i class="bi bi-receipt-cutoff me-2 text-primary"></i><?= $canceladas ? 'Pedir la factura otra vez' : 'Datos para la factura' ?></h2></div>
                <div class="card-body p-4">
                    <p class="text-body-secondary">Pídele al cliente su <strong>Constancia de Situación Fiscal</strong> (o una foto) y copia los datos tal cual.</p>
                    <?php if ($mesAnterior): ?>
                        <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Esta venta es de un mes anterior. Si ya entró en tu factura global, pregúntale a tu contador antes de facturarla.</div>
                    <?php endif; ?>
                    <?php if ($clientes): ?>
                        <div class="mb-3">
                            <label for="cliente_id" class="form-label">Cliente <span class="text-body-secondary fw-normal">(si ya lo tienes registrado, se llenan sus datos)</span></label>
                            <select class="form-select" id="cliente_id" name="cliente_id">
                                <option value="">— Otro / no registrado —</option>
                                <?php foreach ($clientes as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $clienteId ? 'selected' : '' ?>
                                            data-datos="<?= e(json_encode(array_intersect_key($c, $datos), JSON_UNESCAPED_UNICODE)) ?>"><?= e($c['nombre']) ?><?= $c['rfc'] ? ' · ' . e($c['rfc']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="guardar_en_cliente" name="guardar_en_cliente" value="1" checked>
                                <label class="form-check-label small" for="guardar_en_cliente">Guardar estos datos en el cliente para la próxima vez</label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php $camposFiscales(); ?>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check2-circle me-1"></i>Pedir factura</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($canceladas): ?>
            <div class="card">
                <div class="card-header"><h2><i class="bi bi-clock-history me-2 text-secondary"></i>Facturas canceladas de esta venta</h2></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($canceladas as $fc): ?>
                        <li class="list-group-item p-3">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <div class="fw-semibold"><?= e($fc['razon_social']) ?> <span class="num small text-body-secondary"><?= e($fc['rfc']) ?></span></div>
                                    <div class="small text-body-secondary">
                                        Cancelada <?= $fc['cancelada_en'] ? e(fecha_fmt($fc['cancelada_en'])) : '' ?>
                                        <?= $fc['motivo_cancelacion'] ? ' · motivo ' . e($fc['motivo_cancelacion']) . ' (' . e(MOTIVOS_CANCELACION[$fc['motivo_cancelacion']] ?? '') . ')' : ' · era solo una solicitud' ?>
                                        <?= $fc['notas'] ? ' · ' . e($fc['notas']) : '' ?>
                                    </div>
                                    <?php if ($fc['uuid']): ?><div class="small num">Folio fiscal: <?= e($fc['uuid']) ?></div><?php endif; ?>
                                    <?php if ($fc['uuid_sustituto']): ?><div class="small num">Reemplazada por: <?= e($fc['uuid_sustituto']) ?></div><?php endif; ?>
                                </div>
                                <?php if ($fc['motivo_cancelacion']): ?>
                                    <?php if ($fc['cancelada_sat']): ?>
                                        <span class="badge rounded-pill text-bg-success-subtle text-success-emphasis align-self-start"><i class="bi bi-check2 me-1"></i>Cancelada en el SAT</span>
                                    <?php elseif ($administra): ?>
                                        <form method="post" class="align-self-start">
                                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cancelada_sat"><input type="hidden" name="factura_id" value="<?= (int) $fc['id'] ?>">
                                            <button class="btn btn-sm btn-warning" type="submit"><i class="bi bi-check2-square me-1"></i>Ya la cancelé en el SAT</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($fc['motivo_cancelacion'] && !$fc['cancelada_sat']): ?>
                                <div class="alert alert-warning small mt-2 mb-0">
                                    <strong>Falta cancelarla en el SAT:</strong> entra a sat.gob.mx → Factura electrónica → <em>Cancela y recupera tus facturas</em>
                                    (con tu e.firma), busca el folio fiscal de arriba y cancélala con el motivo <strong><?= e($fc['motivo_cancelacion']) ?></strong><?= $fc['uuid_sustituto'] ? ' poniendo el folio de la que la reemplaza' : '' ?>.
                                    Si es de más de $1,000, el cliente tiene que aceptar la cancelación en su buzón del SAT (si no contesta en 3 días, se acepta sola).
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h2><i class="bi bi-list-ul me-2 text-primary"></i>Lo que se factura</h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Sin IVA</th><th class="text-end">IVA</th></tr></thead>
                    <tbody>
                    <?php foreach ($conceptos as $c): ?>
                        <tr><td><?= e($c['nombre']) ?><div class="small text-body-secondary"><?= e(tasa_iva_corta($c['tasa'])) ?></div></td>
                            <td class="text-end num"><?= e(cantidad_fmt($c['cantidad'])) ?></td>
                            <td class="text-end num"><?= dinero($c['base'] / 100) ?></td>
                            <td class="text-end num"><?= dinero($c['iva'] / 100) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="2" class="text-end">Subtotal sin IVA</td><td colspan="2" class="text-end num"><?= dinero($tot['base'] / 100) ?></td></tr>
                        <?php if ($tot['iva16']): ?><tr><td colspan="2" class="text-end">IVA 16%</td><td colspan="2" class="text-end num"><?= dinero($tot['iva16'] / 100) ?></td></tr><?php endif; ?>
                        <?php if ($tot['iva8']): ?><tr><td colspan="2" class="text-end">IVA 8%</td><td colspan="2" class="text-end num"><?= dinero($tot['iva8'] / 100) ?></td></tr><?php endif; ?>
                        <tr class="fw-bold"><td colspan="2" class="text-end">Total</td><td colspan="2" class="text-end num fs-5"><?= dinero($tot['total'] / 100) ?></td></tr>
                    </tfoot>
                </table>
            </div>
            <div class="card-footer small text-body-secondary">
                Forma de pago: <?= e(forma_pago_sat($id)) ?> · Método: <?= forma_pago_sat($id) === '99' ? 'PPD (se paga después)' : 'PUE (pagado en una sola exhibición)' ?>
            </div>
        </div>
        <p class="small text-body-secondary mt-3"><i class="bi bi-info-circle me-1"></i>
            El sistema guarda los datos, te prepara la lista y manda la factura por correo. La factura se hace (timbra) en la página gratuita del SAT o con tu contador.</p>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sel = document.getElementById('cliente_id');
    if (sel) {
        sel.addEventListener('change', function () {
            const opt = sel.selectedOptions[0];
            const datos = opt && opt.dataset.datos ? JSON.parse(opt.dataset.datos) : null;
            if (!datos || !datos.rfc) { return; }
            Object.keys(datos).forEach(function (campo) {
                const inp = document.getElementById(campo);
                if (inp && datos[campo]) { inp.value = datos[campo]; }
            });
        });
    }
    // Motivo 01: pide el folio de la factura nueva y los datos corregidos.
    const sustitucion = document.getElementById('camposSustitucion');
    function actualizarMotivo() {
        const elegido = document.querySelector('.motivo-cancelacion:checked');
        sustitucion.classList.toggle('d-none', !elegido || elegido.value !== '01');
    }
    if (sustitucion) {
        document.querySelectorAll('.motivo-cancelacion').forEach(r => r.addEventListener('change', actualizarMotivo));
        actualizarMotivo();
    }
});
</script>
<?php require __DIR__ . '/../includes/pie.php'; ?>
