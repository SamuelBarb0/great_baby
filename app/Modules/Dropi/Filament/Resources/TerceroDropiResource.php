<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Modules\Dropi\Filament\Resources\TerceroDropiResource\Pages;
use App\Modules\Dropi\Filament\Support\ComprasDropi;
use App\Support\FilamentPolicy\HeredaAutorizacion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «Clientes y proveedores» de Dropi (23-nov-2026).
 *
 * Dropi es la misma empresa ante la DIAN pero internamente va aparte: crea y
 * ve SUS terceros. La ficha es una por documento (RegistrarTerceroDropi): si
 * el NIT ya lo tenía el ERP, se comparte; si no, nace en Dropi y también en el
 * ERP (25-nov-2026).
 *
 * No se borra nada desde acá: «Quitar de Dropi» sólo lo saca de esta lista
 * (la ficha puede tener compras, facturas o estar en el ERP).
 */
class TerceroDropiResource extends Resource
{
    use HeredaAutorizacion;

    protected static ?string $model = Contacto::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clientes y proveedores';

    protected static ?string $modelLabel = 'tercero';

    protected static ?string $pluralModelLabel = 'Clientes y proveedores · Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'terceros-dropi';

    protected static bool $hasTitleCaseModelLabel = false;

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u !== null && (ComprasDropi::puedeArmar($u) || Permisos::puede($u, 'dropi_reportes'));
    }

    /** Desde Dropi no se borra: se saca de Dropi. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * La ficha es una sola para Dropi y el ERP (25-nov-2026): lo que se corrige
     * aquí se ve en los dos. Antes la compartida sólo la editaba el ERP, pero
     * ahora todas son compartidas y Dropi se quedaba sin poder editar las suyas.
     */
    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->deDropi();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre_completo')->label('Nombre o razón social')->required()->maxLength(180),
            Select::make('tipo_documento')->label('Tipo de documento')
                ->options(['NIT' => 'NIT', 'CC' => 'Cédula', 'CE' => 'Cédula de extranjería', 'PP' => 'Pasaporte'])
                ->default('NIT')->required(),
            TextInput::make('numero_documento')->label('Número de documento')->required()->maxLength(30)
                ->disabledOn('edit')->dehydrated(fn (string $operation) => $operation === 'create'),
            TextInput::make('email')->label('Correo')->email()->maxLength(120),
            TextInput::make('telefono')->label('Teléfono')->maxLength(30),
            TextInput::make('direccion')->label('Dirección')->maxLength(200),
            TextInput::make('ciudad')->label('Ciudad')->maxLength(80),
            Toggle::make('es_proveedor')->label('Es proveedor')->default(true),
            Toggle::make('es_cliente')->label('Es cliente'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre_completo')->label('Nombre')->searchable(['nombre_completo', 'razon_social'])->sortable(),
                TextColumn::make('numero_documento')->label('Documento')->searchable()
                    ->formatStateUsing(fn ($state, Contacto $record) => trim(($record->tipo_documento ?? '').' '.$state)),
                IconColumn::make('es_proveedor')->label('Proveedor')->boolean(),
                IconColumn::make('es_cliente')->label('Cliente')->boolean(),
                TextColumn::make('telefono')->label('Teléfono')->toggleable(),
                TextColumn::make('ciudad')->label('Ciudad')->toggleable(),
            ])
            ->defaultSort('nombre_completo')
            ->filters([
                TernaryFilter::make('es_proveedor')->label('Proveedores'),
                TernaryFilter::make('es_cliente')->label('Clientes'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('quitar')->label('Quitar de Dropi')->icon('heroicon-o-minus-circle')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Deja de aparecer en Dropi; en el ERP sigue. No se borra: sus compras y facturas siguen intactas.')
                    ->visible(fn (Contacto $record) => (bool) $record->en_erp)
                    ->action(fn (Contacto $record) => $record->update(['en_dropi' => false])),
            ])
            ->emptyStateHeading('Todavía no hay clientes ni proveedores de Dropi');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTercerosDropi::route('/'),
            'create' => Pages\CreateTerceroDropi::route('/create'),
            'edit' => Pages\EditTerceroDropi::route('/{record}/edit'),
        ];
    }
}
