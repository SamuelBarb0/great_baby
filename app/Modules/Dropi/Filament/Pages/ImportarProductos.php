<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class ImportarProductos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Importar productos';

    protected static string|\UnitEnum|null $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Importar productos desde Excel/CSV';

    protected string $view = 'dropi.pages.importar-productos';

    public ?array $data = [];

    /** @var array<int, array{fila:int, sku:string, mensaje:string, tipo:string}> */
    public array $errores = [];

    /** @var array<string, int> */
    public array $preview = [];

    public bool $previewCalculado = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('archivo')
                ->label('Archivo Excel (.xlsx) o CSV')
                ->acceptedFileTypes([
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/csv',
                    'application/csv',
                    'application/octet-stream',
                ])
                ->disk('local')->directory('imports')
                ->required(),
        ])->statePath('data');
    }

    protected function abrirLector(): array
    {
        $data = $this->form->getState();
        $rel = $data['archivo'] ?? null;
        if (! $rel) {
            throw new \RuntimeException('Sube un archivo primero.');
        }
        $paths = [
            storage_path('app/private/' . $rel),
            storage_path('app/' . $rel),
        ];
        $path = collect($paths)->first(fn ($p) => file_exists($p));
        if (! $path) {
            throw new \RuntimeException('No se encontró el archivo cargado.');
        }
        return [$this->crearLector($path), $path];
    }

    /**
     * Elige el lector según extensión (openspout v4, sin ReaderEntityFactory).
     * Para CSV auto-detecta el separador (Excel en es-CO suele exportar con `;`).
     */
    protected function crearLector(string $ruta): ReaderInterface
    {
        $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            $options = new CsvOptions();
            $options->FIELD_DELIMITER = $this->detectarDelimitador($ruta);
            return new CsvReader($options);
        }

        return new XlsxReader();
    }

    protected function detectarDelimitador(string $ruta): string
    {
        $primera = '';
        if ($h = @fopen($ruta, 'r')) {
            $primera = (string) fgets($h);
            fclose($h);
        }
        // BOM fuera.
        $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera);

        return substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    }

    public function previsualizar(): void
    {
        try {
            [$reader, $path] = $this->abrirLector();
            $reader->open($path);

            $nuevos = 0; $actualizados = 0; $variantes = 0; $errores = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $i => $row) {
                    $cells = array_map(fn ($c) => trim((string) $c->getValue()), $row->getCells());
                    if ($i === 1) {
                        $header = array_map(fn ($h) => strtolower(trim($h)), $cells);
                        if (! in_array('referencia', $header) || ! in_array('nombre', $header)) {
                            $errores[] = ['fila' => 1, 'sku' => '—', 'mensaje' => 'Faltan columnas obligatorias: referencia y nombre.', 'tipo' => 'error'];
                            break;
                        }
                        continue;
                    }
                    if (empty(array_filter($cells))) continue;

                    $r = @array_combine(array_pad($header, count($cells), ''), array_pad($cells, count($header), ''));
                    if (empty($r['referencia'])) {
                        $errores[] = ['fila' => $i, 'sku' => '—', 'mensaje' => 'Referencia vacía.', 'tipo' => 'error'];
                        continue;
                    }
                    if (empty($r['nombre'])) {
                        $errores[] = ['fila' => $i, 'sku' => $r['referencia'], 'mensaje' => 'Nombre vacío.', 'tipo' => 'error'];
                        continue;
                    }
                    $existe = Producto::where('referencia', $r['referencia'])->exists();
                    $existe ? $actualizados++ : $nuevos++;
                    if (! empty($r['color_codigo']) || ! empty($r['diseno_codigo']) || ! empty($r['talla'])) {
                        $variantes++;
                    }
                }
            }

            $reader->close();

            $this->preview = ['nuevos' => $nuevos, 'actualizados' => $actualizados, 'variantes' => $variantes];
            $this->errores = $errores;
            $this->previewCalculado = true;
        } catch (\Throwable $e) {
            Notification::make()->title('Error al leer el archivo')->body($e->getMessage())->danger()->send();
        }
    }

    public function procesar(): void
    {
        try {
            [$reader, $path] = $this->abrirLector();
            $reader->open($path);

            $creados = 0; $actualizados = 0; $variantes = 0; $variantesCreadas = 0; $variantesActualizadas = 0; $errores = [];

            DB::transaction(function () use ($reader, &$creados, &$actualizados, &$variantes, &$variantesCreadas, &$variantesActualizadas, &$errores) {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $header = null;
                    foreach ($sheet->getRowIterator() as $i => $row) {
                        $cells = array_map(fn ($c) => trim((string) $c->getValue()), $row->getCells());
                        if ($i === 1) {
                            $header = array_map(fn ($h) => strtolower(trim($h)), $cells);
                            continue;
                        }
                        if (empty(array_filter($cells))) continue;

                        try {
                            $r = @array_combine(array_pad($header, count($cells), ''), array_pad($cells, count($header), ''));
                            if (empty($r['referencia']) || empty($r['nombre'])) {
                                throw new \RuntimeException('referencia y/o nombre vacíos');
                            }
                            $producto = Producto::updateOrCreate(
                                ['referencia' => $r['referencia']],
                                [
                                    'nombre' => $r['nombre'],
                                    'categoria' => $r['categoria'] ?? null,
                                    'precio_proveedor' => (float) ($r['precio_proveedor'] ?? 0),
                                    'requiere_talla' => filter_var($r['requiere_talla'] ?? false, FILTER_VALIDATE_BOOL),
                                    'es_set' => filter_var($r['es_set'] ?? false, FILTER_VALIDATE_BOOL),
                                    'activo' => filter_var($r['activo'] ?? true, FILTER_VALIDATE_BOOL),
                                ]
                            );
                            $producto->wasRecentlyCreated ? $creados++ : $actualizados++;

                            if (! empty($r['color_codigo']) || ! empty($r['diseno_codigo']) || ! empty($r['talla'])) {
                                // REU-4: preservar código de barras existente para re-imports.
                                $preservar = (bool) setting('catalogo.preservar_codigo_china', true);
                                $bloquearCambio = (bool) setting('catalogo.bloquear_cambio_codigo', true);

                                // Normalizar '' → NULL para matchear filas legacy con NULL.
                                $normalizar = fn ($v) => ($v === '' || $v === null) ? null : trim((string) $v);
                                $colorRef = $normalizar($r['color_codigo'] ?? null);
                                $disenoRef = $normalizar($r['diseno_codigo'] ?? null);
                                $tallaRef = $normalizar($r['talla'] ?? null);

                                // 1) Buscar variante existente por combinación (usa whereNull donde corresponde).
                                $existente = ProductoVariante::where('producto_id', $producto->id)
                                    ->when($colorRef === null, fn ($q) => $q->whereNull('color_codigo'), fn ($q) => $q->where('color_codigo', $colorRef))
                                    ->when($disenoRef === null, fn ($q) => $q->whereNull('diseno_codigo'), fn ($q) => $q->where('diseno_codigo', $disenoRef))
                                    ->when($tallaRef === null, fn ($q) => $q->whereNull('talla'), fn ($q) => $q->where('talla', $tallaRef))
                                    ->first();

                                // 2) Determinar el código a usar:
                                //    - Si viene explícito en el Excel (columna 'codigo_barras' - venido de China), respetar.
                                //    - Si existe la variante y preservar=true, reusar el suyo.
                                //    - Si no, generar uno nuevo.
                                if (! empty($r['codigo_barras'])) {
                                    $codigo = trim((string) $r['codigo_barras']);
                                } elseif ($existente && $preservar) {
                                    $codigo = $existente->codigo_barras;
                                } else {
                                    $codigo = ProductoVariante::generarCodigoBarras(
                                        $producto->referencia,
                                        $colorRef,
                                        $disenoRef,
                                        $tallaRef,
                                    );
                                }

                                // 3) Guardar. Si existe + bloquearCambio, no pisamos el codigo_barras.
                                $dataAttrs = [
                                    'color_nombre' => $r['color_nombre'] ?? null,
                                    'diseno_nombre' => $r['diseno_nombre'] ?? null,
                                ];
                                if ($existente) {
                                    if (! $bloquearCambio && $existente->codigo_barras !== $codigo) {
                                        $dataAttrs['codigo_barras'] = $codigo;
                                    }
                                    $existente->update($dataAttrs);
                                    $variantesActualizadas++;
                                } else {
                                    $nuevaVar = ProductoVariante::create(array_merge($dataAttrs, [
                                        'producto_id' => $producto->id,
                                        'codigo_barras' => $codigo,
                                        'color_codigo' => $colorRef,
                                        'diseno_codigo' => $disenoRef,
                                        'talla' => $tallaRef,
                                    ]));
                                    // Bind del producto ya cargado (evita N+1 en hooks del modelo).
                                    if ($nuevaVar->relationLoaded('producto') === false) {
                                        $nuevaVar->setRelation('producto', $producto);
                                    }
                                    $variantesCreadas++;
                                }
                            }
                        } catch (\Throwable $e) {
                            $errores[] = [
                                'fila' => $i, 'sku' => $r['referencia'] ?? '—',
                                'mensaje' => $e->getMessage(), 'tipo' => 'error',
                            ];
                        }
                    }
                }
            });

            $reader->close();
            @unlink($path);

            $this->errores = $errores;
            $variantes = $variantesCreadas + $variantesActualizadas;
            $this->preview = ['nuevos' => $creados, 'actualizados' => $actualizados, 'variantes' => $variantes, 'variantesCreadas' => $variantesCreadas, 'variantesActualizadas' => $variantesActualizadas];

            Notification::make()
                ->title(empty($errores) ? '✅ Importación exitosa' : "⚠️ Importación con {" . count($errores) . "} errores")
                ->body("Productos → Nuevos: {$creados} · Actualizados: {$actualizados}. Variantes → Nuevas: {$variantesCreadas} · Actualizadas: {$variantesActualizadas}")
                ->color(empty($errores) ? 'success' : 'warning')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error importando')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('previsualizar')
                ->label('1 · Previsualizar')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->action('previsualizar'),
            Action::make('procesar')
                ->label('2 · Importar de verdad')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Se aplicarán los cambios en la BD. ¿Confirmas?')
                ->action('procesar')
                ->hidden(fn () => ! $this->previewCalculado),
        ];
    }
}
