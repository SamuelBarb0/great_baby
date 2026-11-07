<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class InventarioEnVivo extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Inventario en vivo';

    protected static ?string $title = 'Saldo de inventario por variante × ubicación';

    protected static string|\UnitEnum|null $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 4;

    protected string $view = 'dropi.pages.inventario-en-vivo';

    public string $busqueda = '';

    public string $filtroCategoria = '';

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u ? ($u->esAracely() || $u->esAlistador()) : false;
    }

    /**
     * C-F3 FIX ALTO auditor · devuelve saldos de ambos modos.
     *   Granular: key "v-{variante_id}" → [ubicacion_id => saldo]
     *   Agregado: key "p-{producto_id}"  → [ubicacion_id => saldo]
     * Los llamadores usan getFilas() que ya emite las keys correctas.
     */
    public function getSaldos(): array
    {
        // Saldos granulares (movs con variante_id).
        $granulares = InventarioMovimiento::query()
            ->select('variante_id', 'ubicacion_id', DB::raw('SUM(cantidad) as saldo'))
            ->whereNotNull('variante_id')
            ->groupBy('variante_id', 'ubicacion_id')
            ->having('saldo', '!=', 0)
            ->get();

        // Saldos agregados (movs sin variante_id, con producto_id).
        $agregados = InventarioMovimiento::query()
            ->select('producto_id', 'ubicacion_id', DB::raw('SUM(cantidad) as saldo'))
            ->whereNull('variante_id')
            ->whereNotNull('producto_id')
            ->groupBy('producto_id', 'ubicacion_id')
            ->having('saldo', '!=', 0)
            ->get();

        $map = [];
        foreach ($granulares as $s) {
            $map["v-{$s->variante_id}"][$s->ubicacion_id] = (int) $s->saldo;
        }
        foreach ($agregados as $s) {
            $map["p-{$s->producto_id}"][$s->ubicacion_id] = (int) $s->saldo;
        }
        return $map;
    }

    /**
     * C-F3 FIX ALTO auditor · devuelve una lista de "filas" (variante o producto agregado)
     * para que la vista itere y renderice ambos tipos con la key consistente.
     * Cada fila tiene: key, ref, nombre, etiqueta (variante detail o "AGREGADO"), badge.
     *
     * @return array<int, array{key:string, ref:string, nombre:string, etiqueta:string, esAgregado:bool}>
     */
    public function getFilas(): array
    {
        $b = $this->busqueda !== '' ? "%{$this->busqueda}%" : null;

        // Variantes de productos granulares.
        $variantes = ProductoVariante::query()
            ->with('producto:id,nombre,referencia,desglose_stock')
            ->whereHas('producto', fn ($p) => $p->where('desglose_stock', true))
            ->when($b, function ($q) use ($b) {
                $q->where(function ($qq) use ($b) {
                    $qq->where('codigo_barras', 'like', $b)
                       ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', $b)->orWhere('referencia', 'like', $b));
                });
            })
            ->orderBy('producto_id')->orderBy('codigo_barras')
            ->limit(150)
            ->get()
            ->map(fn ($v) => [
                'key'        => "v-{$v->id}",
                'ref'        => (string) ($v->producto?->referencia ?? '—'),
                'nombre'     => (string) ($v->producto?->nombre ?? '—'),
                'etiqueta'   => trim(($v->color_nombre ?? '').' '.($v->talla ?? '').' · '.$v->codigo_barras),
                'esAgregado' => false,
            ]);

        // Productos agregados (sin desglose).
        $agregados = Producto::query()
            ->where('desglose_stock', false)
            ->when($b, fn ($q) => $q->where(fn ($qq) => $qq->where('nombre', 'like', $b)->orWhere('referencia', 'like', $b)))
            ->orderBy('referencia')
            ->limit(150)
            ->get()
            ->map(fn ($p) => [
                'key'        => "p-{$p->id}",
                'ref'        => (string) $p->referencia,
                'nombre'     => (string) $p->nombre,
                'etiqueta'   => 'AGREGADO · '.mb_strimwidth((string) ($p->descripcion ?? ''), 0, 60, '…'),
                'esAgregado' => true,
            ]);

        return $variantes->concat($agregados)->all();
    }

    /**
     * DEPRECATED wrapper legacy para la vista blade que aún use getVariantes().
     * Se removerá cuando la vista adopte getFilas().
     */
    public function getVariantes()
    {
        return ProductoVariante::query()
            ->with('producto')
            ->when($this->busqueda !== '', function ($q) {
                $b = "%{$this->busqueda}%";
                $q->where(function ($qq) use ($b) {
                    $qq->where('codigo_barras', 'like', $b)
                       ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', $b)->orWhere('referencia', 'like', $b));
                });
            })
            ->orderBy('producto_id')
            ->orderBy('codigo_barras')
            ->limit(200)
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, InventarioUbicacion> */
    public function getUbicaciones()
    {
        return InventarioUbicacion::query()
            ->where('activa', true)
            ->when($this->filtroCategoria !== '', fn ($q) => $q->where('categoria', $this->filtroCategoria))
            ->orderByRaw("FIELD(categoria, 'venta','reserva_proveedor','garantia','averia_reparar','averia_baja')")
            ->orderBy('codigo')
            ->get();
    }

    public function getCategorias(): array
    {
        return collect(CategoriaUbicacion::cases())
            ->mapWithKeys(fn ($c) => [$c->value => $c->label()])
            ->prepend('Todas', '')
            ->toArray();
    }
}
