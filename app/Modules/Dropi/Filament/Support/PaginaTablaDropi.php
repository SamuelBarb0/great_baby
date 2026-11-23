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
 * Todo se hace dentro de este panel: no hay botón que lleve al ERP.
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

    /**
     * Sin «Abrir en el ERP» (23-nov-2026): Dropi va aparte y la clienta
     * terminaba en el ERP principal. `rutaEnErp()` queda como referencia de
     * cuál es su pantalla gemela en /app.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
