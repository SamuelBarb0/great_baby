<?php

namespace App\Modules\Dropi\Actions;

use App\Models\Contacto;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Crea un cliente o proveedor DE DROPI (23-nov-2026).
 *
 * Dropi es la misma empresa ante la DIAN pero internamente va aparte, así que
 * maneja sus propios terceros. Una ficha por número de documento:
 *
 *  · si el documento no existe, nace SÓLO en Dropi (no aparece en el ERP);
 *  · si ya existe (lo tenía el ERP), se comparte la ficha: queda también en
 *    Dropi con el rol pedido, sin duplicarla ni cambiarle los datos al ERP.
 */
class RegistrarTerceroDropi
{
    use AsAction;

    /**
     * @param  array{nombre:string, tipo_documento:string, numero_documento:string, email?:?string, telefono?:?string, direccion?:?string, ciudad?:?string, es_cliente?:bool, es_proveedor?:bool}  $datos
     * @return array{contacto: Contacto, ya_existia: bool}
     */
    public function handle(array $datos): array
    {
        $numero = trim((string) $datos['numero_documento']);
        $cliente = (bool) ($datos['es_cliente'] ?? false);
        $proveedor = (bool) ($datos['es_proveedor'] ?? false);
        if (! $cliente && ! $proveedor) {
            throw ValidationException::withMessages(['es_proveedor' => 'Marque si es cliente, proveedor o ambos.']);
        }

        $existente = Contacto::withTrashed()->where('numero_documento', $numero)->first();
        if ($existente) {
            if ($existente->trashed() || ! $existente->activo) {
                throw ValidationException::withMessages([
                    'numero_documento' => 'Ese documento es de un tercero '.($existente->trashed() ? 'eliminado' : 'inactivo')
                        .'. Pídale a contabilidad que lo recupere.',
                ]);
            }
            $existente->update(array_filter([
                'en_dropi' => true,
                'es_cliente' => $cliente ?: null,
                'es_proveedor' => $proveedor ?: null,
            ], fn ($v) => $v !== null));

            return ['contacto' => $existente, 'ya_existia' => true];
        }

        $nuevo = Contacto::create([
            'tipo_documento' => $datos['tipo_documento'],
            'numero_documento' => $numero,
            'nombre_completo' => trim((string) $datos['nombre']),
            'razon_social' => ($datos['tipo_documento'] ?? '') === 'NIT' ? trim((string) $datos['nombre']) : null,
            'email' => $datos['email'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'direccion' => $datos['direccion'] ?? null,
            'ciudad' => $datos['ciudad'] ?? null,
            'es_cliente' => $cliente,
            'es_proveedor' => $proveedor,
            'en_erp' => false,
            'en_dropi' => true,
            'activo' => true,
        ]);

        return ['contacto' => $nuevo, 'ya_existia' => false];
    }
}
