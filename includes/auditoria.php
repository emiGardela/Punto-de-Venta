<?php
/**
 * TiendaWeb - Bitácora de auditoría.
 *
 * Registra las operaciones delicadas con fecha y usuario. La tabla no se
 * puede modificar (un trigger lo impide); solo se agregan registros.
 * Nunca se anotan PINs, contraseñas ni datos de tarjetas.
 */

declare(strict_types=1);

/** Acciones registradas y su etiqueta para la pantalla de bitácora. */
const ACCIONES_AUDITORIA = [
    'sesion_iniciada'     => 'Inició sesión',
    'sesion_fallida'      => 'PIN incorrecto',
    'sesion_cerrada'      => 'Cerró sesión',
    'venta_descuento'     => 'Venta con descuento',
    'venta_cancelada'     => 'Venta cancelada',
    'venta_devolucion'    => 'Devolución de productos',
    'factura'             => 'Factura pedida, hecha o cancelada',
    'ticket_reimpreso'    => 'Ticket reimpreso',
    'cajon_abierto'       => 'Cajón abierto sin venta',
    'caja_abierta'        => 'Caja abierta',
    'caja_movimiento'     => 'Entrada/salida de efectivo',
    'caja_cerrada'        => 'Corte de caja',
    'producto_creado'     => 'Producto creado',
    'producto_modificado' => 'Producto modificado',
    'producto_estado'     => 'Producto activado/desactivado',
    'productos_importados'=> 'Productos importados',
    'inventario'          => 'Entrada o ajuste de inventario',
    'cliente_guardado'    => 'Cliente guardado',
    'abono'               => 'Abono registrado',
    'abono_cancelado'     => 'Abono cancelado',
    'usuario_guardado'    => 'Usuario creado o modificado',
    'configuracion'       => 'Configuración cambiada',
    'respaldo_descargado' => 'Respaldo descargado',
    'respaldo_restaurado' => 'Respaldo restaurado',
    'base_limpiada'       => 'Limpieza de la base',
];

/** Anota una acción en la bitácora. Si falla, no detiene la operación (solo se registra el error). */
function auditar(string $accion, string $detalle, ?string $entidad = null, ?int $entidadId = null, ?int $usuarioId = null): void
{
    try {
        $usuarioId ??= function_exists('usuario_id') ? usuario_id() : null;
        db()->prepare('INSERT INTO auditoria (fecha, usuario_id, usuario, accion, detalle, entidad, entidad_id)
                       VALUES (:fecha, :uid, :usuario, :accion, :detalle, :entidad, :eid)')
            ->execute([
                ':fecha' => date('Y-m-d H:i:s'), ':uid' => $usuarioId,
                ':usuario' => $usuarioId && function_exists('nombre_usuario') ? nombre_usuario($usuarioId) : null,
                ':accion' => $accion, ':detalle' => mb_substr($detalle, 0, 1000),
                ':entidad' => $entidad, ':eid' => $entidadId,
            ]);
    } catch (Throwable $e) {
        error_log('TiendaWeb: no se pudo anotar en la bitácora (' . $accion . '): ' . $e->getMessage());
    }
}
