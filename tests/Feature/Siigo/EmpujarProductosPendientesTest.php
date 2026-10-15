<?php

/**
 * La red de seguridad también tiene que recoger productos.
 *
 * `siigo:empujar-pendientes` barría a diario los documentos que quedaron sin
 * subir, pero **no los productos** — y los productos eran 1029 de los 1115
 * trabajos caídos el 2026-10-09. Un producto que no logró subir se queda sin
 * `siigo_id` para siempre: el observer dispara al guardar, y nadie vuelve a
 * guardarlo.
 *
 * Y arrastra al resto. Los asientos de inventario fallaban literalmente con
 * «el producto X del movimiento N todavía no está en SIIGO, sincronizalo
 * primero»: sin producto no hay asiento, ni factura, ni devolución.
 */

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Siigo\Jobs\PushProductoASiigo;
use App\Modules\Siigo\Models\SiigoConfig;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    SiigoConfig::current()->forceFill(['username' => 'sandbox@siigoapi.com', 'access_key' => 'llave-de-prueba', 'push_auto' => true, 'activo' => true])->save();
    SiigoConfig::invalidarPushAutoCache();
});

/**
 * Arranca la medicion en limpio.
 *
 * El Observer de productos encola un push al crear cada uno, asi que el
 * `Queue::fake()` del beforeEach llega al `artisan()` con esos jobs del
 * montaje ya anotados. Re-fakear justo antes deja contar solo lo que encola
 * el comando.
 */
function desdeCero(): void
{
    Queue::fake();
}

/**
 * `siigo_id` no es asignable en masa ni en el producto ni en la variante: lo
 * escribe solo el codigo de sincronizacion, con `forceFill`. Pasarlo por
 * `create()` lo descarta en silencio y el producto queda como pendiente, que
 * es justo lo contrario de lo que quiere medir la prueba.
 */
function nuevoProducto(array $attrs = []): Producto
{
    $siigoId = $attrs['siigo_id'] ?? null;
    unset($attrs['siigo_id']);

    $p = Producto::sinKardexAutomatico(fn () => Producto::create(array_merge([
        'referencia' => 'REF-'.fake()->unique()->numerify('#####'),
        'nombre' => 'Producto de prueba',
        'desglose_stock' => false,
        'activo' => true,
    ], $attrs)));

    if ($siigoId !== null) {
        $p->forceFill(['siigo_id' => $siigoId])->save();
    }

    return $p->refresh();
}

/** La variante no tiene `sku`: su codigo es `codigo_barras`. */
function nuevaVariante(Producto $p, string $color, ?string $siigoId): ProductoVariante
{
    $v = ProductoVariante::create([
        'producto_id' => $p->id,
        'color_codigo' => $color,
        'color_nombre' => $color,
    ]);

    if ($siigoId !== null) {
        $v->forceFill(['siigo_id' => $siigoId])->save();
    }

    return $v->refresh();
}

it('encola el producto activo que no está en SIIGO', function () {
    $p = nuevoProducto(['siigo_id' => null]);

    desdeCero();

    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'productos'])->assertSuccessful();

    Queue::assertPushed(PushProductoASiigo::class,
        fn ($job) => $job->productoId === $p->id && $job->accion === 'crear');
});

it('no vuelve a encolar uno que ya tiene su id allá', function () {
    nuevoProducto(['siigo_id' => 'abc-123']);

    desdeCero();

    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'productos'])->assertSuccessful();

    Queue::assertNotPushed(PushProductoASiigo::class);
});

it('deja en paz los productos inactivos', function () {
    nuevoProducto(['siigo_id' => null, 'activo' => false]);

    desdeCero();

    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'productos'])->assertSuccessful();

    Queue::assertNotPushed(PushProductoASiigo::class);
});

it('recoge el granular al que le falta una variante en SIIGO', function () {
    // Cada variante viaja a SIIGO como producto propio, así que el padre con
    // id no basta: si una variante se quedó afuera, no se puede facturar.
    $p = nuevoProducto(['siigo_id' => 'padre-ok', 'desglose_stock' => true]);
    nuevaVariante($p, 'ROJ', 'var-ok');
    nuevaVariante($p, 'AZU', null);

    desdeCero();

    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'productos'])->assertSuccessful();

    Queue::assertPushed(PushProductoASiigo::class, fn ($job) => $job->productoId === $p->id);
});

it('el dry-run cuenta y no encola nada', function () {
    nuevoProducto(['siigo_id' => null]);

    desdeCero();

    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'productos', '--dry-run' => true])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('productos es un tipo válido y no un error de opción', function () {
    // Vive fuera de la tabla FUENTES —su job lleva dos argumentos—, y la
    // validación de `--tipo` miraba sólo esa tabla.
    desdeCero();

    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'productos', '--dry-run' => true])
        ->assertExitCode(0);

    desdeCero();

    $this->artisan('siigo:empujar-pendientes', ['--tipo' => 'inventado', '--dry-run' => true])
        ->assertExitCode(1);
});

it('la pasada completa incluye productos sin pedirlo', function () {
    $p = nuevoProducto(['siigo_id' => null]);

    desdeCero();

    $this->artisan('siigo:empujar-pendientes')->assertSuccessful();

    Queue::assertPushed(PushProductoASiigo::class, fn ($job) => $job->productoId === $p->id);
});

it('no encola nada si SIIGO está rechazando la credencial', function () {
    // Encolarlos contra una llave muerta sólo los deja rebotando cada 5
    // minutos hasta que se agota el plazo de 12 horas. El comando corre solo
    // a diario, así que los recoge la pasada siguiente a que la arreglen.
    nuevoProducto(['siigo_id' => null]);
    SiigoConfig::current()->forceFill([
        'ultimo_auth_ok' => false,
        'ultimo_auth_error' => 'HTTP 401 · Incorrect username or password',
    ])->save();

    desdeCero();

    $this->artisan('siigo:empujar-pendientes')->assertExitCode(1);

    Queue::assertNothingPushed();
});

it('el dry-run sigue informando aunque la credencial esté caída', function () {
    // Es el modo con el que uno se entera de cuánto hay pendiente; frenarlo
    // dejaría a oscuras justo cuando más falta ver.
    nuevoProducto(['siigo_id' => null]);
    SiigoConfig::current()->forceFill([
        'ultimo_auth_ok' => false,
        'ultimo_auth_error' => 'HTTP 401 · Incorrect username or password',
    ])->save();

    desdeCero();

    $this->artisan('siigo:empujar-pendientes', ['--dry-run' => true])->assertExitCode(0);
});
