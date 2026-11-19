<?php

use App\Models\Contacto;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Actions\PrepararRecepcionDesdeOC;
use App\Modules\Compras\Actions\RecibirMercancia;
use App\Modules\Compras\Enums\ConceptoGastoImportacion;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EstadoOrdenCompra;
use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;

beforeEach(function () {
    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900123456',
        'nombre_completo' => 'Proveedor Bebe Ltda', 'es_proveedor' => true,
    ]);
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->producto = Producto::create([
        'referencia' => 'REF-TEST-01', 'nombre' => 'Body bebé algodón',
        'precio_proveedor' => 5000, 'activo' => true,
    ]);
    $this->variante = ProductoVariante::create([
        'producto_id' => $this->producto->id,
        'talla' => '2', 'color_codigo' => 'RS', 'color_nombre' => 'Rosa',
    ]);
});

it('crea una OC nacional con totales correctos', function () {
    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id,
        'bodega_id' => $this->bodega->id,
        'tipo' => 'nacional',
        'items' => [
            [
                'producto_id' => $this->producto->id,
                'variante_id' => $this->variante->id,
                'descripcion' => 'Body bebé rosa T2',
                'cantidad' => 100, 'precio_unit' => 5000,
                'iva_pct' => 19,
            ],
        ],
    ]);

    expect($orden->numero)->toStartWith('OC-')
        ->and((float) $orden->subtotal)->toBe(500000.0)
        ->and((float) $orden->iva)->toBe(95000.0)
        ->and((float) $orden->total)->toBe(595000.0)
        ->and($orden->estado)->toBe(EstadoOrdenCompra::Borrador);
});

it('flujo completo: aprobar → recibir → asiento contable → stock', function () {
    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id,
        'bodega_id' => $this->bodega->id,
        'items' => [[
            'producto_id' => $this->producto->id,
            'variante_id' => $this->variante->id,
            'descripcion' => 'Body T2',
            'cantidad' => 50, 'precio_unit' => 6000, 'iva_pct' => 19,
        ]],
    ]);

    AprobarOrdenCompra::run($orden);
    $recepcion = PrepararRecepcionDesdeOC::run($orden->fresh(), $this->bodega->id);
    RecibirMercancia::run($recepcion);

    $orden = $orden->fresh();
    expect($orden->estado)->toBe(EstadoOrdenCompra::Recibida)
        ->and((float) $orden->items->first()->cantidad_recibida)->toBe(50.0);

    // `cantidad` tiene cast decimal, así que la suma vuelve como texto
    // ('50.0000'): se compara por valor y no por tipo.
    expect((float) InventarioMovimiento::where('variante_id', $this->variante->id)->where('tipo', 'entrada_compra')->sum('cantidad'))
        ->toBe(50.0);

    $movs = MovimientoContable::where('origen_type', \App\Modules\Compras\Models\RecepcionCompra::class)->get();
    expect($movs)->toHaveCount(3);
    expect($movs->firstWhere('cuenta_puc', cta('cta_inventario_default'))->debe)->not->toBeNull();
    expect($movs->firstWhere('cuenta_puc', cta('cta_iva_compra_default'))->debe)->not->toBeNull();
    expect($movs->firstWhere('cuenta_puc', cta('cta_proveedores_default'))->haber)->not->toBeNull();
});

it('liquida importación prorrateando gastos por valor', function () {
    // OC importación con dos ítems distintos
    $prodB = Producto::create(['referencia' => 'REF-TEST-02', 'nombre' => 'Chupón', 'precio_proveedor' => 8000, 'activo' => true]);

    $prodBVar = \App\Modules\Dropi\Models\ProductoVariante::create([
        'producto_id' => $prodB->id, 'color_codigo' => 'RS', 'color_nombre' => 'Rosa', 'talla' => 'U',
    ]);

    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id,
        'bodega_id' => $this->bodega->id,
        'tipo' => 'importacion',
        'moneda' => 'USD',
        'tasa_cambio' => 4000,
        'items' => [
            ['producto_id' => $this->producto->id, 'variante_id' => $this->variante->id,
             'descripcion' => 'Body', 'cantidad' => 100, 'precio_unit' => 0.25, 'iva_pct' => 0],
            ['producto_id' => $prodB->id, 'variante_id' => $prodBVar->id,
             'descripcion' => 'Chupón', 'cantidad' => 100, 'precio_unit' => 0.75, 'iva_pct' => 0],
        ],
    ]);
    AprobarOrdenCompra::run($orden);

    $imp = Importacion::create([
        'numero' => Importacion::siguienteNumero(),
        'contenedor' => 'MSCU1234567',
        'moneda_origen' => 'USD',
        'estado' => EstadoImportacion::EnTransito,
    ]);
    $imp->ordenes()->attach($orden->id);

    GastoImportacion::create([
        'importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete,
        'descripcion' => 'Flete Shanghai-Cartagena', 'monto' => 2000000, 'monto_base' => 2000000,
        'capitalizable' => true, 'metodo_prorrateo' => 'valor',
    ]);
    GastoImportacion::create([
        'importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::IvaImportacion,
        'descripcion' => 'IVA importación', 'monto' => 500000, 'monto_base' => 500000,
        'capitalizable' => false, 'metodo_prorrateo' => 'valor',
    ]);

    LiquidarImportacion::run($imp);

    $imp = $imp->fresh(['lineas', 'gastos']);
    expect($imp->estado)->toBe(EstadoImportacion::Liquidada)
        ->and((float) $imp->valor_fob)->toBe(400000.0)
        ->and((float) $imp->valor_gastos)->toBe(2000000.0)
        ->and($imp->lineas)->toHaveCount(2);

    // Prorrateo por valor: 100k y 300k → 25% y 75% de 2.000.000
    $lineaBody = $imp->lineas->firstWhere('producto_id', $this->producto->id);
    $lineaChup = $imp->lineas->firstWhere('producto_id', $prodB->id);
    expect((float) $lineaBody->gasto_prorrateado)->toBe(500000.0)
        ->and((float) $lineaChup->gasto_prorrateado)->toBe(1500000.0)
        ->and((float) $lineaBody->costo_final_unit)->toBe(6000.0)
        ->and((float) $lineaChup->costo_final_unit)->toBe(18000.0);

    // Asiento correcto (fix bug auditor #4):
    //   1435 débito 2.400.000 (FOB 400k + flete capitalizable 2M)
    //   1465 crédito 400k (salida inventario en tránsito por FOB)
    //   1355 débito 500k (IVA importación es crédito fiscal, NO gasto)
    //   2205 crédito 500k (CxP agente aduanero)
    //   2205 crédito 2M (CxP proveedor del flete)
    $movs = \App\Modules\Cartera\Models\MovimientoContable::where('origen_type', Importacion::class)->get();
    expect($movs->firstWhere('cuenta_puc', cta('cta_inventario_default'))->debe + 0)->toBe(2400000.0)
        ->and($movs->firstWhere('cuenta_puc', '1465')->haber + 0)->toBe(400000.0)
        ->and($movs->firstWhere('cuenta_puc', '1355')->debe + 0)->toBe(500000.0)
        ->and((float) $movs->where('cuenta_puc', cta('cta_proveedores_default'))->sum('haber'))->toBe(2500000.0);

    // El stock sube cuando bodega acepta, no al liquidar: acá nadie ha recibido.
    // (Ver RecepcionDeImportacionTest; antes entraba lo pedido sin que nadie lo contara.)
    expect(\App\Modules\Dropi\Models\InventarioMovimiento::where('tipo', 'entrada_importacion')->count())->toBe(0);
});
