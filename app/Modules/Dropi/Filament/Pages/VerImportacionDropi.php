<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Http\Controllers\App\ImportacionDocumentosController;
use App\Modules\Compras\Actions\AgregarGastoImportacion;
use App\Modules\Compras\Actions\LiquidarImportacion;
use App\Modules\Compras\Enums\ConceptoGastoImportacion;
use App\Modules\Compras\Actions\GuardarDocumentoImportacion;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EtapaContenedor;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDeclaracion;
use App\Modules\Compras\Models\ImportacionDocumento;
use App\Modules\Compras\Models\ImportacionInspeccion;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Services\SeguimientoContenedor;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Filament\Support\ComprasDropi;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Detalle de un contenedor de Dropi: sus órdenes, sus gastos y lo que saldría
 * al liquidarlo. Cada paso usa lo mismo que /app (SeguimientoContenedor,
 * AgregarGastoImportacion, LiquidarImportacion), así que el prorrateo, el
 * kardex y el envío a SIIGO son los mismos; el asiento va al libro de Dropi
 * porque el contenedor es de Dropi. El seguimiento (etapas, declaraciones,
 * novedades, documentos y datos) también pasa por SeguimientoContenedor y
 * GuardarDocumentoImportacion, con las mismas reglas que /app.
 */
class VerImportacionDropi extends Page
{
    protected static ?string $slug = 'compras-importacion-dropi';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'dropi.pages.ver-importacion-dropi';

    #[Url]
    public ?int $importacion = null;

    public static function canAccess(): bool
    {
        return ComprasDropi::puedeImportar(auth()->user());
    }

    public function mount(): void
    {
        abort_unless($this->importacion, 404);
        ComprasDropi::importacion($this->importacion);
    }

    public function getTitle(): string
    {
        return 'Importación ' . $this->getImportacion()->numero;
    }

    public function getImportacion(): Importacion
    {
        return ComprasDropi::importacion((int) $this->importacion)
            ->load(['ordenes.proveedor:id,nombre_completo,razon_social', 'gastos', 'lineas',
                'documentos.autor:id,name']);
    }

    /** Etapas, declaraciones y novedades, tal como las arma la pantalla de /app. */
    public function seguimiento(): array
    {
        return app(SeguimientoContenedor::class)->paraPantalla(ComprasDropi::importacion((int) $this->importacion));
    }

    /** Si ya hay mercancía recibida, la tasa quedó en el libro y no se cambia. */
    public function tasaFija(): bool
    {
        return RecepcionCompra::where('estado', 'confirmada')
            ->whereIn('orden_id', $this->getImportacion()->ordenes->pluck('id'))
            ->exists();
    }

    /** Lo que saldría si se liquidara ahora: las líneas no existen hasta liquidar. */
    public function previsto(): array
    {
        return app(LiquidarImportacion::class)->previsto($this->getImportacion());
    }

