<?php
/**
 * TiendaWeb - Configuración general de la aplicación.
 *
 * Este archivo se carga en todas las páginas a través de includes/app.php.
 * La ubicación de la base de datos (SQLite) se define en conexion.php.
 */

declare(strict_types=1);

// Nombre que aparece en la barra superior y en los tickets.
const APP_NOMBRE   = 'Punto de venta';
const APP_NEGOCIO  = '';   // nombre inicial; después se cambia en el menú Configuración
const APP_VERSION  = '3.8.1';

// Zona horaria de México (sin horario de verano desde 2022).
const APP_ZONA_HORARIA = 'America/Mexico_City';

// Moneda.
const APP_MONEDA = '$';

// Registros por página en las tablas.
const APP_POR_PAGINA = 15;

// Ponlo en true SOLO mientras desarrollas: muestra el detalle de los errores en pantalla.
const APP_DEBUG = false;

date_default_timezone_set(APP_ZONA_HORARIA);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

/*
 * Ruta base de la aplicación en la URL (por ejemplo "/tiendaweb").
 * Se calcula automáticamente comparando esta carpeta con la raíz de Apache,
 * de modo que el sistema funcione aunque cambies el nombre de la carpeta.
 */
$__docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
$__appRoot = realpath(__DIR__);
$__base    = '/tiendaweb';
if ($__docRoot !== false && $__appRoot !== false) {
    $docRootNorm = rtrim(str_replace('\\', '/', $__docRoot), '/');
    $appRootNorm = rtrim(str_replace('\\', '/', $__appRoot), '/');
    if ($docRootNorm !== '' && stripos($appRootNorm, $docRootNorm) === 0) {
        $__base = substr($appRootNorm, strlen($docRootNorm));
    }
}
define('BASE_URL', rtrim($__base, '/'));
unset($__docRoot, $__appRoot, $__base);

// Sesión (se usa para el token CSRF y los mensajes de aviso).
if (session_status() === PHP_SESSION_NONE) {
    // En la app de escritorio la carpeta de sesiones (php.ini) puede no existir todavía.
    $__sesiones = session_save_path();
    if ($__sesiones !== '' && !is_dir($__sesiones)) {
        @mkdir($__sesiones, 0775, true);
    }
    unset($__sesiones);
    session_name('tiendaweb_sesion');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}
