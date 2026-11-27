<?php

namespace App\Modules\Dropi\Filament\Widgets;

use App\Modules\Dropi\Jobs\GenerarGuiasEnBloqueJob;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Los reportes de los cortes de hoy en «Pedidos Dropi» (26-nov-2026: «que se
 * vea más bonito e intuitivo»): una tarjeta por reporte con su avance, cuántas
 * guías salieron, cuántas piden algo de Aracely y, a un clic, cuáles y por qué.
 * Se refresca sola mientras algo está en curso.
 */
class ReporteGuiasWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'dropi.widgets.reporte-guias';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** Sólo si hay algo que mostrar: un reporte de hoy o guías saliendo. */
    public static function canView(): bool
    {
        return DropiLoteImpresion::query()->where('created_at', '>=', now('America/Bogota')->startOfDay())->exists()
            || GenerarGuiasEnBloqueJob::avance(auth()->id()) !== null;
    }

    /** @return Collection<int, DropiLoteImpresion> */
    public function lotes(): Collection
    {
        return DropiLoteImpresion::query()
            ->where('created_at', '>=', now('America/Bogota')->startOfDay())
            ->with('corte')->latest('id')->limit(4)->get();
    }

    /**
     * «Generar guías» suelto (sin reporte) en curso: el avance de este usuario.
     *
     * @return array{total:int, listas:int, hechas:int, saltadas:int, errores:list<string>}|null
     */
    public function guiasSueltas(): ?array
    {
        $a = GenerarGuiasEnBloqueJob::avance(auth()->id());
        if (! $a) {
            return null;
        }
        $listas = $a['hechas'] + $a['saltadas'] + count($a['errores']);

        return $listas < $a['total'] ? $a + ['listas' => $listas] : null;
    }

    /**
     * «Ver qué pasó»: cada pedido del reporte, por qué y qué hacer. Es una
     * acción (no un modal dibujado en la tarjeta) para que el refresco de cada
     * 5 s no lo cierre mientras Aracely lo lee.
     */
    public function verDetalleAction(): Action
    {
        return Action::make('verDetalle')
            ->label('Ver qué pasó')
            ->icon('heroicon-o-list-bullet')
            ->size('sm')
            ->color(fn (array $arguments) => DropiLoteImpresion::find($arguments['lote'] ?? 0)?->conAccion() ? 'danger' : 'gray')
            ->modalHeading(fn (array $arguments) => DropiLoteImpresion::with('corte')->find($arguments['lote'] ?? 0)?->titulo() ?? 'Reporte')
            ->modalContent(fn (array $arguments) => view('dropi.pages.partials.resultados-guias', [
                'lote' => DropiLoteImpresion::find($arguments['lote'] ?? 0),
            ]))
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar');
    }

    public static function enCurso(DropiLoteImpresion $l): bool
    {
        return in_array($l->estado, [DropiLoteImpresion::GENERANDO_GUIAS, DropiLoteImpresion::DESCARGANDO, DropiLoteImpresion::ARMANDO], true);
    }

    protected function getViewData(): array
    {
        $lotes = $this->lotes();
        $sueltas = $this->guiasSueltas();

        return [
            'lotes' => $lotes,
            'sueltas' => $sueltas,
            'refrescar' => $sueltas !== null || $lotes->contains(fn ($l) => self::enCurso($l)),
        ];
    }
}
