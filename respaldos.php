<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Respaldos: descargar una copia de la base de datos, ver las copias
 * automáticas diarias y restaurar una copia anterior.
 */
require_once __DIR__ . '/includes/app.php';

requerir_dueno();

// Descargar: una copia nueva (?descargar=1) o una copia guardada (?archivo=nombre.sqlite).
if (isset($_GET['descargar']) || isset($_GET['archivo'])) {
    $temporal = null;
    if (isset($_GET['archivo'])) {
        $ruta = ruta_respaldo((string) $_GET['archivo']);
        if ($ruta === null) {
            flash('error', 'Ese respaldo ya no existe.');
            redirigir('respaldos.php');
        }
        $nombre = basename($ruta);
    } else {
        $temporal = tempnam(sys_get_temp_dir(), 'tw');
        @unlink($temporal);   // VACUUM INTO necesita que el archivo no exista
        crear_respaldo($temporal);
        $ruta   = $temporal;
        $nombre = 'punto-de-venta-respaldo-' . date('Y-m-d-Hi') . '.sqlite';
    }
    auditar('respaldo_descargado', 'Descargó el respaldo «' . $nombre . '»');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . filesize($ruta));
    header('Cache-Control: no-store');
    readfile($ruta);
    if ($temporal !== null) {
        @unlink($temporal);
    }
    exit;
}

// Restaurar desde un archivo elegido por el usuario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $subido = $_FILES['respaldo'] ?? null;
    if (empty($_POST['confirmo'])) {
        flash('error', 'Marca la casilla de confirmación para restaurar.');
    } elseif (!is_array($subido) || ($subido['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $codigo = is_array($subido) ? ($subido['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        flash('error', in_array($codigo, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'El archivo es demasiado grande para subirlo.'
            : 'Elige el archivo de respaldo (.sqlite) que quieres restaurar.');
    } else {
        try {
            $copia = restaurar_respaldo($subido['tmp_name']);
            auditar('respaldo_restaurado', 'Restauró un respaldo. Lo que había antes quedó en «' . $copia . '»');
            cerrar_sesion_usuario();   // los usuarios pudieron cambiar: hay que volver a entrar
            flash('exito', 'Respaldo restaurado. Por si acaso, lo que había antes se guardó como «' . $copia . '».');
        } catch (RuntimeException $ex) {
            if ($ex instanceof PDOException) {
                error_log('TiendaWeb: error al restaurar respaldo: ' . $ex->getMessage());
            }
            flash('error', $ex instanceof PDOException
                ? 'No se pudo restaurar el respaldo. Tus datos no se modificaron.'
                : $ex->getMessage());
        }
    }
    redirigir('respaldos.php');
}

$respaldos = listar_respaldos();
$archivoBd = archivo_base_datos();

$titulo  = 'Respaldos';
$seccion = 'ajustes';
require __DIR__ . '/includes/encabezado.php';
require __DIR__ . '/includes/pestanas_ajustes.php';
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2><i class="bi bi-download me-2 text-primary"></i>Guardar una copia</h2></div>
            <div class="card-body">
                <p>Descarga una copia completa de tus productos y ventas. Guárdala en una <strong>USB</strong> o en tu
                    correo para no perder nada si la computadora se descompone.</p>
                <a href="<?= e(url('respaldos.php?descargar=1')) ?>" class="btn btn-primary btn-lg">
                    <i class="bi bi-download me-2"></i>Descargar respaldo ahora
                </a>
                <p class="text-body-secondary small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>Además, el sistema guarda solo una copia cada día y conserva
                    las últimas <?= (int) RESPALDOS_A_CONSERVAR ?>.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100 border-warning-subtle">
            <div class="card-header"><h2><i class="bi bi-arrow-counterclockwise me-2 text-warning"></i>Restaurar una copia</h2></div>
            <div class="card-body">
                <p>Úsalo para pasar tus datos a otra computadora o para regresar a una copia anterior.
                    <strong>Lo que hay ahora se reemplaza</strong> (antes se guarda una copia automáticamente).</p>
                <form method="post" enctype="multipart/form-data" id="formRestaurar">
                    <?= csrf_campo() ?>
                    <input type="file" name="respaldo" class="form-control mb-3" accept=".sqlite" required>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirmo" value="1" id="confirmo" required>
                        <label class="form-check-label" for="confirmo">Entiendo que los datos actuales se reemplazarán por los del respaldo.</label>
                    </div>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-clock-history me-2 text-primary"></i>Copias guardadas en esta computadora</h2>
                <span class="badge rounded-pill text-bg-light border"><?= count($respaldos) ?></span>
            </div>
            <?php if (!$respaldos): ?>
                <div class="card-body text-body-secondary">Todavía no hay copias guardadas.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Archivo</th><th>Fecha</th><th class="text-end">Tamaño</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($respaldos as $r): ?>
                            <tr>
                                <td><i class="bi bi-file-earmark-zip me-1 text-body-secondary"></i><?= e($r['nombre']) ?></td>
                                <td><?= e(fecha_fmt(date('Y-m-d H:i:s', $r['fecha']))) ?></td>
                                <td class="text-end num"><?= e(tamano_fmt($r['tamano'])) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-light border" href="<?= e(url('respaldos.php?archivo=' . rawurlencode($r['nombre']))) ?>">
                                        <i class="bi bi-download me-1"></i>Descargar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="card-footer small text-body-secondary">
                Base de datos: <code><?= e($archivoBd) ?></code><br>
                Copias automáticas: <code><?= e(carpeta_respaldos()) ?></code>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/pie.php'; ?>
