<?php

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Enums\ConceptoGastoImportacion;
use App\Modules\Compras\Enums\EtapaContenedor;
use App\Modules\Compras\Models\Importacion;

/**
 * Cuántos contenedores hay en el agua y cuánto costó cada uno: «por qué sube el
 * flete, por qué sube el gasto» (reunión con Compras, 21-oct-2026).
 */
class ReporteContenedores
{
    private const LIMITE = 30;

    /** @return array{conteos: array<string,int>, filas: list<array<string,mixed>>} */
    public function handle(): array
    {
        $seguimiento = app(SeguimientoContenedor::class);
        $costos = app(CostoImportacion::class);

        $contenedores = Importacion::query()
            ->whereHas('ordenes')
            ->with(['ordenes', 'gastos', 'etapas'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        $conteos = ['en_agua' => 0, 'en_puerto' => 0, 'en_bodega' => 0, 'sin_etapa' => 0];
        $filas = [];

        foreach ($contenedores as $imp) {
            $r = $seguimiento->resumen($imp);
            $conteos[$this->lugar($r['etapa'])]++;

            $m3 = (float) $imp->ordenes->sum(fn ($o) => (float) $o->pivot->volumen_m3);
            $flete = $this->suma($imp, [ConceptoGastoImportacion::Flete]);
            $fob = (float) $imp->valor_fob > 0
                ? (float) $imp->valor_fob
                : $imp->ordenes->sum(function ($o) use ($costos) {
                    try {
                        return (float) $o->subtotal * $costos->tasa($o);
                    } catch (\InvalidArgumentException) {
                        return 0.0;
                    }
                });

            $filas[] = [
                'id' => $imp->id,
                'numero' => $imp->numero,
                'contenedor' => $imp->contenedor,
                'etapa' => $r['etapa_label'],
                'dias_atraso' => $r['dias_atraso'],
                'liquidado' => $imp->fecha_liquidacion !== null,
                'dias_transito' => $imp->fecha_zarpe && $imp->fecha_llegada ? $imp->diasEnTransito() : null,
                'm3' => round($m3, 2),
                'fob' => round($fob, 2),
                'flete' => $flete,
                'flete_m3' => $m3 > 0 ? round($flete / $m3, 2) : null,
                'arancel' => $this->suma($imp, [ConceptoGastoImportacion::Arancel]),
                'iva' => $this->suma($imp, [ConceptoGastoImportacion::IvaImportacion]),
                'otros' => $this->suma($imp, [
                    ConceptoGastoImportacion::Seguro, ConceptoGastoImportacion::AgenteAduana,
                    ConceptoGastoImportacion::Almacenaje, ConceptoGastoImportacion::TransporteInterno,
                    ConceptoGastoImportacion::Otros,
                ]),
                'gastos' => round((float) $imp->gastos->sum('monto_base'), 2),
            ];
        }

        return ['conteos' => $conteos, 'filas' => $filas];
    }

    /** @param  list<ConceptoGastoImportacion>  $conceptos */
    private function suma(Importacion $imp, array $conceptos): float
    {
        return round((float) $imp->gastos->filter(fn ($g) => in_array($g->concepto, $conceptos, true))->sum('monto_base'), 2);
    }

    private function lugar(?string $etapa): string
    {
        $caso = $etapa ? EtapaContenedor::tryFrom($etapa) : null;

        return match (true) {
            $caso === null => 'sin_etapa',
            $caso->posicion() >= EtapaContenedor::EnBodega->posicion() => 'en_bodega',
            $caso->posicion() >= EtapaContenedor::EnPuerto->posicion() => 'en_puerto',
            default => 'en_agua',
        };
    }
}