    public function abierta(): bool
    {
        return ! in_array($this->getImportacion()->estado, [EstadoImportacion::Liquidada, EstadoImportacion::Cerrada], true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('agregarOrden')->label('Agregar orden')->icon('heroicon-o-plus')->color('gray')
                ->visible(fn () => $this->abierta())
                ->schema([
                    Select::make('orden_id')->label('Orden de importación de Dropi')->required()->searchable()
                        ->options(fn () => self::ordenesDisponibles())
                        ->helperText('Sólo órdenes de importación de Dropi, aprobadas y que no vayan ya en otro contenedor.'),
                ])
                ->action(function (array $data) {
                    $orden = ComprasDropi::orden((int) $data['orden_id']);
                    $this->intentar(
                        fn () => app(SeguimientoContenedor::class)->ligarOrden($this->getImportacion(), $orden),
                        "Orden {$orden->numero} agregada al contenedor.",
                    );
                }),

            Action::make('quitarOrden')->label('Quitar orden')->icon('heroicon-o-minus')->color('gray')
                ->visible(fn () => $this->abierta() && $this->getImportacion()->ordenes->isNotEmpty())
                ->schema([
                    Select::make('orden_id')->label('Orden')->required()
                        ->options(fn () => $this->getImportacion()->ordenes->pluck('numero', 'id')->all()),
                ])
                ->action(function (array $data) {
                    $orden = OrdenCompra::findOrFail((int) $data['orden_id']);
                    $this->intentar(
                        fn () => app(SeguimientoContenedor::class)->soltarOrden($this->getImportacion(), $orden),
                        "Orden {$orden->numero} quitada del contenedor.",
                    );
                }),

            Action::make('agregarGasto')->label('Agregar gasto')->icon('heroicon-o-banknotes')->color('primary')
                ->visible(fn () => $this->abierta())
                ->schema([
                    Select::make('concepto')->required()
                        ->options(collect(ConceptoGastoImportacion::cases())
                            ->mapWithKeys(fn (ConceptoGastoImportacion $c) => [$c->value => $c->label()])->all()),
                    TextInput::make('descripcion')->label('Descripción')->maxLength(200)
                        ->helperText('En blanco queda el nombre del concepto.'),
                    Select::make('moneda')->required()->default('COP')
                        ->options(fn () => array_unique(['COP' => 'COP', $this->getImportacion()->moneda_origen => $this->getImportacion()->moneda_origen])),
                    TextInput::make('monto')->numeric()->required()->minValue(0),
                    Toggle::make('capitalizable')->label('Suma al costo del inventario')->default(true)
                        ->helperText('El IVA de importación nunca suma al costo: va como IVA descontable.'),
                    Select::make('metodo_prorrateo')->label('Se reparte por')->required()->default('valor')
                        ->options(['valor' => 'Valor', 'cantidad' => 'Cantidad', 'peso' => 'Peso', 'volumen' => 'Volumen']),
                    TextInput::make('factura_proveedor')->label('Factura del proveedor')->maxLength(100),
                    DatePicker::make('fecha')->required()->default(now('America/Bogota')->toDateString()),
                    FileUpload::make('comprobante')->label('Soporte (factura del flete, de la agencia…)')
                        ->disk('local')->directory('tmp/comprobantes-importacion')
                        ->storeFileNamesIn('comprobante_nombre')
                        ->maxSize(20480)
                        ->rules(['mimes:' . ImportacionDocumentosController::MIMES]),
                ])
                ->action(function (array $data) {
                    $temporal = $data['comprobante'] ?? null;
                    $nombre = $data['comprobante_nombre'] ?? null;
                    unset($data['comprobante'], $data['comprobante_nombre']);

                    $archivo = $temporal ? self::archivoSubido($temporal, $nombre) : null;

                    try {
                        $this->intentar(
                            fn () => AgregarGastoImportacion::run($this->getImportacion(), [
                                ...$data,
                                'monto' => (float) $data['monto'],
                                'capitalizable' => (bool) ($data['capitalizable'] ?? false),
                            ], $archivo),
                            'Gasto agregado.',
                        );
                    } finally {
                        if ($temporal) {
                            Storage::disk('local')->delete($temporal);
                        }
                    }
                }),

            Action::make('editarDatos')->label('Editar datos')->icon('heroicon-o-pencil-square')->color('gray')
                ->visible(fn () => $this->abierta())
                ->fillForm(fn () => [
                    'contenedor' => $this->getImportacion()->contenedor,
                    'bl_awb' => $this->getImportacion()->bl_awb,
                    'proveedor_pais' => $this->getImportacion()->proveedor_pais,
                    'puerto_origen' => $this->getImportacion()->puerto_origen,
                    'puerto_destino' => $this->getImportacion()->puerto_destino,
                    'incoterm' => $this->getImportacion()->incoterm,
                    'tasa_cambio_liquidacion' => $this->getImportacion()->tasa_cambio_liquidacion,
                    'eta' => $this->getImportacion()->eta?->toDateString(),
                ])
                ->schema([
                    TextInput::make('contenedor')->label('Contenedor')->maxLength(30),
                    TextInput::make('bl_awb')->label('BL / AWB')->maxLength(60),
                    TextInput::make('proveedor_pais')->label('País del proveedor')->maxLength(60),
                    TextInput::make('puerto_origen')->label('Puerto de origen')->maxLength(60),
                    TextInput::make('puerto_destino')->label('Puerto de destino')->maxLength(60),
                    TextInput::make('incoterm')->maxLength(10),
                    TextInput::make('tasa_cambio_liquidacion')->label(fn () => 'Tasa (' . $this->getImportacion()->moneda_origen . ' a pesos)')
                        ->numeric()->minValue(0)
                        ->helperText(fn () => $this->tasaFija() ? 'Ya hay mercancía recibida a esta tasa: no se puede cambiar.' : null),
                    DatePicker::make('eta')->label('ETA'),
                ])
                ->action(fn (array $data) => $this->intentar(
                    fn () => app(SeguimientoContenedor::class)->actualizarDatos($this->getImportacion(), $data),
                    'Datos del contenedor actualizados.',
                )),

            Action::make('subirDocumento')->label('Subir documento')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->schema([
                    Select::make('tipo')->label('Tipo')->required()->searchable()
                        ->options(array_diff_key(ImportacionDocumento::TIPOS, ['comprobante_gasto' => true]))
                        ->helperText('Los soportes de los gastos se suben con cada gasto.'),
                    FileUpload::make('archivo')->label('Archivo')->required()
                        ->disk('local')->directory('tmp/documentos-importacion')
                        ->storeFileNamesIn('archivo_nombre')
                        ->maxSize(ImportacionDocumentosController::limiteMb() * 1024)
                        ->rules(['mimes:' . ImportacionDocumentosController::MIMES])
                        ->helperText('Si el tipo ya existe, queda como versión siguiente: la anterior no se borra.'),
                    Textarea::make('notas')->maxLength(500)->rows(2),
                ])
                ->action(function (array $data) {
                    $temporal = $data['archivo'] ?? null;
                    try {
                        $this->intentarDocumento($temporal, $data['archivo_nombre'] ?? null, $data['tipo'], $data['notas'] ?? null);
                    } finally {
                        if ($temporal) {
                            Storage::disk('local')->delete($temporal);
                        }
                    }
                }),

            Action::make('liquidar')->label('Liquidar')->icon('heroicon-o-calculator')->color('success')
                ->visible(fn () => $this->abierta() && $this->getImportacion()->ordenes->isNotEmpty())
                ->requiresConfirmation()
                ->modalHeading('Liquidar la importación')
                ->modalDescription('Reparte los gastos entre los productos, corrige su costo en el inventario y registra el asiento en el libro de Dropi. Después no se pueden agregar gastos ni cambiar la tasa.')
                ->action(fn () => $this->intentar(
                    fn () => LiquidarImportacion::run($this->getImportacion()),
                    'Importación liquidada: costos prorrateados.',
                )),

        ];
    }

