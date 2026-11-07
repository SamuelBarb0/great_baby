<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Dropi\Services\ContabilidadDropi as Contabilidad;
use BackedEnum;
use Filament\Pages\Page;

/**
 * «Contabilidad Dropi»: los libros del e-commerce dentro del panel de Dropi.
 *
 * Estado de resultados del mes, balance al corte, cartera (por cobrar,
 * antigüedad, cobrado) y notas crédito, todo con la línea de negocio Dropi.
 * Los números salen del mismo cálculo que las pantallas de contabilidad
 * (ver App\Modules\Dropi\Services\ContabilidadDropi).
 */
class ContabilidadDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Contabilidad Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Dinero';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Contabilidad Dropi';

    protected static ?string $slug = 'contabilidad-dropi';

    protected string $view = 'dropi.pages.contabilidad-dropi';

    /** `YYYY-MM` · el input type=month lo entrega así. */
    public string $mes = '';

    public static function canAccess(): bool
    {
        // La misma casilla que las pantallas de contabilidad (Gerente, Contador, root).
        return Permisos::puede(auth()->user(), 'contabilidad');
    }

    public function mount(): void
    {
        $this->mes = now('America/Bogota')->format('Y-m');
    }

    public function getDatosProperty(): array
    {
        return app(Contabilidad::class)->delMes($this->mes);
    }
}
