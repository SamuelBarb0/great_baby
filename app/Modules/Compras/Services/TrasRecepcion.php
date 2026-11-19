<?php

namespace App\Modules\Compras\Services;

use App\Models\EmpresaConfig;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Enums\EstadoTraslado;
use App\Modules\Inventario\Models\Traslado;
use Illuminate\Support\Collection;

/**
 * Lo que sigue cuando Jorge acepta la mercancía de una importación: ubicar lo que
 * no se puede vender, y separar lo de e-commerce para Araceli.
 *
 * Todo entra primero a Great Baby, que es quien paga los impuestos. Lo de
 * e-commerce pasa después por un traslado que Araceli acepta, no por una venta.
 */
class TrasRecepcion
{
    /** Donde queda lo que llegó mal. Nunca en una ubicación de venta. */
    public function ubicacionNoVendible(): InventarioUbicacion
    {
        return InventarioUbicacion::query()
            ->where('categoria', CategoriaUbicacion::AveriaReparar->value)
            ->where('activa', true)
            ->orderBy('id')
            ->first()
            ?? InventarioUbicacion::create([
                'codigo' => 'AVR-RECEPCION',
                'nombre' => 'Avería · Recibida de proveedor',
                'categoria' => CategoriaUbicacion::AveriaReparar,
                'activa' => true,
            ]);
    }

    /**
     * Avisa a Araceli de lo suyo y le deja el traslado listo. Sin ubicación de
     * e-commerce configurada avisa igual: la mercancía ya llegó y tiene que saberlo.
     */
    public function separarEcommerce(RecepcionCompra $recepcion): ?Traslado
    {
        $principal = EmpresaConfig::current()->id;
        $suyos = $recepcion->items
            ->filter(fn ($i) => ($i->clasificacion ?? 'apto') === 'apto')
            ->filter(fn ($i) => $i->ordenItem?->empresa_destino_id && (int) $i->ordenItem->empresa_destino_id !== (int) $principal);

        if ($suyos->isEmpty()) {
            return null;
        }

        $traslado = $this->crearTraslado($recepcion, $suyos);
        $unidades = rtrim(rtrim(number_format((float) $suyos->sum('cantidad_recibida'), 3, ',', '.'), '0'), ',');

        foreach ($this->aracelis() as $u) {
            NotificacionErp::crear([
                'user_id' => $u->id,
                'tipo' => 'recepcion_ecommerce',
                'titulo' => "Llegó mercancía de e-commerce: {$unidades} unidades",
                'mensaje' => $traslado
                    ? "Recepción {$recepcion->numero}. Traslado {$traslado->numero} listo para aceptar."
                    : "Recepción {$recepcion->numero}. No se creó el traslado: falta configurar la ubicación de e-commerce en Reglas, o crear el producto de estas líneas.",
                'color' => 'info',
                'icono' => 'heroicon-o-truck',
                'url' => $traslado ? "/app/inventario/traslados/{$traslado->id}" : "/app/compras/recepcion/{$recepcion->id}",
            ]);
        }

        return $traslado;
    }

    private function crearTraslado(RecepcionCompra $recepcion, Collection $items): ?Traslado
    {
        // Una línea sin producto creado no tiene qué trasladar en el kardex.
        $items = $items->filter(fn ($i) => $i->variante_id || $i->producto_id);
        $destino = (int) setting('compras.ubicacion_ecommerce', 0);
        if ($items->isEmpty()) {
            return null;
        }
        if ($destino <= 0 || $destino === (int) $recepcion->bodega_id || ! InventarioUbicacion::whereKey($destino)->exists()) {
            return null;
        }

        $traslado = Traslado::create([
            'numero' => Traslado::siguienteNumero(),
            'origen_id' => $recepcion->bodega_id,
            'destino_id' => $destino,
            'motivo' => 'Importación e-commerce',
            'observaciones' => "Recepción {$recepcion->numero}, orden {$recepcion->orden?->numero}. Se traslada al costo de la liquidación, sin factura de venta.",
            'solicitado_por' => auth()->id(),
            'fecha_solicitud' => now('America/Bogota')->toDateString(),
            'estado' => EstadoTraslado::Borrador,
        ]);

        foreach ($items as $i) {
            $traslado->items()->create([
                'variante_id' => $i->variante_id,
                'producto_id' => $i->producto_id,
                'cantidad_solicitada' => $i->cantidad_recibida,
            ]);
        }

        return $traslado;
    }

    /** @return Collection<int, User> */
    private function aracelis(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Aracely', 'Gerencia']))->get();
    }
}
