<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Asistente de primer uso: tipo y nombre del negocio, impresora de tickets
 * (opcional), empezar vacío o con
 * productos de ejemplo, y usuario dueño con PIN.
 *
 * Solo aparece en una base sin estrenar (ver necesita_bienvenida()); en una
 * tienda que ya vendió no se puede abrir, así que no sirve para borrar datos.
 */
const PAGINA_SIN_SESION = true;
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/limpieza.php';

if (!necesita_bienvenida()) {
    redirigir('index.php');
}

$productosActuales = (int) db()->query('SELECT COUNT(*) FROM productos')->fetchColumn();
$errores = [];
$sesionVencida = false;
$form = ['giro' => GIRO_PREDETERMINADO, 'negocio_nombre' => ajuste('negocio_nombre'), 'negocio_datos' => ajuste('negocio_datos'),
         'productos' => 'vacio', 'proteger' => true, 'nombre' => '',
         'impresora_tipo' => 'ninguna', 'impresora_nombre' => '', 'impresora_ip' => '', 'impresora_puerto' => '9100', 'papel' => '80'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sin token válido no se guarda nada, pero tampoco se tira lo que la persona ya escribió.
    $token = $_POST['csrf_token'] ?? null;
    $sesionVencida = !csrf_valido(is_string($token) ? $token : null);
    $form = [
        'giro'           => isset(GIROS[$_POST['giro'] ?? '']) ? $_POST['giro'] : GIRO_PREDETERMINADO,
        'negocio_nombre' => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['negocio_nombre'] ?? '')) ?? ''),
        'negocio_datos'  => trim(str_replace("\r\n", "\n", (string) ($_POST['negocio_datos'] ?? ''))),
        'productos'      => in_array($_POST['productos'] ?? '', ['vacio', 'ejemplos', 'conservar'], true) ? $_POST['productos'] : 'vacio',
        'proteger'       => !empty($_POST['proteger']),
        'nombre'         => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')) ?? ''),
        'impresora_tipo'   => in_array($_POST['impresora_tipo'] ?? '', ['ninguna', 'windows', 'red'], true) ? $_POST['impresora_tipo'] : 'ninguna',
        'impresora_nombre' => trim((string) ($_POST['impresora_nombre'] ?? '')),
        'impresora_ip'     => trim((string) ($_POST['impresora_ip'] ?? '')),
        'impresora_puerto' => trim((string) ($_POST['impresora_puerto'] ?? '')) ?: '9100',
        'papel'            => ($_POST['papel'] ?? '80') === '58' ? '58' : '80',
    ];
    $pin = (string) ($_POST['pin'] ?? '');

    if ($form['negocio_nombre'] === '' || mb_strlen($form['negocio_nombre']) > 60) {
        $errores['negocio_nombre'] = 'Escribe el nombre del negocio (máximo 60 caracteres).';
    }
    if (mb_strlen($form['negocio_datos']) > 300) {
        $errores['negocio_datos'] = 'Máximo 300 caracteres.';
    }
    if ($form['impresora_tipo'] === 'windows' && ($form['impresora_nombre'] === '' || mb_strlen($form['impresora_nombre']) > 200)) {
        $errores['impresora_nombre'] = 'Elige tu impresora de tickets (o marca "No tengo" y la eliges después).';
    }
    if ($form['impresora_tipo'] === 'red') {
        if (!filter_var($form['impresora_ip'], FILTER_VALIDATE_IP)) {
            $errores['impresora_ip'] = 'Escribe una dirección IP válida, por ejemplo 192.168.1.100.';
        } elseif (!ctype_digit($form['impresora_puerto']) || (int) $form['impresora_puerto'] < 1 || (int) $form['impresora_puerto'] > 65535) {
            $errores['impresora_ip'] = 'El puerto no es válido (normalmente 9100).';
        }
    }
    if ($form['proteger']) {
        if ($form['nombre'] === '' || mb_strlen($form['nombre']) > 40) {
            $errores['nombre'] = 'Escribe tu nombre (máximo 40 caracteres).';
        }
        if (!pin_valido($pin)) {
            $errores['pin'] = 'El PIN debe tener de 4 a 6 números.';
        } elseif ($pin !== (string) ($_POST['pin2'] ?? '')) {
            $errores['pin2'] = 'Los dos PIN no coinciden.';
        }
    }

    if (!$errores && !$sesionVencida) {
        // Productos: vacío (con folios desde 000001) o con los de ejemplo.
        if ($form['productos'] === 'vacio') {
            limpiar_base('todo');
        } elseif ($form['productos'] === 'ejemplos' && $productosActuales === 0) {
            cargar_productos_ejemplo($form['giro']);
        }
        guardar_ajustes([
            'giro'           => $form['giro'],
            'negocio_nombre' => $form['negocio_nombre'],
            'negocio_datos'  => $form['negocio_datos'],
            'primer_uso'     => date('Y-m-d H:i:s'),
        ]);
        if ($form['impresora_tipo'] !== 'ninguna') {
            guardar_ajustes([
                'impresora_tipo'   => $form['impresora_tipo'],
                'impresora_nombre' => $form['impresora_tipo'] === 'windows' ? $form['impresora_nombre'] : '',
                'impresora_ip'     => $form['impresora_tipo'] === 'red' ? $form['impresora_ip'] : '',
                'impresora_puerto' => $form['impresora_tipo'] === 'red' ? $form['impresora_puerto'] : '9100',
                'papel'            => $form['papel'],
            ]);
        }
        if ($form['proteger']) {
            db()->prepare("INSERT INTO usuarios (nombre, pin_hash, rol) VALUES (:nombre, :pin, 'dueno')")
                ->execute([':nombre' => $form['nombre'], ':pin' => password_hash($pin, PASSWORD_DEFAULT)]);
            iniciar_sesion_usuario((int) db()->lastInsertId());
        }
        flash('exito', '¡Listo! ' . ($form['productos'] === 'ejemplos'
            ? 'Puedes practicar con los productos de ejemplo.'
            : 'Empieza registrando tus productos o impórtalos de Excel.'));
        redirigir($form['productos'] === 'ejemplos' ? 'ventas/index.php' : 'productos/index.php');
    }
}

