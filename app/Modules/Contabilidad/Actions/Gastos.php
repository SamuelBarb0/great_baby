<?php

namespace App\Modules\Contabilidad\Actions;

use App\Models\User;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Models\MovimientoCaja;
use App\Modules\Contabilidad\Support\CatalogoGastos;
use App\Modules\Gerencia\Models\GastoOperativo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El ciclo de un gasto operativo: pedirlo, aprobarlo (se causa), rechazarlo,
 * pagarlo (sale de caja o banco) y reclasificarlo.
 *
 * Vivía en GastosController; se sacó (7-nov) para que el panel de Dropi lo
 * haga con las mismas reglas. Los datos llegan ya validados por quien llama.
 */
final class Gastos
{
    public static function puedeAprobar(?User $u): bool
    {
        return (bool) ($u && ($u->esAracely() || $u->hasAnyRole(['Gerente'])));
    }

    /**
     * @param array{fecha:string, categoria:string, descripcion:string, monto:float, proveedor?:?string,
     *     factura_ref?:?string, metodo_pago:string, tipo:string, notas?:?string} $data
     */
    public static function crear(array $data, User $quien, LineaNegocio $linea = LineaNegocio::GreatBaby): GastoOperativo
    {
        $numero = 'GO-' . now()->format('ym') . '-' . str_pad((string) (GastoOperativo::whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT);

        /*
         * Un gasto sin factura del proveedor no se puede declarar: no hay con
         * que soportar el IVA ni el costo ante la DIAN. Se marca interno por
         * defecto, que es lo conservador, y queda VISIBLE para que el contador
         * lo cambie si la factura aparece despues.
         */
        $sinFactura = trim((string) ($data['factura_ref'] ?? '')) === '';

        return GastoOperativo::create([
            ...$data,
            'numero' => $numero,
            'solicita_id' => $quien->id,
            'estado' => 'pendiente',
            'cuenta_puc' => CatalogoGastos::cuentaDe($data['categoria']),
            'destino_fiscal' => $sinFactura ? DestinoFiscal::Interno : DestinoFiscal::Siigo,
            'motivo_destino' => $sinFactura ? 'Sin factura del proveedor' : null,
            'linea_negocio' => $linea,
        ]);
    }

    public static function aprobar(GastoOperativo $g, User $quien): GastoOperativo
    {
        abort_unless(self::puedeAprobar($quien), 403);

        /*
         * Solo se aprueba lo PENDIENTE. Sin esto, un POST directo o una pestana
         * vieja devolvia a «aprobado» un gasto ya pagado: la caja ya habia
         * salido en el libro y la pantalla lo mostraba como pendiente de pagar.
         */
        abort_unless($g->estado === 'pendiente', 422, 'Sólo se puede aprobar un gasto pendiente.');

        $g->update(['estado' => 'aprobado', 'aprueba_id' => $quien->id]);

        // La obligacion ya existe aunque no se haya pagado: se causa ahora.
        app(RegistrarAsientoGasto::class)->causar($g->fresh());

        return $g;
    }

    public static function rechazar(GastoOperativo $g, User $quien, string $motivo): GastoOperativo
    {
        abort_unless(self::puedeAprobar($quien), 403);

        $g->update([
            'estado' => 'rechazado', 'aprueba_id' => $quien->id,
            'notas' => trim(($g->notas ?: '') . "\n[RECHAZO] " . $motivo),
        ]);

        return $g;
    }

    /**
     * Si sale en efectivo, de qué caja: el movimiento se crea acá para que
     * aterrice en el arqueo de ese día y la plata que falta tenga explicación.
     */
    public static function pagar(GastoOperativo $g, User $quien, ?int $cajaId): GastoOperativo
    {
        abort_unless($quien->esContable(), 403);
        abort_unless($g->estado === 'aprobado', 422, 'Sólo aprobados pueden marcarse pagados.');

        DB::transaction(function () use ($g, $quien, $cajaId) {
            $g->update([
                'estado' => 'pagado',
                'caja_id' => $cajaId,
            ]);

            if ($cajaId) {
                MovimientoCaja::create([
                    'caja_id' => $cajaId,
                    'fecha' => now('America/Bogota')->toDateString(),
                    'tipo' => 'gasto',
                    'descripcion' => "Gasto {$g->numero} · {$g->descripcion}",
                    // El proveedor es a quien se le entregó la plata; si no
                    // hay, queda quien lo solicitó, que es quien la recibió.
                    'entregado_a' => $g->proveedor ?: ($g->solicita?->name ?? 'Sin registrar'),
                    'valor' => $g->monto,
                    'user_id' => $quien->id,
                ]);
            }

            // Cancela el pasivo contra caja o banco segun el medio de pago.
            app(RegistrarAsientoGasto::class)->pagar($g->fresh());
        });

        return $g;
    }

    /**
     * Lo que el contador decide: contra qué cuenta va y si se declara. Rehace
     * el asiento en vez de parchearlo, para que el libro no siga mostrando la
     * cuenta vieja hasta el cierre.
     */
    public static function reclasificar(GastoOperativo $g, ?string $cuenta, DestinoFiscal $destino, ?string $motivo, ?LineaNegocio $linea = null): GastoOperativo
    {
        $motivo = trim((string) $motivo);

        if ($destino !== DestinoFiscal::Siigo && $motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Para que el gasto no se declare hay que escribir por qué.',
            ]);
        }

        $g->update([
            'cuenta_puc' => $cuenta ?: null,
            'destino_fiscal' => $destino,
            'motivo_destino' => $destino === DestinoFiscal::Siigo ? null : $motivo,
            'linea_negocio' => $linea ?? $g->linea_negocio,
        ]);

        app(RegistrarAsientoGasto::class)->rehacer($g->fresh());

        return $g;
    }
}
