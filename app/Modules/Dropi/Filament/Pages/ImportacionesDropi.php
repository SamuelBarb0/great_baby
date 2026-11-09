<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Models\User;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Services\SeguimientoContenedor;
use App\Modules\Dropi\Filament\Support\ComprasDropi;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * «Importaciones» de la línea Dropi: los contenedores, sus gastos y su
 * liquidación. Los indicadores son los mismos de /app, contados sólo en Dropi.
 */
class ImportacionesDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-asia-australia';

    protected static ?string $navigationLabel = 'Importaciones';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Importaciones · Dropi';

    protected static ?string $slug = 'compras-importaciones-dropi';

    protected static function rutaEnErp(): string
    {
        return '/app/compras/importacion';
    }

    protected static function puedeVer(User $u): bool
    {
        // ComprasGestionController::importacionIndex.
        return ComprasDropi::puedeImportar($u);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nueva')->label('Nueva importación')->icon('heroicon-o-plus')
                ->url(fn () => NuevaImportacionDropi::getUrl()),
            ...parent::getHeaderActions(),
        ];
    }

    public function resumen(): array
    {
        $dropi = fn () => static::ejesDropi()->aplicar(Importacion::query());
        $seguimiento = app(SeguimientoContenedor::class);

        return [
            ['label' => 'Contenedores', 'valor' => $dropi()->count()],
            // Como en /app: todos los estados intermedios cuentan como en camino.
            ['label' => 'En tránsito', 'valor' => $dropi()->whereIn('estado', ['en_transito', 'en_puerto', 'nacionalizada'])->count()],
            ['label' => 'Por liquidar', 'valor' => $dropi()->whereNotNull('fecha_llegada')->whereNull('fecha_liquidacion')->count()],
            ['label' => 'Atrasados', 'valor' => $atrasados = $dropi()->where('estado', '!=', 'cerrada')->whereHas('etapas')->with('etapas')->get()
                ->filter(fn ($i) => $seguimiento->resumen($i)['dias_atraso'] > 0)->count(), 'alerta' => $atrasados > 0],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::ejesDropi()->aplicar(Importacion::query()->withCount('ordenes')))
            ->columns([
                TextColumn::make('numero')->label('Importación')->searchable()->sortable(),
                TextColumn::make('contenedor')->searchable()->placeholder('—'),
                TextColumn::make('bl_awb')->label('BL / AWB')->searchable()->placeholder('—'),
                TextColumn::make('moneda_origen')->label('Moneda'),
                TextColumn::make('ordenes_count')->label('Órdenes')->alignEnd(),
                TextColumn::make('eta')->label('ETA')->date('Y-m-d')->sortable()->placeholder('—'),
                TextColumn::make('fecha_liquidacion')->label('Liquidada')->date('Y-m-d')->placeholder('—'),
                TextColumn::make('estado')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof EstadoImportacion ? $state->label() : (string) $state)
                    ->color(fn ($state) => $state instanceof EstadoImportacion ? $state->color() : 'gray'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(collect(EstadoImportacion::cases())
                    ->mapWithKeys(fn (EstadoImportacion $e) => [$e->value => $e->label()])->all()),
            ])
            ->recordUrl(fn (Importacion $record) => VerImportacionDropi::getUrl(['importacion' => $record->id]))
            ->emptyStateHeading('No hay importaciones de Dropi');
    }
}
