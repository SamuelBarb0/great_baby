<?php

/**
 * Fase G · Control documental.
 *
 * El recuadro de arriba de cada formato —código, versión, fecha— no es
 * decoración: es lo que permite decirle a una auditoría «esto se diligenció
 * con la versión 002 del GH-F-015, vigente desde tal fecha».
 *
 * Estaba escrito a mano dentro de cada plantilla, y ya había empezado a
 * divergir: tres formatos con una fecha, dos con otras distintas, y la entrega
 * de cargo imprimiendo en ese renglón la fecha DEL REGISTRO. Peor: subir un
 * formato a versión 002 —lo normal, para eso existe el número— obligaba a
 * pedirle a un programador que editara código.
 *
 * Lo que estas pruebas cuidan es que eso no vuelva a pasar: un solo origen
 * para los tres datos, editable por el área, y ningún formato impreso que se
 * quede sin su ficha.
 */

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EntregaCargo;
use App\Modules\Rrhh\Models\FormatoControlado;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\PermisosSeeder::class);
    $this->seed(Database\Seeders\RolesGestionHumanaSeeder::class);
    $this->seed(Database\Seeders\FormatosControladosSeeder::class);

    $this->elena = User::factory()->create(['name' => 'Elena']);
    $this->elena->assignRole(Role::firstOrCreate(['name' => 'GestionHumana', 'guard_name' => 'web']));

    $this->jorge = User::factory()->create(['name' => 'Jorge Líder']);
    $this->jorge->assignRole(Role::firstOrCreate(['name' => 'LiderArea', 'guard_name' => 'web']));
});

/** El impreso de entrega de cargo, que es el más barato de armar. */
function unFormatoImpreso(): string
{
    $e = Empleado::create([
        'nombre' => 'Quien entrega', 'tipo_documento' => 'CC', 'numero_documento' => 'cd1',
        'cargo' => 'Auxiliar de bodega', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1_500_000, 'fecha_ingreso' => '2026-01-15',
        'estado' => 'retirado', 'estado_induccion' => 'completada',
    ]);

    $entrega = EntregaCargo::create(['empleado_id' => $e->id, 'fecha' => '2026-06-30']);

    $e->load('asignaciones');

    return view('rrhh.pdf.entrega-cargo', [
        'e' => $e, 'entrega' => $entrega, 'bloques' => EntregaCargo::BLOQUES,
        'equipos' => $e->asignaciones, 'dotacion' => $e->asignaciones,
        'empresa' => App\Models\EmpresaConfig::current(), 'generadoPor' => 'Elena',
    ])->render();
}

// ── Un solo origen ───────────────────────────────────────────────────────────

it('ningún formato impreso se queda sin su ficha de control', function () {
    /*
     * La guarda que sostiene toda la fase. El día que alguien programe un
     * formato nuevo y se olvide de registrarlo, esta prueba lo dice: sin
     * ficha, la hoja sale sin código ni versión y no se puede rastrear.
     */
    $conRecuadro = collect(glob(resource_path('views/rrhh/pdf/*.blade.php')))
        ->filter(fn (string $ruta) => str_contains(file_get_contents($ruta), 'partials.control'))
        ->map(fn (string $ruta) => str(basename($ruta, '.blade.php'))->replace('-', '_')->toString())
        ->values();

    expect($conRecuadro)->not->toBeEmpty();

    foreach ($conRecuadro as $clave) {
        expect(FormatoControlado::SEMILLA)->toHaveKey($clave);
        expect(FormatoControlado::where('clave', $clave)->exists())->toBeTrue();
    }
});

it('le pasa la ficha al impreso sin que el controlador tenga que acordarse', function () {
    /*
     * La ficha la inyecta un composer y no cada controlador: si dependiera de
     * acordarse, un formato nuevo saldría sin recuadro y nadie lo notaría
     * hasta que lo pidiera una auditoría.
     */
    $html = unFormatoImpreso();

    expect($html)
        ->toContain('PROCESO DE GESTIÓN HUMANA')
        ->toContain('FORMATO ENTREGA DE CARGO · PAZ Y SALVO')
        ->toContain('Versión: 001');
});

