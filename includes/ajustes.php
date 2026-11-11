<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * TiendaWeb - Ajustes guardados en la base de datos (tabla "ajustes").
 *
 * A diferencia de configuracion.php, estos se cambian desde la pantalla
 * Configuración sin tocar archivos.
 */

declare(strict_types=1);

const AJUSTES_PREDETERMINADOS = [
    // Tipo de negocio (ver includes/giros.php)
    'giro'             => 'general',

    // Datos del negocio (encabezado del ticket y menú)
    'negocio_nombre'   => APP_NEGOCIO,
    'negocio_datos'    => '',                         // dirección, teléfono, RFC… (una línea por renglón)
    'ticket_pie'       => '¡Gracias por su compra!',
    'ticket_factura'   => '¿Necesitas factura? Pídela con este ticket dentro del mes de tu compra.',

    // Impresora de tickets ESC/POS
    'impresora_tipo'   => 'ninguna',                  // ninguna | windows | red
    'impresora_nombre' => '',                         // nombre en Windows
    'impresora_ip'     => '',
    'impresora_puerto' => '9100',
    'papel'            => '80',                       // 58 | 80 (mm)
    'caracteres'       => 'pc850',                    // pc850 | pc437 | sin_acentos
    'cortar_papel'     => '1',
    'iva_ticket'       => '1',                        // mostrar en el ticket cuánto IVA incluye

    // Al cobrar
    'imprimir_al_cobrar' => 'siempre',                // siempre | preguntar
    'cajon_al_cobrar'    => 'efectivo',               // efectivo | siempre | nunca
    'cajon_pin'          => '2',                      // 2 | 5 (conector del cajón)

    // Correo para mandar facturas (SMTP). La contraseña se guarda solo en esta computadora.
    'correo_servidor'    => '',
    'correo_puerto'      => '465',
    'correo_seguridad'   => 'ssl',                    // ssl | tls | ninguna
    'correo_usuario'     => '',                       // también es el remitente
    'correo_contrasena'  => '',
    'correo_nombre'      => '',                       // nombre que ve el cliente (vacío = nombre del negocio)

    // Funciones avanzadas: compras, proveedores, listas escolares, paquetes y kits, mayoreo.
    // Apagadas, el sistema muestra solo lo básico.
    'funciones_avanzadas' => '0',

    // Fecha en que se terminó el asistente de primer uso ('' = todavía no).
    'primer_uso'         => '',
];

/** Valores permitidos para los ajustes de opción fija. */
const AJUSTES_OPCIONES = [
    'giro'               => ['abarrotes', 'licoreria', 'papeleria', 'general'],
    'impresora_tipo'     => ['ninguna', 'windows', 'red'],
    'papel'              => ['58', '80'],
    'caracteres'         => ['pc850', 'pc437', 'sin_acentos'],
    'cortar_papel'       => ['0', '1'],
    'iva_ticket'         => ['0', '1'],
    'imprimir_al_cobrar' => ['siempre', 'preguntar'],
    'cajon_al_cobrar'    => ['efectivo', 'siempre', 'nunca'],
    'cajon_pin'          => ['2', '5'],
    'funciones_avanzadas'=> ['0', '1'],
    'correo_seguridad'   => ['ssl', 'tls', 'ninguna'],
];

/** Todos los ajustes (los guardados encima de los predeterminados). */
function ajustes(bool $recargar = false): array
{
    static $cache = null;
    if ($cache === null || $recargar) {
        $guardados = db()->query('SELECT clave, valor FROM ajustes')->fetchAll(PDO::FETCH_KEY_PAIR);
        $cache = array_merge(AJUSTES_PREDETERMINADOS, array_intersect_key($guardados, AJUSTES_PREDETERMINADOS));
    }
    return $cache;
}

function ajuste(string $clave): string
{
    return (string) (ajustes()[$clave] ?? '');
}

/** Guarda los ajustes indicados (ignora claves desconocidas). */
function guardar_ajustes(array $valores): void
{
    $st = db()->prepare('INSERT INTO ajustes (clave, valor) VALUES (:clave, :valor)
                         ON CONFLICT (clave) DO UPDATE SET valor = excluded.valor');
    foreach (array_intersect_key($valores, AJUSTES_PREDETERMINADOS) as $clave => $valor) {
        $st->execute([':clave' => $clave, ':valor' => (string) $valor]);
    }
    ajustes(true);
}

/** ¿Están activadas las funciones avanzadas (compras, listas escolares, paquetes y kits, mayoreo)? */
function funciones_avanzadas(): bool
{
    return ajuste('funciones_avanzadas') === '1';
}

/** Nombre del negocio para el menú y los tickets. */
function nombre_negocio(): string
{
    return ajuste('negocio_nombre');
}

/**
 * ¿Hay que mostrar el asistente de primer uso? Solo en una base sin estrenar:
 * sin terminar el asistente, sin ventas, sin turnos y sin usuarios.
 * Las instalaciones que ya están en uso nunca lo ven.
 */
function necesita_bienvenida(): bool
{
    if (ajuste('primer_uso') !== '') {
        return false;
    }
    $usada = (int) db()->query('SELECT (SELECT COUNT(*) FROM ventas) + (SELECT COUNT(*) FROM turnos)
                                     + (SELECT COUNT(*) FROM usuarios)')->fetchColumn() > 0;
    if ($usada) {
        guardar_ajustes(['primer_uso' => date('Y-m-d H:i:s')]);   // ya estaba en uso: no volver a preguntar
    }
    return !$usada;
}

/** ¿Hay una impresora de tickets configurada? */
function impresora_activa(): bool
{
    $a = ajustes();
    // Una impresora USB solo se puede usar desde la app de escritorio: desde el navegador ni se intenta.
    return ($a['impresora_tipo'] === 'windows' && $a['impresora_nombre'] !== '' && puede_usar_impresoras_windows())
        || ($a['impresora_tipo'] === 'red' && $a['impresora_ip'] !== '');
}

/** ¿Este PHP puede mandar a impresoras de Windows (USB)? Sí en la app de escritorio; no en XAMPP. */
function puede_usar_impresoras_windows(): bool
{
    return PHP_OS_FAMILY === 'Windows' && extension_loaded('ffi')
        && in_array(strtolower((string) ini_get('ffi.enable')), ['1', 'on', 'true'], true);
}

/** Aviso para cuando la impresora USB está elegida pero no se puede usar desde aquí; null si todo bien. */
function aviso_impresora_no_disponible(): ?string
{
    $a = ajustes();
    if ($a['impresora_tipo'] !== 'windows' || puede_usar_impresoras_windows()) {
        return null;
    }
    return 'Estás usando el Punto de venta desde el navegador y desde aquí no se puede usar la impresora USB. '
         . 'Abre el programa con el ícono "Punto de venta" del escritorio para imprimir tickets. Las ventas se guardan igual.';
}