    /* ---- Acciones por fila: la vista las pinta con sus argumentos ---- */

    public function marcarEtapaAction(): Action
    {
        return Action::make('marcarEtapa')
            ->label(fn (array $arguments) => ($arguments['marcada'] ?? false) ? 'Corregir' : 'Marcar')
            ->size('sm')->link()
            ->modalHeading(fn (array $arguments) => 'Etapa «' . (EtapaContenedor::tryFrom($arguments['etapa'] ?? '')?->label() ?? '') . '»')
            ->fillForm(function (array $arguments) {
                $fila = $this->getImportacion()->etapas()->where('etapa', $arguments['etapa'] ?? '')->first();

                return [
                    'fecha' => $fila?->fecha?->toDateString() ?? now('America/Bogota')->toDateString(),
                    'notas' => $fila?->notas,
                ];
            })
            ->schema([
                DatePicker::make('fecha')->required()
                    ->helperText('El día en que pasó. No puede ser futura ni quedar fuera de orden con las otras etapas.'),
                Textarea::make('notas')->maxLength(1000)->rows(2),
            ])
            ->action(function (array $data, array $arguments) {
                $etapa = EtapaContenedor::tryFrom($arguments['etapa'] ?? '');
                abort_unless($etapa, 404);
                $this->intentar(
                    fn () => app(SeguimientoContenedor::class)->marcarEtapa($this->getImportacion(), $etapa, Carbon::parse($data['fecha']), $data['notas'] ?? null, auth()->id()),
                    "Etapa «{$etapa->label()}» marcada.",
                );
            });
    }