it('arma el recuadro aunque el controlador no mande la empresa', function () {
    /*
     * Cinco de los seis formatos pasaban la empresa y el sexto no: tenía el
     * nombre escrito dentro de la plantilla. Al unificar el encabezado se
     * quedó sin marca y el impreso reventó. El recuadro se arma solo.
     */
    $e = Empleado::create([
        'nombre' => 'Sin empresa', 'tipo_documento' => 'CC', 'numero_documento' => 'se1',
        'cargo' => 'Auxiliar', 'area' => 'Logística', 'tipo_contrato' => 'indefinido',
        'salario' => 1_500_000, 'fecha_ingreso' => '2026-01-15', 'estado' => 'retirado',
    ]);
    $entrega = EntregaCargo::create(['empleado_id' => $e->id, 'fecha' => '2026-06-30']);
    $e->load('asignaciones');

    $html = view('rrhh.pdf.entrega-cargo', [
        'e' => $e, 'entrega' => $entrega, 'bloques' => EntregaCargo::BLOQUES,
        'equipos' => $e->asignaciones, 'dotacion' => $e->asignaciones,
        'generadoPor' => 'Elena',
    ])->render();

    expect($html)->toContain('PROCESO DE GESTIÓN HUMANA');
});

it('lo que el área cambia en el listado sale en el siguiente impreso', function () {
    FormatoControlado::where('clave', 'entrega_cargo')->update([
        'codigo' => 'GH-F-021',
        'version' => '002',
        'fecha_version' => '2026-10-27',
    ]);

    $html = unFormatoImpreso();

    expect($html)
        ->toContain('GH-F-021')
        ->toContain('Versión: 002')
        ->toContain('Fecha: 27/10/2026')
        // El pie lo repite: una hoja suelta se archiva sola y tiene que
        // poder rastrearse sin el encabezado.
        ->toContain('GH-F-021 · versión 002');
});

it('no imprime la fecha del registro en el renglón de la versión', function () {
    /*
     * El error concreto que tenía la entrega de cargo: en «Fecha» imprimía la
     * fecha de la entrega. Son dos cosas distintas —una es cuándo rige el
     * formato, otra cuándo pasó el hecho— y confundirlas hace ilegible el
     * control de versiones.
     */
    FormatoControlado::where('clave', 'entrega_cargo')->update(['fecha_version' => '2020-02-20']);

    $html = unFormatoImpreso();

    expect($html)
        ->toContain('Fecha: 20/02/2020')
        ->not->toContain('Fecha: 30/06/2026');
});

it('dice «sin asignar» en vez de inventarse un código', function () {
    /*
     * Dos de los formatos del área llegaron sin código. Un código inventado
     * por el sistema es peor que ninguno: parece válido y nadie lo verifica.
     */
    $html = unFormatoImpreso();

    expect($html)->toContain('sin asignar');
    expect(FormatoControlado::de('entrega_cargo')->tieneCodigo())->toBeFalse();
});

it('no se cae si le borraron el renglón del listado', function () {
    FormatoControlado::where('clave', 'entrega_cargo')->delete();

    $f = FormatoControlado::de('entrega_cargo');

    expect($f->exists)->toBeFalse()
        ->and($f->nombre)->toBe('Entrega de cargo · paz y salvo')
        ->and($f->version)->toBe('001');

    expect(unFormatoImpreso())->toContain('FORMATO ENTREGA DE CARGO');
});

// ── La pantalla del área ─────────────────────────────────────────────────────

