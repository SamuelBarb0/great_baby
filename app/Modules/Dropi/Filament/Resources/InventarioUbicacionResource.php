<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Filament\Resources\InventarioUbicacionResource\Pages;
use App\Modules\Dropi\Models\InventarioUbicacion;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use App\Support\FilamentPolicy\HeredaAutorizacion;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InventarioUbicacionResource extends Resource
{
    use HeredaAutorizacion;

    protected static ?string $model = InventarioUbicacion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Ubicaciones';

    protected static ?string $modelLabel = 'Ubicación';

    protected static ?string $pluralModelLabel = 'Ubicaciones de inventario';

    protected static string|\UnitEnum|null $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'ubicaciones';

    /**
     * Fuera del panel: las bodegas y ubicaciones se manejan en Vue, en
     * `/app/inventario/ubicaciones`. Está en la carpeta de Dropi por historia,
     * pero es inventario general y ninguna pantalla Dropi lo referencia.
     */
    public static function canViewAny(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('codigo')->required()->unique(ignoreRecord: true)
                ->helperText('Ej: R-A-01 (rack-pasillo-nivel), AVR-01, GAR-01, RES-PROV-01'),
            TextInput::make('nombre')->required(),
            Select::make('categoria')
                ->options(collect(CategoriaUbicacion::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->required()->live(),
            Toggle::make('activa')->default(true),
            Textarea::make('notas')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')->badge()->weight('bold')->copyable(),
                TextColumn::make('nombre')->searchable(),
                BadgeColumn::make('categoria')
                    ->formatStateUsing(fn ($state) => $state instanceof CategoriaUbicacion ? $state->label() : $state)
                    ->color(fn ($state) => match ($state instanceof CategoriaUbicacion ? $state->value : $state) {
                        'venta' => 'success',
                        'reserva_proveedor' => 'info',
                        'garantia' => 'warning',
                        'averia_reparar', 'averia_baja' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('disponible_para_venta')->boolean()->label('Venta'),
                IconColumn::make('activa')->boolean(),
            ])
            ->defaultSort('codigo')
            ->filters([
                SelectFilter::make('categoria')
                    ->options(collect(CategoriaUbicacion::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventarioUbicaciones::route('/'),
            'create' => Pages\CreateInventarioUbicacion::route('/create'),
            'edit' => Pages\EditInventarioUbicacion::route('/{record}/edit'),
        ];
    }
}
