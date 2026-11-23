<?php

namespace App\Modules\Inventario\Actions;

// El modelo del kardex vive en el namespace Dropi por historia del proyecto:
// es el kardex de TODO el inventario, no sólo de ese canal.
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Support\BodegaDeSalida;
use App\Modules\Inventario\Support\CostoPromedio;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Support\Facades\Log;

/**
 * Saca del kardex la mercancía que se despacha en un pedido B2B.
 *
 * Hasta ahora una venta B2B NUNCA bajaba el stock: el único egreso de venta que
 * existía era el del canal Dropi (`DescontarInventarioAlEmpacar`). Se despachaban
 * 50 unidades, se facturaban, y el ERP seguía mostrándolas en bodega — el
 * inventario del sistema no volvía a coincidir con el estante nunca.
 *
 * Se descuenta al DESPACHAR, no al facturar, porque es el momento en que la
 * mercancía sale físicamente y es lo que el conteo del estante va a reflejar.
 */
class DescontarStockPorDespacho
{
    public const REFERENCIA_TIPO = 'pedido_b2b_despacho';

    /**
     * Por qué no se descontó (todo o parte). El despacho lo muestra en pantalla:
     * un inventario que no bajó y nadie avisó es exactamente como se llega a
     * vender mercancía que no está.
     */
    public ?string $motivo = null;

