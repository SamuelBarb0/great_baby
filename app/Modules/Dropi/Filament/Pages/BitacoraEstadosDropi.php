<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiEstadoBitacora;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * «Bitácora de estados» (Reportes › Auditoría): quién cambió qué estado de un
 * pedido Dropi y cuándo. Lee `dropi_estados_bitacora`, que ya escribe
 * DropiPedido::transicionar() en cada cambio (sync, alistador, escáner…).
 * Sólo lectura: la bitácora no se edita.
 */
class BitacoraEstadosDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $title = 'Bitácora de estados';

    protected static ?string $slug = 'bitacora-estados-dropi';

    // Se entra desde Reportes; no ocupa un lugar más en el menú.
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'dropi.pages.bitacora-estados-dropi';

    /** Cuántas filas se muestran; «Ver más» suma otras tantas. */
    public const PASO = 100;

    public string $guia = '';

    public string $fuente = '';

    public string $estado = '';

    public string $desde = '';

    public string $hasta = '';

    public int $mostrar = self::PASO;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        return \App\Auth\Permisos::puede($u, 'dropi_reportes');
    }

    public function updated(string $propiedad): void
    {
        // Con cada filtro nuevo se vuelve a la primera tanda.
        if ($propiedad !== 'mostrar') {
            $this->mostrar = self::PASO;
        }
    }

    public function verMas(): void
    {
        $this->mostrar += self::PASO;
    }

    protected function consulta(): Builder
    {
        return DropiEstadoBitacora::query()
            ->when(trim($this->guia) !== '', fn ($q) => $q->whereHas('pedido', fn ($p) => $p->where(fn ($w) => $w
                ->where('guia', 'like', '%'.trim($this->guia).'%')
                ->orWhere('dropi_orden_id', trim($this->guia)))))
            ->when($this->fuente !== '', fn ($q) => $q->where('fuente', $this->fuente))
            ->when($this->estado !== '', fn ($q) => $q->where('estado_hasta', $this->estado))
            ->when($this->desde !== '', fn ($q) => $q->where('created_at', '>=', $this->desde.' 00:00:00'))
            ->when($this->hasta !== '', fn ($q) => $q->where('created_at', '<=', $this->hasta.' 23:59:59'));
    }

    public function getTotal(): int
    {
        return $this->consulta()->count();
    }

    public function getFilas(): Collection
    {
        return $this->consulta()
            ->with(['pedido:id,guia,dropi_orden_id', 'user:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($this->mostrar)
            ->get();
    }

    /** @return array<string, string> */
    public function getFuentes(): array
    {
        return DropiEstadoBitacora::query()->whereNotNull('fuente')
            ->distinct()->orderBy('fuente')->pluck('fuente', 'fuente')->all();
    }

    /** @return array<string, string> */
    public function getEstados(): array
    {
        return collect(EstadoPedidoDropi::cases())
            ->mapWithKeys(fn (EstadoPedidoDropi $e) => [$e->value => self::nombreEstado($e->value)])
            ->all();
    }

    public static function nombreEstado(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return ucfirst(str_replace('_', ' ', $valor));
    }
}
