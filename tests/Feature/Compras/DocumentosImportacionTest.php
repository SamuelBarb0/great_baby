<?php

/**
 * Documentos del contenedor y comprobantes de gasto (reunión con Alejandro y
 * Liz, 30-oct-2026): cada tipo guarda sus versiones, el gasto lleva su soporte
 * y solo Aracely/Gerencia quitan documentos.
 */

use App\Models\User;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDocumento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake(ImportacionDocumento::DISCO);

    $this->aracely = User::factory()->create(['name' => 'Aracely']);
    $this->aracely->assignRole(Role::firstOrCreate(['name' => 'Aracely', 'guard_name' => 'web']));
    $this->jorge = User::factory()->create(['name' => 'Jorge']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'AdminBodega', 'guard_name' => 'web']));
    $this->vendedor = User::factory()->create();
    $this->vendedor->assignRole(Role::firstOrCreate(['name' => 'Vendedor', 'guard_name' => 'web']));

    $this->imp = Importacion::create([
        'numero' => Importacion::siguienteNumero(), 'contenedor' => 'MSKU7725140',
        'moneda_origen' => 'USD', 'estado' => EstadoImportacion::EnTransito, 'tasa_cambio_liquidacion' => 4000,
    ]);
    $this->subir = fn (User $u, string $tipo, string $nombre) => $this->actingAs($u)
        ->post("/app/compras/importacion/{$this->imp->id}/documento", [
            'tipo' => $tipo, 'archivo' => UploadedFile::fake()->create($nombre, 120, 'application/pdf'),
        ]);
});

it('guarda el documento en el disco privado y una segunda lista de empaque queda como versión 2', function () {
    ($this->subir)($this->aracely, 'borrador_lista_empaque', 'PL borrador.pdf')->assertSessionHas('success');
    ($this->subir)($this->aracely, 'lista_empaque', 'PL final.pdf')->assertSessionHas('success');
    ($this->subir)($this->aracely, 'lista_empaque', 'PL final corregida.pdf')->assertSessionHas('success');

    $docs = ImportacionDocumento::where('importacion_id', $this->imp->id)->get();
    expect($docs)->toHaveCount(3)
        ->and($docs->where('tipo', 'lista_empaque')->pluck('version')->sort()->values()->all())->toBe([1, 2])
        ->and($docs->firstWhere('tipo', 'borrador_lista_empaque')->version)->toBe(1);
    foreach ($docs as $d) {
        Storage::disk(ImportacionDocumento::DISCO)->assertExists($d->ruta);
    }

    $this->actingAs($this->jorge)->get("/app/compras/importacion/{$this->imp->id}")
        ->assertInertia(fn (Assert $p) => $p->component('Compras/Importacion/Show')
            ->has('importacion.documentos', 3)
            ->where('puede_quitar_documentos', false));
});

it('el gasto guarda su comprobante y se puede abrir', function () {
    $this->actingAs($this->aracely)->post("/app/compras/importacion/{$this->imp->id}/gasto", [
        'concepto' => 'flete', 'descripcion' => 'Flete marítimo', 'moneda' => 'COP', 'monto' => 24000000, 'capitalizable' => true,
        'metodo_prorrateo' => 'volumen', 'fecha' => now()->toDateString(),
        'comprobante' => UploadedFile::fake()->create('factura flete.pdf', 80, 'application/pdf'),
    ])->assertSessionHas('success');

    $doc = ImportacionDocumento::where('tipo', 'comprobante_gasto')->firstOrFail();
    expect($doc->gasto_id)->toBe($this->imp->gastos()->value('id'))
        ->and($doc->nombre)->toBe('factura flete.pdf');

    $this->actingAs($this->jorge)->get("/app/compras/importacion/documento/{$doc->id}")->assertOk();
    $this->actingAs($this->jorge)->get("/app/compras/importacion/{$this->imp->id}")
        ->assertInertia(fn (Assert $p) => $p->where('importacion.gastos.0.comprobantes.0.nombre', 'factura flete.pdf')
            ->has('importacion.documentos', 0));
});

it('solo Aracely quita documentos, y quien no es de compras/bodega no los ve', function () {
    ($this->subir)($this->aracely, 'bl', 'BL.pdf');
    $doc = ImportacionDocumento::firstOrFail();

    $this->actingAs($this->vendedor)->get("/app/compras/importacion/documento/{$doc->id}")->assertForbidden();
    ($this->subir)($this->vendedor, 'bl', 'otro.pdf')->assertForbidden();

    $this->actingAs($this->jorge)->delete("/app/compras/importacion/documento/{$doc->id}")->assertForbidden();
    expect($doc->fresh()->trashed())->toBeFalse();

    $this->actingAs($this->aracely)->delete("/app/compras/importacion/documento/{$doc->id}")->assertSessionHas('success');
    expect($doc->fresh()->trashed())->toBeTrue();
});

it('rechaza un tipo inventado y un archivo que no es documento', function () {
    ($this->subir)($this->aracely, 'contrato_secreto', 'x.pdf')->assertSessionHasErrors('tipo');
    $this->actingAs($this->aracely)->post("/app/compras/importacion/{$this->imp->id}/documento", [
        'tipo' => 'bl', 'archivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
    ])->assertSessionHasErrors('archivo');
});