    /**
     * Debe llamarse DENTRO de la transacción del despacho.
     *
     * @return int cuántas líneas se descontaron
     */
    /**
     * Baja del kardex lo que sale.
     *
     * @param  array<int, float>|null  $envio  Cuánto sale de cada línea EN ESTE
     *        despacho, por id de ítem. Null = sale lo que diga la línea, que es
     *        como funcionó siempre.
     *
     *        Se usa en el despacho parcial con resto pendiente: ahí el mismo
     *        pedido sale en dos viajes, y cada viaje descuenta LO SUYO. Sin
     *        esto, el segundo viaje no descontaba nada —la guarda de abajo veía
     *        que el pedido ya tenía egreso y se iba— y la mercancía del segundo
     *        envío salía de la bodega sin bajar del sistema.
     */
    public function ejecutar(PedidoCliente $pedido, ?int $userId = null, ?array $envio = null): int
    {
        $this->envio = $envio;

        /*
         * Idempotencia: si el pedido ya tiene su egreso, no duplicar. El
         * despacho ya se protege con `despachado_at`, pero esto cubre un
         * reintento manual o un despacho corregido a mano.
         *
         * NO aplica cuando se dice explícitamente qué sale en este envío: ahí
         * quien llama ya decidió que esto es un viaje nuevo del mismo pedido, y
         * la guarda lo confundiría con un reintento.
         */
        if ($envio === null) {
            $yaDescontado = InventarioMovimiento::query()
                ->where('referencia_tipo', self::REFERENCIA_TIPO)
                ->where('referencia_id', $pedido->id)
                ->exists();

            if ($yaDescontado) {
                return 0;
            }
        }

        // Se cargan los productos por adelantado: el asiento los necesita para
        // resolver la cuenta propia de cada uno. Con sólo `items`, `$producto`
        // salía null, el costo caía siempre en la cuenta global y la glosa del
        // libro decía «sin-ref» en vez de la referencia.
        $pedido->loadMissing(['items.variante.producto', 'items.producto']);

        $lineas = 0;
        $sinBodega = 0;

        $sujetos = $pedido->items->map(fn ($i) => [
            'variante_id' => $i->variante_id,
            'producto_id' => $i->producto_id,
        ])->all();

        // De dónde sale cada ítem. Normalmente es la bodega que el pedido ya
        // trae; si nació sin ruteo, se deduce de dónde está la mercancía.
        /*
         * Sólo sale mercancía de lo que se puede vender.
         *
         * Esta era la única salida de inventario que no pasaba las categorías:
         * el canal Dropi sí lo hace (SalidaInventarioDropi.php:137). Sin el
         * filtro, cuando el pedido no trae bodega el sistema deducía de dónde
         * sacar mirando sólo dónde hay saldo, y ahí entran BAJAS, GARANTÍA y
         * AVERÍAS: se vendía mercancía chatarrizada o reservada para el
         * reclamo al proveedor, y la prueba de ese reclamo desaparecía del
         * kardex. Al mismo tiempo la bodega buena no bajaba, así que el stock
         * seguía mostrándose disponible.
         */
        $bodegas = BodegaDeSalida::resolver(
            $sujetos,
            $pedido->ubicacion_origen_id,
            [\App\Modules\Dropi\Enums\CategoriaUbicacion::Venta->value],
        );

        if (! $bodegas) {
            // Ni el pedido lo dice ni el kardex lo sabe: no hay existencias de
            // nada de esto en ninguna bodega. No frenamos el despacho —la
            // mercancía ya está saliendo— pero queda el rastro y el aviso.
            Log::warning('[despacho] sin bodega de salida: no se descontó stock', [
                'pedido_id' => $pedido->id, 'numero' => $pedido->numero,
            ]);
            $this->motivo = 'No se pudo determinar de qué bodega salió: '
                .'el pedido no tiene bodega de origen y ninguna referencia tiene existencias.';

            return 0;
        }

        // Antes de escribir nada: si una línea deja el kardex en negativo, el
        // despacho no sale. Los locks que toma quedan hasta el commit.
        $this->exigirSaldo($pedido, $bodegas);

        // Si la bodega se dedujo, se deja escrita en el pedido: la Cola de
        // alistamiento y los reportes filtran por ella.
        if (! $pedido->ubicacion_origen_id) {
            $pedido->forceFill(['ubicacion_origen_id' => reset($bodegas)])->save();
        }

        /*
         * Costo con el que sale cada ítem: promedio ponderado de las entradas
         * a SU bodega Y A SUS ESTANTES.
         *
         * El egreso ya se reparte entre las posiciones (`repartirEgreso`),
         * pero el costo se seguía preguntando sólo a la fila de la sede. Si la
         * mercancía entró directo a `R12-S1-N1` —que es lo que el área pidió
         * poder hacer— la sede no tiene entradas propias y el costo daba CERO:
         * el movimiento de kardex y el asiento de costo de ventas se escribían
         * en cero, el margen bruto daba 100% y nada se quejaba.
         *
         * Se pregunta por el mismo conjunto que usa el reparto: la sede y sus
         * posiciones son el mismo sitio físico.
         */
        $costos = [];
        foreach (array_unique(array_values($bodegas)) as $ubi) {
            $costos[$ubi] = CostoPromedio::porUbicaciones($sujetos, $this->dondeBuscar($ubi));
        }
        $asiento = [];

        foreach ($pedido->items as $item) {
            // Ítem sin catálogo (texto libre): no hay nada que descontar.
            if (! $item->variante_id && ! $item->producto_id) {
                continue;
            }
            $cantidad = $this->aDespachar($item);
            if ($cantidad <= 0) {
                continue;
            }

            $esAgregado = $item->variante_id === null && $item->producto_id !== null;
            $clave = CostoPromedio::clave($item->variante_id, $item->producto_id);

            $ubicacionId = $bodegas[$clave] ?? null;
            if (! $ubicacionId) {
                // Esta referencia no está en ninguna bodega. Se despacha igual
                // —ya salió— pero no se inventa un egreso de algo que el kardex
                // nunca registró como entrada.
                Log::warning('[despacho] ítem sin existencias en ninguna bodega', [
                    'pedido_id' => $pedido->id, 'sku' => $item->sku_snapshot,
                ]);
                $sinBodega++;

                continue;
            }

            $costoUnit = $costos[$ubicacionId][$clave] ?? 0.0;

            foreach ($this->repartirEgreso($item, $ubicacionId, $cantidad) as $ubi => $cuanto) {
            InventarioMovimiento::create([
                'variante_id' => $item->variante_id,
                'producto_id' => $item->producto_id,
                'ubicacion_id' => $ubi,
                'tipo' => 'salida_venta',
                // Negativo: `SUM(cantidad)` es el saldo físico.
                'cantidad' => -$cuanto,
                // Sin costo el kardex no se puede valorizar ni asentar.
                'costo_unit' => $costoUnit,
                'referencia_tipo' => self::REFERENCIA_TIPO,
                'referencia_id' => $pedido->id,
                'user_id' => $userId,
                'notas' => "Despacho pedido {$pedido->numero}"
                    . ($pedido->guia_transportadora ? " · guía {$pedido->guia_transportadora}" : '')
                    . ($esAgregado ? ' · AGREGADO' : ''),
            ]);
            }

            $lineas++;

            if ($costoUnit > 0) {
                $asiento[] = [
                    'producto' => $item->variante?->producto ?? $item->producto,
                    // El producto puede estar borrado (pasa con datos viejos);
                    // el SKU que el pedido guardo al crearse siempre esta.
                    'sku' => $item->sku_snapshot,
                    'valor' => round($costoUnit * $cantidad, 2),
                ];
            }
        }

        $this->causarCosto($pedido, $asiento, $userId);

        if ($sinBodega > 0) {
            $this->motivo = "{$sinBodega} ítem(s) no se descontaron: no tienen existencias en ninguna bodega.";
        }

        return $lineas;
    }

