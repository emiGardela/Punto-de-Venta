<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Formulario de producto compartido por crear.php y editar.php.
 * Variables esperadas: $producto (arreglo con los valores), $errores (campo => mensaje),
 *                      $accion (URL de envío), $esEdicion (bool).
 *
 * Arriba va lo básico (nombre, código, precio, existencia). Lo demás está en
 * "Más opciones", que se abre sola si el producto ya usa alguna de ellas.
 * Proveedor, mayoreo y paquetes/kits solo aparecen con las funciones
 * avanzadas activadas (o si el producto ya los tiene).
 */
$v = fn(string $campo) => e($producto[$campo] ?? '');
$invalido = fn(string $campo) => isset($errores[$campo]) ? ' is-invalid' : '';
$mensaje  = fn(string $campo) => isset($errores[$campo]) ? '<div class="invalid-feedback">' . e($errores[$campo]) . '</div>' : '';
$controla = !empty($producto['controlar_stock']);
$fracciona = !empty($producto['permite_fraccion']);
$categorias = db()->query('SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY sin_acentos(nombre)')->fetchAll(PDO::FETCH_KEY_PAIR);
$proveedores = db()->query('SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY sin_acentos(nombre)')->fetchAll(PDO::FETCH_KEY_PAIR);
$marcas = db()->query('SELECT DISTINCT marca FROM productos WHERE marca IS NOT NULL ORDER BY marca')->fetchAll(PDO::FETCH_COLUMN);
$volDesde = array_values(array_filter((array) ($producto['volumen_desde'] ?? []), fn($x) => $x !== '' && $x !== null));
$volPrecio = array_values((array) ($producto['volumen_precio'] ?? []));
$renglonesVolumen = max(3, count($volDesde) + 1);
$tipo = in_array($producto['tipo'] ?? '', ['normal', 'paquete', 'kit'], true) ? $producto['tipo'] : 'normal';
$propio = (int) ($producto['id'] ?? 0);
// Candidatos: base de un paquete = producto normal con inventario; componente de kit = normal o paquete.
$candidatos = db()->query("SELECT id, nombre, tipo, controlar_stock FROM productos WHERE activo = 1 AND tipo <> 'kit' ORDER BY sin_acentos(nombre)")->fetchAll();
$kitIds = array_values((array) ($producto['kit_producto'] ?? []));
$kitCant = array_values((array) ($producto['kit_cantidad'] ?? []));
$renglonesKit = max(4, count($kitIds) + 2);

$avanzadas = funciones_avanzadas();
$verProveedor = $avanzadas || !empty($producto['proveedor_id']);
$verMayoreo   = $avanzadas || $volDesde;
$verTipo      = $avanzadas || $tipo !== 'normal';
// "Más opciones" abierta si el producto ya usa algo de ahí o hay un error en esos campos.
$camposExtra = ['tasa_iva', 'categoria_id', 'marca', 'codigo_interno', 'unidad', 'descripcion', 'proveedor_id', 'volumen', 'producto_base_id', 'factor_base', 'kit'];
$abrirExtra = array_intersect_key($errores, array_flip($camposExtra))
    || (string) ($producto['tasa_iva'] ?? '16') !== '16' || !empty($producto['categoria_id']) || trim((string) ($producto['marca'] ?? '')) !== ''
    || trim((string) ($producto['codigo_interno'] ?? '')) !== '' || ($producto['unidad'] ?? 'pieza') !== 'pieza'
    || trim((string) ($producto['descripcion'] ?? '')) !== '' || !empty($producto['proveedor_id']) || $volDesde || $tipo !== 'normal' || $fracciona;
