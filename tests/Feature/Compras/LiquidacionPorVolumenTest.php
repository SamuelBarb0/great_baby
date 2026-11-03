<?php

/**
 * Liquidación por los m³ de CADA ítem (reunión con Alejandro, 30-oct-2026):
 * «sumando toda la cantidad de gastos … la promedio en los metros cúbicos».
 * Antes cada ítem tomaba el m³ de su orden entera y todos los de una misma
 * orden quedaban con la misma porción del flete.
 */

use App\Models\Contacto;
use App\Modules\Compras\Actions\AprobarOrdenCompra;
use App\Modules\Compras\Actions\CrearOrdenCompra;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Enums\ConceptoGastoImportacion;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\GastoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;

beforeEach(function () {
    $this->proveedor = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900777111',
        'nombre_completo' => 'Topwin', 'es_proveedor' => true,
    ]);
    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'PPAL', 'nombre' => 'Bodega principal', 'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);
    $this->nuevoItem = function (string $ref, array $extra) {
        $p = Producto::create(['referencia' => $ref, 'nombre' => $ref, 'precio_proveedor' => 1000, 'activo' => true]);
        $v = ProductoVariante::create(['producto_id' => $p->id, 'talla' => 'U', 'color_codigo' => 'NA', 'color_nombre' => 'NA']);

        return ['producto_id' => $p->id, 'variante_id' => $v->id, 'descripcion' => $ref, 'iva_pct' => 0, ...$extra];
    };
});

it('reparte el gasto por los m³ de cada ítem: cajas × CBM de la caja', function () {
    // Mismo valor, distinto tamaño: 5 m³ y 15 m³ de un contenedor de 20.
    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
        'tipo' => 'importacion', 'moneda' => 'USD', 'tasa_cambio' => 4000,
        'items' => [
            ($this->nuevoItem)('PEQ', ['cantidad' => 100, 'cajas' => 10, 'unidades_por_caja' => 10, 'cbm_caja' => 0.5, 'precio_unit' => 0.25]),
            ($this->nuevoItem)('GRA', ['cantidad' => 100, 'cajas' => 25, 'unidades_por_caja' => 4, 'cbm_caja' => 0.6, 'precio_unit' => 0.25]),
        ],
    ]);
    AprobarOrdenCompra::run($orden);
    $imp = Importacion::create([
        'numero' => Importacion::siguienteNumero(), 'contenedor' => 'TCLU1234567',
        'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnPuerto,
    ]);
    // El m³ de la orden (20) ya no se le pone entero a cada ítem.
    $imp->ordenes()->attach($orden->id, ['volumen_m3' => 20]);
    GastoImportacion::create([
        'importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete,
        'descripcion' => 'Flete + gastos Colombia', 'monto' => 2000000, 'monto_base' => 2000000,
        'capitalizable' => true, 'metodo_prorrateo' => 'volumen',
    ]);

    $lineas = LiquidarImportacion::run($imp)->lineas;
    $peq = $lineas->firstWhere('producto_id', Producto::where('referencia', 'PEQ')->value('id'));
    $gra = $lineas->firstWhere('producto_id', Producto::where('referencia', 'GRA')->value('id'));

    expect((float) $peq->gasto_prorrateado)->toBe(500000.0)
        ->and((float) $gra->gasto_prorrateado)->toBe(1500000.0)
        // 100 unidades cada uno: 1.000 de FOB + su porción del gasto
        ->and((float) $peq->costo_final_unit)->toBe(6000.0)
        ->and((float) $gra->costo_final_unit)->toBe(16000.0);
});

it('una orden vieja sin cajas reparte el m³ de la orden por valor entre sus ítems', function () {
    $orden = CrearOrdenCompra::run([
        'proveedor_id' => $this->proveedor->id, 'bodega_id' => $this->bodega->id,
        'tipo' => 'importacion', 'moneda' => 'USD', 'tasa_cambio' => 4000,
        'items' => [
            ($this->nuevoItem)('VIEJO-A', ['cantidad' => 100, 'precio_unit' => 0.25]),
            ($this->nuevoItem)('VIEJO-B', ['cantidad' => 100, 'precio_unit' => 0.75]),
        ],
    ]);
    AprobarOrdenCompra::run($orden);
    $imp = Importacion::create([
        'numero' => Importacion::siguienteNumero(), 'contenedor' => 'TCLU7654321',
        'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnPuerto,
    ]);
    $imp->ordenes()->attach($orden->id, ['volumen_m3' => 20]);
    GastoImportacion::create([
        'importacion_id' => $imp->id, 'concepto' => ConceptoGastoImportacion::Flete,
        'descripcion' => 'Flete', 'monto' => 2000000, 'monto_base' => 2000000,
        'capitalizable' => true, 'metodo_prorrateo' => 'volumen',
    ]);

    $lineas = LiquidarImportacion::run($imp)->lineas;
    $a = $lineas->firstWhere('producto_id', Producto::where('referencia', 'VIEJO-A')->value('id'));
    $b = $lineas->firstWhere('producto_id', Producto::where('referencia', 'VIEJO-B')->value('id'));

    // Sin medidas, los 20 m³ se reparten 25 % / 75 % (su valor) y no 20 + 20.
    expect((float) $a->gasto_prorrateado)->toBe(500000.0)
        ->and((float) $b->gasto_prorrateado)->toBe(1500000.0)
        ->and((float) $a->gasto_prorrateado + (float) $b->gasto_prorrateado)->toBe(2000000.0);
});
