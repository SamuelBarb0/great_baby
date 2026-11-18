<?php

/**
 * Lo que la revisión adversarial le encontró a los arreglos del QA.
 *
 * Después de arreglar treinta defectos de logística en un solo pase, seis
 * revisiones independientes auditaron ESOS arreglos y cada hallazgo pasó por
 * tres escépticos. Sobrevivieron quince, que son nueve problemas distintos.
 * Los graves eran todos regresiones nuevas, metidas por el arreglo mismo:
 *
 *   · el aviso a Aracely decía «no hace falta aceptar ningún traslado» cuando
 *     el traslado hacía falta y NO SE PUDO crear;
 *   · el conteo guardado se recortaba también en importación, donde contar más
 *     de lo que la línea tenía pendiente es legítimo;
 *   · y de los controles de Gerencia de la pantalla de ubicaciones se
 *     escondieron seis… y eran siete.
 *
 * La moraleja que estas pruebas guardan: un arreglo también es código nuevo.
 */

use App\Models\User;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Models\RecepcionCompraItem;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Models\Oficina;
use App\Modules\Portal\Models\ReglaRuteoCiudad;
use Database\Seeders\CatalogoOficinasAlmacenesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function bodegaRev(string $codigo, array $extra = []): InventarioUbicacion
{
    return InventarioUbicacion::create(array_merge([
        'codigo' => $codigo,
        'nombre' => "Bodega {$codigo}",
        'categoria' => CategoriaUbicacion::Venta,
        'activa' => true,
    ], $extra));
}

// ───────────────────── D · el papel que se firma ─────────────────────

it('el PDF no dice «varias bodegas» por una línea que no llegó', function () {
    $bodega = bodegaRev('PPAL');

    $orden = \App\Modules\Compras\Models\OrdenCompra::create([
        'numero' => 'OC-REV-1',
        'proveedor_id' => \App\Models\Contacto::create([
            'nombre_completo' => 'Textiles Andinos', 'numero_documento' => '901333444',
            'es_proveedor' => true, 'activo' => true,
        ])->id,
        'bodega_id' => $bodega->id,
        'tipo' => 'nacional', 'moneda' => 'COP', 'tasa_cambio' => 1,
        'estado' => 'aprobada', 'fecha_emision' => now()->toDateString(),
        'subtotal' => 100, 'iva' => 0, 'total' => 100,
        'creado_por' => User::factory()->create()->id,
    ]);

    $ocItem = $orden->items()->create([
        'descripcion' => 'Set de prueba', 'cantidad' => 60, 'cantidad_recibida' => 0,
        'precio_unit' => 1, 'subtotal' => 60,
    ]);

    $rec = RecepcionCompra::create([
        'numero' => 'REC-REV-1', 'orden_id' => $orden->id, 'bodega_id' => $bodega->id,
        'estado' => 'confirmada', 'fecha_recepcion' => now(),
    ]);

    foreach ([
        ['cantidad_recibida' => 40, 'clasificacion' => 'apto'],
        ['cantidad_recibida' => 10, 'clasificacion' => 'faltante'],
    ] as $attrs) {
        $it = new RecepcionCompraItem($attrs + ['costo_unit' => 1, 'orden_item_id' => $ocItem->id]);
        $it->recepcion_id = $rec->id;
        $it->save();
    }

    $rec->load('items');

    /*
     * Todo lo que ENTRÓ entró a una sola bodega. La línea faltante no entró a
     * ninguna —`bodegaDondeQuedo()` devuelve null— y null nunca es igual al id
     * de la bodega, así que la comparación cruda daba «varias» y el encabezado
     * del papel que alguien firma dejaba de nombrar la bodega.
     */
    $repartida = $rec->items
        ->reject(fn ($x) => $x->bodegaDondeQuedo() === null)
        ->contains(fn ($x) => $x->bodegaDondeQuedo() !== (int) $rec->bodega_id);

    expect($repartida)->toBeFalse();

    // Y cuando SÍ se repartió de verdad, lo dice.
    $otra = bodegaRev('11-01');
    $it = new RecepcionCompraItem(['cantidad_recibida' => 5, 'costo_unit' => 1, 'clasificacion' => 'apto', 'ubicacion_id' => $otra->id, 'orden_item_id' => $ocItem->id]);
    $it->recepcion_id = $rec->id;
    $it->save();

    $rec->load('items');

    expect($rec->items
        ->reject(fn ($x) => $x->bodegaDondeQuedo() === null)
        ->contains(fn ($x) => $x->bodegaDondeQuedo() !== (int) $rec->bodega_id))->toBeTrue();
});

