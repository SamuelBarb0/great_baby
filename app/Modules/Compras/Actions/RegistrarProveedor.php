<?php

namespace App\Modules\Compras\Actions;

use App\Models\Contacto;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Crea el proveedor desde la orden nueva, o marca como proveedor al contacto
 * que ya tenía ese documento. Vivía en `ComprasGestionController::proveedorCrear`;
 * se sacó (7-nov) para que el panel de Dropi use la misma regla.
 */
class RegistrarProveedor
{
    use AsAction;

    /**
     * @param array{nombre:string, tipo_documento:string, numero_documento:string, email?:?string, telefono?:?string} $datos
     * @return array{contacto: Contacto, ya_existia: bool}
     */
    public function handle(array $datos): array
    {
        $numero = trim($datos['numero_documento']);

        // Un documento repetido es el mismo contacto: puede ser ya cliente, y se marca
        // también proveedor.
        $existente = Contacto::withTrashed()->where('numero_documento', $numero)->first();
        if ($existente) {
            if ($existente->trashed() || ! $existente->activo) {
                throw ValidationException::withMessages([
                    'numero_documento' => 'Ese documento es de un contacto '.($existente->trashed() ? 'eliminado' : 'inactivo')
                        .'. Pídale a contabilidad que lo recupere.',
                ]);
            }
            if (! $existente->es_proveedor) {
                $existente->update(['es_proveedor' => true]);
            }

            return ['contacto' => $existente, 'ya_existia' => true];
        }

        $nuevo = Contacto::create([
            'tipo_documento' => $datos['tipo_documento'],
            'numero_documento' => $numero,
            'nombre_completo' => trim($datos['nombre']),
            'email' => $datos['email'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'es_proveedor' => true,
            'activo' => true,
        ]);

        return ['contacto' => $nuevo, 'ya_existia' => false];
    }
}
