<?php

namespace App\Modules\Dropi\Filament\Resources;

use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Filament\Resources\DropiDevolucionResource\Pages;
use App\Modules\Dropi\Models\DropiDevolucion;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use App\Support\FilamentPolicy\HeredaAutorizacion;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DropiDevolucionResource extends Resource
{
    use HeredaAutorizacion;

    protected static ?string $model = DropiDevolucion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Devoluciones';

    protected static ?string $modelLabel = 'Devolución';

    protected static ?string $pluralModelLabel = 'Devoluciones Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Operación';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'dropi-devoluciones';

    // §24 — Alistador registra, Aracely/SAC ven, Alistador puede leer también
    public static function canViewAny(): bool
    {
        // La casilla «Devoluciones Dropi» manda (gerencia, alistador, SAC y
        //   perfiles como «Operador Dropi»); antes era una lista de roles.
        return \App\Auth\Permisos::puede(auth()->user(), 'dropi_devoluciones');
    }

    // Re-audit DR-γ (SEG-C3) · SAC/Alistador NO deben editar destino_inventario:
    //   reingreso vs averia_baja mueve el kardex real. Sólo Aracely edita.
    public static function canCreate(): bool
    {
        $u = auth()->user();
        return $u && ($u->esAracely() || $u->esAlistador());
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    // Cerrar bulk-delete accidental por HeredaAutorizacion en un futuro.
    public static function canDeleteAny(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('pedido.guia')
                ->label('Guía')
                ->disabled(),
            Select::make('destino_inventario')
                ->options(collect(DestinoDevolucion::cases())->mapWithKeys(fn ($d) => [$d->value => $d->label()]))
                ->required(),
            Textarea::make('notas')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pedido.guia')->label('Guía')->weight('bold')->searchable()->copyable(),
                TextColumn::make('pedido.cliente_nombre')->label('Cliente')->limit(25),
                TextColumn::make('recibido_at')->label('Recibido')->dateTime('Y-m-d H:i')->sortable(),
                BadgeColumn::make('destino_inventario')
                    ->label('Destino')
                    ->formatStateUsing(fn ($state) => DestinoDevolucion::from($state)->label())
                    ->color(fn ($state) => match ($state) {
                        'reingreso' => 'success',
                        'averia_reparar' => 'warning',
                        'averia_baja', 'baja_total' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('genero_nota_credito')
                    ->label('NC')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Sí' : 'No')
                    ->color(fn ($state) => $state ? 'info' : 'gray'),
                TextColumn::make('decidioAlistador.name')->label('Decidió')->toggleable(),
            ])
            ->defaultSort('recibido_at', 'desc')
            ->filters([
                SelectFilter::make('destino_inventario')
                    ->options(collect(DestinoDevolucion::cases())->mapWithKeys(fn ($d) => [$d->value => $d->label()])),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction::make()
                        ->exports([
                            \pxlrbt\FilamentExcel\Exports\ExcelExport::make('devoluciones')
                                ->fromTable()
                                ->withFilename('devoluciones-dropi-' . now()->format('Y-m-d')),
                        ]),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropiDevoluciones::route('/'),
            'registrar' => Pages\RegistrarDevolucion::route('/registrar'),
        ];
    }
}
