<?php

namespace App\Modules\Portal\Actions;

use App\Auth\Permisos;
use App\Models\Contacto;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Cartera\Actions\ConsultarCredito;
use App\Modules\Catalogo\Models\PrecioProducto;
use App\Modules\Catalogo\Models\PrecioVariante;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\PedidoClienteItem;
use App\Modules\Portal\Models\ReglaRuteoCiudad;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crea un pedido B2B, venga del portal del cliente o del vendedor en la calle.
 *
 * Esto vivía dentro de `PortalCarritoController::confirmar()`. Se sacó acá
 * cuando se construyó la pantalla del vendedor (LOG-J1): el pedido que levanta
 * el vendedor tiene que pasar por EXACTAMENTE las mismas reglas que el que
 * arma el cliente —precios de su lista, compuerta de cartera, ruteo por
 * ciudad, consecutivo— y una segunda copia de todo eso se habría ido
 * separando de la primera sin que nadie lo notara.
 *
 * Lo único que cambia entre los dos casos es quién lo levantó:
 * `$vendedorId` queda en el pedido para comisiones y trazabilidad, y es null
 * cuando el propio cliente se autogestionó.
 */
class CrearPedidoCliente
{
    /**
     * Que nadie pida más de lo que hay para vender.
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    private function validarDisponibilidad(array $items): void
    {
        $stock = app(\App\Modules\Inventario\Services\StockService::class);
        $faltan = [];

        foreach ($items as $it) {
            $cantidad = (int) ($it['cantidad'] ?? 0);
            if ($cantidad <= 0) {
                continue;
            }

            $sujeto = ! empty($it['variante_id'])
                ? \App\Modules\Dropi\Models\ProductoVariante::find($it['variante_id'])
                : (! empty($it['producto_id'])
                    ? \App\Modules\Dropi\Models\Producto::find($it['producto_id'])
                    : null);

            if (! $sujeto) {
                continue;
            }

            /*
             * Un producto granular se pide por variante, no por producto: el
             * servicio revienta a propósito si le preguntan por el agregado de
             * algo que lleva desglose. Un POST armado a mano podría llegar
             * así, y vale más dejarlo pasar que devolver un error 500 que no
             * le dice nada a nadie.
             */
            if ($sujeto instanceof \App\Modules\Dropi\Models\Producto && $sujeto->desglose_stock) {
                continue;
            }

