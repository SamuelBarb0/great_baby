<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Una factura de Dropi, dentro del panel de Dropi (23-nov-2026).
 *
 * El libro, los pagos recibidos, la wallet y el cruce de pedidos abrían la
 * factura en el ERP (/app/facturas/{id}) y la clienta terminaba en el ERP
 * principal. Es de consulta: datos, líneas, pagos y el PDF de SIIGO.
 * Sólo abre facturas de la línea Dropi.
 */
class VerFacturaDropi extends Page
{
    protected static ?string $slug = 'factura-dropi';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'dropi.pages.ver-factura-dropi';

    #[Url]
    public ?int $factura = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u !== null && (Permisos::puede($u, 'dropi_wallet') || Permisos::puede($u, 'dropi_reportes') || Permisos::puede($u, 'facturas'));
    }

    /** La URL de la factura dentro de Dropi, o null si no hay factura. */
    public static function urlDe(?int $facturaId): ?string
    {
        return $facturaId ? static::getUrl(['factura' => $facturaId]) : null;
    }

    public function mount(): void
    {
        abort_unless($this->factura, 404);
        $this->getFactura();
    }

    public function getFactura(): FacturaVenta
    {
        return FacturaVenta::query()
            ->whereKey((int) $this->factura)
            ->where('linea_negocio', LineaNegocio::Dropi->value)
            ->with(['contacto:id,nombre_completo,numero_documento,tipo_documento', 'items', 'pagos'])
            ->firstOrFail();
    }

    public function getTitle(): string
    {
        return 'Factura '.$this->getFactura()->numero;
    }

    protected function getHeaderActions(): array
    {
        $f = $this->getFactura();

        return [
            Action::make('pdf')->label('PDF de SIIGO')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->visible(fn () => (bool) $f->siigo_id)
                ->url(route('app.facturas.siigo.pdf', $f->id, false), shouldOpenInNewTab: true),
        ];
    }
}
