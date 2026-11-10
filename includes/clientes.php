<?php
/**
 * TiendaWeb - Clientes y crédito ("fiado").
 *
 * Saldo de un cliente = ventas a crédito completadas - abonos aplicados
 *                       - devoluciones descontadas del crédito.
 * Cancelar una venta a crédito o un abono corrige el saldo solo.
 */

declare(strict_types=1);

function cliente_por_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM clientes WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

/** Lo que debe el cliente, en centavos. */
function saldo_cliente(int $clienteId): int
{
    $st = db()->prepare("SELECT
            COALESCE((SELECT SUM(p.monto) FROM pagos p JOIN ventas v ON v.id = p.venta_id
                      WHERE v.cliente_id = :c1 AND p.metodo = 'credito' AND v.estado = 'completada'), 0)
          - COALESCE((SELECT SUM(monto) FROM abonos WHERE cliente_id = :c2 AND estado = 'aplicado'), 0)
          - COALESCE((SELECT SUM(d.total) FROM devoluciones d JOIN ventas v ON v.id = d.venta_id
                      WHERE v.cliente_id = :c3 AND d.reembolso = 'credito' AND v.estado = 'completada'), 0)");
    $st->execute([':c1' => $clienteId, ':c2' => $clienteId, ':c3' => $clienteId]);
    return a_centavos($st->fetchColumn());
}

/** Subconsulta SQL del saldo, para listados (usa el alias "c" para clientes). Cuenta solo la parte a crédito. */
const SQL_SALDO_CLIENTE = "(COALESCE((SELECT SUM(p.monto) FROM pagos p JOIN ventas v ON v.id = p.venta_id
                                      WHERE v.cliente_id = c.id AND p.metodo = 'credito' AND v.estado = 'completada'), 0)
                         - COALESCE((SELECT SUM(a.monto) FROM abonos a WHERE a.cliente_id = c.id AND a.estado = 'aplicado'), 0)
                         - COALESCE((SELECT SUM(dv.total) FROM devoluciones dv JOIN ventas v ON v.id = dv.venta_id
                                      WHERE v.cliente_id = c.id AND dv.reembolso = 'credito' AND v.estado = 'completada'), 0))";

function abono_por_id(int $id): ?array
{
    $st = db()->prepare('SELECT a.*, c.nombre AS cliente FROM abonos a JOIN clientes c ON c.id = a.cliente_id WHERE a.id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

/** Comprobante de abono para la impresora de tickets. */
function escpos_abono(array $abono, array $aj): string
{
    $saldo = saldo_cliente((int) $abono['cliente_id']);
    $t = ticket_nuevo($aj);
    $t->comando(ESCPOS_CENTRO . ESCPOS_NEGRITA_SI)->linea('COMPROBANTE DE ABONO')
      ->comando(ESCPOS_NEGRITA_NO . ESCPOS_IZQUIERDA)->separador()
      ->columnas('Abono:', '#' . $abono['id'])
      ->columnas('Fecha:', fecha_fmt($abono['fecha']))
      ->linea('Cliente: ' . $abono['cliente']);
    if ($abono['estado'] === 'cancelado') {
        $t->comando(ESCPOS_CENTRO . ESCPOS_NEGRITA_SI)->linea('*** CANCELADO ***')->comando(ESCPOS_NEGRITA_NO . ESCPOS_IZQUIERDA);
    }
    $t->separador()
      ->comando(ESCPOS_DOBLE_ALTO . ESCPOS_NEGRITA_SI)->columnas('ABONO:', dinero($abono['monto']))
      ->comando(ESCPOS_TAMANO_NORMAL . ESCPOS_NEGRITA_NO)
      ->columnas('Forma de pago:', METODOS_ABONO[$abono['metodo_pago']] ?? $abono['metodo_pago'])
      ->columnas('Saldo pendiente:', dinero($saldo / 100));
    if ($abono['usuario_id']) {
        $t->columnas('Atendió:', nombre_usuario((int) $abono['usuario_id']));
    }
    return ticket_cerrar($t, $aj);
}