    public function quitarEtapaAction(): Action
    {
        return Action::make('quitarEtapa')
            ->label('Quitar')->size('sm')->link()->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Quitar la etapa «' . (EtapaContenedor::tryFrom($arguments['etapa'] ?? '')?->label() ?? '') . '»')
            ->action(function (array $arguments) {
                $etapa = EtapaContenedor::tryFrom($arguments['etapa'] ?? '');
                abort_unless($etapa, 404);
                $this->intentar(
                    fn () => app(SeguimientoContenedor::class)->quitarEtapa($this->getImportacion(), $etapa),
                    "Etapa «{$etapa->label()}» quitada.",
                );
            });
    }

    /** Sin argumento crea una declaración; con `declaracion` corrige esa. */
    public function guardarDeclaracionAction(): Action
    {
        return Action::make('guardarDeclaracion')
            ->label(fn (array $arguments) => isset($arguments['declaracion']) ? 'Editar' : 'Agregar declaración')
            ->size('sm')
            ->modalHeading(fn (array $arguments) => isset($arguments['declaracion']) ? 'Editar declaración' : 'Nueva declaración')
            ->fillForm(function (array $arguments) {
                $d = isset($arguments['declaracion']) ? $this->declaracion((int) $arguments['declaracion']) : null;

                return $d ? [
                    'numero' => $d->numero,
                    'selectividad' => $d->selectividad,
                    'fecha_cita' => $d->fecha_cita?->toDateString(),
                    'fecha_levante' => $d->fecha_levante?->toDateString(),
                    'notas' => $d->notas,
                ] : ['selectividad' => 'pendiente'];
            })
            ->schema([
                TextInput::make('numero')->label('Número')->maxLength(40),
                Select::make('selectividad')->required()->options(ImportacionDeclaracion::SELECTIVIDAD),
                DatePicker::make('fecha_cita')->label('Cita de inspección'),
                DatePicker::make('fecha_levante')->label('Levante')
                    ->helperText('Con levante automático y sin fecha, queda la de hoy.'),
                Textarea::make('notas')->maxLength(1000)->rows(2),
            ])
            ->action(function (array $data, array $arguments) {
                $d = isset($arguments['declaracion']) ? $this->declaracion((int) $arguments['declaracion']) : null;
                $this->intentar(
                    fn () => app(SeguimientoContenedor::class)->guardarDeclaracion($this->getImportacion(), $data, $d, auth()->id()),
                    'Declaración guardada.',
                );
            });
    }

    public function quitarDeclaracionAction(): Action
    {
        return Action::make('quitarDeclaracion')
            ->label('Quitar')->size('sm')->link()->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Quitar la declaración')
            ->action(function (array $arguments) {
                // Se busca FUERA de intentar(), como en resolverInspeccion: una
                // declaración de otro contenedor es una petición alterada y da
                // 404. Dentro, intentar() se tragaba el ModelNotFoundException
                // (es un RuntimeException) y lo mostraba como «No se pudo».
                $declaracion = $this->declaracion((int) ($arguments['declaracion'] ?? 0));
                $this->intentar(
                    fn () => app(SeguimientoContenedor::class)->quitarDeclaracion($this->getImportacion(), $declaracion),
                    'Declaración quitada.',
                );
            });
    }

    public function registrarInspeccionAction(): Action
    {
        return Action::make('registrarInspeccion')
            ->label('Registrar novedad')->size('sm')->icon('heroicon-o-exclamation-triangle')->color('warning')
            ->modalDescription('Todo Compras recibe el aviso en la campana.')
            ->schema([
                Select::make('tipo')->required()->options(ImportacionInspeccion::TIPOS),
                DatePicker::make('fecha')->required()->default(now('America/Bogota')->toDateString()),
                Textarea::make('notas')->maxLength(1000)->rows(2),
            ])
            ->action(fn (array $data) => $this->intentar(
                fn () => app(SeguimientoContenedor::class)->registrarInspeccion($this->getImportacion(), $data, auth()->id()),
                'Novedad registrada. Compras ya recibió el aviso.',
            ));
    }

