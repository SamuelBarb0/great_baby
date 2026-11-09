<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\User;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/** «Libro contable»: los asientos de la línea Dropi, por fecha y cuenta. */
class LibroContableDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Libro contable';

    protected static string|\UnitEnum|null $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Libro contable · Dropi';

    protected static ?string $slug = 'contabilidad-libro-dropi';

    /**
     * Los filtros viajan en la URL, como en las tablas de recursos: así la
     * wallet y el cruce enlazan directo a los asientos de un movimiento.
     *
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    /**
     * Enlace al libro con los asientos de la wallet: los de un movimiento, o
     * todos (los del movimiento y los recibos de caja pagados desde la wallet).
     * Sin fecha: el asiento de un movimiento viejo no debe quedar escondido
     * detrás del filtro «este mes».
     */
    public static function urlDeWallet(?int $movimientoId = null): string
    {
        return static::getUrl().'?'.http_build_query(['filters' => [
            'periodo' => ['desde' => '', 'hasta' => ''],
            'wallet' => $movimientoId ? ['movimiento' => $movimientoId] : ['solo' => 1],
        ]]);
    }

    protected static function rutaEnErp(): string
    {
        return '/app/contabilidad';
    }

    protected static function puedeVer(User $u): bool
    {
        // ContabilidadController.
        return $u->esContable();
    }

    public function table(Table $table): Table
    {
        $cop = fn () => Sum::make()->money('COP')->label('Total');

        return $table
            ->query(fn () => static::ejesDropi()->aplicar(MovimientoContable::query()->with('user:id,name')))
            ->columns([
                TextColumn::make('fecha')->date('Y-m-d')->sortable(),
                TextColumn::make('cuenta_puc')->label('Cuenta')->searchable()->sortable(),
                TextColumn::make('descripcion')->label('Descripción')->searchable()->wrap(),
                TextColumn::make('origen_type')->label('Origen')
                    ->formatStateUsing(fn ($state) => match ((string) $state) {
                        DropiWalletMovimiento::class => 'Wallet Dropi',
                        PagoVenta::class => 'Recibo de caja',
                        default => class_basename((string) $state),
                    })->toggleable(),
                TextColumn::make('debe')->money('COP')->alignEnd()->summarize($cop()),
                TextColumn::make('haber')->money('COP')->alignEnd()->summarize($cop()),
                TextColumn::make('user.name')->label('Usuario')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('fecha')->orderByDesc('id'))
            ->recordUrl(fn (MovimientoContable $m) => $this->urlDelOrigen($m), shouldOpenInNewTab: true)
            ->filters([
                Filter::make('wallet')
                    ->label('Wallet Dropi')
                    ->schema([
                        Checkbox::make('solo')->label('Sólo la wallet Dropi (movimientos y recibos de caja)'),
                        TextInput::make('movimiento')->label('Movimiento de la wallet #')->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['movimiento'] ?? null, fn ($q, $id) => $q
                            ->where('origen_type', DropiWalletMovimiento::class)->where('origen_id', (int) $id))
                        ->when(! ($data['movimiento'] ?? null) && ($data['solo'] ?? false), fn ($q) => $q
                            ->where(fn ($w) => $w->where('origen_type', DropiWalletMovimiento::class)
                                ->orWhere(fn ($r) => $r->where('origen_type', PagoVenta::class)->whereIn('origen_id',
                                    PagoVenta::withTrashed()->select('id')->where('medio_pago', 'wallet_dropi')))))),
                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('desde')->default(now('America/Bogota')->startOfMonth()),
                        DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
                        ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '<=', $d))),
            ])
            ->emptyStateHeading('No hay asientos de Dropi en ese periodo');
    }

    /** De un asiento a lo que lo generó: el movimiento de la wallet o la factura del recibo. */
    protected function urlDelOrigen(MovimientoContable $m): ?string
    {
        return match ($m->origen_type) {
            DropiWalletMovimiento::class => DropiWalletMovimientoResource::urlDelMovimiento((int) $m->origen_id),
            PagoVenta::class => ($f = PagoVenta::withTrashed()->whereKey($m->origen_id)->value('factura_id'))
                ? '/app/facturas/'.$f : null,
            default => null,
        };
    }
}
