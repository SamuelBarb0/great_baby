<?php

namespace Database\Seeders;

use App\Modules\Rrhh\Models\FormatoControlado;
use Illuminate\Database\Seeder;

/**
 * Fase G · Siembra el listado maestro con los formatos que el ERP imprime.
 *
 * Es idempotente y, sobre todo, **no pisa lo que el área haya actualizado**.
 * Si Gestión Humana ya subió el GH-F-015 a versión 002, volver a correr esto
 * en un despliegue no puede devolverlo a 001: el dueño de ese dato es el área.
 *
 * Sólo crea los que falten. Agregar un formato nuevo al código es agregarlo a
 * `FormatoControlado::SEMILLA` y correr esto otra vez.
 */
class FormatosControladosSeeder extends Seeder
{
    public function run(): void
    {
        foreach (FormatoControlado::SEMILLA as $clave => $s) {
            $existe = FormatoControlado::where('clave', $clave)->exists();

            if ($existe) {
                $this->command?->line("  {$clave}: ya está, no se toca");

                continue;
            }

            FormatoControlado::create([
                'clave' => $clave,
                'codigo' => $s['codigo'],
                'nombre' => $s['nombre'],
                'proceso' => $s['proceso'],
                'version' => $s['version'],
                'fecha_version' => $s['fecha'],
            ]);

            $this->command?->line("  {$clave}: creado (".($s['codigo'] ?: 'sin código').')');
        }
    }
}
