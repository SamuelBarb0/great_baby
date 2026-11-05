<?php

namespace App\Modules\Cartera\Actions;

use App\Models\Contacto;
use App\Modules\Cartera\Models\CondicionCredito;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\FacturaVentaItem;
use App\Modules\Contabilidad\Enums\DestinoFiscal;
use App\Modules\Contabilidad\Enums\LineaNegocio;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Siigo\Models\SiigoConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * F2b · Una factura de venta que no nace de un pedido.
 *
 * Hasta hoy la única forma de que existiera una factura era facturar un pedido
 * B2B. Eso deja afuera lo que el negocio igual factura: un servicio, un flete,
 * una venta que se cerró por teléfono, y —sobre todo— el histórico que el
 * cliente trae en Excel de antes de entrar al ERP.
 *
 * Acá vive el ÚNICO camino para crear esas facturas, y lo usan las dos puertas
 * (el formulario y la importación de Excel). Si cada una armara su propia
 * factura, una de las dos se iba a quedar sin el asiento, sin las retenciones o
 * sin el consecutivo — y una factura sin asiento no aparece en ningún balance,
 * que es la peor clase de error: silencioso.
 *
 * Lo que garantiza, en orden:
 *   1) consecutivo DIAN por el generador transaccional (no MAX()+1)
 *   2) totales que cumplen el invariante de la factura (total = subtotal − descuento + impuestos)
 *   3) retenciones aplicadas ANTES del asiento, que las lee de la factura
 *   4) asiento contable explícito DESPUÉS de los ítems
 *   5) los dos ejes puestos en el documento, de donde los hereda el asiento
 */
class CrearFacturaManual
{
    use AsAction;