    /**
     * Donde buscar el saldo de una sede: ella misma y sus posiciones.
     *
     * Una bodega y sus racks son el mismo sitio fisico. Si el alistador bajo
     * la mercancia a `R12-S1-N1`, sigue estando en la bodega principal: solo
     * que ahora se sabe en que estante.
     *
     * Esto importa porque el despacho razonaba SOLO contra la fila de la sede.
     * El dia que alguien usara las 435 posiciones para lo que son, el saldo de
     * la sede daba cero y el despacho se trababa con «no hay saldo suficiente»
     * sobre mercancia que estaba ahi, a tres metros.
     *
     * Las posiciones van antes que la sede, igual que en la hoja de picking.
     *
     * @return array<int, int>
     */
    private function dondeBuscar(int $sedeId): array
    {
        // La consulta vive en el modelo: el mismo descuido apareció en cuatro
        // sitios distintos, cada uno con su propia versión.
        return InventarioUbicacion::laSedeYSusPosiciones($sedeId);
    }

    /**
     * Cuanto baja de cada ubicacion, en el orden en que se recorre la bodega.
     *
     * Se toma primero de las posiciones con mas saldo y la sede queda de
     * ultima. Antes escribia todo contra la sede, y con las posiciones en uso
     * eso dejaba la sede en negativo y el estante intacto: el sistema seguiria
     * diciendo que hay 15 unidades en `R12-S1-N1` despues de haberlas
     * despachado, y nadie se entera hasta el conteo fisico.
     *
     * @return array<int, int>  [ubicacion_id => cuanto]
     */
    private function repartirEgreso($item, int $sedeId, int $cantidad): array
    {
        $ubicaciones = $this->dondeBuscar($sedeId);

        $saldos = InventarioMovimiento::query()
            ->whereIn('ubicacion_id', $ubicaciones)
            ->when($item->variante_id,
                fn ($q) => $q->where('variante_id', $item->variante_id),
                fn ($q) => $q->where('producto_id', $item->producto_id)->whereNull('variante_id'),
            )
            ->groupBy('ubicacion_id')
            ->selectRaw('ubicacion_id, SUM(cantidad) as saldo')
            ->pluck('saldo', 'ubicacion_id');

        // Las posiciones de mayor a menor saldo; la sede siempre de ultima,
        // porque es la que absorbe lo que no estuvo ubicado en ningun estante.
        $orden = collect($ubicaciones)
            ->filter(fn ($u) => $u !== $sedeId && (int) ($saldos[$u] ?? 0) > 0)
            ->sortByDesc(fn ($u) => (int) $saldos[$u])
            ->push($sedeId)
            ->values();

        $reparto = [];
        $falta = $cantidad;

        foreach ($orden as $u) {
            if ($falta <= 0) {
                break;
            }

            $disponible = $u === $sedeId ? $falta : min($falta, (int) ($saldos[$u] ?? 0));

            if ($disponible > 0) {
                $reparto[$u] = $disponible;
                $falta -= $disponible;
            }
        }

        // Si nada tenia saldo —no deberia pasar, `exigirSaldo` ya corto— el
        // egreso entero va contra la sede, que es el comportamiento de antes.
        return $reparto ?: [$sedeId => $cantidad];
    }

