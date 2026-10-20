<?php

namespace App\Modules\Dropi\Filament\Resources\DropiDevolucionResource\Pages;

use App\Modules\Dropi\Actions\RegistrarDevolucion as RegistrarDevolucionAction;
use App\Modules\Dropi\Enums\DestinoDevolucion;
use App\Modules\Dropi\Filament\Resources\DropiDevolucionResource;
use App\Modules\Dropi\Models\DropiPedido;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RegistrarDevolucion extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = DropiDevolucionResource::class;

    protected static ?string $title = 'Registrar devolución';

    protected string $view = 'dropi.pages.registrar-devolucion';

    public ?array $data = [];

    public ?DropiPedido $pedido = null;

    /** 5.4 · lo escaneado al recibir: clave `v:{id}` / `p:{id}` => unidades. */
    public array $recibidos = [];

    public string $lectura = '';

    public ?string $ultimaLectura = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('1 · Escanear o ingresar guía')->schema([
                TextInput::make('guia')
                    ->label('Guía Dropi')
                    ->placeholder('GUI-000001')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state) {
                        $this->pedido = $state ? DropiPedido::with(['items.variante.producto', 'items.producto'])->where('guia', strtoupper(trim($state)))->first() : null;
                        $this->recibidos = $this->pedido
                            ? array_map(fn () => 0, RegistrarDevolucionAction::esperados($this->pedido))
                            : [];
                        $this->ultimaLectura = null;
                        if ($state && ! $this->pedido) {
                            Notification::make()->title("Guía {$state} no encontrada")->danger()->send();
                        }
                    }),
            ]),
            Section::make('3 · Decidir destino del inventario')
                ->description('Elige según la verificación física del producto')
                ->schema([
                    Select::make('destino')
                        ->label('Destino')
                        ->options(collect(DestinoDevolucion::cases())->mapWithKeys(fn ($d) => [$d->value => $d->label()]))
                        ->required(),
                    Textarea::make('notas')->columnSpanFull(),
                ])
                ->visible(fn () => $this->pedido !== null),
        ])->statePath('data');
    }

    /**
     * Renglones a re-escanear: referencia, esperadas y códigos que acepta.
     *
     * @return array<string, array{referencia:string, esperadas:int, codigos:list<string>}>
     */
    public function porEscanear(): array
    {
        if (! $this->pedido) return [];
        $esperados = RegistrarDevolucionAction::esperados($this->pedido);
        $refs = RegistrarDevolucionAction::referencias($this->pedido);
        $out = [];
        foreach ($this->pedido->items as $i) {
            if (! $i->variante_id && ! $i->producto_id) continue;
            $clave = \App\Modules\Inventario\Support\CostoPromedio::clave($i->variante_id, $i->producto_id);
            $codigo = $i->variante_id ? $i->variante?->codigo_barras : ($i->producto?->referencia);
            $out[$clave] ??= ['referencia' => $refs[$clave], 'esperadas' => $esperados[$clave], 'codigos' => []];
            if ($codigo) $out[$clave]['codigos'][] = strtoupper(trim($codigo));
        }

        return $out;
    }

    public function escanear(): void
    {
        $codigo = strtoupper(trim($this->lectura));
        $this->lectura = '';
        if ($codigo === '') return;

        foreach ($this->porEscanear() as $clave => $r) {
            if (! in_array($codigo, $r['codigos'], true)) continue;
            if (($this->recibidos[$clave] ?? 0) >= $r['esperadas']) {
                $this->ultimaLectura = "⚠ {$r['referencia']}: ya están las {$r['esperadas']} que se despacharon";
                return;
            }
            $this->recibidos[$clave] = ($this->recibidos[$clave] ?? 0) + 1;
            $this->ultimaLectura = "✓ {$r['referencia']} ({$this->recibidos[$clave]}/{$r['esperadas']})";
            return;
        }
        $this->ultimaLectura = "✖ {$codigo} no es de este pedido";
    }

    public function ajustar(string $clave, int $delta): void
    {
        $max = $this->porEscanear()[$clave]['esperadas'] ?? 0;
        $this->recibidos[$clave] = max(0, min($max, ($this->recibidos[$clave] ?? 0) + $delta));
    }

    public function registrar(): void
    {
        $data = $this->form->getState();

        try {
            $result = RegistrarDevolucionAction::run(
                guia: $data['guia'],
                destino: DestinoDevolucion::from($data['destino']),
                userId: auth()->id(),
                notas: $data['notas'] ?? null,
                recibidos: $this->recibidos,
            );

            Notification::make()
                ->title('Devolución registrada')
                ->body("Guía {$data['guia']} · destino: " . DestinoDevolucion::from($data['destino'])->label() . " · movimientos: {$result['movimientos']}"
                    .(($f = array_sum($result['faltantes'])) ? " · faltaron {$f} unidad(es), quedó anotado" : ''))
                ->success()
                ->send();

            $this->redirect(DropiDevolucionResource::getUrl('index'));
        } catch (\Throwable $e) {
            Notification::make()->title('No se pudo registrar')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('registrar')
                ->label('Registrar devolución')
                ->color('primary')
                ->action('registrar'),
        ];
    }
}
