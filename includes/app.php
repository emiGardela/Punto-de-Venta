<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Arranque común.
 *
 * Todas las páginas incluyen este archivo en su primera línea. Carga la
 * configuración, la conexión, las funciones y el manejo de errores.
 *
 * También verifica la sesión: si hay usuarios creados, todas las páginas
 * piden PIN (includes/usuarios.php). Cada página exige además su permiso.
 */

declare(strict_types=1);

require_once __DIR__ . '/../configuracion.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/respaldos.php';
require_once __DIR__ . '/ajustes.php';
require_once __DIR__ . '/giros.php';
require_once __DIR__ . '/impresora.php';
require_once __DIR__ . '/usuarios.php';
require_once __DIR__ . '/caja.php';
require_once __DIR__ . '/inventario.php';
require_once __DIR__ . '/clientes.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/devoluciones.php';
require_once __DIR__ . '/facturas.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/excel.php';

// Texto que no llegue en UTF-8 (por ejemplo, de otro programa) se convierte desde Windows-1252,
// así ningún formulario falla por un acento o una ñ.
foreach ([&$_GET, &$_POST] as &$__entrada) {
    array_walk_recursive($__entrada, function (&$valor): void {
        if (is_string($valor) && !mb_check_encoding($valor, 'UTF-8')) {
            $valor = mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
        }
    });
}
unset($__entrada);

// Cabeceras de seguridad básicas.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/*
 * Cualquier error no controlado se registra en el log de PHP
 * (en la app de escritorio: carpeta "php\logs" del programa) y al usuario
 * solo se le muestra un mensaje general, sin datos sensibles.
 */
set_exception_handler(function (Throwable $e): void {
    error_log('TiendaWeb: ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());

    $mensaje = $e instanceof RuntimeException && !($e instanceof PDOException)
        ? $e->getMessage()
        : 'Ocurrió un error inesperado. Intenta de nuevo; si el problema continúa revisa el registro de errores de PHP.';
    if (APP_DEBUG) {
        $mensaje .= ' [' . get_class($e) . ': ' . $e->getMessage() . ']';
    }

    if (!headers_sent()) {
        http_response_code(500);
    }
    if (peticion_espera_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Error - ' . e(APP_NOMBRE) . '</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="' . e(url('assets/vendor/bootstrap/bootstrap.min.css')) . '"></head>'
        . '<body class="bg-light"><div class="container py-5" style="max-width:640px">'
        . '<div class="card shadow-sm border-0"><div class="card-body p-4">'
        . '<h1 class="h4 text-danger">No se pudo completar la operación</h1>'
        . '<p class="mb-4">' . e($mensaje) . '</p>'
        . '<a class="btn btn-primary" href="' . e(url('index.php')) . '">Volver al inicio</a>'
        . '</div></div></div></body></html>';
});

// Copia de seguridad del día (solo la primera vez que se abre el sistema cada día).
respaldo_automatico();

// Con usuarios creados, todas las páginas piden PIN (salvo las que definen PAGINA_SIN_SESION).
if (!defined('PAGINA_SIN_SESION')) {
    requerir_sesion();
}

// Base sin estrenar: primero el asistente de primer uso (nombre del negocio, productos, dueño).
if (!defined('PAGINA_SIN_SESION') && necesita_bienvenida()) {
    if (peticion_espera_json()) {
        responder_json(['ok' => false, 'mensaje' => 'Primero termina la configuración inicial.'], 409);
    }
    redirigir('bienvenida.php');
}
