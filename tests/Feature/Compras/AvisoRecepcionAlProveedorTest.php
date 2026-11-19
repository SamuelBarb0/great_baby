<?php

/**
 * El aviso de recepción al proveedor no puede reventar.
 *
 * Encontrado el 2026-10-14 recibiendo una orden de compra real: la recepción
 * quedaba confirmada y el stock entraba bien, pero el job que le avisa al
 * proveedor moría con
 *
 *   RelationNotFoundException · Call to undefined relationship [ordenCompra]
 *   on model [RecepcionCompra]
 *
 * La relación se llama `orden`. Con el nombre equivocado el aviso NO salía
 * nunca —en ninguna recepción, desde siempre— y el trabajo caía en
 * `failed_jobs`, que es donde nadie mira. La operación «terminaba bien», así
 * que nada lo delataba.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Notificaciones\Services\NotificadorCadenaValor;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();

    $this->bodega = InventarioUbicacion::create([
        'codigo' => 'BOD-1', 'nombre' => 'Bodega principal',
        'categoria' => CategoriaUbicacion::Venta, 'activa' => true,
    ]);

    $this->proveedor = Contacto::create([
        'numero_documento' => '901000111', 'nombre_completo' => 'Textiles Andinos SAS',
        'es_proveedor' => true, 'telefono' => '3001234567',
    ]);

    $this->orden = OrdenCompra::create([
        'numero' => 'OC-TEST-0001',
        'proveedor_id' => $this->proveedor->id,
        'bodega_id' => $this->bodega->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'tasa_cambio' => 1,
        'estado' => 'aprobada',
        'fecha_emision' => now()->toDateString(),
        'subtotal' => 240000, 'iva' => 0, 'total' => 240000,
        'creado_por' => User::factory()->create()->id,
    ]);

    $this->recepcion = RecepcionCompra::create([
        'numero' => 'REC-TEST-0001',
        'orden_id' => $this->orden->id,
        'bodega_id' => $this->bodega->id,
        'fecha_recepcion' => now()->toDateString(),
        'estado' => 'confirmada',
        'total_recibido' => 240000,
    ]);
});

it('la relación de la recepción con su orden se llama «orden»', function () {
    // Si alguien la renombra, el notificador vuelve a romperse en silencio.
    expect($this->recepcion->orden)->not->toBeNull()
        ->and($this->recepcion->orden->numero)->toBe('OC-TEST-0001');
});

it('avisar la recepción no lanza RelationNotFoundException', function () {
    // Es el fallo exacto que mataba el job en cada recepción.
    app(NotificadorCadenaValor::class)->recepcionConfirmada($this->recepcion);
})->throwsNoExceptions();

it('llega hasta el proveedor de la orden', function () {
    // Sin resolver la cadena recepción → orden → proveedor no hay a quién
    // avisarle, y el método se iría en silencio por el `return` del teléfono.
    $this->recepcion->loadMissing('orden.proveedor');

    expect($this->recepcion->orden->proveedor->telefono)->toBe('3001234567')
        ->and($this->recepcion->orden->proveedor->nombre_completo)->toBe('Textiles Andinos SAS');
});

it('un proveedor sin teléfono no revienta, sólo no avisa', function () {
    // `compras_recepciones.orden_id` es NOT NULL, así que una recepción
    // huérfana no puede existir. El caso real de salida temprana es el
    // proveedor sin teléfono.
    $this->proveedor->forceFill(['telefono' => null])->save();

    app(NotificadorCadenaValor::class)->recepcionConfirmada($this->recepcion->fresh());
})->throwsNoExceptions();
