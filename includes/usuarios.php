<?php
/**
 * TiendaWeb - Usuarios, inicio de sesión con PIN y permisos.
 *
 * - Mientras no exista ningún usuario, el sistema no pide clave (como una
 *   tienda de una sola persona). Al crear el primero (el dueño), se empieza
 *   a pedir el PIN.
 * - El dueño puede todo. A cada cajero se le eligen los permisos de PERMISOS;
 *   vender, abrir caja y ver el historial no requieren permiso.
 */

declare(strict_types=1);

const PERMISOS = [
    'cancelar'   => 'Cancelar ventas',
    'descuentos' => 'Hacer descuentos',
    'credito'    => 'Vender a crédito y recibir abonos',
    'caja'       => 'Entradas y salidas de efectivo, y corte de caja',
    'productos'  => 'Agregar y modificar productos y precios',
    'inventario' => 'Entradas de mercancía y ajustes de inventario',
    'reportes'   => 'Ver reportes y ganancias',
];

const ROLES = ['dueno' => 'Dueño (puede todo)', 'cajero' => 'Cajero'];

/** ¿Ya se creó algún usuario activo? (si no, el sistema no pide clave) */
function hay_usuarios(): bool
{
    static $hay = null;
    return $hay ??= (int) db()->query('SELECT COUNT(*) FROM usuarios WHERE activo = 1')->fetchColumn() > 0;
}

/** Usuario con sesión iniciada, o null. */
function usuario_actual(): ?array
{
    static $usuario = false;
    if ($usuario !== false) {
        return $usuario;
    }
    $usuario = null;
    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($id > 0) {
        $st = db()->prepare('SELECT id, nombre, rol, permisos FROM usuarios WHERE id = :id AND activo = 1');
        $st->execute([':id' => $id]);
        $usuario = $st->fetch() ?: null;
        if ($usuario === null) {
            unset($_SESSION['usuario_id']);   // lo desactivaron o se restauró un respaldo
        }
    }
    return $usuario;
}

function usuario_id(): ?int
{
    $u = usuario_actual();
    return $u ? (int) $u['id'] : null;
}

function iniciar_sesion_usuario(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $id;
}

function cerrar_sesion_usuario(): void
{
    unset($_SESSION['usuario_id'], $_SESSION['csrf_token']);
    session_regenerate_id(true);
}

function es_dueno(): bool
{
    return !hay_usuarios() || (usuario_actual()['rol'] ?? '') === 'dueno';
}

/** ¿El usuario actual tiene este permiso? Sin usuarios creados, todo está permitido. */
function permiso(string $permiso): bool
{
    if (es_dueno()) {
        return true;
    }
    $u = usuario_actual();
    return $u !== null && in_array($permiso, explode(',', (string) $u['permisos']), true);
}

/** Corta la petición si falta el permiso (JSON o página de aviso). */
function requerir_permiso(string $permiso): void
{
    if (!permiso($permiso)) {
        negar_acceso('Tu usuario no tiene permiso para: ' . mb_strtolower(PERMISOS[$permiso] ?? $permiso) . '.');
    }
}

function requerir_dueno(): void
{
    if (!es_dueno()) {
        negar_acceso('Solo el dueño puede entrar a esta sección.');
    }
}

function negar_acceso(string $mensaje): never
{
    if (peticion_espera_json()) {
        responder_json(['ok' => false, 'mensaje' => $mensaje], 403);
    }
    flash('error', $mensaje);
    redirigir('index.php');
}

/** Si hay usuarios y nadie inició sesión, manda a la pantalla de PIN. */
function requerir_sesion(): void
{
    if (!hay_usuarios() || usuario_actual() !== null) {
        return;
    }
    if (peticion_espera_json()) {
        responder_json(['ok' => false, 'mensaje' => 'Se cerró la sesión. Vuelve a escribir tu PIN.', 'sesion' => false], 401);
    }
    $volver = (string) ($_SERVER['REQUEST_URI'] ?? '');
    redirigir('login.php' . ($volver !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? '?volver=' . rawurlencode($volver) : ''));
}

function pin_valido(string $pin): bool
{
    return (bool) preg_match('/^\d{4,6}$/', $pin);
}

/** Nombre corto para tickets y listados ("Ana", "—" si no hay). */
function nombre_usuario(?int $id): string
{
    static $nombres = null;
    if ($id === null) {
        return '';
    }
    $nombres ??= db()->query('SELECT id, nombre FROM usuarios')->fetchAll(PDO::FETCH_KEY_PAIR);
    return (string) ($nombres[$id] ?? '');
}
