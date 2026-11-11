<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Pie común: cierra la estructura, carga los scripts y muestra los avisos.
 * Una página puede definir $scripts = ['assets/js/ventas.js'] antes de incluirlo.
 */
$scripts = $scripts ?? [];
$avisos  = flash_obtener();
?>
    </main>
</div>

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script>
    window.TIENDAWEB = {
        base: <?= json_encode(BASE_URL) ?>,
        csrf: <?= json_encode(csrf_token()) ?>,
        moneda: <?= json_encode(APP_MONEDA) ?>,
        avisos: <?= json_encode($avisos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
</script>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>
<?php foreach ($scripts as $js): ?>
<script src="<?= e(url($js)) ?>?v=<?= e(APP_VERSION) ?>"></script>
<?php endforeach; ?>
</body>
</html>