?>
<form method="post" action="<?= e($accion) ?>" class="needs-validation" novalidate id="formProducto" style="max-width:860px">
    <?= csrf_campo() ?>
    <?php if ($esEdicion): ?>
        <input type="hidden" name="id" value="<?= (int) $producto['id'] ?>">
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body p-4">
            <div class="mb-3">
                <label for="nombre" class="form-label">Nombre <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-lg<?= $invalido('nombre') ?>" id="nombre" name="nombre"
                       value="<?= $v('nombre') ?>" maxlength="150" required autofocus
                       placeholder="<?= e(giro_actual()['producto']) ?>">
                <?= $mensaje('nombre') ?: '<div class="invalid-feedback">Escribe el nombre del producto.</div>' ?>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="precio" class="form-label">Precio de venta <span class="text-danger">*</span></label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control<?= $invalido('precio') ?>" id="precio" name="precio"
                               value="<?= $v('precio') ?>" min="0" max="99999999.99" step="0.01" required inputmode="decimal">
                        <?= $mensaje('precio') ?: '<div class="invalid-feedback">Escribe un precio válido.</div>' ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="codigo_barras" class="form-label">Código de barras <span class="text-body-secondary fw-normal">(si tiene)</span></label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white"><i class="bi bi-upc-scan"></i></span>
                        <input type="text" class="form-control<?= $invalido('codigo_barras') ?>" id="codigo_barras" name="codigo_barras"
                               value="<?= $v('codigo_barras') ?>" maxlength="64" pattern="[A-Za-z0-9\-_.]+"
                               placeholder="Escanéalo aquí" autocomplete="off">
                        <?= $mensaje('codigo_barras') ?: '<div class="invalid-feedback">Solo letras, números, guiones o puntos.</div>' ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="costo" class="form-label">Lo que te costó <span class="text-body-secondary fw-normal">(opcional)</span></label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control<?= $invalido('costo') ?>" id="costo" name="costo"
                               value="<?= $v('costo') ?>" min="0" max="99999999.99" step="0.01" inputmode="decimal">
                        <?= $mensaje('costo') ?: '<div class="invalid-feedback">Escribe un costo válido o déjalo vacío.</div>' ?>
                    </div>
                    <div class="form-text" id="margenTexto">Sirve para saber cuánto ganas.</div>
                </div>
            </div>

            <div id="camposNormal">
                <hr class="my-4">
                <div class="form-check form-switch interruptor-tarjeta mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="controlar_stock" name="controlar_stock" value="1" <?= $controla ? 'checked' : '' ?>>
                    <label class="form-check-label" for="controlar_stock">
                        <span class="fw-semibold d-block">Llevar la cuenta de cuántos hay</span>
                        <small class="text-body-secondary">Apágalo para servicios como copias o impresiones.</small>
                    </label>
                </div>
                <div class="row g-3" id="camposInventario">
                    <div class="col-6">
                        <label for="existencia" class="form-label">¿Cuántos tienes?</label>
                        <input type="number" class="form-control<?= $invalido('existencia') ?>" id="existencia" name="existencia"
                               value="<?= $v('existencia') ?>" min="0" max="999999999" step="1" inputmode="decimal">
                        <?= $mensaje('existencia') ?: '<div class="invalid-feedback">Cantidad no válida.</div>' ?>
                    </div>
                    <div class="col-6">
                        <label for="stock_minimo" class="form-label">Avisar cuando queden</label>
                        <input type="number" class="form-control<?= $invalido('stock_minimo') ?>" id="stock_minimo" name="stock_minimo"
                               value="<?= $v('stock_minimo') ?>" min="0" max="999999999" step="1" inputmode="decimal">
                        <?= $mensaje('stock_minimo') ?: '<div class="invalid-feedback">Cantidad no válida.</div>' ?>
                    </div>
                </div>
                <div class="alert alert-info small mt-3 mb-0 d-none" id="avisoServicio">
                    <i class="bi bi-info-circle me-1"></i>Se venderá sin límite de existencias.
                </div>
            </div>

            <?php if ($esEdicion): ?>
                <hr class="my-4">
                <div class="form-check form-switch interruptor-tarjeta">
                    <input type="hidden" name="activo" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1" <?= !empty($producto['activo']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="activo">
                        <span class="fw-semibold d-block">Disponible para vender</span>
                        <small class="text-body-secondary">Si lo apagas no aparece al vender, pero se conserva su historial.</small>
                    </label>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <button class="btn btn-link px-0 mb-2 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#masOpciones"
            aria-expanded="<?= $abrirExtra ? 'true' : 'false' ?>" aria-controls="masOpciones">
        <i class="bi bi-sliders me-1"></i>Más opciones <span class="text-body-secondary small">(IVA, categoría, granel<?= $verMayoreo ? ', mayoreo' : '' ?><?= $verTipo ? ', paquetes y kits' : '' ?>…)</span>
    </button>
    <div class="collapse<?= $abrirExtra ? ' show' : '' ?>" id="masOpciones">
        <div class="card mb-3">
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="tasa_iva" class="form-label">IVA <span class="text-body-secondary fw-normal">(el precio ya lo incluye)</span></label>
                        <select class="form-select<?= $invalido('tasa_iva') ?>" id="tasa_iva" name="tasa_iva">
                            <?php foreach (TASAS_IVA as $clave => [$etiqueta]): ?>
                                <option value="<?= e($clave) ?>" <?= (string) ($producto['tasa_iva'] ?? '16') === (string) $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensaje('tasa_iva') ?>
                        <div class="form-text">Casi todo lleva 16%. Los libros y alimentos básicos, 0%.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="unidad" class="form-label">Se vende por</label>
                        <select class="form-select<?= $invalido('unidad') ?>" id="unidad" name="unidad">
                            <?php foreach (UNIDADES as $clave => [$etiqueta]): ?>
                                <option value="<?= e($clave) ?>" <?= ($producto['unidad'] ?? 'pieza') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check mt-2" id="campoFraccion">
                            <input class="form-check-input" type="checkbox" id="permite_fraccion" name="permite_fraccion" value="1" <?= $fracciona ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="permite_fraccion">Se vende a granel (0.5, 1.250… kilos o metros)</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="categoria_id" class="form-label">Categoría</label>
                        <select class="form-select<?= $invalido('categoria_id') ?>" id="categoria_id" name="categoria_id">
                            <option value="">Sin categoría</option>
                            <?php foreach ($categorias as $cid => $cnombre): ?>
                                <option value="<?= (int) $cid ?>" <?= (int) ($producto['categoria_id'] ?? 0) === (int) $cid ? 'selected' : '' ?>><?= e($cnombre) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $mensaje('categoria_id') ?>
                        <div class="form-text"><a href="<?= e(url('productos/categorias.php')) ?>" target="_blank">Crear o cambiar categorías</a></div>
                    </div>
                    <div class="col-md-6">
                        <label for="marca" class="form-label">Marca</label>
                        <input type="text" class="form-control<?= $invalido('marca') ?>" id="marca" name="marca" value="<?= $v('marca') ?>"
                               maxlength="60" list="listaMarcas" placeholder="Ej. Scribe, Bic, Pelikan">
                        <datalist id="listaMarcas"><?php foreach ($marcas as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
                        <?= $mensaje('marca') ?>
                    </div>
                    <div class="col-md-6">
                        <label for="codigo_interno" class="form-label">Clave corta <span class="text-body-secondary fw-normal">(para buscarlo rápido)</span></label>
                        <input type="text" class="form-control<?= $invalido('codigo_interno') ?>" id="codigo_interno" name="codigo_interno"
                               value="<?= $v('codigo_interno') ?>" maxlength="30" placeholder="Ej. CUA-100" autocomplete="off">
                        <?= $mensaje('codigo_interno') ?>
                    </div>
                    <?php if ($verProveedor): ?>
                        <div class="col-md-6">
                            <label for="proveedor_id" class="form-label">Proveedor</label>
                            <select class="form-select<?= $invalido('proveedor_id') ?>" id="proveedor_id" name="proveedor_id">
                                <option value="">Ninguno</option>
                                <?php foreach ($proveedores as $pid => $pnombre): ?>
                                    <option value="<?= (int) $pid ?>" <?= (int) ($producto['proveedor_id'] ?? 0) === (int) $pid ? 'selected' : '' ?>><?= e($pnombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $mensaje('proveedor_id') ?>
                        </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <label for="descripcion" class="form-label">Descripción</label>
                        <textarea class="form-control<?= $invalido('descripcion') ?>" id="descripcion" name="descripcion" rows="2" maxlength="500"
                                  placeholder="Medidas, color, número de hojas…"><?= $v('descripcion') ?></textarea>
                        <?= $mensaje('descripcion') ?>
                    </div>
                </div>

                <?php if ($verMayoreo): ?>
                    <hr class="my-4">
                    <div class="form-label mb-1">Precio de mayoreo</div>
                    <div class="form-text mt-0 mb-2">Si alguien compra muchos, se cobra más barato. Ej.: desde 12 piezas a $4.50 c/u.</div>
                    <?php if (isset($errores['volumen'])): ?><div class="text-danger small mb-2"><?= e($errores['volumen']) ?></div><?php endif; ?>
                    <?php for ($i = 0; $i < $renglonesVolumen; $i++): ?>
                        <div class="input-group input-group-sm mb-2" style="max-width:380px">
                            <span class="input-group-text">Desde</span>
                            <input type="text" class="form-control num" name="volumen_desde[]" value="<?= e($volDesde[$i] ?? '') ?>" inputmode="decimal" placeholder="12">
                            <span class="input-group-text">a $</span>
                            <input type="text" class="form-control num" name="volumen_precio[]" value="<?= e($volPrecio[$i] ?? '') ?>" inputmode="decimal" placeholder="4.50">
                            <span class="input-group-text">c/u</span>
                        </div>
                    <?php endfor; ?>
                <?php endif; ?>

                <?php if ($verTipo): ?>
                    <hr class="my-4">
                    <div class="form-label">¿Qué es este producto?</div>
                    <div class="d-grid gap-2 mb-3" role="radiogroup">
                        <?php foreach (['normal' => ['bi-box', 'Producto normal', 'Tiene su propia existencia (o es un servicio).'],
                                        'paquete' => ['bi-box2', 'Paquete de otro producto', 'Ej. caja de 12 lápices: al venderla se descuentan 12 lápices.'],
                                        'kit' => ['bi-backpack', 'Kit de varios productos', 'Ej. kit escolar: al venderlo se descuenta cada artículo.']] as $clave => [$icono, $etiqueta, $ayuda]): ?>
                            <input type="radio" class="btn-check" name="tipo" id="tipo_<?= $clave ?>" value="<?= $clave ?>" <?= $tipo === $clave ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary text-start" for="tipo_<?= $clave ?>">
                                <i class="bi <?= $icono ?> me-1"></i><strong><?= $etiqueta ?></strong>
                                <small class="d-block opacity-75"><?= $ayuda ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div id="camposPaquete" class="mb-3">
                        <label for="producto_base_id" class="form-label">Contiene</label>
                        <div class="input-group">
                            <input type="text" class="form-control num<?= $invalido('factor_base') ?>" name="factor_base" value="<?= $v('factor_base') ?>"
                                   inputmode="decimal" placeholder="12" style="max-width:90px" aria-label="Cuántas unidades">
                            <span class="input-group-text">de</span>
                            <select class="form-select<?= $invalido('producto_base_id') ?>" id="producto_base_id" name="producto_base_id">
                                <option value="">Elige el producto…</option>
                                <?php foreach ($candidatos as $c): if ($c['tipo'] !== 'normal' || !(int) $c['controlar_stock'] || (int) $c['id'] === $propio) { continue; } ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= (int) ($producto['producto_base_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?= isset($errores['producto_base_id']) || isset($errores['factor_base'])
                            ? '<div class="text-danger small mt-1">' . e($errores['producto_base_id'] ?? $errores['factor_base']) . '</div>' : '' ?>
                    </div>

                    <div id="camposKit" class="mb-0">
                        <div class="form-label">Productos del kit</div>
                        <?php if (isset($errores['kit'])): ?><div class="text-danger small mb-2"><?= e($errores['kit']) ?></div><?php endif; ?>
                        <?php for ($i = 0; $i < $renglonesKit; $i++): ?>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control num" name="kit_cantidad[]" value="<?= e($kitCant[$i] ?? '') ?>" inputmode="decimal"
                                       placeholder="1" style="max-width:70px" aria-label="Cantidad">
                                <select class="form-select" name="kit_producto[]" aria-label="Producto">
                                    <option value="">—</option>
                                    <?php foreach ($candidatos as $c): if ((int) $c['id'] === $propio) { continue; } ?>
                                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($kitIds[$i] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endfor; ?>
                        <div class="form-text">La existencia del kit es la de sus productos. Ponle el precio de kit en "Precio de venta".</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
        <a href="<?= e(url('productos/index.php')) ?>" class="btn btn-light border btn-lg px-4">Cancelar</a>
        <?php if (!$esEdicion): ?>
            <button type="submit" name="otro" value="1" class="btn btn-outline-primary btn-lg px-4"><i class="bi bi-plus-circle me-1"></i>Guardar y agregar otro</button>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-lg px-4"><i class="bi bi-check2-circle me-1"></i><?= $esEdicion ? 'Guardar cambios' : 'Guardar producto' ?></button>
    </div>
</form>

<script>
(function () {
    const controla = document.getElementById('controlar_stock');
    const fracciona = document.getElementById('permite_fraccion');
    const campos = document.getElementById('camposInventario');
    const aviso = document.getElementById('avisoServicio');
    const existencia = document.getElementById('existencia');
    const minimo = document.getElementById('stock_minimo');
    const precio = document.getElementById('precio');
    const costo = document.getElementById('costo');
    const margen = document.getElementById('margenTexto');

    function actualizar() {
        const activo = controla.checked;
        campos.classList.toggle('d-none', !activo);
        aviso.classList.toggle('d-none', activo);
        [existencia, minimo].forEach(function (inp) {
            inp.disabled = !activo;
            inp.step = fracciona.checked ? '0.001' : '1';
        });
    }
    function calcularMargen() {
        const p = parseFloat(precio.value), c = parseFloat(costo.value);
        if (p > 0 && c >= 0 && costo.value !== '') {
            const g = p - c;
            margen.textContent = 'Ganas $' + g.toFixed(2) + ' por cada uno (' + ((g / p) * 100).toFixed(1) + '% del precio)';
            margen.classList.toggle('text-danger', g < 0);
        } else {
            margen.textContent = 'Sirve para saber cuánto ganas.';
            margen.classList.remove('text-danger');
        }
    }
    // Paquete o kit: su existencia es la de los productos que contiene.
    function actualizarTipo() {
        const marcado = document.querySelector('input[name="tipo"]:checked');
        const tipo = marcado ? marcado.value : 'normal';
        const paquete = document.getElementById('camposPaquete');
        const kit = document.getElementById('camposKit');
        if (paquete) { paquete.classList.toggle('d-none', tipo !== 'paquete'); }
        if (kit) { kit.classList.toggle('d-none', tipo !== 'kit'); }
        document.getElementById('camposNormal').classList.toggle('d-none', tipo !== 'normal');
        document.getElementById('campoFraccion').classList.toggle('d-none', tipo !== 'normal');
    }
    document.querySelectorAll('input[name="tipo"]').forEach(r => r.addEventListener('change', actualizarTipo));
    actualizarTipo();
    controla.addEventListener('change', actualizar);
    fracciona.addEventListener('change', actualizar);
    precio.addEventListener('input', calcularMargen);
    costo.addEventListener('input', calcularMargen);
    // Un lector de códigos "presiona Enter" al terminar: evita que envíe el formulario.
    document.getElementById('codigo_barras').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); costo.focus(); }
    });
    actualizar();
    calcularMargen();
})();
</script>
