<?php

namespace App\Modules\Dropi\Filament\Support;

use App\Http\Controllers\Catalogo\EtiquetasMasivasController;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\CatalogoDropi;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * «Imprimir etiquetas» en el panel Dropi: el mismo selector de la ficha de /app
 * (SelectorEtiquetas.vue) —el producto original, las variantes o los colores de
 * Dropi, y cuántas de cada una— en una ventana de Filament (25-nov-2026: en el
 * panel sólo se imprimía una de cada una).
 *
 * El producto es el registro de la fila o de la página, o el argumento
 * `producto` (desde «Mis productos en Dropi»). El PDF se abre desde el aviso que
 * sale al confirmar: abrirlo solo, después de ir al servidor, el navegador lo
 * bloquea como ventana emergente.
 */
class ImprimirEtiquetasAccion
{
    public const NOMBRE = 'imprimirEtiquetas';

    public static function make(): Action
    {
        return Action::make(self::NOMBRE)
            ->label('Imprimir etiquetas')
            ->icon('heroicon-o-printer')
            ->color('warning')
            ->modalHeading(fn (array $arguments, ?Model $record) => 'Imprimir etiquetas · ' . (self::producto($arguments, $record)?->referencia ?? ''))
            ->modalDescription('Siempre en el rollo de la térmica. «Cuántas de cada una» las pone todas iguales; al lado de cada una puedes cambiar la suya.')
            ->modalSubmitActionLabel('Preparar etiquetas')
            ->modalWidth('lg')
            ->schema(fn (array $arguments, ?Model $record) => self::campos(self::opciones($arguments, $record)))
            ->action(function (array $data, array $arguments, ?Model $record): void {
                $o = self::opciones($arguments, $record);
                $copias = self::limitar($data['copias'] ?? 1);
                $original = (bool) ($data['original'] ?? false);
                $variantes = collect($o['variantes'])->filter(fn ($v) => $data["var_{$v['id']}"] ?? false)->pluck('id')->all();
                $colores = collect($o['colores'])->filter(fn ($c, $i) => $data["color_{$i}"] ?? false)->values()->all();

                $cant = [];
                if ($original) {
                    $cant['original'] = self::limitar($data['cant_original'] ?? $copias);
                }
                foreach ($variantes as $id) {
                    $cant["v:{$id}"] = self::limitar($data["cant_v_{$id}"] ?? $copias);
                }
                foreach ($o['colores'] as $i => $c) {
                    if (in_array($c, $colores, true)) {
                        $cant["c:{$c}"] = self::limitar($data["cant_c_{$i}"] ?? $copias);
                    }
                }

                if ($cant === []) {
                    Notification::make()->title('Escoge al menos una etiqueta para imprimir.')->warning()->send();

                    return;
                }

                $url = EtiquetasMasivasController::urlSeleccion($o['producto']['id'], $original, $variantes, $colores, $copias, $cant);
                $total = array_sum($cant);

                Notification::make()
                    ->title("{$total} etiqueta(s) de {$o['producto']['referencia']} listas")
                    ->body('Ábrelas e imprímelas en la térmica.')
                    ->success()
                    ->persistent()
                    ->actions([
                        Action::make('abrir')->label('🖨 Abrir etiquetas')->button()
                            ->url($url, shouldOpenInNewTab: true)->close(),
                    ])
                    ->send();
            });
    }

    private static function producto(array $arguments, ?Model $record): ?Producto
    {
        if ($record instanceof Producto) {
            return $record;
        }

        return isset($arguments['producto']) ? Producto::find((int) $arguments['producto']) : null;
    }

    private static function opciones(array $arguments, ?Model $record): array
    {
        $p = self::producto($arguments, $record);
        abort_unless($p, 404, 'Ese producto ya no está en el ERP.');

        return EtiquetasMasivasController::opcionesDe($p, app(CatalogoDropi::class));
    }

    private static function limitar(mixed $n): int
    {
        return min(500, max(1, (int) $n));
    }

    /** Una casilla por etiqueta y, al marcarla, su cantidad al lado. */
    private static function campos(array $o): array
    {
        $cantidades = ['cant_original'];
        foreach ($o['variantes'] as $v) {
            $cantidades[] = "cant_v_{$v['id']}";
        }
        foreach (array_keys($o['colores']) as $i) {
            $cantidades[] = "cant_c_{$i}";
        }

        $fila = fn (string $casilla, string $cantidad, string $etiqueta, bool $marcada, ?string $ayuda = null) => Grid::make(4)->schema([
            Checkbox::make($casilla)->label($etiqueta)->helperText($ayuda)->default($marcada)->live()->columnSpan(3),
            TextInput::make($cantidad)->label("Cuántas de {$etiqueta}")->hiddenLabel()->numeric()->integer()->minValue(1)->maxValue(500)->default(1)
                ->prefix('×')->visible(fn (Get $get) => (bool) $get($casilla))->columnSpan(1),
        ]);

        $sinOtras = $o['variantes'] === [] && $o['colores'] === [];

        $campos = [
            TextInput::make('copias')->label('Cuántas de cada una')->numeric()->integer()->minValue(1)->maxValue(500)->default(1)
                ->live(debounce: 400)
                ->afterStateUpdated(function ($state, Set $set) use ($cantidades) {
                    foreach ($cantidades as $c) {
                        $set($c, self::limitar($state));
                    }
                }),
            $fila('original', 'cant_original', "Producto original · {$o['producto']['referencia']}", $sinOtras,
                'Una etiqueta con la referencia del producto, sin color.'),
        ];

        if ($o['variantes'] !== []) {
            $campos[] = Section::make('Variantes (' . count($o['variantes']) . ')')->compact()->schema(
                array_map(fn ($v) => $fila("var_{$v['id']}", "cant_v_{$v['id']}", "{$v['nombre']} · {$v['codigo']}", true), $o['variantes'])
            );
        }

        if ($o['colores'] !== []) {
            $campos[] = Section::make('Colores de Dropi (' . count($o['colores']) . ')')->compact()
                ->description('Llevan el color escrito y el mismo código de la referencia: este producto no tiene variantes.')
                ->schema(array_map(fn ($c, $i) => $fila("color_{$i}", "cant_c_{$i}", $c, true), $o['colores'], array_keys($o['colores'])));
        }

        return $campos;
    }
}
