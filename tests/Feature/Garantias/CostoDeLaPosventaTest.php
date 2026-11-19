<?php

/**
 * Una garantía le cuesta a la empresa, aunque el cliente no pague nada.
 *
 * El ticket guardaba `valor_reposicion`, y el flujo lo pone en CERO con el
 * comentario «contrato: reposición a valor $0». Eso es correcto —en una
 * garantía el cliente no paga— pero es lo que cobra la empresa, no lo que le
 * cuesta. El módulo medía tiempos (el SLA del concepto) y no medía plata, así
 * que la pregunta «¿cuál referencia nos está costando la posventa?» no tenía
 * respuesta en ninguna pantalla.
 *
 * Lo que se protege acá:
 *   · que el costo se guarde desglosado y no como un total, porque lo que
 *     importa no es cuánto se gastó sino EN QUÉ: si el grueso es flete, el
 *     problema es la logística inversa, no el producto;
 *   · que cargar el costo sea independiente de cerrar el ticket, porque el
 *     flete llega facturado semanas después del cierre;
 *   · que la fecha de carga no se mueva al corregir, para que un informe
 *     mensual no cambie solo cuando alguien edita un ticket viejo;
 *   · que las garantías sin costo cargado se cuenten aparte, porque una
 *     garantía sin costo no es gratis: es un costo que nadie midió.
 */

use App\Models\Contacto;
use App\Models\User;
use App\Modules\Dropi\Models\GarantiaTicket;
use App\Modules\Dropi\Models\Producto;
use App\Support\Reglas;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    Reglas::limpiarRequestCache();
    $this->seed(\Database\Seeders\PermisosSeeder::class);

    $rol = Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']);
    $this->usuario = User::factory()->create();
    $this->usuario->assignRole($rol);

    $this->cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900222111',
        'nombre_completo' => 'Pañalera del Este', 'es_cliente' => true,
    ]);

    $this->coche = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'POS-COCHE', 'nombre' => 'Coche de paseo',
        'desglose_stock' => true, 'activo' => true, 'precio_proveedor' => 180000,
    ]));

    $this->chupo = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'POS-CHUPO', 'nombre' => 'Chupo anatómico',
        'desglose_stock' => true, 'activo' => true, 'precio_proveedor' => 4000,
    ]));

    /*
     * `garantia_tickets.variante_id` es NOT NULL, asi que todo ticket necesita
     * una variante. Eso deja fuera a los productos AGREGADOS —colores
     * surtidos—, que no tienen variantes: hoy no se les puede abrir garantia.
     * Queda anotado como hallazgo aparte; estos tests usan granulares.
     */
    $this->varianteCoche = \App\Modules\Dropi\Models\ProductoVariante::create([
        'producto_id' => $this->coche->id, 'color_nombre' => 'Gris',
        'talla' => 'U', 'combo_key' => 'pos-coche-gris',
    ]);

    $this->varianteChupo = \App\Modules\Dropi\Models\ProductoVariante::create([
        'producto_id' => $this->chupo->id, 'color_nombre' => 'Rosa',
        'talla' => 'U', 'combo_key' => 'pos-chupo-rosa',
    ]);
});

function ticketDe($producto, string $estado = 'cerrada', int $cantidad = 1): GarantiaTicket
{
    static $n = 0;
    $n++;

    $variante = \App\Modules\Dropi\Models\ProductoVariante::where('producto_id', $producto->id)->firstOrFail();

    return GarantiaTicket::create([
        'numero' => 'GAR-POS-'.$n,
        'producto_id' => $producto->id,
        'variante_id' => $variante->id,
        'contacto_id' => test()->cliente->id,
        'cantidad' => $cantidad,
        'cliente_nombre' => 'Pañalera del Este',
        'descripcion_falla' => 'Se partió la rueda al segundo uso.',
        'estado' => $estado,
        'creado_por' => test()->usuario->id,
        // El alta real lo calcula desde la regla de plazo; la columna es NOT NULL.
        'plazo_concepto_at' => now()->addDays((int) setting('garantias.dias_concepto')),
    ]);
}

