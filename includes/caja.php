<?php
/**
 * TiendaWeb - Turnos de caja y corte.
 *
 * Un turno se abre con un fondo (el cambio con el que empieza el cajón) y
 * se cierra con el corte: se cuenta el efectivo y se compara con lo que
 * debería haber:
 *
 *   esperado = fondo + ventas en efectivo + abonos en efectivo
 *            + entradas de efectivo - salidas de efectivo
 *
 * Las ventas canceladas no cuentan. Si se cancela en efectivo una venta de
 * un turno anterior, el dinero sale del turno actual como "salida". Las
 * devoluciones en efectivo también se registran como salida.
 */

declare(strict_types=1);

/** Turno abierto ahora, o null si la caja está cerrada. */
function turno_abierto(): ?array
{
    return db()->query('SELECT * FROM turnos WHERE cerrado_en IS NULL LIMIT 1')->fetch() ?: null;
}

function turno_por_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM turnos WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

/** Abre un turno con el fondo indicado (en centavos). Devuelve su id. */
function abrir_turno(int $fondoCentavos): int
{
    try {
        db()->prepare('INSERT INTO turnos (abierto_en, abierto_por, fondo) VALUES (:fecha, :usuario, :fondo)')
            ->execute([':fecha' => date('Y-m-d H:i:s'), ':usuario' => usuario_id(), ':fondo' => centavos_a_decimal($fondoCentavos)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new RuntimeException('Ya hay una caja abierta.');
        }
        throw $e;
    }
    return (int) db()->lastInsertId();
}

/**
 * Totales de un turno, en centavos.
 * Devuelve: fondo, ventas[metodo] (total y operaciones), abonos[metodo], entradas,
 *           salidas, descuentos, canceladas, operaciones, devoluciones[reembolso], devuelto, esperado.
 */
function resumen_turno(array $turno): array
{
    $pdo = db();
    $id  = (int) $turno['id'];
    $r = [
        'fondo'       => a_centavos($turno['fondo']),
        'ventas'      => array_fill_keys(array_keys(METODOS_PAGO), ['total' => 0, 'operaciones' => 0]),
        'abonos'      => array_fill_keys(array_keys(METODOS_ABONO), 0),
        'entradas'    => 0,
        'salidas'     => 0,
        'descuentos'  => 0,
        'canceladas'  => 0,
        'operaciones' => 0,
        'vendido'     => 0,
        'devoluciones' => array_fill_keys(array_keys(METODOS_REEMBOLSO), 0),
        'devuelto'    => 0,
    ];

    // Por método se suman los pagos (una venta dividida aparece en cada método que usó).
    $st = $pdo->prepare("SELECT p.metodo, COUNT(DISTINCT p.venta_id) AS n, SUM(p.monto) AS total
                         FROM pagos p JOIN ventas v ON v.id = p.venta_id
                         WHERE v.turno_id = :id AND v.estado = 'completada' GROUP BY p.metodo");
    $st->execute([':id' => $id]);
    foreach ($st->fetchAll() as $f) {
        $r['ventas'][$f['metodo']] = ['total' => a_centavos($f['total']), 'operaciones' => (int) $f['n']];
    }
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(descuento), 0) AS descuento
                         FROM ventas WHERE turno_id = :id AND estado = 'completada'");
    $st->execute([':id' => $id]);
    $f = $st->fetch();
    $r['operaciones'] = (int) $f['n'];
    $r['vendido']     = a_centavos($f['total']);
    $r['descuentos']  = a_centavos($f['descuento']);

    $st = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE turno_id = :id AND estado = 'cancelada'");
    $st->execute([':id' => $id]);
    $r['canceladas'] = (int) $st->fetchColumn();

    $st = $pdo->prepare("SELECT metodo_pago, SUM(monto) FROM abonos WHERE turno_id = :id AND estado = 'aplicado' GROUP BY metodo_pago");
    $st->execute([':id' => $id]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $metodo => $monto) {
        $r['abonos'][$metodo] = a_centavos($monto);
    }

    // Devoluciones hechas en este turno (las de efectivo ya están en las salidas).
    $st = $pdo->prepare('SELECT reembolso, SUM(total) FROM devoluciones WHERE turno_id = :id GROUP BY reembolso');
    $st->execute([':id' => $id]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $metodo => $monto) {
        $r['devoluciones'][$metodo] = a_centavos($monto);
    }
    $r['devuelto'] = array_sum($r['devoluciones']);

    $st = $pdo->prepare('SELECT tipo, SUM(monto) FROM movimientos_caja WHERE turno_id = :id GROUP BY tipo');
    $st->execute([':id' => $id]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $tipo => $monto) {
        $r[$tipo === 'entrada' ? 'entradas' : 'salidas'] = a_centavos($monto);
    }

    $r['esperado'] = $r['fondo'] + $r['ventas']['efectivo']['total'] + $r['abonos']['efectivo'] + $r['entradas'] - $r['salidas'];
    return $r;
}

function movimientos_turno(int $turnoId): array
{
    $st = db()->prepare('SELECT * FROM movimientos_caja WHERE turno_id = :id ORDER BY id');
    $st->execute([':id' => $turnoId]);
    return $st->fetchAll();
}

/** Registra una entrada o salida de efectivo en el turno abierto. */
function registrar_movimiento_caja(string $tipo, int $montoCentavos, string $concepto, ?int $ventaId = null): void
{
    $turno = turno_abierto();
    if (!$turno) {
        throw new RuntimeException('La caja está cerrada. Ábrela primero.');
    }
    db()->prepare('INSERT INTO movimientos_caja (turno_id, fecha, tipo, monto, concepto, usuario_id, venta_id)
                   VALUES (:turno, :fecha, :tipo, :monto, :concepto, :usuario, :venta)')
        ->execute([':turno' => $turno['id'], ':fecha' => date('Y-m-d H:i:s'), ':tipo' => $tipo,
                   ':monto' => centavos_a_decimal($montoCentavos), ':concepto' => $concepto,
                   ':usuario' => usuario_id(), ':venta' => $ventaId]);
}

/** Cierra el turno: guarda lo esperado y lo contado. */
function cerrar_turno(array $turno, int $contadoCentavos, string $notas): void
{
    transaccion_iniciar();
    try {
        $esperado = resumen_turno($turno)['esperado'];
        $st = db()->prepare('UPDATE turnos SET cerrado_en = :fecha, cerrado_por = :usuario, efectivo_esperado = :esperado,
                                               efectivo_contado = :contado, notas = :notas
                             WHERE id = :id AND cerrado_en IS NULL');
        $st->execute([':fecha' => date('Y-m-d H:i:s'), ':usuario' => usuario_id(), ':esperado' => centavos_a_decimal($esperado),
                      ':contado' => centavos_a_decimal($contadoCentavos), ':notas' => $notas !== '' ? $notas : null, ':id' => $turno['id']]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('Este turno ya estaba cerrado.');
        }
        transaccion_confirmar();
    } catch (Throwable $e) {
        transaccion_revertir();
        throw $e;
    }
}

/** Texto de diferencia: "Sobran $10.00", "Faltan $5.00" o "Cuadra exacto". */
function diferencia_texto(int $diferenciaCentavos): string
{
    if ($diferenciaCentavos === 0) {
        return 'Cuadra exacto';
    }
    return ($diferenciaCentavos > 0 ? 'Sobran ' : 'Faltan ') . dinero(abs($diferenciaCentavos) / 100);
}

/** Ticket ESC/POS del corte (parcial si el turno sigue abierto). */
function escpos_corte(array $turno, array $aj): string
{
    $r = resumen_turno($turno);
    $cerrado = $turno['cerrado_en'] !== null;
    $t = ticket_nuevo($aj);
    $t->comando(ESCPOS_CENTRO . ESCPOS_NEGRITA_SI)->linea($cerrado ? 'CORTE DE CAJA' : 'CORTE PARCIAL (caja abierta)')
      ->comando(ESCPOS_NEGRITA_NO . ESCPOS_IZQUIERDA)->separador()
      ->columnas('Turno:', '#' . $turno['id'])
      ->columnas('Abrió:', fecha_fmt($turno['abierto_en']) . ($turno['abierto_por'] ? ' ' . nombre_usuario((int) $turno['abierto_por']) : ''));
    if ($cerrado) {
        $t->columnas('Cerró:', fecha_fmt($turno['cerrado_en']) . ($turno['cerrado_por'] ? ' ' . nombre_usuario((int) $turno['cerrado_por']) : ''));
    } else {
        $t->columnas('Impreso:', fecha_fmt(date('Y-m-d H:i:s')));
    }
    $t->separador()->linea('VENTAS (' . $r['operaciones'] . ')');
    foreach (METODOS_PAGO as $clave => $m) {
        if ($r['ventas'][$clave]['operaciones'] > 0) {
            $t->columnas('  ' . $m['etiqueta'] . ' (' . $r['ventas'][$clave]['operaciones'] . ')', dinero($r['ventas'][$clave]['total'] / 100));
        }
    }
    $t->columnas('  Total vendido', dinero($r['vendido'] / 100));
    if ($r['descuentos'] > 0) {
        $t->columnas('  Descuentos dados', dinero($r['descuentos'] / 100));
    }
    if ($r['canceladas'] > 0) {
        $t->columnas('  Ventas canceladas', (string) $r['canceladas']);
    }
    if ($r['devuelto'] > 0) {
        $t->linea('DEVOLUCIONES');
        foreach (METODOS_REEMBOLSO as $clave => $etiqueta) {
            if ($r['devoluciones'][$clave] > 0) {
                $t->columnas('  ' . explode(' (', $etiqueta)[0], '-' . dinero($r['devoluciones'][$clave] / 100));
            }
        }
    }
    if (array_sum($r['abonos']) > 0) {
        $t->linea('ABONOS DE CLIENTES');
        foreach (METODOS_ABONO as $clave => $etiqueta) {
            if ($r['abonos'][$clave] > 0) {
                $t->columnas('  ' . $etiqueta, dinero($r['abonos'][$clave] / 100));
            }
        }
    }
    $t->separador()->linea('EFECTIVO EN CAJA')
      ->columnas('  Fondo inicial', dinero($r['fondo'] / 100))
      ->columnas('+ Ventas en efectivo', dinero($r['ventas']['efectivo']['total'] / 100))
      ->columnas('+ Abonos en efectivo', dinero($r['abonos']['efectivo'] / 100))
      ->columnas('+ Entradas', dinero($r['entradas'] / 100))
      ->columnas('- Salidas', dinero($r['salidas'] / 100))
      ->comando(ESCPOS_NEGRITA_SI)->columnas('= Debe haber', dinero($r['esperado'] / 100))->comando(ESCPOS_NEGRITA_NO);
    if ($cerrado) {
        $contado = a_centavos($turno['efectivo_contado']);
        $t->columnas('  Contado', dinero($contado / 100))
          ->comando(ESCPOS_NEGRITA_SI)->columnas('  Diferencia', diferencia_texto($contado - a_centavos($turno['efectivo_esperado'])))
          ->comando(ESCPOS_NEGRITA_NO);
        if ($turno['notas']) {
            $t->separador()->linea('Notas: ' . $turno['notas']);
        }
    }
    $movs = movimientos_turno((int) $turno['id']);
    if ($movs) {
        $t->separador()->linea('ENTRADAS Y SALIDAS');
        foreach ($movs as $mv) {
            $t->columnas(($mv['tipo'] === 'entrada' ? '+ ' : '- ') . $mv['concepto'], dinero($mv['monto']));
        }
    }
    if ($cerrado) {
        $t->linea()->linea()->comando(ESCPOS_CENTRO)->linea('______________________')->linea('Firma')->comando(ESCPOS_IZQUIERDA);
    }
    return ticket_cerrar($t, $aj);
}
