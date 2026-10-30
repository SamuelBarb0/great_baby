<?php

/**
 * El comentario del vendedor le llega a Contabilidad.
 *
 * El vendedor en la calle pacta cosas que el precio de lista no registra —un
 * descuento que prometió, un plazo, una condición de entrega— y las escribe
 * en el comentario del pedido. El campo existía desde siempre y el texto se
 * guardaba, pero ahí moría: Contabilidad se enteraba al facturar, si alguien
 * se acordaba de abrir el pedido, o no se enteraba.
 *
 * Por el mismo camino entran los pedidos que el cliente arma solo en el
 * Portal, y ahí las notas las escribe el cliente —«llamen antes de entregar»—
 * que no es lo que hay que revisar. Por eso el aviso sale sólo cuando el
 * pedido lo levantó un vendedor.
 *
 * Avisar a «Contabilidad» es avisarle a quien tenga ese acceso, no a un rol
 * escrito en código: si mañana Aracely crea un rol nuevo y le marca la
 * casilla, le llega igual.
 */

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Portal\Actions\CrearPedidoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    // Se siembran los permisos y el rol recibe los suyos desde la matriz, para
    // ejercitar el camino de producción y no el respaldo por rol.
    $this->seed(Database\Seeders\PermisosSeeder::class);

    $contador = Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']);
    $contador->syncPermissions(collect(Permisos::MATRIZ)
        ->filter(fn ($roles) => in_array('Contador', $roles, true))
        ->keys()->map(fn ($s) => Permisos::permiso($s))->all());

    $this->silvia = User::factory()->create(['name' => 'Silvia Contadora']);
    $this->silvia->assignRole($contador);

    // Un vendedor, que no tiene por qué ver contabilidad.
    $this->vendedora = User::factory()->create(['name' => 'Marcela Vendedora']);

    $this->lista = ListaPrecios::create([
        'codigo' => 'MAY', 'nombre' => 'Mayorista', 'canal' => 'b2b', 'activa' => true,
    ]);

    $this->cliente = Contacto::create([
        'numero_documento' => '901555222',
        'nombre_completo' => 'Pañalera del Centro SAS',
        'es_cliente_b2b' => true,
        'lista_precios_id' => $this->lista->id,
        'ciudad' => 'Bucaramanga',
    ]);

    $this->articulo = Producto::sinKardexAutomatico(fn () => Producto::create([
        'referencia' => 'AGR-COMENT',
        'nombre' => 'Set de bloques',
        'desglose_stock' => false,
        'stock_directo' => 80,
        'precio_proveedor' => 12000,
    ]));

    PrecioProducto::create([
        'producto_id' => $this->articulo->id,
        'lista_id' => $this->lista->id,
        'precio' => 30000,
        'vigente_desde' => now()->subDay()->toDateString(),
    ]);
});

/** Nombre propio: en Pest las funciones del archivo son globales. */
function levantarPedidoCon(object $caso, ?string $comentario, ?int $vendedorId)
{
    return app(CrearPedidoCliente::class)->ejecutar(
        cliente: $caso->cliente,
        items: [['producto_id' => $caso->articulo->id, 'cantidad' => 3]],
        notas: $comentario,
        vendedorId: $vendedorId,
    );
}

function avisosDeComentario(): \Illuminate\Database\Eloquent\Collection
{
    return NotificacionErp::where('tipo', 'pedido_con_comentario')->get();
}

it('un pedido del vendedor con comentario le avisa a contabilidad', function () {
    $pedido = levantarPedidoCon($this, 'Le prometí 10% de descuento por volumen.', $this->vendedora->id);

    $avisos = avisosDeComentario();

    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()->user_id)->toBe($this->silvia->id)
        ->and($avisos->first()->titulo)->toContain($pedido->numero)
        ->and($avisos->first()->titulo)->toContain('Marcela Vendedora')
        // El texto del comentario viaja en el aviso: si hay que abrir el
        // pedido para saber de qué se trata, nadie lo abre.
        ->and($avisos->first()->mensaje)->toContain('10% de descuento')
        ->and($avisos->first()->mensaje)->toContain('Pañalera del Centro')
        ->and($avisos->first()->url)->toBe("/app/pedidos-b2b/{$pedido->id}");
});

it('sin comentario no se avisa nada', function () {
    levantarPedidoCon($this, null, $this->vendedora->id);

    // Avisar por cada pedido es no avisar: a la semana nadie mira la campana.
    expect(avisosDeComentario())->toHaveCount(0);
});

it('un comentario en blanco tampoco cuenta como comentario', function () {
    levantarPedidoCon($this, "   \n  ", $this->vendedora->id);

    expect(avisosDeComentario())->toHaveCount(0);
});

it('las notas que escribe el cliente en el portal no avisan', function () {
    // Mismo camino, sin vendedor: lo armó el cliente desde su portal y la nota
    // es suya («llamen antes de entregar»), no algo que revisar.
    levantarPedidoCon($this, 'Entregar después de las 2pm por favor.', null);

    expect(avisosDeComentario())->toHaveCount(0);
});

it('el vendedor no recibe su propio aviso', function () {
    // La vendedora también es contadora: pasa en operación chica, donde una
    // persona hace dos cosas. Su comentario no tiene por qué volverle.
    $this->vendedora->assignRole(Role::findByName('Contador'));

    levantarPedidoCon($this, 'Le di plazo a 60 días.', $this->vendedora->id);

    expect(avisosDeComentario()->pluck('user_id')->all())
        ->toBe([$this->silvia->id]);
});

it('le llega a todo el que tenga el acceso, no a un rol escrito en código', function () {
    // Un rol inventado hoy, con la casilla de contabilidad marcada a mano.
    $rolNuevo = Role::create(['name' => 'AuxiliarDeSilvia', 'guard_name' => 'web']);
    $rolNuevo->givePermissionTo(Permisos::permiso('contabilidad'));

    $auxiliar = User::factory()->create(['name' => 'Auxiliar Nueva']);
    $auxiliar->assignRole($rolNuevo);

    levantarPedidoCon($this, 'Pactado flete incluido.', $this->vendedora->id);

    expect(avisosDeComentario()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->silvia->id, $auxiliar->id])->sort()->values()->all());
});