it('el costo se guarda desglosado, no como un total', function () {
    $t = ticketDe($this->coche);

    $this->actingAs($this->usuario)
        ->post("/app/garantias/{$t->id}/costo", [
            'costo_producto' => 180000,
            'costo_flete_recoger' => 12000,
            'costo_flete_enviar' => 14000,
        ])
        ->assertRedirect();

    $t->refresh();

    expect($t->costoTotal())->toBe(206000.0)
        ->and($t->desgloseCosto()['flete_recoger'])->toBe(12000.0)
        // Lo que paga el cliente sigue siendo cero: son dos cosas distintas.
        ->and((float) $t->valor_reposicion)->toBe(0.0);
});

it('el costo se puede cargar después de cerrar el ticket', function () {
    // El flete llega facturado semanas después del cierre. Exigirlo para
    // cerrar sólo lograría que cierren con ceros.
    $t = ticketDe($this->coche, 'cerrada');

    $this->actingAs($this->usuario)
        ->post("/app/garantias/{$t->id}/costo", ['costo_flete_enviar' => 14000])
        ->assertRedirect();

    expect($t->fresh()->costoTotal())->toBe(14000.0);
});

it('la fecha de carga no se mueve al corregir', function () {
    $t = ticketDe($this->coche);

    $this->actingAs($this->usuario)
        ->post("/app/garantias/{$t->id}/costo", ['costo_flete_enviar' => 14000]);

    $primera = $t->fresh()->costo_cargado_at;

    $this->travel(3)->days();

    $this->actingAs($this->usuario)
        ->post("/app/garantias/{$t->id}/costo", ['costo_flete_enviar' => 14000, 'costo_otros' => 5000]);

    // Si se reescribiera, un informe mensual cambiaría solo al editar un
    // ticket viejo.
    expect($t->fresh()->costo_cargado_at->toDateTimeString())->toBe($primera->toDateTimeString())
        ->and($t->fresh()->costoTotal())->toBe(19000.0);
});

it('una garantía sin costo cargado se cuenta aparte', function () {
    ticketDe($this->coche, 'cerrada');
    ticketDe($this->coche, 'en_reposicion');
    // Una abierta todavía no debería tener costo: no se cuenta como faltante.
    ticketDe($this->coche, 'abierto');

    expect(GarantiaTicket::sinCosto()->count())->toBe(2);
});

it('deja de contarse como faltante cuando se le carga el costo', function () {
    $t = ticketDe($this->coche, 'cerrada');

    expect(GarantiaTicket::sinCosto()->count())->toBe(1);

    $this->actingAs($this->usuario)
        ->post("/app/garantias/{$t->id}/costo", ['costo_flete_enviar' => 14000]);

    expect(GarantiaTicket::sinCosto()->count())->toBe(0);
});

it('el índice dice cuánto costó la posventa y en qué se fue', function () {
    $a = ticketDe($this->coche);
    $b = ticketDe($this->chupo);

    $this->actingAs($this->usuario)->post("/app/garantias/{$a->id}/costo", [
        'costo_producto' => 180000, 'costo_flete_recoger' => 12000, 'costo_flete_enviar' => 14000,
    ]);
    $this->actingAs($this->usuario)->post("/app/garantias/{$b->id}/costo", [
        'costo_producto' => 4000, 'costo_flete_enviar' => 14000,
    ]);

    $costos = $this->actingAs($this->usuario)
        ->get('/app/garantias')
        ->viewData('page')['props']['costos'];

    expect($costos['tickets'])->toBe(2)
        ->and($costos['total'])->toBe(224000.0)
        ->and($costos['promedio'])->toBe(112000.0)
        ->and($costos['desglose']['producto'])->toBe(184000.0)
        ->and($costos['desglose']['flete_enviar'])->toBe(28000.0);
});