    /**
     * Frena el despacho si la bodega de origen no tiene saldo para todo el pedido.
     *
     * Hasta ahora el egreso se escribía siempre: despachar 10 con 3 en bodega
     * dejaba el kardex en −7 sin avisar a nadie. Se compara contra el saldo
     * FÍSICO (lo que hay en el estante), no el disponible: el pedido que se
     * está despachando puede tener su propia reserva y se contaría dos veces.
     *
     * Las cantidades se suman por producto porque un mismo SKU puede venir en
     * dos líneas, y cada una sola cabría aunque juntas no.
     *
     * Cada ítem se mide en SU bodega de salida. El que no tiene ninguna no se
     * mide: no se le escribe egreso, así que no puede dejar el kardex en
     * negativo (el despacho lo reporta en `$motivo`).
     *
     * @param  array<string, int>  $bodegas  clave `v:{id}`/`p:{id}` => ubicacion_id
     */
    /**
     * Cuánto sale de esta línea: lo despachado si alguien lo dijo, y si no, lo
     * pedido.
     *
     * Hasta el 21-nov-2026 el despacho no manejaba cantidades: salía el pedido
     * entero o no salía nada, y si la bodega no tenía todo, `exigirSaldo()`
     * abortaba con el camión esperando. El área pidió poder despachar lo que
     * hay («se autoriza el despacho de la mercancía disponible, sin esperar a
     * completar la totalidad»), así que ahora cada línea puede salir con menos.
     *
     * El `??` es lo que hace que nada viejo cambie de significado: una línea
     * que nunca se tocó sigue saliendo completa, y las que ya salieron tienen
     * su `cantidad_despachada` igual a `cantidad` desde la migración.
     *
     * Nunca más de lo pedido: despachar de más no es un despacho parcial, es
     * un error, y dejarlo pasar descontaría stock que nadie compró.
     */
    private function aDespachar(object $item): int
    {
        $pedida = (int) ($item->cantidad ?? 0);

        // Un envío explícito manda sobre todo: es lo que sale AHORA, en este
        // viaje, y puede ser menos de lo que la línea lleva acumulado.
        if ($this->envio !== null) {
            return max(0, min($pedida, (int) ($this->envio[$item->id] ?? 0)));
        }

        $despachada = $item->cantidad_despachada ?? null;

        if ($despachada === null) {
            return $pedida;
        }

        return max(0, min($pedida, (int) $despachada));
    }

    /** Lo que sale en ESTE viaje, por id de ítem. Null = lo que diga la línea. */
    private ?array $envio = null;

    private function exigirSaldo(PedidoCliente $pedido, array $bodegas): void
    {
        $pedidas = [];
        foreach ($pedido->items as $item) {
            if (! $item->variante_id && ! $item->producto_id) continue;
            $cantidad = $this->aDespachar($item);
            if ($cantidad <= 0) continue;

            $clave = CostoPromedio::clave($item->variante_id, $item->producto_id);
            if (! isset($bodegas[$clave])) continue;
            $pedidas[$clave] ??= ['item' => $item, 'ubicacion_id' => $bodegas[$clave], 'cantidad' => 0];
            $pedidas[$clave]['cantidad'] += $cantidad;
        }

        $faltantes = [];
        foreach ($pedidas as ['item' => $item, 'ubicacion_id' => $ubicacionId, 'cantidad' => $cantidad]) {
            // Serializa contra un conteo o traslado simultáneo sobre el mismo
            // producto y bodega, igual que hace el egreso de Dropi.
            $q = InventarioMovimiento::query()->whereIn('ubicacion_id', $this->dondeBuscar($ubicacionId));
            $item->variante_id
                ? $q->where('variante_id', $item->variante_id)
                : $q->where('producto_id', $item->producto_id)->whereNull('variante_id');
            $saldo = (int) $q->lockForUpdate()->get(['cantidad'])->sum('cantidad');

            if ($saldo < $cantidad) {
                $ref = $item->sku_snapshot ?: ($item->descripcion_snapshot ?: "ítem #{$item->id}");
                $faltantes[] = "{$ref}: pide {$cantidad}, hay ".max($saldo, 0);
            }
        }

        abort_if($faltantes, 422,
            'No hay saldo suficiente en la bodega de origen para despachar: '
            .implode(' · ', $faltantes).'. Cargá la entrada o trasladá la mercancía antes de despachar.');
    }