it('deja que Gestión Humana suba la versión de un formato', function () {
    $f = FormatoControlado::where('clave', 'evaluacion_prueba')->firstOrFail();

    $this->actingAs($this->elena)->put("/app/rrhh/formatos/{$f->id}", [
        'codigo' => 'GH-F-015',
        'nombre' => 'Evaluación período de prueba',
        'proceso' => FormatoControlado::PROCESO_GH,
        'version' => '002',
        'fecha_version' => '2026-10-27',
    ])->assertRedirect();

    $f->refresh();

    expect($f->version)->toBe('002')
        ->and($f->fecha_version->toDateString())->toBe('2026-10-27')
        // Quién lo subió y cuándo: sin eso la versión es un número que cambia
        // solo.
        ->and($f->actualizado_por_id)->toBe($this->elena->id)
        ->and($f->actualizado_at)->not->toBeNull();
});

it('no deja dos formatos con el mismo código', function () {
    /*
     * Es la razón de ser de un código. Con dos iguales, un archivo deja de
     * poder decir de cuál de los dos documentos habla.
     */
    $f = FormatoControlado::where('clave', 'evaluacion_prueba')->firstOrFail();

    $this->actingAs($this->elena)->put("/app/rrhh/formatos/{$f->id}", [
        'codigo' => 'SST-F-001',
        'nombre' => 'Evaluación período de prueba',
        'proceso' => FormatoControlado::PROCESO_GH,
        'version' => '001',
    ])->assertSessionHasErrors('codigo');

    expect($f->refresh()->codigo)->toBe('GH-F-015');
});

it('deja guardar el mismo formato sin que choque consigo mismo', function () {
    $f = FormatoControlado::where('clave', 'evaluacion_prueba')->firstOrFail();

    $this->actingAs($this->elena)->put("/app/rrhh/formatos/{$f->id}", [
        'codigo' => 'GH-F-015',
        'nombre' => 'Evaluación del período de prueba',
        'proceso' => FormatoControlado::PROCESO_GH,
        'version' => '001',
    ])->assertSessionHasNoErrors();

    expect($f->refresh()->nombre)->toBe('Evaluación del período de prueba');
});

it('permite dejar el código vacío mientras el área no lo asigne', function () {
    $f = FormatoControlado::where('clave', 'entrega_cargo')->firstOrFail();

    $this->actingAs($this->elena)->put("/app/rrhh/formatos/{$f->id}", [
        'codigo' => '',
        'nombre' => 'Entrega de cargo · paz y salvo',
        'proceso' => FormatoControlado::PROCESO_GH,
        'version' => '001',
    ])->assertSessionHasNoErrors();

    expect($f->refresh()->codigo)->toBeNull();
});

it('el listado es de Gestión Humana, no de cualquiera', function () {
    $f = FormatoControlado::where('clave', 'evaluacion_prueba')->firstOrFail();

    $this->actingAs($this->jorge)->get('/app/rrhh/formatos')->assertForbidden();
    $this->actingAs($this->jorge)->put("/app/rrhh/formatos/{$f->id}", [
        'codigo' => 'GH-F-099', 'nombre' => 'Lo que sea',
        'proceso' => FormatoControlado::PROCESO_GH, 'version' => '009',
    ])->assertForbidden();

    $this->actingAs($this->elena)->get('/app/rrhh/formatos')->assertOk();
});

// ── El sembrador ─────────────────────────────────────────────────────────────

it('no le devuelve la versión al área cuando se vuelve a sembrar', function () {
    /*
     * Pasa en cada despliegue. Si el sembrador pisara lo que el área
     * actualizó, el listado maestro volvería solo a como estaba y nadie
     * entendería por qué.
     */
    FormatoControlado::where('clave', 'evaluacion_prueba')
        ->update(['version' => '005', 'fecha_version' => '2026-09-01']);

    $this->seed(Database\Seeders\FormatosControladosSeeder::class);

    $f = FormatoControlado::where('clave', 'evaluacion_prueba')->firstOrFail();

    expect($f->version)->toBe('005')
        ->and($f->fecha_version->toDateString())->toBe('2026-09-01')
        ->and(FormatoControlado::count())->toBe(count(FormatoControlado::SEMILLA));
});