it('dice cuál referencia está costando la posventa', function () {
    $a = ticketDe($this->coche);
    $b = ticketDe($this->chupo);
    $c = ticketDe($this->chupo);

    $this->actingAs($this->usuario)->post("/app/garantias/{$a->id}/costo", ['costo_producto' => 180000]);
    $this->actingAs($this->usuario)->post("/app/garantias/{$b->id}/costo", ['costo_producto' => 4000]);
    $this->actingAs($this->usuario)->post("/app/garantias/{$c->id}/costo", ['costo_producto' => 4000]);

    $porProducto = $this->actingAs($this->usuario)
        ->get('/app/garantias')
        ->viewData('page')['props']['costos']['por_producto'];

    // El coche cuesta más aunque el chupo falle más veces: una garantía suelta
    // es mala suerte, pero lo que duele es la plata.
    expect($porProducto[0]['referencia'])->toBe('POS-COCHE')
        ->and($porProducto[0]['costo'])->toBe(180000.0)
        ->and($porProducto[1]['referencia'])->toBe('POS-CHUPO')
        ->and($porProducto[1]['tickets'])->toBe(2);
});

it('un costo de otro mes no entra en el total del mes', function () {
    $viejo = ticketDe($this->coche);
    $this->travelTo(now()->subMonths(2));
    $this->actingAs($this->usuario)->post("/app/garantias/{$viejo->id}/costo", ['costo_producto' => 999000]);
    $this->travelBack();

    $nuevo = ticketDe($this->chupo);
    $this->actingAs($this->usuario)->post("/app/garantias/{$nuevo->id}/costo", ['costo_producto' => 4000]);

    $costos = $this->actingAs($this->usuario)
        ->get('/app/garantias')
        ->viewData('page')['props']['costos'];

    expect($costos['total'])->toBe(4000.0)
        ->and($costos['tickets'])->toBe(1);
});

it('sugiere el costo del producto para no arrancar en ceros', function () {
    $t = ticketDe($this->coche, 'en_reposicion', 2);

    $ticket = $this->actingAs($this->usuario)
        ->get("/app/garantias/{$t->id}")
        ->viewData('page')['props']['ticket'];

    // Dos unidades a 180.000 de costo de proveedor.
    expect($ticket['costo_sugerido'])->toBe(360000.0)
        ->and($ticket['costo']['total'])->toBe(0.0);
});

it('el costo no se carga sin permiso de edición', function () {
    $t = ticketDe($this->coche);

    $mirón = User::factory()->create();
    $rol = Role::firstOrCreate(['name' => 'SoloVer', 'guard_name' => 'web']);
    $rol->givePermissionTo('ver.servicio_cliente');
    $mirón->assignRole($rol);

    $this->actingAs($mirón)
        ->post("/app/garantias/{$t->id}/costo", ['costo_producto' => 1])
        ->assertForbidden();

    expect($t->fresh()->costoTotal())->toBe(0.0);
});

it('el ticket que sólo trae variante también entra en el ranking', function () {
    /*
     * Son todos los tickets anteriores a que existiera el producto agregado:
     * traen `variante_id` y `producto_id` en nulo. El total los contaba y el
     * ranking no, así que la pantalla llegaba a decir que no había costos
     * justo debajo del total.
     */
    $t = ticketDe($this->coche);
    $t->update(['producto_id' => null]);     // como quedaron los viejos

    $this->actingAs($this->usuario)->post("/app/garantias/{$t->id}/costo", [
        'costo_producto' => 90000,
    ]);

    $r = $this->actingAs($this->usuario)->get('/app/garantias')
        ->viewData('page')['props']['costos'];

    expect($r['total'])->toBe(90000.0)
        ->and($r['por_producto'])->toHaveCount(1)
        ->and($r['por_producto'][0]['referencia'])->toBe($this->coche->referencia)
        ->and($r['por_producto'][0]['costo'])->toBe(90000.0);
});