    public function resolverInspeccionAction(): Action
    {
        return Action::make('resolverInspeccion')
            ->label('Resolver')->size('sm')->link()->color('success')
            ->requiresConfirmation()
            ->modalHeading('Marcar la novedad como resuelta')
            ->modalDescription('Queda resuelta con la fecha de hoy.')
            ->action(function (array $arguments) {
                $inspeccion = ImportacionInspeccion::where('importacion_id', $this->importacion)->findOrFail((int) ($arguments['inspeccion'] ?? 0));
                $this->intentar(
                    fn () => app(SeguimientoContenedor::class)->resolverInspeccion($this->getImportacion(), $inspeccion),
                    'Novedad resuelta.',
                );
            });
    }

    /** Solo Aracely o Gerencia quitan documentos («solo el admin borra», 21-oct); queda en la papelera. */
    public function quitarDocumentoAction(): Action
    {
        return Action::make('quitarDocumento')
            ->label('Quitar')->size('sm')->link()->color('danger')
            ->visible(fn () => (bool) auth()->user()?->esAracely())
            ->requiresConfirmation()
            ->modalHeading('Quitar el documento')
            ->modalDescription('Queda en la papelera: el archivo no se borra.')
            ->action(function (array $arguments) {
                abort_unless(auth()->user()?->esAracely(), 403, 'Solo Aracely o Gerencia quitan documentos.');
                ImportacionDocumento::where('importacion_id', $this->importacion)
                    ->findOrFail((int) ($arguments['documento'] ?? 0))
                    ->delete();
                Notification::make()->title('Documento quitado.')->success()->send();
            });
    }

    private function declaracion(int $id): ImportacionDeclaracion
    {
        return ImportacionDeclaracion::where('importacion_id', $this->importacion)->findOrFail($id);
    }

    /** El archivo que Livewire dejó en tmp, como si hubiera llegado en un formulario. */
    private static function archivoSubido(string $temporal, mixed $nombre): UploadedFile
    {
        $ruta = Storage::disk('local')->path($temporal);

        return new UploadedFile($ruta, is_string($nombre) && $nombre !== '' ? $nombre : basename($ruta), mime_content_type($ruta) ?: null, null, true);
    }

    /** Sube el documento con el mismo mensaje que /app: «Lista de empaque (versión 2) subido.» */
    private function intentarDocumento(?string $temporal, mixed $nombre, string $tipo, ?string $notas): void
    {
        if (! $temporal) {
            Notification::make()->title('No se pudo')->body('Falta el archivo.')->danger()->send();

            return;
        }

        try {
            $doc = GuardarDocumentoImportacion::run($this->getImportacion(), self::archivoSubido($temporal, $nombre), $tipo, null, $notas);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title(ImportacionDocumento::TIPOS[$doc->tipo] . ($doc->version > 1 ? " (versión {$doc->version})" : '') . ' subido.')
            ->success()->send();
    }

    /** Órdenes de importación de Dropi que se pueden meter: aprobadas y sueltas. */
    public static function ordenesDisponibles(): array
    {
        return OrdenCompra::query()
            ->where('linea_negocio', LineaNegocio::Dropi->value)
            ->where('tipo', 'importacion')
            ->whereIn('estado', ['aprobada', 'parcial', 'recibida'])
            ->whereDoesntHave('importaciones')
            ->orderByDesc('id')->limit(100)
            ->get(['id', 'numero', 'moneda', 'total'])
            ->mapWithKeys(fn (OrdenCompra $o) => [$o->id => "{$o->numero} · {$o->moneda} " . number_format((float) $o->total, 2, ',', '.')])
            ->all();
    }

    /** Corre un paso y avisa en pantalla con el mismo mensaje que daría /app. */
    private function intentar(callable $paso, string $exito): void
    {
        try {
            $paso();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->title('No se pudo')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();

            return;
        } catch (HttpExceptionInterface $e) {
            Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($exito)->success()->send();
    }
}