    /**
     * @param  array{
     *     contacto_id:int,
     *     fecha_emision?:string|null,
     *     fecha_vencimiento?:string|null,
     *     linea_negocio?:string|null,
     *     destino_fiscal?:string|null,
     *     monto_fiscal?:float|null,
     *     motivo_destino?:string|null,
     *     observaciones?:string|null,
     *     items:array<int,array<string,mixed>>
     * }  $datos
     */
    public function handle(array $datos, ?int $userId = null): FacturaVenta
    {
        $contacto = Contacto::findOrFail((int) ($datos['contacto_id'] ?? 0));

        $items = array_values(array_filter(
            $datos['items'] ?? [],
            fn ($i) => trim((string) ($i['descripcion'] ?? '')) !== '' && (float) ($i['cantidad'] ?? 0) > 0,
        ));

        if ($items === []) {
            throw new InvalidArgumentException('La factura necesita al menos un ítem con descripción y cantidad.');
        }

        /*
         * Todo ítem apunta a un producto del catálogo. No es un capricho de
         * acá: la base lo exige (`chk_factura_venta_item_sujeto`, que existe
         * para que el inventario nunca tenga filas sin saber de qué eran) y
         * SIIGO también, porque su API pide un `code` por línea.
         *
         * Para lo que no es mercancía —fletes, servicios, instalaciones— se
         * crea UNA vez el producto en el catálogo sin control de stock, y
         * queda disponible para siempre. El mensaje lo dice así porque
         * «producto_id requerido» no le explica a nadie qué hacer.
         */
        $sinProducto = array_values(array_filter(
            $items,
            fn ($i) => ! (($i['producto_id'] ?? null) ?: null),
        ));

        if ($sinProducto !== []) {
            $cuales = implode(', ', array_map(
                fn ($i) => '«'.trim((string) $i['descripcion']).'»',
                array_slice($sinProducto, 0, 3),
            ));

            throw new InvalidArgumentException(
                "Estos ítems no están amarrados a un producto del catálogo: {$cuales}. "
                .'Buscalos con el buscador de productos. Si es un servicio o un flete, creá el '
                .'producto una vez en Catálogo → Productos (sin control de stock) y ya queda para siempre.'
            );
        }

        $emision = $this->fecha($datos['fecha_emision'] ?? null) ?? now('America/Bogota')->toDateString();
        $vencimiento = $this->fecha($datos['fecha_vencimiento'] ?? null)
            ?? Carbon::parse($emision)->addDays($this->plazoDe($contacto->id))->toDateString();

        $destino = $this->destino($datos['destino_fiscal'] ?? null);
        $linea = $this->linea($datos['linea_negocio'] ?? null);
        $montoFiscal = isset($datos['monto_fiscal']) && $datos['monto_fiscal'] !== null && $datos['monto_fiscal'] !== ''
            ? round((float) $datos['monto_fiscal'], 2)
            : null;

        $calculo = $this->calcular($items);

        if ($calculo['total'] <= 0) {
            throw new InvalidArgumentException('La factura quedaría en $0. Revisá cantidades y precios.');
        }

        /*
         * El reparto exige decir cuánto se declara, y que sea una porción de
         * verdad: en 0 no es un reparto sino «interno», y en el total tampoco,
         * es «fiscal». Se valida acá y no sólo en el formulario, porque la otra
         * puerta es un Excel y nadie revisa 300 filas a ojo.
         */
        if ($destino === DestinoFiscal::Reparto) {
            if ($montoFiscal === null || $montoFiscal <= 0) {
                throw new InvalidArgumentException('En un reparto hay que decir cuánto se declara en SIIGO.');
            }
            if ($montoFiscal >= $calculo['total']) {
                throw new InvalidArgumentException(sprintf(
                    'Lo declarado ($%s) no puede ser igual ni mayor al total ($%s). Si se declara todo, el destino es «Fiscal».',
                    number_format($montoFiscal, 0, ',', '.'),
                    number_format($calculo['total'], 0, ',', '.'),
                ));
            }
        }

        if ($destino !== DestinoFiscal::Siigo && trim((string) ($datos['motivo_destino'] ?? '')) === '') {
            throw new InvalidArgumentException('Para que la factura no vaya a lo fiscal hay que escribir por qué.');
        }

        /*
         * El destino manda, no el estado de SIIGO en este segundo.
         *
         * Antes era `$siigoActivo && $destino->tieneParteFiscal()`. Con SIIGO
         * caído la factura nacía marcada «no viaja» y quedaba así para
         * siempre, porque nadie la vuelve a evaluar. Que la integración esté
         * arriba o abajo no cambia si la factura se declara: cambia cuándo
         * sale. De eso se ocupan la cola y el interruptor `push_auto`.
         */
        $viajaASiigo = $destino->tieneParteFiscal();

        return DB::transaction(function () use (
            $contacto, $emision, $vencimiento, $destino, $linea,
            $montoFiscal, $calculo, $datos, $viajaASiigo, $userId
        ) {
            $factura = FacturaVenta::create([
                'numero' => $this->siguienteNumero(),
                'contacto_id' => $contacto->id,
                'fecha_emision' => $emision,
                'fecha_vencimiento' => $vencimiento,
                'estado' => 'pendiente',
                'tipo' => $vencimiento > $emision ? 'credito' : 'contado',
                'subtotal' => $calculo['subtotal'],
                'descuento' => $calculo['descuento'],
                'impuestos' => $calculo['impuestos'],
                'total' => $calculo['total'],
                'saldo' => $calculo['total'],
                // Sólo nace electrónica si de verdad va a viajar a SIIGO. Una
                // factura interna marcada electrónica queda esperando una
                // emisión que nadie va a disparar.
                'es_electronica' => $viajaASiigo,
                'linea_negocio' => $linea,
                'destino_fiscal' => $destino,
                'monto_fiscal' => $destino === DestinoFiscal::Reparto ? $montoFiscal : null,
                'motivo_destino' => $destino === DestinoFiscal::Siigo ? null : ($datos['motivo_destino'] ?? null),
                'observaciones' => $datos['observaciones'] ?? null,
                'facturado_por_user_id' => $userId ?? auth()->id(),
                'facturado_send_dian' => $viajaASiigo,
                'facturado_send_mail' => false,
            ]);

            foreach ($calculo['lineas'] as $l) {
                FacturaVentaItem::create([
                    'factura_id' => $factura->id,
                    'producto_id' => $l['producto_id'],
                    'descripcion' => $l['descripcion'],
                    'cantidad' => $l['cantidad'],
                    'precio_unit' => $l['precio_unit'],
                    'descuento_pct' => $l['descuento_pct'],
                    'impuesto_pct' => $l['impuesto_pct'],
                    'subtotal' => $l['subtotal'],
                ]);
            }

            // Antes del asiento: las lee de la factura.
            app(AplicarRetencionesVenta::class)->ejecutar($factura);

            // Explícito y después de los ítems: el observer `saved` corre con
            // items=0 y no asienta nada.
            (new RegistrarAsientoContable())->factura($factura->refresh());

            return $factura->refresh();
        });
    }