    /**
     * Lleva al libro el costo de lo que salió: debe costo, haber inventario.
     *
     * Hasta ahora la venta sólo registraba el ingreso. El resultado era que el
     * margen bruto del Estado de resultados daba siempre 100% —no había un solo
     * movimiento de clase 6 en toda la base— y la cuenta de inventario jamás
     * bajaba, así que el balance sobrestimaba el activo de forma permanente.
     *
     * Se causa al DESPACHAR, igual que el egreso de kardex: es el momento en
     * que la mercancía sale, y así el libro y el estante cuentan lo mismo.
     *
     * Las cuentas salen de la misma cascada que usa la devolución Dropi:
     * primero la del producto, si no la global del plan.
     *
     * @param  list<array{producto:mixed, sku:?string, valor:float}>  $asiento
     */
    private function causarCosto(PedidoCliente $pedido, array $asiento, ?int $userId): void
    {
        if (! $asiento) {
            // Pasa cuando ningún ítem tiene costo conocido todavía. No se
            // frena el despacho, pero queda el rastro: un asiento en cero
            // ensuciaría el libro sin decir nada.
            Log::warning('[despacho] sin costo conocido: no se causó el costo de ventas', [
                'pedido_id' => $pedido->id, 'numero' => $pedido->numero,
            ]);

            return;
        }

        $fecha = now('America/Bogota')->toDateString();

        /*
         * El costo va donde va su VENTA.
         *
         * El origen del asiento es el pedido, y `pedidos_cliente` no tiene los
         * dos ejes: el estampado de `MovimientoContable` caia en el valor por
         * defecto («fiscal») SIEMPRE. Resultado medido el 26-oct: los dos
         * despachos eran de facturas interna y reparto, y su costo estaba en el
         * libro fiscal. El libro interno mostraba **margen del 100%** —tenia
         * los ingresos no declarados y ningun costo— y la perdida declarada
         * ante la DIAN salia inflada por lo mismo.
         *
         * El destino estaba a un join de distancia: el pedido conoce su
         * factura. Un reparto manda su parte interna, que es lo conservador:
         * el costo de una venta que solo se declara en parte no se puede
         * descontar entero.
         */
        $factura = $pedido->factura_id
            ? \App\Modules\Cartera\Models\FacturaVenta::find($pedido->factura_id)
            : null;

        $destino = $factura?->destino_fiscal ?? DestinoFiscal::porDefecto();
        if ($destino === DestinoFiscal::Reparto) {
            $destino = DestinoFiscal::Interno;
        }
        $linea = $factura?->linea_negocio;

        $lineas = [];

        foreach ($asiento as $a) {
            $producto = $a['producto'];
            $ctaCosto = $producto?->cta('costo') ?? setting('contable.cta_costo_default');
            $ctaInv = $producto?->cta('inventario') ?? setting('contable.cta_inventario_default');
            $ref = $producto?->referencia ?? $a['sku'] ?? 'sin referencia';

            $lineas[] = [
                'fecha' => $fecha, 'cuenta_puc' => $ctaCosto,
                'debe' => $a['valor'], 'haber' => 0,
                'origen_type' => PedidoCliente::class, 'origen_id' => $pedido->id,
                'descripcion' => "Costo de venta {$ref} · despacho {$pedido->numero}",
                'linea_negocio' => $linea, 'destino_fiscal' => $destino,
                'user_id' => $userId,
            ];
            $lineas[] = [
                'fecha' => $fecha, 'cuenta_puc' => $ctaInv,
                'debe' => 0, 'haber' => $a['valor'],
                'origen_type' => PedidoCliente::class, 'origen_id' => $pedido->id,
                'descripcion' => "Salida inventario {$ref} · despacho {$pedido->numero}",
                'linea_negocio' => $linea, 'destino_fiscal' => $destino,
                'user_id' => $userId,
            ];
        }

        MovimientoContable::registrarAsientoAtomico($lineas);
    }
}
