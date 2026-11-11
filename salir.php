<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Cierra la sesión del usuario actual (por ejemplo, al cambiar de cajero).
 */
const PAGINA_SIN_SESION = true;
require_once __DIR__ . '/includes/app.php';

requerir_post_csrf();
if (usuario_actual()) {
    auditar('sesion_cerrada', '«' . usuario_actual()['nombre'] . '» salió del sistema', 'usuario', usuario_id());
}
cerrar_sesion_usuario();
redirigir('login.php');