    /**
     * Los totales de la factura, calculados una sola vez.
     *
     * El `subtotal` del encabezado es BRUTO y el descuento va aparte: así lo
     * lee `RegistrarAsientoContable`, que postea como ingreso
     * `subtotal − descuento`. Si acá se guardara el subtotal ya neteado, el
     * descuento se restaría dos veces y el ingreso del libro saldría corto.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array{subtotal:float,descuento:float,impuestos:float,total:float,lineas:array<int,array<string,mixed>>}
     */
    public function calcular(array $items): array
    {
        $subtotal = 0.0;
        $descuento = 0.0;
        $impuestos = 0.0;
        $lineas = [];

        foreach ($items as $i) {
            $cantidad = round((float) ($i['cantidad'] ?? 0), 3);
            $precio = round((float) ($i['precio_unit'] ?? 0), 2);
            $descPct = min(100, max(0, (float) ($i['descuento_pct'] ?? 0)));
            $ivaPct = max(0, (float) ($i['impuesto_pct'] ?? 0));

            $bruto = round($cantidad * $precio, 2);
            $desc = round($bruto * $descPct / 100, 2);
            $neto = round($bruto - $desc, 2);
            $iva = round($neto * $ivaPct / 100, 2);

            $subtotal += $bruto;
            $descuento += $desc;
            $impuestos += $iva;

            $lineas[] = [
                'producto_id' => ($i['producto_id'] ?? null) ?: null,
                'descripcion' => trim((string) $i['descripcion']),
                'cantidad' => $cantidad,
                'precio_unit' => $precio,
                'descuento_pct' => $descPct,
                'impuesto_pct' => $ivaPct,
                'subtotal' => $neto,
            ];
        }

        $subtotal = round($subtotal, 2);
        $descuento = round($descuento, 2);
        $impuestos = round($impuestos, 2);

        return [
            'subtotal' => $subtotal,
            'descuento' => $descuento,
            'impuestos' => $impuestos,
            // El total se ARMA con los componentes ya redondeados, no se
            // redondea aparte: así nunca difiere en el centavo que haría
            // reventar el invariante de partida doble de FacturaVenta.
            'total' => round($subtotal - $descuento + $impuestos, 2),
            'lineas' => $lineas,
        ];
    }

    /**
     * Qué le falta a estos ítems para poder viajar a SIIGO.
     *
     * No bloquea: devuelve los avisos para que la pantalla los muestre y la
     * persona decida. Una factura interna nunca viaja a SIIGO, así que
     * exigirle producto sincronizado sería estorbar por nada.
     *
     * Existe porque `SiigoEmisionService::codigoSiigoDe()` LANZA cuando el ítem
     * no tiene código allá, y ese error aparecía recién al emitir — horas
     * después, en la campana, sin la factura delante.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    public static function avisosDeSiigo(array $items, DestinoFiscal $destino): array
    {
        if (! $destino->tieneParteFiscal()) {
            return [];
        }

        $avisos = [];
        foreach ($items as $i) {
            $id = ($i['producto_id'] ?? null) ?: null;
            $desc = trim((string) ($i['descripcion'] ?? '—'));

            if (! $id) {
                // Sin producto la factura ni siquiera se crea; eso lo corta
                // `handle()`. Acá no hay nada que avisar.
                continue;
            }

            $p = Producto::query()->whereKey($id)->first(['id', 'nombre', 'referencia', 'siigo_id']);
            if (! $p) {
                continue;
            }

            /*
             * Se espeja lo que de verdad exige `SiigoEmisionService::codigoSiigoDe()`:
             * para un ítem sin variante, el código que viaja es la REFERENCIA, y
             * sólo sirve si el producto además está sincronizado. Chequear nada
             * más el `siigo_id` dejaba pasar un producto sincronizado sin
             * referencia, que allá revienta igual.
             */
            if (! $p->siigo_id) {
                $avisos[] = "«{$p->nombre}»: todavía no está sincronizado con SIIGO "
                    .'(Catálogo → Productos → Sincronizar). Hasta entonces la emisión falla.';
            } elseif (trim((string) $p->referencia) === '') {
                $avisos[] = "«{$p->nombre}»: está en SIIGO pero sin referencia, y la referencia es "
                    .'el código con el que viaja el ítem. Ponele una en el catálogo.';
            }
        }

        return array_values(array_unique($avisos));
    }

    /** El consecutivo DIAN, con el mismo prefijo que usa la facturación B2B. */
    private function siguienteNumero(): string
    {
        $prefijo = (setting('empresa.prefijo_dian') ?: 'FV').'-'.now('America/Bogota')->format('ymd').'-';
        $rangoHasta = (int) setting('empresa.rango_hasta', 0) ?: null;

        return SiguienteConsecutivoFactura::run($prefijo, 4, $rangoHasta);
    }

    /** El plazo del crédito del cliente; 30 días si no tiene condición activa. */
    private function plazoDe(int $contactoId): int
    {
        return (int) (CondicionCredito::query()
            ->where('contacto_id', $contactoId)
            ->where('activa', true)
            ->latest('vigente_desde')
            ->value('plazo_dias') ?? 30);
    }

    private function fecha(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function destino(?string $v): DestinoFiscal
    {
        return DestinoFiscal::tryFrom(trim((string) $v)) ?? DestinoFiscal::porDefecto();
    }

    private function linea(?string $v): LineaNegocio
    {
        return LineaNegocio::tryFrom(trim((string) $v)) ?? LineaNegocio::GreatBaby;
    }
}
