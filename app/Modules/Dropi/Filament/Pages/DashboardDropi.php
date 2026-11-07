<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Filament\Resources\DropiPedidoResource;
use App\Modules\Dropi\Filament\Widgets\AlertasOperativasWidget;
use App\Modules\Dropi\Filament\Widgets\EmbudoPedidosWidget;
use App\Modules\Dropi\Filament\Widgets\MetricasFinancierasWidget;
use App\Modules\Dropi\Filament\Widgets\ResumenCorteActivoWidget;
use App\Modules\Dropi\Models\DropiPedido;
use App\Support\Periodos;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class DashboardDropi extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Dinero';

    protected static ?int $navigationSort = 1;

    protected string $view = 'dropi.pages.dashboard-dropi';

    public string $periodo = Periodos::MES;

    public string $guiaBuscada = '';

    public string $q = '';        // buscador general del tablero
    public string $tiendaQ = '';  // filtro de la tabla de tiendas

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        return $u->esAracely() || $u->hasAnyRole(['Gerente', 'Contador']);
    }

    public function mount(): void
    {
        $this->periodo = Periodos::actual();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('periodo')
                ->label('Periodo')
                ->options(Periodos::opciones())
                ->live()
                ->afterStateUpdated(fn ($state) => Periodos::guardar($state)),
            TextInput::make('guiaBuscada')
                ->label('Buscar guía')
                ->placeholder('GUI-000001')
                ->suffixIcon('heroicon-o-magnifying-glass'),
        ]);
    }

    public function irAGuia(): void
    {
        $guia = trim($this->guiaBuscada);
        if ($guia === '') {
            return;
        }
        $existe = DropiPedido::where('guia', $guia)->exists();
        if (! $existe) {
            Notification::make()->title("Guía {$guia} no encontrada")->warning()->send();
            return;
        }
        $this->redirect(DropiPedidoResource::getUrl('index', ['tableSearch' => $guia]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ir_guia')
                ->label('Ir a guía')
                ->icon('heroicon-o-arrow-right')
                ->action('irAGuia'),
        ];
    }

    public function getHeaderWidgets(): array
    {
        return [
            ResumenCorteActivoWidget::class,
            MetricasFinancierasWidget::class,
            AlertasOperativasWidget::class,
            EmbudoPedidosWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return ['default' => 1, 'sm' => 2, 'lg' => 4];
    }

    // ===== Tablero de ventas (integrado en el Dashboard) =====

    /** Query base con métricas por grupo (transportadora / depto / tienda). */
    private function baseMetricas(string $grupoExpr, string $alias): \Illuminate\Database\Query\Builder
    {
        return DB::table('dropi_pedidos')
            ->whereNull('deleted_at')
            ->selectRaw("$grupoExpr AS $alias,
                COUNT(*) AS ordenes,
                SUM(estado IN ('entregado','pagado')) AS entregadas,
                SUM(estado IN ('devuelto','devolucion_en_camino')) AS devueltas,
                SUM(estado IN ('cancelado_dropi','cancelado_gb')) AS canceladas,
                SUM(estado = 'despachado') AS en_ruta,
                SUM(CASE WHEN estado IN ('entregado','pagado') THEN monto_cliente_final ELSE 0 END) AS recaudado,
                SUM(CASE WHEN estado IN ('entregado','pagado') THEN monto_esperado_proveedor ELSE 0 END) AS bodega")
            ->groupBy($alias);
    }

    private function conPct(array $rows): array
    {
        foreach ($rows as $r) {
            $base = (int) $r->entregadas + (int) $r->devueltas;
            $r->pct_entrega = $base ? round($r->entregadas / $base * 100, 1) : 0.0;
            $r->pct_devol = $base ? round($r->devueltas / $base * 100, 1) : 0.0;
            $r->otros = (int) $r->ordenes - (int) $r->entregadas - (int) $r->devueltas;
        }
        return $rows;
    }

    public function transportadoras(): array
    {
        return $this->conPct(
            $this->baseMetricas("COALESCE(NULLIF(transportadora,''),'(sin)')", 'transportadora')
                ->orderByDesc('ordenes')->get()->all()
        );
    }

    public function departamentos(): array
    {
        return $this->conPct(
            $this->baseMetricas("COALESCE(NULLIF(cliente_depto,''),'(sin)')", 'depto')
                ->orderByDesc('ordenes')->limit(15)->get()->all()
        );
    }

    public function tiendas(): array
    {
        $b = $this->baseMetricas("COALESCE(NULLIF(tienda,''),'(sin tienda)')", 'tienda');
        if (mb_strlen(trim($this->tiendaQ)) >= 2) {
            $b->where('tienda', 'like', '%' . trim($this->tiendaQ) . '%');
        }
        return $this->conPct($b->orderByDesc('bodega')->limit(60)->get()->all());
    }

    public function topProductos(): array
    {
        return DB::table('dropi_pedido_items as i')
            ->join('dropi_pedidos as p', 'p.id', '=', 'i.pedido_id')
            ->where('p.estado', 'entregado')
            ->whereNull('p.deleted_at')->whereNull('i.deleted_at')
            ->whereNotNull('i.producto_nombre')
            ->selectRaw('i.producto_nombre, SUM(i.cantidad) AS unidades')
            ->groupBy('i.producto_nombre')->orderByDesc('unidades')->limit(10)->get()->all();
    }

    public function resultados(): array
    {
        $q = trim($this->q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . $q . '%';

        return DB::table('dropi_pedidos as p')
            ->leftJoin('dropi_pedido_items as i', 'i.pedido_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->where(function ($w) use ($like) {
                $w->where('p.guia', 'like', $like)
                    ->orWhere('p.tienda', 'like', $like)
                    ->orWhere('p.cliente_nombre', 'like', $like)
                    ->orWhere('i.producto_nombre', 'like', $like);
            })
            ->selectRaw('p.id, p.guia, p.tienda, p.cliente_nombre, p.cliente_ciudad,
                p.estado, p.monto_cliente_final AS venta, MAX(i.producto_nombre) AS producto')
            ->groupBy('p.id', 'p.guia', 'p.tienda', 'p.cliente_nombre', 'p.cliente_ciudad', 'p.estado', 'p.monto_cliente_final')
            ->orderByDesc('p.id')->limit(40)->get()->all();
    }
}
