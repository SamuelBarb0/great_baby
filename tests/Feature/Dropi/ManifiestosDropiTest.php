<?php

/**
 * Los manifiestos de Dropi en el ERP (reunión con la señora Lahida, 24-nov-2026):
 * cada entrega a una transportadora con su PDF y Excel guardados, buscable por
 * guía, con «Revisado» como checklist. Formato capturado en vivo ese día.
 */

use App\Models\User;
use App\Modules\Dropi\Actions\SincronizarManifiestosDropi;
use App\Modules\Dropi\Models\DropiManifiesto;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Services\DropiWebAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('local');
    config()->set('dropi.web', [
        'email' => 'c@e.co', 'password' => 'x', 'white_brand_id' => 1, 'timeout' => 5,
        'bff_url' => 'https://bff.test', 'legacy_url' => 'https://legacy.test',
    ]);
    app()->forgetInstance(DropiWebAuth::class);
    app()->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));
    DropiWebSesion::create(['email' => 'c@e.co', 'estado' => DropiWebSesion::CONECTADO, 'token' => 'tok', 'expira_at' => now()->addHour()]);
});

function mdSalida(int $id, string $transportadora, string $creado, array $guias): array
{
    return ['id' => $id, 'type_management_id' => 9, 'distribution_company_id' => 5, 'warehouse_id' => 10548, 'user_id' => 151057,
        'created_at' => $creado, 'user' => ['id' => 151057, 'name' => 'Janderson', 'surname' => 'Aristizabal'],
        'distribution_company' => ['id' => 5, 'name' => $transportadora],
        'history_logistic_management' => array_map(fn ($g, $k) => ['order_id' => 97900000 + $k,
            'history_orders' => ['status' => 'PREPARADO PARA TRANSPORTADORA', 'shipping_guide' => $g]], $guias, array_keys($guias))];
}

function mdDropi(array $salidas, bool $pdfFalla = false): void
{
    Http::fake(function (Request $r) use ($salidas, $pdfFalla) {
        if (str_contains($r->url(), '/logisticManagement/index')) {
            return Http::response(['isSuccess' => true, 'status' => 200, 'objects' => array_slice($salidas, (int) $r['startData'], (int) $r['pageSize']), 'count' => count($salidas)]);
        }
        if (str_contains($r->url(), '/manifiest/pdf/')) {
            return $pdfFalla ? Http::response('error', 500) : Http::response('%PDF-1.4 manifiesto', 200, ['Content-Type' => 'application/pdf']);
        }
        if (str_contains($r->url(), '/manifiest/excel/')) {
            return Http::response("PK\x03\x04 excel", 200);
        }

        return Http::response(['isSuccess' => false, 'message' => 'ruta no simulada ' . $r->url()], 404);
    });
}

it('trae las salidas de Dropi con su PDF y Excel, y no las duplica', function () {
    mdDropi([
        mdSalida(4265539, 'COORDINADORA', now('America/Bogota')->format('Y-m-d') . ' 17:48:15', ['36397332953', '36397332954']),
        mdSalida(4264827, 'ENVIA', now('America/Bogota')->format('Y-m-d') . ' 17:15:51', ['064109448636']),
    ]);

    $r = SincronizarManifiestosDropi::make()->handle(2);
    SincronizarManifiestosDropi::make()->handle(2);

    $m = DropiManifiesto::where('dropi_id', 4265539)->firstOrFail();
    expect($r)->toMatchArray(['vistos' => 2, 'nuevos' => 2, 'archivos' => 4])
        ->and(DropiManifiesto::count())->toBe(2)
        ->and($m->transportadora)->toBe('COORDINADORA')
        ->and($m->pedidos)->toBe(2)
        ->and($m->dropi_creado_at->timezone('America/Bogota')->format('H:i'))->toBe('17:48')
        ->and(Storage::disk('local')->get($m->pdf_path))->toStartWith('%PDF')
        ->and(Storage::disk('local')->get($m->excel_path))->toStartWith("PK\x03\x04");
    // La segunda vuelta no vuelve a descargar: 2 manifiestos × (PDF + Excel).
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/manifiest/'))->count())->toBe(4);
});

it('busca en qué manifiesto salió un paquete por su guía', function () {
    mdDropi([mdSalida(4265539, 'COORDINADORA', now('America/Bogota')->format('Y-m-d') . ' 17:48:15', ['36397332953'])]);
    SincronizarManifiestosDropi::make()->handle(2);

    expect(DropiManifiesto::query()->conGuia('36397332953')->value('dropi_id'))->toBe(4265539)
        ->and(DropiManifiesto::query()->conGuia('000')->exists())->toBeFalse();
});

it('si Dropi no entrega un archivo queda anotado y se reintenta en la siguiente vuelta', function () {
    $s = [mdSalida(4265539, 'COORDINADORA', now('America/Bogota')->format('Y-m-d') . ' 17:48:15', ['36397332953'])];
    mdDropi($s, pdfFalla: true);
    SincronizarManifiestosDropi::make()->handle(2);

    $m = DropiManifiesto::firstOrFail();
    expect($m->pdf_path)->toBeNull()->and($m->excel_path)->not->toBeNull()->and($m->error_archivos)->toContain('pdf');

    Http::swap(new \Illuminate\Http\Client\Factory);
    mdDropi($s);
    SincronizarManifiestosDropi::make()->handle(2);
    expect($m->fresh()->pdf_path)->not->toBeNull()->and($m->fresh()->error_archivos)->toBeNull();
});

it('lo más viejo que los días pedidos no se trae', function () {
    mdDropi([
        mdSalida(2, 'TCC', now('America/Bogota')->format('Y-m-d') . ' 10:00:00', ['1']),
        mdSalida(1, 'TCC', now('America/Bogota')->subDays(10)->format('Y-m-d') . ' 10:00:00', ['2']),
    ]);

    SincronizarManifiestosDropi::make()->handle(2, archivos: false);

    expect(DropiManifiesto::pluck('dropi_id')->all())->toBe([2]);
});

it('los archivos sólo los abre quien ve «Manifiestos»', function () {
    $this->seed(\Database\Seeders\PermisosSeeder::class);
    mdDropi([mdSalida(4265539, 'COORDINADORA', now('America/Bogota')->format('Y-m-d') . ' 17:48:15', ['1'])]);
    SincronizarManifiestosDropi::make()->handle(2);
    $m = DropiManifiesto::firstOrFail();

    $this->actingAs(User::factory()->create())->get(route('dropi.manifiestos.archivo', [$m, 'pdf']))->assertForbidden();

    $aracely = User::factory()->create();
    $aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->actingAs($aracely)->get(route('dropi.manifiestos.archivo', [$m, 'pdf']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