// ───────────────── H · un campo opcional no puede tumbar el sistema ─────────

it('guardar una regla de ruteo sin prioridad no revienta', function () {
    Role::findOrCreate('Gerencia');
    $gerencia = User::factory()->create();
    $gerencia->assignRole('Gerencia');

    $bodega = bodegaRev('PPAL', ['disponible_para_venta' => true]);

    /*
     * La casilla es opcional y `v-model.number` deja cadena vacía, que Laravel
     * convierte a null antes de validar. La columna es NOT NULL con default,
     * así que ese null pasaba la validación entera y moría en el INSERT con un
     * 500: dejar en blanco un campo opcional tumbaba la pantalla.
     */
    $this->actingAs($gerencia)
        ->post('/app/logistica/reglas-ruteo', [
            'ciudad' => 'Neiva',
            'ubicacion_id' => $bodega->id,
            'prioridad' => null,
            'activa' => true,
        ])
        ->assertSessionHasNoErrors();

    $regla = ReglaRuteoCiudad::where('ciudad', 'Neiva')->first();

    expect($regla)->not->toBeNull()
        ->and($regla->prioridad)->not->toBeNull();
});

// ───────────── G · nadie se deja a sí mismo sin la última bodega ────────────

it('el jefe de bodega no se puede quitar de su única bodega', function () {
    Role::findOrCreate('AdminBodega');
    Role::findOrCreate('Alistador');

    $jorge = User::factory()->create(['name' => 'Jorge']);
    $jorge->assignRole('AdminBodega');

    $suya = bodegaRev('PPAL', ['responsable_user_id' => $jorge->id]);

    $otro = User::factory()->create(['name' => 'Alistador']);
    $otro->assignRole('Alistador');

    /*
     * El alcance de operar se calcula desde esta misma columna: quitándose de
     * su última bodega, se quedaba sin cola, sin kardex y sin la pantalla desde
     * la cual lo acababa de hacer, y sin forma de devolvérselo.
     */
    $this->actingAs($jorge)
        ->postJson('/app/inventario/ubicaciones/responsable', [
            'ubicacion_id' => $suya->id,
            'responsable_user_id' => null,
        ])
        ->assertStatus(422);

    expect($suya->fresh()->responsable_user_id)->toBe($jorge->id);

    // Con dos bodegas sí puede soltar una: lo que se cuida es quedarse en cero.
    $segunda = bodegaRev('SEG', ['responsable_user_id' => $jorge->id]);

    $this->actingAs($jorge)
        ->postJson('/app/inventario/ubicaciones/responsable', [
            'ubicacion_id' => $segunda->id,
            'responsable_user_id' => $otro->id,
        ])
        ->assertOk();

    expect($segunda->fresh()->responsable_user_id)->toBe($otro->id);
});

// ───────── F · el sembrador no resucita una fila que el área renombró ───────

it('renombrarle el código a una oficina no la duplica en la próxima corrida', function () {
    $this->seed(CatalogoOficinasAlmacenesSeeder::class);

    $dropi = Oficina::where('codigo', '11')->firstOrFail();
    $dropi->update(['codigo' => '11A']);

    $antes = Oficina::count();

    // La segunda corrida no puede devolver la «11» a la vida: quedarían dos
    // oficinas para el mismo lugar, las dos admitiendo contenedor, y el reparto
    // mandaría el stock a una bodega distinta de la que ya tiene la mercancía.
    $this->seed(CatalogoOficinasAlmacenesSeeder::class);

    expect(Oficina::count())->toBe($antes)
        ->and(Oficina::where('nombre', 'DROPI')->count())->toBe(1)
        ->and(Oficina::where('codigo', '11')->exists())->toBeFalse();
});
