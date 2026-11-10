<?php

/**
 * Nadie consulta usuarios por permiso en crudo.
 *
 * `User::permission('ver.x')` revienta con un 500 cuando ese permiso no está
 * en la tabla: Spatie tira `PermissionDoesNotExist`. Y ese es exactamente el
 * estado que el resto de la autorización SÍ tolera —`Permisos::puede()` se cae
 * a los roles de la matriz cuando los permisos no están sembrados—, así que la
 * incoherencia dejaba pantallas enteras caídas en una instalación recién
 * montada, o en producción antes de que corra el sembrador.
 *
 * Eran diez lugares: metas del vendedor, RRHH, requisiciones, órdenes de
 * contratación, el portal del colaborador y el aviso de comentarios a
 * contabilidad. Todos avisan a gente, así que lo que se caía no era un reporte
 * sino la operación.
 *
 * Este test mira el código fuente a propósito: el defecto sólo aparece cuando
 * faltan los permisos, que es justo el escenario que ningún otro test monta.
 */
it('nadie usa User::permission() fuera del ayudante que lo protege', function () {
    $culpables = [];

    foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $archivo) {
        if ($archivo->getExtension() !== 'php') {
            continue;
        }

        // El ayudante es el único que puede: envuelve la llamada y se cae a los
        // roles cuando el permiso no existe.
        if (str_ends_with($archivo->getPathname(), 'Permisos.php')) {
            continue;
        }

        foreach (preg_split('/\R/u', $archivo->getContents()) as $i => $linea) {
            // Se ignoran los comentarios: varios explican por qué no se usa.
            $limpia = ltrim($linea);
            if (str_starts_with($limpia, '*') || str_starts_with($limpia, '//')) {
                continue;
            }

            if (str_contains($linea, 'User::permission(')) {
                $culpables[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $archivo->getPathname())
                    .':'.($i + 1);
            }
        }
    }

    expect($culpables)->toBe([],
        'Usá Permisos::usuariosQuePueden($seccion) en vez de User::permission(): '
        .implode(', ', $culpables));
});

it('el ayudante devuelve una consulta encadenable y no revienta sin permisos', function () {
    // Sin sembrar nada: antes esto era el 500.
    $q = \App\Auth\Permisos::usuariosQuePueden('vendedor');

    expect($q)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class)
        ->and($q->pluck('id')->all())->toBeArray();
});

it('una sección que no existe devuelve a nadie, no a todo el mundo', function () {
    \App\Models\User::factory()->count(3)->create();

    /*
     * Es la diferencia entre no avisarle a nadie y mandarle los datos de
     * cartera a toda la empresa. Ante la duda, nadie.
     */
    expect(\App\Auth\Permisos::usuariosQuePueden('seccion_que_no_existe')->count())->toBe(0);
});
