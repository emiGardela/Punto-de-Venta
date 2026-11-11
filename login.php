<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Inicio de sesión con PIN. Solo aparece cuando ya se creó algún usuario.
 */
const PAGINA_SIN_SESION = true;
require_once __DIR__ . '/includes/app.php';

// "volver" solo puede ser una ruta dentro del sistema.
$volver = (string) ($_REQUEST['volver'] ?? '');
if ($volver === '' || !str_starts_with($volver, BASE_URL . '/') || str_contains($volver, '//') || str_contains($volver, 'login.php')) {
    $volver = url('index.php');
}

if (!hay_usuarios()) {
    header('Location: ' . url('index.php'));
    exit;
}

$error = null;
foreach (flash_obtener() as $aviso) {   // por ejemplo, una sesión vencida
    if ($aviso['tipo'] === 'error') {
        $error = $aviso['mensaje'];
    }
}
$elegido = (int) ($_POST['usuario'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerir_post_csrf();
    $pin = (string) ($_POST['pin'] ?? '');
    $st = db()->prepare('SELECT id, nombre, pin_hash FROM usuarios WHERE id = :id AND activo = 1');
    $st->execute([':id' => $elegido]);
    $u = $st->fetch();
    if ($u && pin_valido($pin) && password_verify($pin, $u['pin_hash'])) {
        iniciar_sesion_usuario((int) $u['id']);
        auditar('sesion_iniciada', '«' . $u['nombre'] . '» entró al sistema', 'usuario', (int) $u['id'], (int) $u['id']);
        header('Location: ' . $volver);
        exit;
    }
    if ($u) {
        auditar('sesion_fallida', 'PIN incorrecto para «' . $u['nombre'] . '»', 'usuario', (int) $u['id']);
    }
    sleep(1);   // frena a quien intente adivinar PINs
    $error = 'PIN incorrecto. Intenta de nuevo.';
}

$usuarios = db()->query("SELECT id, nombre, rol FROM usuarios WHERE activo = 1 ORDER BY rol = 'dueno' DESC, nombre")->fetchAll();
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · <?= e(APP_NOMBRE) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/estilos.css')) ?>?v=<?= e(APP_VERSION) ?>">
</head>
<body class="pagina-login">
<main class="login-caja">
    <div class="text-center mb-4">
        <span class="marca-logo mx-auto mb-3"><i class="bi bi-shop"></i></span>
        <h1 class="h4 fw-bold mb-1"><?= e(nombre_negocio() ?: APP_NOMBRE) ?></h1>
        <p class="text-body-secondary mb-0">¿Quién va a usar la caja?</p>
    </div>

    <form method="post" id="formLogin" autocomplete="off">
        <?= csrf_campo() ?>
        <input type="hidden" name="volver" value="<?= e($volver) ?>">
        <input type="hidden" name="pin" id="pin">

        <div class="login-usuarios mb-4" role="radiogroup" aria-label="Usuario">
            <?php foreach ($usuarios as $i => $u): ?>
                <input type="radio" class="btn-check" name="usuario" id="u<?= (int) $u['id'] ?>" value="<?= (int) $u['id'] ?>"
                    <?= ($elegido ? $elegido === (int) $u['id'] : $i === 0) ? 'checked' : '' ?>>
                <label class="btn login-usuario" for="u<?= (int) $u['id'] ?>">
                    <span class="login-inicial"><?= e(mb_strtoupper(mb_substr($u['nombre'], 0, 1))) ?></span>
                    <span class="d-block fw-semibold text-truncate"><?= e($u['nombre']) ?></span>
                    <small class="text-body-secondary"><?= $u['rol'] === 'dueno' ? 'Dueño' : 'Cajero' ?></small>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="text-center mb-3">
            <div class="pin-puntos" id="puntos" aria-live="polite"></div>
            <div class="text-danger small mt-2" id="errorPin"><?= e($error ?? '') ?></div>
        </div>

        <div class="pin-teclado">
            <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $n): ?>
                <button type="button" class="btn btn-light border" data-digito="<?= $n ?>"><?= $n ?></button>
            <?php endforeach; ?>
            <button type="button" class="btn btn-light border" data-borrar title="Borrar"><i class="bi bi-backspace"></i></button>
            <button type="button" class="btn btn-light border" data-digito="0">0</button>
            <button type="submit" class="btn btn-primary" title="Entrar"><i class="bi bi-arrow-right"></i></button>
        </div>
    </form>
</main>

<script>
(function () {
    const pin = document.getElementById('pin');
    const puntos = document.getElementById('puntos');
    const error = document.getElementById('errorPin');
    const form = document.getElementById('formLogin');
    let valor = '';

    function pintar() {
        pin.value = valor;
        puntos.innerHTML = '';
        for (let i = 0; i < Math.max(4, valor.length); i++) {
            const s = document.createElement('span');
            if (i < valor.length) { s.className = 'lleno'; }
            puntos.appendChild(s);
        }
    }
    function teclear(d) {
        if (valor.length < 6) { valor += d; error.textContent = ''; pintar(); }
    }
    function borrar() { valor = valor.slice(0, -1); pintar(); }

    document.querySelectorAll('[data-digito]').forEach(b => b.addEventListener('click', () => teclear(b.dataset.digito)));
    document.querySelector('[data-borrar]').addEventListener('click', borrar);
    document.querySelectorAll('input[name="usuario"]').forEach(r => r.addEventListener('change', () => { valor = ''; pintar(); }));
    document.addEventListener('keydown', function (ev) {
        if (/^[0-9]$/.test(ev.key)) { teclear(ev.key); ev.preventDefault(); }
        else if (ev.key === 'Backspace') { borrar(); ev.preventDefault(); }
        else if (ev.key === 'Enter') { ev.preventDefault(); form.requestSubmit(); }
    });
    form.addEventListener('submit', function (ev) {
        if (valor.length < 4) { ev.preventDefault(); error.textContent = 'El PIN tiene de 4 a 6 números.'; }
    });
    pintar();
})();
</script>
</body>
</html>