            $hay = $stock->disponibleParaVenta($sujeto);
            if ($cantidad > $hay) {
                $nombre = $sujeto->codigo_barras ?? $sujeto->referencia ?? ('#'.$sujeto->getKey());
                $faltan[] = $hay === 0
                    ? "{$nombre} está agotado"
                    : "{$nombre}: pediste {$cantidad} y quedan {$hay}";
            }
        }

        abort_if($faltan !== [], 422,
            'No alcanzamos a cubrir el pedido. '.implode(' · ', $faltan)
            .'. Ajustá las cantidades y volvé a enviarlo.');
    }

    /**
     * @param  list<array{variante_id?:int|null, producto_id?:int|null, cantidad:int}>  $items
     * @param  array<string,float>  $preciosPactados  Precio por «variante-{id}» o «producto-{id}»
     *                                                que pisa al de la lista. Lo usa la conversión
     *                                                de una cotización, y nada más.
     */
    public function ejecutar(
        Contacto $cliente,
        array $items,
        ?string $notas = null,
        ?int $vendedorId = null,
        ?int $empresaId = null,
        array $preciosPactados = [],
    ): PedidoCliente {
        $listaId = (int) ($cliente->lista_precios_id ?? 0);
        abort_if(! $listaId, 422, 'El cliente no tiene lista de precios asignada.');

        [$precios, $variantes, $productosAgg] = $this->resolverPrecios($items, $listaId);

        /*
         * El tope de verdad vive acá, no en el navegador.
         *
         * La pantalla ya no deja subir el contador más allá de lo que hay,
         * pero eso es comodidad: un POST armado a mano se la salta. Este es el
         * candado, y cubre los dos caminos —el carrito del portal y el pedido
         * que levanta el vendedor en la calle— porque los dos pasan por acá.
         *
         * Se rechaza el pedido entero en vez de recortar las líneas: mandarle
         * al cliente una confirmación con menos cantidad de la que pidió, sin
         * avisarle, es peor que decirle que no alcanza.
         *
         * SÓLO para el cliente que se autogestiona. El vendedor en la calle
         * sigue pudiendo levantar un pedido de algo que está por llegar: él
         * conoce al cliente, sabe qué le prometió y cuándo, y ese pedido lo
         * resuelve después logística con la cola de novedades. Quitarle esa
         * posibilidad sería cambiarle el oficio, no arreglarle un defecto.
         */
        if ($vendedorId === null && setting('portal.bloquear_sin_stock')) {
            $this->validarDisponibilidad($items);
        }
        /*
         * El semáforo mide contra el pedido que se va a crear, no contra otro.
         *
         * Sin los pactados acá, la compuerta de cartera evaluaba el cupo con
         * los precios de la LISTA DE HOY mientras el pedido se creaba con los
         * cotizados: con un descuento negociado retenía de más, y con la lista
         * subida después de cotizar, también. Dos números distintos para el
         * mismo pedido, y el que se le mostraba al vendedor era el que no era.
         */
        [$retenido, $motivos] = $this->evaluarCartera(
            $cliente,
            $this->totalEstimado($items, $precios, $variantes, $productosAgg, $listaId, $preciosPactados)
        );

        $pedido = DB::transaction(function () use (
            $cliente, $items, $notas, $vendedorId, $listaId, $empresaId,
            $precios, $variantes, $productosAgg, $retenido, $motivos, $preciosPactados
        ) {
            // LOG-J4 · ruteo automático · si hay regla para la ciudad del
            // cliente, el pedido nace apuntando a esa bodega origen y la Cola
            // Jorge lo filtra para la sede correcta.
            $ped = PedidoCliente::create([
                'numero' => $this->siguienteNumero(),
                'empresa_id' => $empresaId,
                'contacto_id' => $cliente->id,
                'vendedor_id' => $vendedorId,
                'lista_precios_id' => $listaId,
                'ubicacion_origen_id' => ReglaRuteoCiudad::resolver($cliente->ciudad),
                'estado' => $retenido ? 'retenido' : 'enviado',
                'motivo_retencion' => $retenido ? implode(' · ', $motivos) : null,
                /*
                 * Y cuándo lo frenó, que sobrevive a la liberación.
                 *
                 * El hito del historial se pintaba leyendo el estado, así que
                 * desaparecía en el momento en que Gerencia liberaba el
                 * pedido: después nadie podía ver que cartera lo había
                 * bloqueado. Se estampa acá, que es donde de verdad ocurre, y
                 * no al aprobarlo.
                 */
                'retenido_at' => $retenido ? now() : null,
                'notas_cliente' => $notas,
                'enviado_at' => now(),
                'subtotal' => 0, 'iva' => 0, 'total' => 0,
            ]);

            $subtotal = 0.0;
            $iva = 0.0;
            $lineasValidas = 0;

            foreach ($items as $it) {
                $linea = $this->resolverLinea($it, $precios, $variantes, $productosAgg, $listaId, $preciosPactados);

                if (! $linea) {
                    /*
                     * Una línea cotizada no se cae en silencio.
                     *
                     * El precio ya no puede ser la causa —el pactado entra en
                     * `resolverLinea` y pisa al de la lista—, así que si llega
                     * acá es que el producto o la variante ya no existen. Y un
                     * pedido con menos renglones de los que el cliente aceptó,
                     * marcando la cotización como convertida y sin decirle a
                     * nadie, es peor que no convertirla: el vendedor cree que
                     * despachó lo prometido.
                     *
                     * Es el mismo criterio que la compuerta de stock de arriba:
                     * se rechaza el pedido entero en vez de recortarlo.
                     */
                    if ($preciosPactados !== []) {
                        throw ValidationException::withMessages([
                            'items' => $this->nombreDeLinea($it).' ya no está disponible en el catálogo: '
                                .'lo desactivaron o lo borraron después de cotizar. Hay que recotizar sin '
                                .'ese producto, o volver a activarlo.',
                        ]);
                    }

                    continue;
                }

                $cantidad = (int) $it['cantidad'];
                $lineaSub = round($linea['precio'] * $cantidad, 2);
                $lineaIva = round($lineaSub * ($linea['ivaPct'] / 100), 2);
                $lineasValidas++;

                PedidoClienteItem::create([
                    'pedido_id' => $ped->id,
                    'variante_id' => $linea['variante_id'],
                    'producto_id' => $linea['producto_id'],
                    'sku_snapshot' => $linea['sku'],
                    'descripcion_snapshot' => $linea['desc'],
                    'cantidad' => $cantidad,
                    'precio_unitario' => $linea['precio'],
                    'iva_porcentaje' => $linea['ivaPct'],
                    'subtotal' => $lineaSub,
                    'iva_valor' => $lineaIva,
                    'total' => round($lineaSub + $lineaIva, 2),
                ]);

                $subtotal += $lineaSub;
                $iva += $lineaIva;
            }

            if ($lineasValidas === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Ninguno de los productos tiene precio en la lista del cliente.',
                ]);
            }

            $ped->update([
                'subtotal' => round($subtotal, 2),
                'iva' => round($iva, 2),
                'total' => round($subtotal + $iva, 2),
            ]);

            return $ped;
        });

        Cache::forget("portal.kpis.{$cliente->id}");

        // COM-1 · El comentario del vendedor le llega a Contabilidad.
        //
        // El vendedor en la calle pacta cosas que el precio de lista no
        // registra —un descuento que prometió, un plazo, una condición— y las
        // escribe en el comentario del pedido. Hasta ahora ese texto se
        // guardaba y ahí moría: Contabilidad lo descubría al facturar, o no lo
        // descubría. Se avisa sólo cuando el pedido lo levantó un vendedor,
        // porque por este mismo camino entran los del Portal y ahí las notas
        // las escribe el propio cliente, que es otra cosa.
        if ($vendedorId && trim((string) $notas) !== '') {
            $quienVende = User::find($vendedorId)?->name ?? 'un vendedor';

            Permisos::usuariosQuePueden('contabilidad')
                // Quien lo escribió ya sabe lo que escribió. Recibir el propio
                // aviso es ruido, y el ruido es lo que hace que a la semana
                // nadie mire la campana.
                ->whereKeyNot($vendedorId)
                ->get(['id'])
                ->each(fn ($u) => NotificacionErp::crear([
                    'user_id' => $u->id,
                    'tipo' => 'pedido_con_comentario',
                    'titulo' => "Pedido {$pedido->numero} con comentario de {$quienVende}",
                    'mensaje' => $cliente->nombreDisplay().' · '.trim((string) $notas),
                    'color' => 'info',
                    'icono' => 'heroicon-o-chat-bubble-left-ellipsis',
                    'url' => "/app/pedidos-b2b/{$pedido->id}",
                ]));
        }

        // LOG-J3 · Si el semáforo retuvo el pedido, Gerencia necesita saberlo
        // para liberarlo o rechazarlo.
        if ($pedido->estado === 'retenido') {
            NotificacionErp::crear([
                'tipo' => 'pedido_retenido_cartera',
                'titulo' => "Pedido {$pedido->numero} RETENIDO · requiere Gerencia",
                'mensaje' => $pedido->motivo_retencion ?: 'El semáforo de cartera lo bloqueó.',
                'color' => 'warning',
                'icono' => 'heroicon-o-pause-circle',
                'url' => "/app/pedidos-b2b/{$pedido->id}",
            ]);
        }

        return $pedido;
    }

    /**
     * Precios vigentes de la lista del cliente, más los modelos que hacen falta
     * para armar cada línea.
     *
     * @return array{0: array<int,float>, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection}
     */
    private function resolverPrecios(array $items, int $listaId): array
    {
        $varianteIds = collect($items)->pluck('variante_id')->filter()->unique()->all();
        $productoIds = collect($items)
            ->filter(fn ($i) => empty($i['variante_id']) && ! empty($i['producto_id']))
            ->pluck('producto_id')->unique()->all();

        $vigente = fn ($w) => $w->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', now()->toDateString());

        $precios = $varianteIds
            ? PrecioVariante::whereIn('variante_id', $varianteIds)
                ->where('lista_id', $listaId)
                ->where($vigente)
                ->pluck('precio', 'variante_id')->all()
            : [];

        $variantes = $varianteIds
            ? ProductoVariante::with(['producto' => fn ($q) => $q
                    ->select('id', 'referencia', 'nombre', 'impuesto_id', 'desglose_stock', 'activo')
                    ->with('impuesto:id,porcentaje')])
                ->whereIn('id', $varianteIds)
                ->whereHas('producto', fn ($q) => $q->where('activo', true)->where('desglose_stock', true))
                ->get()->keyBy('id')
            : collect();

        $productosAgg = $productoIds
            ? Producto::with('impuesto:id,porcentaje')
                ->whereIn('id', $productoIds)
                ->where('activo', true)
                ->where('desglose_stock', false)
                ->get()->keyBy('id')
            : collect();

        return [$precios, $variantes, $productosAgg];
    }

    /**
     * LOG-J2 · El semáforo de cartera decide si el pedido nace retenido.
     *
     * Antes todo pedido pasaba a «enviado» y gerencia tenía que leer la cartera
     * del cliente a mano. Ahora, si hay mora crítica, facturas vencidas o cupo
     * excedido, nace «retenido» con el motivo visible.
     *
     * @return array{0: bool, 1: list<string>}
     */
    private function evaluarCartera(Contacto $cliente, float $totalEstimado): array
    {
        $credito = ConsultarCredito::run($cliente->id);

        $motivos = [];
        if ($credito['tiene_mora_critica']) {
            $motivos[] = "mora crítica ({$credito['dias_mora_max']} días)";
        }
        if ($credito['facturas_vencidas'] > 0) {
            $motivos[] = "{$credito['facturas_vencidas']} factura(s) vencida(s)";
        }
        $cupo = (float) ($credito['cupo'] ?? 0);
        if ($cupo > 0 && ($credito['saldo_cartera'] + $totalEstimado) > $cupo) {
            $excedido = round(($credito['saldo_cartera'] + $totalEstimado) - $cupo, 2);
            $motivos[] = 'cupo excedido en $'.number_format($excedido, 0);
        }

        return [! empty($motivos), $motivos];
    }

    /**
     * Total con IVA que va a tener el pedido, calculado igual que al crearlo.
     *
     * Antes el cupo sólo sumaba `$precios[variante_id]`: un producto agregado
     * (sin variante) pesaba $0, así que un pedido de puros agregados nunca
     * excedía el cupo. Además se comparaba sin IVA contra una cartera que sí
     * lo trae, y una línea sin precio se contaba aunque después se descarte.
     */
    private function totalEstimado(array $items, array $precios, $variantes, $productosAgg, int $listaId, array $preciosPactados = []): float
    {
        $total = 0.0;
        foreach ($items as $it) {
            $linea = $this->resolverLinea($it, $precios, $variantes, $productosAgg, $listaId, $preciosPactados);
            if (! $linea) continue;

            $lineaSub = round($linea['precio'] * (int) $it['cantidad'], 2);
            $total += $lineaSub + round($lineaSub * ($linea['ivaPct'] / 100), 2);
        }

        return round($total, 2);
    }

    /**
     * La misma llave con la que la cotización guarda sus precios pactados.
     *
     * Vive acá y en `CotizacionItem::claveDeLinea()`. Si alguna vez cambia el
     * formato, tienen que cambiar las dos o la conversión deja de encontrar el
     * precio y vuelve a tomar el de la lista sin avisar.
     */
    private function claveDeLinea(array $it): string
    {
        return ! empty($it['variante_id'])
            ? 'variante-'.$it['variante_id']
            : 'producto-'.($it['producto_id'] ?? 0);
    }

    /**
     * Cómo llamar a un producto que ya no se pudo resolver.
     *
     * Vale una consulta extra: es un camino de error, y «variante-412» no le
     * dice a nadie qué hay que recotizar. Se lee sin filtros de activo ni de
     * borrado, justo porque lo que pasó es que dejó de estar.
     */
    private function nombreDeLinea(array $it): string
    {
        if (! empty($it['variante_id'])) {
            // ProductoVariante no usa borrado suave; Producto sí, y es
            // justamente el que puede haber desaparecido.
            $v = ProductoVariante::query()->find($it['variante_id']);
            $nombre = $v ? Producto::withTrashed()->find($v->producto_id)?->nombre : null;

            return $v
                ? trim(($nombre ?? 'Un producto').' '.($v->color_nombre ?? '').' '.($v->talla ?? ''))
                : 'Un producto de la cotización';
        }

        return Producto::withTrashed()->find($it['producto_id'] ?? 0)?->nombre
            ?? 'Un producto de la cotización';
    }

    /**
     * Datos de una línea, o null si no se puede vender.
     *
     * Una línea sin precio en la lista del cliente se descarta a propósito:
     * `precio_proveedor` es el COSTO, no el precio de venta, y caer en él le
     * vendería al cliente B2B a costo.
     *
     * @return array{variante_id:?int, producto_id:?int, sku:string, desc:string, precio:float, ivaPct:float}|null
     */
    private function resolverLinea(array $it, array $precios, $variantes, $productosAgg, int $listaId, array $preciosPactados = []): ?array
    {
        /*
         * El precio pactado manda sobre el de la lista, y se decide ACÁ.
         *
         * Es lo que hace que una cotización signifique algo: el cliente aceptó
         * UN precio con una fecha de vigencia, y si al convertirla se
         * recalculara contra la lista de hoy, cotizar no serviría de nada.
         *
         * Antes esto se aplicaba DESPUÉS de llamar a este método, y ahí estaba
         * el problema: un producto al que le quitaron el precio de la lista
         * después de cotizar se descartaba acá adentro —precio 0— y nunca
         * llegaba a que se le aplicara el pactado. El pedido nacía sin ese
         * renglón, en silencio, y la cotización quedaba marcada «convertida».
         * Sacar un producto de la lista es una decisión de catálogo; no anula
         * un precio que ya se le prometió a un cliente.
         *
         * Sólo entra por acá la conversión de una cotización: el portal y el
         * vendedor mandan el arreglo vacío y siguen tomando el de la lista.
         */
        $pactado = (float) ($preciosPactados[$this->claveDeLinea($it)] ?? 0);

        if (! empty($it['variante_id'])) {
            $var = $variantes[$it['variante_id']] ?? null;
            $precio = $pactado > 0 ? $pactado : (float) ($precios[$it['variante_id']] ?? 0);
            if (! $var || $precio <= 0) return null;

            return [
                'variante_id' => $var->id,
                'producto_id' => $var->producto_id,
                'sku' => $var->codigo_barras ?: ($var->producto?->referencia.'-'.$var->id),
                'desc' => trim(($var->producto?->nombre ?? '').' · '.($var->color_nombre ?? '').' '.($var->talla ?? '')),
                'precio' => $precio,
                'ivaPct' => (float) ($var->producto?->impuesto?->porcentaje ?? 0),
            ];
        }

        if (empty($it['producto_id'])) return null;

        $p = $productosAgg[$it['producto_id']] ?? null;
        if (! $p) return null;

        // Precio del agregado desde su propia lista.
        //
        // Antes esto se consultaba con `class_exists()` sobre un modelo que no
        // existía, así que siempre daba 0 y la línea se descartaba en silencio:
        // ningún producto agregado se podía vender. La tabla se creó el
        // 2026-10-08.
        //
        // Si no tiene precio en esta lista la línea se sigue descartando, pero
        // a propósito: no se cae al `precio_proveedor` porque ese es el COSTO
        // y le vendería al cliente B2B a precio de compra.
        $precio = $pactado > 0 ? $pactado : (float) (PrecioProducto::query()
            ->where('producto_id', $p->id)
            ->where('lista_id', $listaId)
            ->vigentes()
            ->value('precio') ?? 0);

        if ($precio <= 0) return null;

        return [
            'variante_id' => null,
            'producto_id' => $p->id,
            'sku' => $p->referencia,
            'desc' => $p->nombre.' · colores surtidos',
            'precio' => $precio,
            'ivaPct' => (float) ($p->impuesto?->porcentaje ?? 0),
        ];
    }

    /** Consecutivo diario PB-YYMMDD-#### atómico, bajo la transacción abierta. */
    private function siguienteNumero(): string
    {
        $prefijo = 'PB-'.now()->format('ymd').'-';
        $ultimo = PedidoCliente::where('numero', 'like', $prefijo.'%')
            ->lockForUpdate()
            ->orderByDesc('id')->value('numero');
        // Se lee DESPUÉS del prefijo, no por los últimos cuatro: `str_pad`
        // rellena pero no recorta, así que pasado el 9999 el consecutivo crece
        // a cinco dígitos y `substr(-4)` volvía a leer 0001 contra un `numero`
        // que es único.
        $sig = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $sig, 4, '0', STR_PAD_LEFT);
    }
}
