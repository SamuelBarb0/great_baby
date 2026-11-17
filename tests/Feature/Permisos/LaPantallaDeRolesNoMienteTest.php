<?php

/**
 * Que la pantalla de roles no prometa candados que no existen.
 *
 * Pintaba cuatro casillas —Ver, Crear, Editar, Eliminar— para las 68 secciones:
 * 272 casillas, de las cuales el código consultaba 74. Las otras 198 se
 * guardaban en la base, se veían marcadas en pantalla, y NADIE las miraba
 * nunca. Las 68 de «eliminar», ni una sola vez.
 *
 * Eso es peor que no tenerlas. Aracely le desmarcaba «Eliminar» al vendedor
 * convencida de que le quitaba ese poder, guardaba, y no pasaba nada. Una
 * casilla que no hace nada hace creer que el candado está puesto.
 */

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Illuminate\Support\Facades\Queue::fake();
    $this->seed(\Database\Seeders\PermisosSeeder::class);
});

it('cada acción que la pantalla ofrece, el código la consulta', function () {
    /*
     * Este test lee el código fuente a propósito. Es la única forma de que la
     * lista no se vuelva a separar de la realidad: si alguien agrega una
     * sección a `ACCIONES_QUE_SE_CUMPLEN` sin poner el chequeo, falla acá.
     */
    $codigo = '';

    foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $f) {
        if ($f->getExtension() === 'php') {
            $codigo .= $f->getContents();
        }
    }

    preg_match_all("/puedeAccion\(\s*[^,]+,\s*'([a-z_]+)'\s*,\s*([^)]+)\)/", $codigo, $m);

    $consultadas = [];

    foreach ($m[1] as $i => $seccion) {
        foreach (['crear', 'editar', 'eliminar'] as $accion) {
            if (str_contains($m[2][$i], "'{$accion}'")) {
                $consultadas[$seccion][] = $accion;
            }
        }
    }

    /*
     * `puedeEditar($u, 'seccion')` también cuenta.
     *
     * Es el atajo documentado para lo que más se pregunta, y por dentro llama
     * a `puedeAccion($u, $seccion, 'editar')`: consultar por ahí es consultar
     * igual. Buscando sólo `puedeAccion` esta prueba tenía un hueco —una
     * sección consultada por el atajo se veía como casilla que nadie mira— y
     * lo encontramos el 17-nov, cuando `editar.ubicaciones` empezó a usarse
     * así y la prueba la acusó de mentir estando bien puesta.
     */
    preg_match_all("/puedeEditar\(\s*[^,]+,\s*'([a-z_]+)'\s*\)/", $codigo, $atajo);

    foreach ($atajo[1] as $seccion) {
        $consultadas[$seccion][] = 'editar';
    }

    $mentiras = [];

    foreach (Permisos::ACCIONES_QUE_SE_CUMPLEN as $seccion => $acciones) {
        foreach ($acciones as $a) {
            if (! in_array($a, $consultadas[$seccion] ?? [], true)) {
                $mentiras[] = "{$a}.{$seccion}";
            }
        }
    }

    expect($mentiras)->toBe([],
        'La pantalla ofrece estas casillas y ningún controlador las consulta: '
        .implode(', ', $mentiras));
});

it('ninguna sección ofrece «eliminar»', function () {
    /*
     * Lo destructivo del ERP está protegido con `esRoot()` —sólo Aracely y
     * Gerencia—, que es más duro que cualquier casilla. Ofrecerla haría creer
     * que ese poder se le puede dar a alguien más, y no se puede.
     */
    foreach (Permisos::ACCIONES_QUE_SE_CUMPLEN as $seccion => $acciones) {
        expect($acciones)->not->toContain('eliminar');
    }
});

it('la sección sin acciones declaradas no pinta casillas de más', function () {
    $catalogo = Permisos::catalogo();

    $conAcciones = 0;
    $total = 0;

    foreach ($catalogo as $grupo) {
        foreach ($grupo as $fila) {
            $total++;

            if ($fila['acciones'] !== []) {
                $conAcciones++;
                // Y la que sí tiene, tiene exactamente las declaradas.
                expect(array_column($fila['acciones'], 'accion'))
                    ->toBe(Permisos::ACCIONES_QUE_SE_CUMPLEN[$fila['seccion']]);
            }
        }
    }

    expect($conAcciones)->toBe(count(Permisos::ACCIONES_QUE_SE_CUMPLEN))
        ->and($total)->toBeGreaterThan($conAcciones);
});

it('sin el permiso de crear, no se crea un tercero', function () {
    /*
     * `ver.contabilidad` es la puerta de la pantalla de terceros —la guarda
     * `esContable()`, no el permiso «contactos»—, así que hace falta para
     * llegar. Lo que se prueba acá es la capa de adentro: con la puerta
     * abierta pero sin «crear», no se crea.
     */
    $rol = Role::firstOrCreate(['name' => 'SoloMiraTerceros', 'guard_name' => 'web']);
    $rol->givePermissionTo(['ver.contabilidad', 'ver.contactos']);   // mira, pero no toca

    $mirón = User::factory()->create();
    $mirón->assignRole($rol);

    $this->actingAs($mirón)->post('/app/contactos', [
        'tipo_documento' => 'NIT', 'numero_documento' => '900999888',
        'nombre_completo' => 'No debería crearse',
    ])->assertForbidden();

    expect(Contacto::where('numero_documento', '900999888')->exists())->toBeFalse();
});

it('con el permiso de crear sí se crea', function () {
    $rol = Role::firstOrCreate(['name' => 'CreaTerceros', 'guard_name' => 'web']);
    $rol->givePermissionTo(['ver.contabilidad', 'ver.contactos', 'crear.contactos']);

    $quienPuede = User::factory()->create();
    $quienPuede->assignRole($rol);

    $this->actingAs($quienPuede)->post('/app/contactos', [
        'tipo_documento' => 'NIT', 'numero_documento' => '900777666',
        'nombre_completo' => 'Tercero de prueba',
    ])->assertRedirect();

    expect(Contacto::where('numero_documento', '900777666')->exists())->toBeTrue();
});
