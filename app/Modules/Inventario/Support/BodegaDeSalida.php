<?php

namespace App\Modules\Inventario\Support;

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;

/**
 * De qué bodega sale cada ítem de un despacho.
 *
 * El pedido trae `ubicacion_origen_id` cuando una regla de ruteo por ciudad lo
 * resolvió al crearse. El problema encontrado el 2026-10-13 llevando una venta
 * B2B completa por la interfaz: la tabla `reglas_ruteo_ciudad` está vacía y no
 * existe ninguna pantalla para cargarla, así que TODO pedido nace con origen
 * nulo. El descuento de inventario se rendía ahí mismo —devolvía 0 en silencio—
 * y el resultado era el que el propio descuento dice evitar: se despacha, se
 * factura, y el ERP sigue mostrando en bodega lo que ya va camino al cliente.
 * Sin egreso tampoco hay costo de ventas, así que el margen bruto daba 100%.
 *
 * Cuando el pedido no dice de dónde sale, se deduce de la única fuente que no
 * miente: dónde está la mercancía. Si una referencia tiene saldo en una sola
 * bodega, de ahí sale. No se adivina nada que el kardex no respalde.
 */
class BodegaDeSalida
{
    /**
     * Ubicación por ítem, en una sola consulta.
     *
     * `$categorias` limita las bodegas candidatas (Dropi sólo vende de las de
     * categoría `venta`: una avería o una reserva de garantía no se despacha).
     *
     * @param  list<array{variante_id:?int, producto_id:?int}>  $sujetos
     * @param  list<string>  $categorias
     * @return array<string, int>  clave `v:{id}` o `p:{id}` => ubicacion_id
     */
    public static function resolver(array $sujetos, ?int $origenDelPedido = null, array $categorias = []): array
    {
        if ($origenDelPedido) {
            // El pedido ya sabe de dónde sale: se respeta tal cual, que es el
            // comportamiento que ya existía y el que usa la Cola de Jorge.
            return array_fill_keys(
                array_map(fn ($s) => CostoPromedio::clave($s['variante_id'] ?? null, $s['producto_id'] ?? null), $sujetos),
                $origenDelPedido
            );
        }

        $variantes = array_values(array_filter(array_column($sujetos, 'variante_id')));
        $productos = [];
        foreach ($sujetos as $s) {
            if (empty($s['variante_id']) && ! empty($s['producto_id'])) {
                $productos[] = $s['producto_id'];
            }
        }

        // Bodegas elegibles: activas, de verdad, no los espejos del catálogo de
        // SIIGO —esas no guardan nada, son sólo el reflejo de la cuenta ajena.
        $elegibles = InventarioUbicacion::query()
            ->where('activa', true)
            ->where('codigo', 'not like', 'SIIGO-%')
            // Una SEDE, nunca un estante. El id que sale de acá se estampa en
            // `ubicacion_origen_id` del pedido, y la Cola de alistamiento y
            // las reglas de ruteo filtran por bodegas: un pedido con un rack
            // ahí adentro no aparece en ninguna de las dos y queda huérfano.
            ->whereNull('bodega_id')
            ->when($categorias, fn ($q) => $q->whereIn('categoria', $categorias))
            ->pluck('id')->all();

        if (! $elegibles) {
            return [];
        }

        /*
         * El saldo se busca TAMBIÉN en los estantes, y se le acredita a su sede.
         *
         * Lo que se DEVUELVE sigue siendo una sede —por lo que dice el
         * comentario de arriba—, pero buscar el saldo sólo en la fila de la
         * sede dejaba ciega a esta clase en cuanto alguien usara las
         * posiciones para lo que son: la mercancía recibida directo a
         * `R12-S1-N1` no aparecía por ningún lado, `resolver()` devolvía
         * vacío, y el despacho se rendía con «no se pudo determinar de qué
         * bodega salió». Inventario que no baja, costo de ventas que no se
         * causa: exactamente el defecto que esta clase existe para evitar,
         * reaparecido por la puerta de al lado.
         *
         * `$deQuienEs` mapea cada ubicación —sede o estante— a la sede que
         * responde por ella.
         */
        $deQuienEs = array_combine($elegibles, $elegibles);

        InventarioUbicacion::query()
            ->where('activa', true)
            ->whereIn('bodega_id', $elegibles)
            // El estante también tiene que ser del tipo que se despacha. Una
            // posición de cuarentena o de avería vive dentro de una bodega de
            // venta, y su saldo no es vendible por estar donde está.
            ->when($categorias, fn ($q) => $q->whereIn('categoria', $categorias))
            ->get(['id', 'bodega_id'])
            ->each(function ($u) use (&$deQuienEs) {
                $deQuienEs[(int) $u->id] = (int) $u->bodega_id;
            });

        $donde = array_keys($deQuienEs);

        $out = [];

        $saldos = function ($campo, array $ids) use ($donde) {
            if (! $ids) {
                return collect();
            }
            /*
             * Sin `having > 0` por ubicación: ahora cada sede puede venir
             * repartida en varios estantes, y uno en negativo —un ajuste, una
             * devolución mal ubicada— tiene que restar del total de su sede en
             * vez de desaparecer de la cuenta.
             */
            $q = InventarioMovimiento::query()
                ->whereIn($campo, $ids)
                ->whereIn('ubicacion_id', $donde)
                ->selectRaw("{$campo} as sujeto, ubicacion_id, SUM(cantidad) as saldo")
                ->groupBy($campo, 'ubicacion_id');

            // Un producto sólo cuenta como agregado si el movimiento no tiene
            // variante; si no, el saldo de las variantes se sumaría dos veces.
            if ($campo === 'producto_id') {
                $q->whereNull('variante_id');
            }

            return $q->get();
        };

        foreach ([['variante_id', $variantes, 'v'], ['producto_id', $productos, 'p']] as [$campo, $ids, $pre]) {
            // Lo de cada estante se suma al de su sede: el alistador no
            // camina a «un estante», camina a una bodega y adentro busca.
            $porSede = [];

            foreach ($saldos($campo, $ids) as $r) {
                $sede = $deQuienEs[(int) $r->ubicacion_id] ?? null;

                if (! $sede) {
                    continue;
                }

                $porSede[$r->sujeto][$sede] = ($porSede[$r->sujeto][$sede] ?? 0) + (int) $r->saldo;
            }

            foreach ($porSede as $sujeto => $sedes) {
                // La bodega con más existencias de esa referencia: es de donde
                // el alistador la habría tomado. Las que quedaron en cero o en
                // negativo no son candidatas.
                arsort($sedes);
                $mejor = array_key_first(array_filter($sedes, fn ($s) => $s > 0));

                if ($mejor !== null) {
                    $out["{$pre}:{$sujeto}"] ??= (int) $mejor;
                }
            }
        }

        return $out;
    }
}
