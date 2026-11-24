<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Dropi\Actions\SincronizarManifiestosDropi;
use App\Modules\Dropi\Filament\Support\PaginaTablaDropi;
use App\Modules\Dropi\Models\DropiManifiesto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;

/**
 * «Manifiestos»: cada entrega de paquetes a una transportadora (Dropi →
 * Logistic Management → Salidas), con su PDF y su Excel guardados en el ERP y
 * un «Revisado» como checklist (reunión con la señora Lahida, 24-nov-2026).
 *
 * Buscar por guía dice en qué manifiesto salió un paquete: la constancia para
 * reclamarle a la transportadora uno perdido.
 */
class ManifiestosDropi extends PaginaTablaDropi
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Manifiestos';

    protected static string|\UnitEnum|null $navigationGroup = 'Operación';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Manifiestos de transportadoras';

    protected static ?string $slug = 'manifiestos-dropi';

    protected static function rutaEnErp(): string
    {
        return '/admin/manifiestos-dropi';
    }

    /** Los manifiestos traen nombres y direcciones de clientes. */
    protected static function puedeVer(User $u): bool
    {
        return Permisos::puede($u, 'dropi_cambiar_en_dropi');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('traer')->label('Traer de Dropi ahora')->icon('heroicon-o-arrow-path')->color('gray')
                ->action(function () {
                    $clave = 'dropi-manifiestos:' . auth()->id();
                    if (RateLimiter::tooManyAttempts($clave, 2)) {
                        Notification::make()->title('Espera un momento')->body('Reintenta en ' . RateLimiter::availableIn($clave) . ' s.')->warning()->send();

                        return;
                    }
                    RateLimiter::hit($clave, 60);
                    try {
                        $r = app(SincronizarManifiestosDropi::class)->handle(2);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Dropi no respondió')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    $n = Notification::make()->title("{$r['nuevos']} manifiesto(s) nuevo(s)")->body("{$r['archivos']} archivo(s) guardado(s).");
                    ($r['errores'] ? $n->warning()->body(implode(' | ', array_slice($r['errores'], 0, 3))) : $n->success())->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $descarga = fn (string $tipo) => Action::make($tipo)
            ->label($tipo === 'pdf' ? 'PDF' : 'Excel')
            ->icon($tipo === 'pdf' ? 'heroicon-o-document' : 'heroicon-o-table-cells')
            ->color('gray')->size('sm')
            ->url(fn (DropiManifiesto $record) => route('dropi.manifiestos.archivo', [$record, $tipo]), shouldOpenInNewTab: true)
            ->visible(fn (DropiManifiesto $record) => filled($record->{$tipo === 'pdf' ? 'pdf_path' : 'excel_path'}));

        return $table
            ->query(DropiManifiesto::query())
            ->defaultSort('dropi_creado_at', 'desc')
            ->columns([
                TextColumn::make('dropi_creado_at')->label('Fecha')->dateTime('d/m/Y g:i a', 'America/Bogota')->sortable(),
                TextColumn::make('transportadora')->badge()->color('gray'),
                TextColumn::make('pedidos')->label('Paquetes')->alignEnd()->sortable(),
                TextColumn::make('dropi_id')->label('Salida en Dropi')->copyable()->color('gray'),
                TextColumn::make('usuario_dropi')->label('Hecha por')->toggleable(),
                TextColumn::make('error_archivos')->label('Archivos')->placeholder('Guardados')->color('danger')->limit(40)->toggleable(),
                IconColumn::make('revisado_at')->label('Revisado')->boolean()
                    ->state(fn (DropiManifiesto $record) => $record->revisado_at !== null)
                    ->tooltip(fn (DropiManifiesto $record) => $record->revisado_at
                        ? 'Por ' . ($record->revisadoPor?->name ?? '—') . ' · ' . $record->revisado_at->timezone('America/Bogota')->format('d/m g:i a')
                        : null),
            ])
            ->filters([
                SelectFilter::make('transportadora')
                    ->options(fn () => DropiManifiesto::query()->whereNotNull('transportadora')->distinct()->orderBy('transportadora')->pluck('transportadora', 'transportadora')->all()),
                Filter::make('guia')
                    ->schema([\Filament\Forms\Components\TextInput::make('guia')->label('Guía del paquete')])
                    ->query(fn (Builder $query, array $data) => $query->when(trim((string) ($data['guia'] ?? '')), fn ($q, $g) => $q->conGuia($g)))
                    ->indicateUsing(fn (array $data) => filled($data['guia'] ?? null) ? ['Guía ' . $data['guia']] : []),
                Filter::make('fecha')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('desde')->native(false),
                        \Filament\Forms\Components\DatePicker::make('hasta')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('dropi_creado_at', '>=', $d))
                        ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('dropi_creado_at', '<=', $d))),
                SelectFilter::make('revisado')->label('Revisado')
                    ->options(['no' => 'Sin revisar', 'si' => 'Revisados'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'no' => $query->whereNull('revisado_at'),
                        'si' => $query->whereNotNull('revisado_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                $descarga('pdf'),
                $descarga('excel'),
                Action::make('revisar')
                    ->label(fn (DropiManifiesto $record) => $record->revisado_at ? 'Quitar revisado' : 'Marcar revisado')
                    ->icon(fn (DropiManifiesto $record) => $record->revisado_at ? 'heroicon-o-x-mark' : 'heroicon-o-check')
                    ->color(fn (DropiManifiesto $record) => $record->revisado_at ? 'gray' : 'success')
                    ->size('sm')
                    ->action(fn (DropiManifiesto $record) => $record->forceFill($record->revisado_at
                        ? ['revisado_at' => null, 'revisado_por' => null]
                        : ['revisado_at' => now(), 'revisado_por' => auth()->id()])->save()),
            ]);
    }
}
