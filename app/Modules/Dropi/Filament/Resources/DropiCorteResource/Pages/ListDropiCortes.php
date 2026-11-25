<?php

namespace App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages;

use App\Modules\Dropi\Actions\AsignarCorteACarga;
use App\Modules\Dropi\Filament\Resources\DropiCorteResource;
use App\Modules\Dropi\Listeners\RecalcularContadoresCorte;
use App\Modules\Dropi\Models\DropiPedido;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDropiCortes extends ListRecords
{
    protected static string $resource = DropiCorteResource::class;

    /**
     * Desde el 25-nov-2026 el corte nace cuando Aracely genera: un pedido que se
     * empacó o despachó sin pasar por «Generar corte» (se manejó en Dropi) no
     * está en ningún corte y no entraría a las remisiones ni a las facturas del
     * cierre. Aquí se ven y se meten al corte abierto con un clic.
     */
    public static function despachadosSinCorte(): Builder
    {
        return DropiPedido::query()->whereNull('corte_id')->whereIn('estado', DropiPedido::estadosYaDespachados());
    }

    public function getSubheading(): ?string
    {
        $n = self::despachadosSinCorte()->count();

        return $n ? "⚠ {$n} pedido(s) empacados o despachados sin corte: no entran a la facturación del cierre hasta meterlos a un corte." : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('meterSinCorte')
                ->label(fn () => 'Meter ' . self::despachadosSinCorte()->count() . ' sin corte al corte abierto')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('warning')
                ->visible(fn () => self::despachadosSinCorte()->exists())
                ->requiresConfirmation()
                ->modalDescription('Entran al corte de la hora de ahora (antes de las 2 p. m. el 1, después el 2; si ese ya se cerró, el siguiente), para que se facturen al cerrarlo.')
                ->action(function () {
                    $corte = AsignarCorteACarga::run(CarbonImmutable::now());
                    $n = self::despachadosSinCorte()->update(['corte_id' => $corte->id]);
                    RecalcularContadoresCorte::recontar($corte->id);
                    Notification::make()->title("{$n} pedido(s) al {$corte->etiqueta()}")->success()->send();
                }),
            CreateAction::make(),
        ];
    }
}