$invalido = fn(string $c) => isset($errores[$c]) ? ' is-invalid' : '';
$mensaje  = fn(string $c) => isset($errores[$c]) ? '<div class="invalid-feedback">' . e($errores[$c]) . '</div>' : '';
$marcado  = fn(string $valor) => $form['productos'] === $valor ? 'checked' : '';

// Impresoras instaladas en Windows (solo se pueden listar en la app de escritorio).
$impresoras = [];
try {
    $impresoras = impresoras_windows();
} catch (Throwable $ex) {
    // Desde el navegador no se pueden listar: se elige después en Configuración.
}
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bienvenido · <?= e(APP_NOMBRE) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/estilos.css')) ?>?v=<?= e(APP_VERSION) ?>">
</head>
<body class="pagina-login">
<main class="login-caja bienvenida-caja">
    <div class="text-center mb-4">
        <span class="marca-logo mx-auto mb-3"><i class="bi bi-shop"></i></span>
        <h1 class="h4 fw-bold mb-1">¡Bienvenido a tu punto de venta!</h1>
        <p class="text-body-secondary mb-0">Cinco pasos y listo para vender.</p>
    </div>

    <?php if ($sesionVencida): ?>
        <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>No se pudo guardar porque se perdió la sesión. Tus datos siguen aquí: escribe tu PIN otra vez y dale a Empezar.</div>
    <?php endif; ?>
    <?php if ($errores): ?>
        <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-circle me-1"></i>Revisa los campos marcados en rojo.</div>
    <?php endif; ?>

    <form method="post" novalidate autocomplete="off">
        <?= csrf_campo() ?>

        <h2 class="h6 fw-bold"><span class="badge rounded-pill text-bg-primary me-2">1</span>¿Qué tipo de negocio es?</h2>
        <div class="giros mb-4" role="radiogroup" aria-label="Tipo de negocio">
            <?php foreach (GIROS as $clave => $g): ?>
                <input type="radio" class="btn-check" name="giro" id="giro_<?= e($clave) ?>" value="<?= e($clave) ?>" <?= $form['giro'] === $clave ? 'checked' : '' ?>>
                <label class="btn btn-metodo" for="giro_<?= e($clave) ?>"><i class="bi <?= e($g['icono']) ?>"></i><?= e($g['etiqueta']) ?></label>
            <?php endforeach; ?>
        </div>

        <h2 class="h6 fw-bold"><span class="badge rounded-pill text-bg-primary me-2">2</span>Tu negocio</h2>
        <div class="mb-2">
            <label for="negocio_nombre" class="form-label small mb-1">Nombre del negocio</label>
            <input type="text" class="form-control form-control-lg<?= $invalido('negocio_nombre') ?>" id="negocio_nombre" name="negocio_nombre"
                   value="<?= e($form['negocio_nombre']) ?>" maxlength="60" required placeholder="Ej. La Esperanza">
            <?= $mensaje('negocio_nombre') ?>
        </div>
        <div class="mb-4">
            <label for="negocio_datos" class="form-label small mb-1">Para el ticket <span class="text-body-secondary">(opcional)</span></label>
            <textarea class="form-control<?= $invalido('negocio_datos') ?>" id="negocio_datos" name="negocio_datos" rows="2" maxlength="300"
                      placeholder="Dirección, teléfono… (un dato por renglón)"><?= e($form['negocio_datos']) ?></textarea>
            <?= $mensaje('negocio_datos') ?>
        </div>

        <h2 class="h6 fw-bold"><span class="badge rounded-pill text-bg-primary me-2">3</span>¿Cómo quieres empezar?</h2>
        <div class="d-grid gap-2 mb-4" role="radiogroup">
            <?php if ($productosActuales === 0): ?>
                <input type="radio" class="btn-check" name="productos" id="p_vacio" value="vacio" <?= $marcado('vacio') ?>>
                <label class="btn btn-outline-primary text-start" for="p_vacio">
                    <i class="bi bi-box me-1"></i><strong>Vacío</strong>, para la tienda real
                    <small class="d-block opacity-75">Tú das de alta tus productos o los importas de Excel.</small>
                </label>
                <input type="radio" class="btn-check" name="productos" id="p_ejemplos" value="ejemplos" <?= $marcado('ejemplos') ?>>
                <label class="btn btn-outline-primary text-start" for="p_ejemplos">
                    <i class="bi bi-mortarboard me-1"></i><strong>Con productos de ejemplo</strong>, para practicar
                    <small class="d-block opacity-75">Según tu tipo de negocio. Después los puedes desactivar.</small>
                </label>
            <?php else: ?>
                <input type="radio" class="btn-check" name="productos" id="p_vacio" value="vacio" <?= $marcado('vacio') ?>>
                <label class="btn btn-outline-primary text-start" for="p_vacio">
                    <i class="bi bi-box me-1"></i><strong>Vacío</strong>: quitar los <?= $productosActuales ?> productos que hay
                    <small class="d-block opacity-75">Para empezar la tienda real desde cero.</small>
                </label>
                <input type="radio" class="btn-check" name="productos" id="p_conservar" value="conservar" <?= $marcado('conservar') ?>>
                <label class="btn btn-outline-primary text-start" for="p_conservar">
                    <i class="bi bi-boxes me-1"></i><strong>Conservar</strong> los <?= $productosActuales ?> productos que hay
                </label>
            <?php endif; ?>
        </div>

        <h2 class="h6 fw-bold"><span class="badge rounded-pill text-bg-primary me-2">4</span>¿Tienes impresora de tickets?</h2>
        <div class="giros giros-3 mb-2" role="radiogroup" aria-label="Impresora de tickets">
            <?php foreach (['ninguna' => ['bi-slash-circle', 'No tengo'], 'windows' => ['bi-usb-symbol', 'Sí, USB'], 'red' => ['bi-ethernet', 'Sí, de red']] as $clave => [$icono, $etiqueta]): ?>
                <input type="radio" class="btn-check" name="impresora_tipo" id="imp_<?= $clave ?>" value="<?= $clave ?>" <?= $form['impresora_tipo'] === $clave ? 'checked' : '' ?>>
                <label class="btn btn-metodo" for="imp_<?= $clave ?>"><i class="bi <?= $icono ?>"></i><?= $etiqueta ?></label>
            <?php endforeach; ?>
        </div>
        <div class="form-text mb-4" id="impAyudaNinguna">Puedes vender sin impresora. Si después conectas una, la eliges en Configuración.</div>
        <div class="mb-3 d-none" id="impCamposWindows">
            <?php if ($impresoras): ?>
                <select class="form-select<?= $invalido('impresora_nombre') ?>" name="impresora_nombre" aria-label="Impresora">
                    <option value="">Elige tu impresora de tickets…</option>
                    <?php foreach ($impresoras as $nombre): ?>
                        <option value="<?= e($nombre) ?>" <?= $form['impresora_nombre'] === $nombre ? 'selected' : '' ?>><?= e($nombre) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $mensaje('impresora_nombre') ?>
            <?php else: ?>
                <div class="alert alert-warning small mb-0">No encontré impresoras instaladas en Windows. Instálala con el disco o el programa que trae y luego elígela en <strong>Configuración → Impresora y cajón</strong>. Por ahora marca "No tengo".</div>
                <?php if (isset($errores['impresora_nombre'])): ?><div class="text-danger small mt-1"><?= e($errores['impresora_nombre']) ?></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="row g-2 mb-3 d-none" id="impCamposRed">
            <div class="col-8">
                <input type="text" class="form-control<?= $invalido('impresora_ip') ?>" name="impresora_ip" value="<?= e($form['impresora_ip']) ?>" placeholder="Dirección IP, ej. 192.168.1.100" inputmode="decimal" aria-label="Dirección IP">
                <?= $mensaje('impresora_ip') ?>
            </div>
            <div class="col-4">
                <input type="text" class="form-control" name="impresora_puerto" value="<?= e($form['impresora_puerto']) ?>" inputmode="numeric" aria-label="Puerto" title="Puerto (normalmente 9100)">
            </div>
        </div>
        <div class="mb-4 d-none" id="impPapel">
            <select class="form-select" name="papel" aria-label="Ancho del papel">
                <option value="80" <?= $form['papel'] === '80' ? 'selected' : '' ?>>Papel de 80 mm (el más común)</option>
                <option value="58" <?= $form['papel'] === '58' ? 'selected' : '' ?>>Papel de 58 mm (impresoras pequeñas)</option>
            </select>
            <div class="form-text">Al terminar te sugerimos imprimir un ticket de prueba.</div>
        </div>

        <h2 class="h6 fw-bold"><span class="badge rounded-pill text-bg-primary me-2">5</span>Tu usuario de dueño</h2>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="proteger" name="proteger" value="1" <?= $form['proteger'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="proteger">Pedir un PIN para entrar <span class="text-body-secondary small">(recomendado)</span></label>
        </div>
        <div id="camposDueno">
            <div class="mb-2">
                <input type="text" class="form-control<?= $invalido('nombre') ?>" name="nombre" value="<?= e($form['nombre']) ?>" maxlength="40" placeholder="Tu nombre">
                <?= $mensaje('nombre') ?>
            </div>
            <div class="row g-2 mb-1">
                <div class="col-6">
                    <input type="password" class="form-control<?= $invalido('pin') ?>" name="pin" inputmode="numeric" maxlength="6" placeholder="PIN (4 a 6 números)" autocomplete="new-password">
                    <?= $mensaje('pin') ?>
                </div>
                <div class="col-6">
                    <input type="password" class="form-control<?= $invalido('pin2') ?>" name="pin2" inputmode="numeric" maxlength="6" placeholder="Repite el PIN" autocomplete="new-password">
                    <?= $mensaje('pin2') ?>
                </div>
            </div>
            <div class="form-text mb-3">Anótalo en un lugar seguro. Después podrás crear cajeros en "Usuarios".</div>
        </div>
        <div class="form-text mb-3 d-none" id="avisoSinPin">Cualquiera podrá entrar a todo. Puedes crear usuarios después en "Usuarios".</div>

        <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-rocket-takeoff me-1"></i>Empezar</button>
    </form>
</main>
<script>
(function () {
    const sw = document.getElementById('proteger');
    const actualizar = () => {
        document.getElementById('camposDueno').classList.toggle('d-none', !sw.checked);
        document.getElementById('avisoSinPin').classList.toggle('d-none', sw.checked);
    };
    sw.addEventListener('change', actualizar);
    actualizar();

    const impresora = () => {
        const tipo = document.querySelector('input[name="impresora_tipo"]:checked').value;
        document.getElementById('impAyudaNinguna').classList.toggle('d-none', tipo !== 'ninguna');
        document.getElementById('impCamposWindows').classList.toggle('d-none', tipo !== 'windows');
        document.getElementById('impCamposRed').classList.toggle('d-none', tipo !== 'red');
        document.getElementById('impPapel').classList.toggle('d-none', tipo === 'ninguna');
    };
    document.querySelectorAll('input[name="impresora_tipo"]').forEach(t => t.addEventListener('change', impresora));
    impresora();
})();
</script>
</body>
</html>
