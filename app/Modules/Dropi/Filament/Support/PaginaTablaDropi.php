<?php

namespace App\Modules\Dropi\Filament\Support;

use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Contabilidad\Support\FiltroEjes;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;

/**
 * Base de las pantallas de compras y contabilidad del panel de Dropi.
 *
 * Muestran sólo lo de la línea Dropi, separado de las otras empresas, con el
 * mismo filtro (`FiltroEjes`) que usa /app para que los números no difieran.
 * Son de consulta: crear o editar se hace en el ERP, y el botón «Abrir en el
 * ERP» lleva allá.
 *
 * Los enlaces a /app van RELATIVOS: armados con `url()` toman el APP_URL del
 * .env y en un servidor con otro puerto apuntaban al equivocado (7-nov).
 */
abstract class PaginaTablaDropi extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'dropi.pages.tabla-dropi';

    /** La ruta de /app equivalente, para «Abrir en el ERP». */
    abstract protected static function rutaEnErp(): string;

    /** Quién la ve: la misma regla que su pantalla en /app. */
    abstract protected static function puedeVer(\App\Models\User $u): bool;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u !== null && static::puedeVer($u);
    }

    protected static function ejesDropi(): FiltroEjes
    {
        return FiltroEjes::de(LineaNegocio::Dropi->value, null);
    }

    /** Tarjetas de totales arriba de la tabla; vacío = sin tarjetas. */
    public function resumen(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('abrirEnErp')
                ->label('Abrir en el ERP')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(static::rutaEnErp() . '?linea=' . LineaNegocio::Dropi->value, shouldOpenInNewTab: true),
        ];
    }
}
