<?php

namespace App\Modules\Siigo\Services;

use App\Mail\FacturaClienteMail;
use App\Models\NotificacionErp;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Exceptions\PagoSinFacturaEnSiigo;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Support\CuentasSiigo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Emite facturas de venta electrónicas a la DIAN vía Siigo API (POST /v1/invoices).
 * Timbra con stamp.send = true.
 *
 * Anti-doble-emisión: setea `emitiendo_at` bajo lock; si ya hay uno < 60s se rechaza.
 * Email + notif salen FUERA de la DB::transaction para no bloquear la conexión con SMTP.
 *
 * @see https://developers.siigo.com/docs/siigoapi/invoice/1-create-invoice
 */
class SiigoEmisionService
{
    public function __construct(
        private readonly SiigoClient $cliente,
        private readonly QrDianService $qr,
    ) {}

    public function emitir(FacturaVenta $factura): FacturaVenta
    {
        $this->validarElegible($factura);
        $config = $this->validarConfig();

        // El cliente tiene que existir en SIIGO antes de facturarle.
        //   Sólo había importación (SIIGO → ERP), nunca envío, así que a un
        //   cliente dado de alta en el ERP la primera factura le rebotaba con
        //   «The customer doesn't exist». Comprobado en el flujo real.
        //
        //   Va ANTES de tomar el lock: si esto falla, la factura no debe
        //   quedar marcada como "emitiendo" y bloqueada 60 segundos.
        $this->asegurarClienteEnSiigo($factura->contacto()->first());

        // 1. LOCK anti-carrera + set emitiendo_at
        $factura = DB::transaction(function () use ($factura) {
            $f = FacturaVenta::lockForUpdate()->findOrFail($factura->id);
            if ($f->es_electronica && !empty($f->cufe)) {
                throw new RuntimeException("La factura {$f->numero} ya fue emitida electrónicamente.");
            }
            if ($f->emitiendo_at && $f->emitiendo_at->diffInSeconds(now()) < 60) {
                throw new RuntimeException("Ya hay una emisión en curso para {$f->numero} (iniciada hace " . $f->emitiendo_at->diffInSeconds(now()) . 's).');
            }
            $f->emitiendo_at = now();
            $f->save();
            return $f->load(['contacto', 'items.variante.producto']);
        });

        $payload = $this->construirPayload($factura, $config);

        Log::channel('single')->info('[SIIGO emisión] enviando', [
            'factura_id' => $factura->id,
            'numero' => $factura->numero,
        ]);

        // 2. HTTP a SIIGO — FUERA de la transacción, con try/finally para limpiar la bandera SIEMPRE
        try {
            $response = $this->cliente->request('POST', '/v1/invoices', $payload, 1, "inv:{$factura->id}");
        } catch (\Throwable $e) {
            // Excepción de red / DNS / timeout: liberar bandera y agendar reintento
            $factura->update(['emitiendo_at' => null]);
            \App\Jobs\ReintentarEmisionDian::dispatch($factura->id)->delay(now()->addMinute());
            NotificacionErp::crear([
                'tipo' => 'timbrado_rechazado',
                'titulo' => "Error de red al emitir {$factura->numero}",
                'mensaje' => 'Se agendaron reintentos automáticos. Error: ' . $e->getMessage(),
                'color' => 'warning',
                'icono' => 'heroicon-o-arrow-path',
                'url' => '/app/facturas',
            ]);
            throw new RuntimeException("Error de red hacia SIIGO: {$e->getMessage()} — se agendó reintento.");
        }

        if ($response->failed()) {
            // Rechazo HTTP de SIIGO (4xx/5xx): agendar reintento + limpiar bandera
            $factura->update(['emitiendo_at' => null]);
            \App\Jobs\ReintentarEmisionDian::dispatch($factura->id)->delay(now()->addMinutes(5));

            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('message')
                ?? 'Siigo rechazó la factura (respuesta ' . $response->status() . ').');

            NotificacionErp::crear([
                'tipo' => 'timbrado_rechazado',
                'titulo' => "SIIGO rechazó {$factura->numero} (HTTP {$response->status()})",
                'mensaje' => $msg . ' — se agendó reintento en 5min.',
                'color' => 'danger',
                'icono' => 'heroicon-o-x-circle',
                'url' => '/app/facturas',
            ]);
            throw new RuntimeException("Siigo rechazó la factura: {$msg}");
        }

        $data = (array) $response->json();

        // 3. Persistir resultado + limpiar bandera (todo transaccional)
        $factura = DB::transaction(function () use ($factura, $data) {
            $stamp = (array) ($data['stamp'] ?? []);
            $stampStatus = (string) ($stamp['status'] ?? 'Unknown');
            $cufe = (string) ($stamp['cufe'] ?? $stamp['cude'] ?? '');

            $factura->fill([
                'siigo_id' => (string) ($data['id'] ?? ''),
                'numero_siigo' => (string) ($data['name'] ?? $data['number'] ?? ''),
                // El documento impreso que devuelve SIIGO, para poder abrirlo
                // e imprimirlo desde el ERP sin ir a buscarlo allá.
                'siigo_public_url' => (string) ($data['public_url'] ?? '') ?: null,
                'cufe' => $cufe,
                'stamp_status' => $stampStatus,
                // Guardamos respuesta cruda pero excluimos de auditoría (ver FacturaVenta::$auditExclude)
                'siigo_response' => $data,
                'es_electronica' => true,
                'emitiendo_at' => null,
            ]);

            // Token público único (retry si colisión, aunque 48-char random es ~imposible)
            if ($factura->token_publico === null) {
                do {
                    $token = Str::random(48);
                } while (FacturaVenta::where('token_publico', $token)->exists());
                $factura->token_publico = $token;
            }

            if ($cufe !== '' && $this->stampAceptado($stampStatus)) {
                $factura->emitida_at = now();
                $factura->qr_html = $this->qr->generarSvg($factura);
                $factura->qr_url = $this->qr->urlConsultaDian($cufe);
                if ($factura->estado === EstadoFactura::Borrador) {
                    $factura->estado = EstadoFactura::Pendiente;
                }
            } else {
                Log::channel('single')->warning('[SIIGO emisión] timbrado no aceptado', [
                    'factura_id' => $factura->id,
                    'stamp_status' => $stampStatus,
                    'errors' => $stamp['errors'] ?? null,
                ]);
            }

            $factura->save();
            return $factura;
        });

        // 4. Side-effects FUERA de la transacción (email + notif)
        $mailStatus = 'omitido';
        if ($factura->es_electronica && $factura->emitida_at) {
            $mailStatus = $this->enviarPorEmail($factura);

            NotificacionErp::crear([
                'tipo' => 'timbrado_ok',
                'titulo' => "Factura {$factura->numero} timbrada",
                'mensaje' => "SIIGO nº {$factura->numero_siigo} · CUFE " . substr((string) $factura->cufe, 0, 24) . '… · Email: ' . $mailStatus,
                'color' => 'success',
                'icono' => 'heroicon-o-check-badge',
                'url' => '/app/facturas',
            ]);
        } else {
            NotificacionErp::crear([
                'tipo' => 'timbrado_rechazado',
                'titulo' => "DIAN rechazó factura {$factura->numero}",
                'mensaje' => "Stamp: {$factura->stamp_status}. Se agendaron reintentos automáticos.",
                'color' => 'danger',
                'icono' => 'heroicon-o-x-circle',
                'url' => '/app/facturas',
            ]);
            // Agenda reintento con backoff exponencial
            \App\Jobs\ReintentarEmisionDian::dispatch($factura->id)->delay(now()->addMinute());
        }

        return $factura;
    }

    private function validarElegible(FacturaVenta $factura): void
    {
        if ($factura->es_electronica && !empty($factura->cufe)) {
            throw new RuntimeException("La factura {$factura->numero} ya fue emitida electrónicamente (CUFE: " . substr($factura->cufe, 0, 20) . '…).');
        }

        // El documento ya existe en SIIGO aunque la DIAN no lo haya timbrado.
        //
        // El guard de arriba sólo miraba el CUFE, así que una factura creada en
        // SIIGO y rechazada por la DIAN (sin CUFE) seguía siendo "elegible" y
        // cada reintento la volvía a enviar. Se vio en vivo: 11 reintentos
        // encolados para la MISMA factura que ya estaba allá. La clave de
        // idempotencia los frena mientras SIIGO la recuerde, pero esas claves
        // caducan y después se duplicaría el documento, consumiendo numeración
        // de la DIAN. Si el timbrado falló, lo que corresponde es reintentar el
        // timbrado, no volver a crear la factura.
        if (! empty($factura->siigo_id)) {
            throw new RuntimeException(
                "La factura {$factura->numero} ya está en SIIGO como {$factura->numero_siigo} "
                .'· no se vuelve a crear. Si la DIAN la rechazó, hay que reintentar el timbrado desde SIIGO.'
            );
        }

        if ($factura->items->isEmpty()) {
            $factura->load('items');
        }

        if ($factura->items->isEmpty()) {
            throw new RuntimeException('La factura no tiene ítems.');
        }

        if (empty($factura->contacto?->numero_documento)) {
            throw new RuntimeException('El cliente no tiene identificación — obligatoria para DIAN.');
        }
    }

    private function validarConfig(): SiigoConfig
    {
        $config = SiigoConfig::current();

        if (! $config->activo) {
            throw new RuntimeException('La integración con Siigo está desactivada. Actívala en Configuración → Integración Siigo.');
        }

        $labels = [
            'tipo_documento_id' => 'Tipo de documento (Document Type ID)',
            'seller_id' => 'Vendedor Siigo (Seller ID)',
            'payment_type_id' => 'Método de pago Siigo (Payment Type ID)',
        ];

        foreach ($labels as $col => $label) {
            if (empty($config->{$col})) {
                throw new RuntimeException("Falta configurar «{$label}» en Configuración → Integración Siigo.");
            }
        }

        return $config;
    }

    private function construirPayload(FacturaVenta $factura, SiigoConfig $config): array
    {
        // Fix H-2 CRÍTICO re-audit · trazabilidad SIIGO/DIAN para items agregados.
        //   Antes: para item con variante_id NULL (agregado) enviábamos
        //   `ITEM-{id}` como code → SIIGO creaba productos ghost o rechazaba.
        //   Ahora: fallback a la referencia del producto agregado. La migración
        //   100011 agregó producto_id justo para esto.
        $factura->loadMissing(['items.variante.producto', 'items.producto', 'ubicacion']);

        // Mapa porcentaje IVA → id de impuesto en SIIGO. Sin esto, SIIGO calcula
        //   el total SIN IVA y rechaza la factura con `invalid_total_payments`
        //   (él suma 75.000 mientras el ERP paga 89.250).
        $ivaPorPct = \DB::table('impuestos')
            ->where('tipo', 'iva')->where('activo', true)->whereNotNull('siigo_id')
            ->pluck('siigo_id', 'porcentaje');

        $retencionesSiigo = $this->retencionesSiigo($factura);

        $items = $factura->items->map(function ($item) use ($ivaPorPct, $retencionesSiigo) {
            $variante = $item->variante;
            $producto = $variante?->producto ?? $item->producto;
            $codigo = $this->codigoSiigoDe($variante, $producto, $item->descripcion);

            $linea = [
                'code' => (string) $codigo,
                'description' => (string) ($item->descripcion ?? $producto?->nombre ?? 'Ítem'),
                'quantity' => (float) $item->cantidad,
                'price' => (float) $item->precio_unit,
                'discount' => (float) ($item->descuento_pct ?? 0),
            ];

            $pct = (float) ($item->impuesto_pct ?? 0);
            if ($pct > 0) {
                $siigoTaxId = null;
                foreach ($ivaPorPct as $p => $sid) {
                    if (abs((float) $p - $pct) < 0.01) { $siigoTaxId = (int) $sid; break; }
                }
                if (! $siigoTaxId) {
                    throw new RuntimeException(
                        "El IVA del {$pct}% de la línea «{$linea['description']}» no está mapeado a SIIGO. "
                        . 'Asigná su «ID Siigo» en Catálogo → Maestras → Impuestos.'
                    );
                }
                $linea['taxes'] = [['id' => $siigoTaxId]];
            }

            // Retenciones del cliente: SIIGO las calcula por ítem con el
            // impuesto de retención y las descuenta del total a pagar.
            foreach ($retencionesSiigo as $taxId) {
                $linea['taxes'][] = ['id' => $taxId];
            }

            return $linea;
        })->all();

        // UBIC-5 · Si la factura está ligada a una ubicación con resolución
        // DIAN propia, usamos ESA resolución en el document.id. Esto hace que
        // SIIGO timbre con el prefijo/numeración correctos para la bodega
        // despachadora (ej: punto venta local vs factura electrónica).
        // Si la ubicación no tiene resolución, cae al tipo_documento_id global
        // (comportamiento previo, 100% retrocompatible).
        $documentId = (int) $config->tipo_documento_id;
        if ($factura->ubicacion && $factura->ubicacion->siigo_resolution_id) {
            $documentId = (int) $factura->ubicacion->siigo_resolution_id;
        }

        $payload = [
            'document' => ['id' => $documentId],
            'date' => $factura->fecha_emision->format('Y-m-d'),
            'customer' => ['identification' => (string) $factura->contacto->numero_documento],
            'seller' => (int) $config->seller_id,
            'items' => $items,
            'payments' => [[
                'id' => (int) $config->payment_type_id,
                // Neto de retenciones: SIIGO exige que los pagos sumen el
                // total MENOS lo retenido, si no responde invalid_total_payments.
                'value' => round((float) $factura->total - (float) $factura->retenciones, 2),
                'due_date' => $factura->fecha_vencimiento?->format('Y-m-d')
                    ?? $factura->fecha_emision->format('Y-m-d'),
            ]],
            // Facturador pack · si el Facturador tildó "no mandar a DIAN" en la
            //   bandeja, la factura nace guardada en SIIGO pero sin radicar.
            //   Mail idem · default: enviar a DIAN sí, mail no (comportamiento
            //   histórico), pero quien emitió puede sobrescribirlo.
            'stamp' => ['send' => (bool) ($factura->facturado_send_dian ?? true)],
            'mail'  => ['send' => (bool) ($factura->facturado_send_mail ?? false)],
        ];

        if (! empty($factura->observaciones)) {
            $payload['observations'] = (string) $factura->observaciones;
        }

        return $payload;
    }

    /**
     * Ids de impuesto SIIGO de las retenciones que lleva la factura.
     *
     * Una retención sin `siigo_tax_id` frena la emisión, igual que un IVA sin
     * mapear: mandar la factura sin ella dejaría en SIIGO un total a pagar
     * distinto del que ve el cliente en el ERP.
     *
     * @return list<int>
     */
    private function retencionesSiigo(FacturaVenta $factura): array
    {
        if ((float) $factura->retenciones <= 0) {
            return [];
        }

        return $factura->retencionesAplicadas()->with('config')->get()
            ->map(function ($r) {
                $taxId = (int) ($r->config?->siigo_tax_id ?? 0);
                if (! $taxId) {
                    throw new RuntimeException(
                        "La regla de {$r->tipo} «{$r->config?->concepto}» no tiene «ID impuesto SIIGO». "
                        .'Asignalo en Cartera → Retenciones.'
                    );
                }

                return $taxId;
            })
            ->unique()->values()->all();
    }

    /**
     * Encola el envío del email al cliente (queue en producción, sync en local).
     * Retorna 'ok' | 'omitido' | 'falló'.
     */
    private function enviarPorEmail(FacturaVenta $factura): string
    {
        $email = $factura->contacto?->email;
        if (empty($email)) {
            return 'omitido (contacto sin email)';
        }

        try {
            $link = route('cartera.factura.publica', $factura->token_publico);
            Mail::to($email)->queue(new FacturaClienteMail($factura, $link));

            Log::channel('single')->info('[SIIGO emisión] email encolado', [
                'factura_id' => $factura->id, 'to' => $email,
            ]);
            return 'encolado';
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[SIIGO emisión] fallo al encolar email', [
                'factura_id' => $factura->id, 'error' => $e->getMessage(),
            ]);
            return 'falló: ' . $e->getMessage();
        }
    }

    private function stampAceptado(string $status): bool
    {
        return in_array(strtolower($status), ['accepted', 'aceptado', 'enviado', 'ok', 'success'], true);
    }

    /**
     * F9 · Emite una FACTURA DE COMPRA (proveedor) contra SIIGO.
     * Endpoint: POST /v1/purchases
     * Se dispara al confirmar una `RecepcionCompra` (mercancía recibida).
     *
     * Reutiliza el mismo cliente HTTP + logging + rate limit del sync de
     * productos. Idempotente: si la recepción ya tiene `siigo_id`, no re-emite.
     *
     * @param  \App\Modules\Compras\Models\RecepcionCompra  $r
     * @return \App\Modules\Compras\Models\RecepcionCompra
     */
    /**
     * COMP-B4 · N recepciones → 1 factura.
     *
     * El proveedor suele mandar UNA factura que cubre VARIAS recepciones
     * (ej. llegaron 3 contenedores en días distintos pero la factura es una).
     * Antes de emitir individualmente, agrupamos recepciones del MISMO
     * proveedor con el MISMO `factura_proveedor` (no vacío) que aún no estén
     * en SIIGO y las consolidamos en un POST único a /v1/purchases. Todas
     * quedan con el mismo `siigo_id` para que el voucher egreso (COMP-B3)
     * y el reporte CxP vean una sola CxP en SIIGO, no N saldos huérfanos.
     */
    public function emitirCompra(\App\Modules\Compras\Models\RecepcionCompra $r): \App\Modules\Compras\Models\RecepcionCompra
    {
        if ($r->siigo_id) {
            return $r; // ya emitida · idempotente
        }
        if ($r->estado !== 'confirmada') {
            throw new RuntimeException("La recepción {$r->numero} no está confirmada.");
        }

        // Agrupación por factura_proveedor: si hay hermanas sin siigo_id,
        // consolidamos. Si r es la única con ese nº de factura, cae al flujo
        // individual original (misma función, pero $hermanas queda vacía).
        $hermanas = \App\Modules\Compras\Models\RecepcionCompra::query()
            ->whereNull('siigo_id')
            ->where('estado', 'confirmada')
            ->where('id', '!=', $r->id)
            ->where('factura_proveedor', $r->factura_proveedor)
            ->when($r->factura_proveedor, function ($q) use ($r) {
                $q->whereHas('orden', fn ($qq) => $qq->where('proveedor_id', $r->orden->proveedor_id));
            }, fn ($q) => $q->whereRaw('1=0'))
            ->get();
        if ($hermanas->isNotEmpty()) {
            return $this->emitirCompraConsolidada($hermanas->prepend($r));
        }

        $r->loadMissing(['orden.proveedor', 'items.ordenItem.producto', 'bodega']);

        if (empty($r->orden?->proveedor?->numero_documento)) {
            throw new RuntimeException("El proveedor de la OC {$r->orden->numero} no tiene NIT.");
        }

        $ivaTaxId = (int) setting('siigo.tax_id_iva_19');

        // Items: reutiliza referencias de producto/variante del ERP.
        // SIIGO Purchases exige: type, code, quantity, price y taxes[] por item.
        $items = $r->items->map(function ($i) use ($ivaTaxId) {
            $ordenItem = $i->ordenItem;
            $producto = $ordenItem?->producto;
            $variante = $ordenItem?->variante;
            // Mismo criterio que en la factura: el código con que SIIGO conoce
            // el ítem, no el de barras a ciegas.
            $code = $this->codigoSiigoDe($variante, $producto, $producto?->nombre);
            $line = [
                'type' => 'Product',
                'code' => (string) $code,
                'description' => (string) ($producto?->nombre ?? 'Ítem'),
                'quantity' => (float) $i->cantidad_recibida,
                'price' => (float) ($ordenItem?->precio_unit ?? 0),
                'discount' => 0,
            ];
            /*
             * El IVA se adjunta sólo si la LÍNEA DE LA ORDEN lo lleva.
             *
             * Antes se le pegaba a toda línea sin mirar la orden, así que una
             * compra exenta o al 0% salía gravada al 19% en SIIGO: el ERP
             * mandaba `payments` por el total de la orden y SIIGO calculaba
             * otro, rechazando todo con
             *   `invalid_total_payments · The total purchase calculated is X`.
             * Comprobado el 2026-10-14 con una OC real de $240.000 sin IVA,
             * que SIIGO liquidaba en $285.600 (exactamente 19% más).
             */
            if ($ivaTaxId > 0 && (float) ($ordenItem?->iva_pct ?? 0) > 0) {
                $line['taxes'] = [['id' => $ivaTaxId]];
            }

            return $line;
        })->filter(fn ($it) => $it['quantity'] > 0)->values()->all();

        if (empty($items)) {
            throw new RuntimeException("La recepción {$r->numero} no tiene ítems recibidos.");
        }

        $total = (float) ($r->orden?->total ?? 0);
        $paymentId = (int) setting('siigo.payment_type_compra_default', 0);

        $payload = [
            'document' => ['id' => (int) setting('siigo.doc_type_compra', 0)],
            'date' => $r->fecha_recepcion->format('Y-m-d'),
            'supplier' => ['identification' => (string) $r->orden->proveedor->numero_documento],
            'cost_center' => (int) setting('siigo.cost_center_compras', 0) ?: null,
            // SIIGO: para moneda local (COP) NO se envía currency; sólo moneda extranjera lo requiere.
            'provider_invoice' => [
                'prefix' => 'FC',
                // SIIGO rechaza letras/guiones en number · extrae solo dígitos
                'number' => (string) (preg_replace('/\D/', '', (string) ($r->factura_proveedor ?: $r->numero)) ?: $r->id),
            ],
            'items' => $items,
            'observations' => "Recepción {$r->numero} · OC {$r->orden->numero}",
        ];
        if ($paymentId > 0 && $total > 0) {
            $payload['payments'] = [[
                'id' => $paymentId,
                'value' => round($total, 2),
                'due_date' => now()->addDays(30)->format('Y-m-d'),
            ]];
        }
        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== 0);

        Log::channel('single')->info('[SIIGO compra] enviando', [
            'recepcion_id' => $r->id, 'numero' => $r->numero,
        ]);

        // A2 FIX · B1 CRÍTICO · Idempotency-Key previene FC proveedor
        //   duplicada en SIIGO cuando hay timeout+retry (tries=5 del job).
        //   El path consolidado (línea ~509) ya lo pasa; faltaba acá.
        $response = $this->cliente->request('POST', '/v1/purchases', $payload, 1, "rec:{$r->id}");

        if ($response->failed()) {
            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('message')
                ?? 'SIIGO rechazó la factura de compra (HTTP ' . $response->status() . ').');

            // "The document already exists": la compra sí se creó en SIIGO en un
            //   intento anterior, pero el ERP no alcanzó a guardar el id (pasaba
            //   siempre: el guard de inmutabilidad de RecepcionCompra bloqueaba
            //   escribir `siigo_id` una vez confirmada). Sin esto el job reintenta
            //   para siempre y la recepción queda huérfana. La adoptamos en vez
            //   de crear un duplicado fiscal.
            if (str_contains(mb_strtolower($msg), 'already exists')) {
                if ($adoptada = $this->buscarCompraEnSiigo($r)) {
                    Log::channel('single')->warning('[SIIGO compra] adoptada la que ya existía', [
                        'recepcion_id' => $r->id, 'siigo_id' => $adoptada['id'],
                    ]);
                    $r->forceFill([
                        'siigo_id' => (string) $adoptada['id'],
                        'siigo_number' => (string) ($adoptada['name'] ?? ''),
                        'siigo_sync_at' => now(),
                    ])->save();

                    return $r;
                }
            }

            throw new RuntimeException("SIIGO rechazó compra {$r->numero}: {$msg}");
        }

        $data = (array) $response->json();
        if (empty($data['id'])) {
            throw new RuntimeException("SIIGO respondió 200 sin ID para compra {$r->numero}.");
        }

        $r->forceFill([
            'siigo_id' => (string) $data['id'],
            'siigo_number' => (string) ($data['name'] ?? $data['number'] ?? ''),
            'siigo_sync_at' => now(),
        ])->save();

        return $r;
    }

    /**
     * Busca en SIIGO la factura de compra que corresponde a esta recepción.
     *
     * El payload escribe `observations` como "Recepción {numero} · OC {…}", así
     * que el número del ERP viaja dentro del documento y sirve de huella para
     * reencontrarlo. Se usa sólo cuando SIIGO dice que el documento ya existe.
     *
     * @return array{id:string, name:?string}|null
     */
    private function buscarCompraEnSiigo(\App\Modules\Compras\Models\RecepcionCompra $r): ?array
    {
        $desde = ($r->fecha_recepcion ?? now())->copy()->subDays(7)->format('Y-m-d');

        for ($pagina = 1; $pagina <= 5; $pagina++) {
            try {
                $resp = $this->cliente->request('GET', sprintf(
                    '/v1/purchases?created_start=%s&page=%d&page_size=100', $desde, $pagina,
                ));
            } catch (\Throwable) {
                return null;
            }
            if ($resp->failed()) {
                return null;
            }

            $filas = $resp->json('results') ?? [];
            if (empty($filas)) {
                return null;
            }

            foreach ($filas as $x) {
                if (str_contains((string) ($x['observations'] ?? ''), $r->numero)) {
                    return ['id' => (string) $x['id'], 'name' => $x['name'] ?? null];
                }
            }
        }

        return null;
    }

    /**
     * COMP-B4 · emite UNA sola factura de compra en SIIGO agregando los
     * items de VARIAS recepciones. Pre: todas tienen el mismo proveedor y el
     * mismo `factura_proveedor` (ambos no vacíos), están confirmadas y sin
     * siigo_id. La primera recepción da la fecha. Al éxito, marca TODAS con
     * el mismo siigo_id/siigo_number para que el modelo contable sea 1:1 con
     * la realidad del proveedor (una factura · una CxP).
     */
    private function emitirCompraConsolidada(\Illuminate\Support\Collection $recepciones): \App\Modules\Compras\Models\RecepcionCompra
    {
        $primera = $recepciones->first();
        $recepciones->load(['orden.proveedor', 'items.ordenItem.producto', 'items.ordenItem.variante']);
        if (empty($primera->orden?->proveedor?->numero_documento)) {
            throw new RuntimeException("Proveedor sin NIT en recepción {$primera->numero}.");
        }

        $ivaTaxId = (int) setting('siigo.tax_id_iva_19');
        $items = [];
        $totalPagar = 0.0;
        foreach ($recepciones as $r) {
            foreach ($r->items as $i) {
                $ordenItem = $i->ordenItem;
                $producto = $ordenItem?->producto;
                $variante = $ordenItem?->variante;
                // Mismo criterio que en la factura: el código con que SIIGO conoce
            // el ítem, no el de barras a ciegas.
            $code = $this->codigoSiigoDe($variante, $producto, $producto?->nombre);
                $cant = (float) $i->cantidad_recibida;
                if ($cant <= 0) continue;
                $precio = (float) ($ordenItem?->precio_unit ?? 0);
                $line = [
                    'type' => 'Product',
                    'code' => (string) $code,
                    'description' => (string) ($producto?->nombre ?? 'Ítem') . " · Rec {$r->numero}",
                    'quantity' => $cant,
                    'price' => $precio,
                    'discount' => 0,
                ];
                if ($ivaTaxId > 0) $line['taxes'] = [['id' => $ivaTaxId]];
                $items[] = $line;
                $totalPagar += $cant * $precio;
            }
        }
        if (empty($items)) {
            throw new RuntimeException('Consolidación sin ítems recibidos > 0.');
        }

        $paymentId = (int) setting('siigo.payment_type_compra_default', 0);
        $numeros = $recepciones->pluck('numero')->implode(', ');

        $payload = [
            'document' => ['id' => (int) setting('siigo.doc_type_compra', 0)],
            'date' => $primera->fecha_recepcion->format('Y-m-d'),
            'supplier' => ['identification' => (string) $primera->orden->proveedor->numero_documento],
            'cost_center' => (int) setting('siigo.cost_center_compras', 0) ?: null,
            'provider_invoice' => [
                'prefix' => 'FC',
                'number' => (string) (preg_replace('/\D/', '', (string) $primera->factura_proveedor) ?: $primera->id),
            ],
            'items' => $items,
            'observations' => "Consolidado · {$recepciones->count()} recepciones · {$numeros}",
        ];
        if ($paymentId > 0 && $totalPagar > 0) {
            $payload['payments'] = [[
                'id' => $paymentId,
                'value' => round($totalPagar, 2),
                'due_date' => now()->addDays(30)->format('Y-m-d'),
            ]];
        }
        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== 0);

        Log::channel('single')->info('[SIIGO compra consolidada] enviando', [
            'recepcion_ids' => $recepciones->pluck('id')->all(),
            'factura_proveedor' => $primera->factura_proveedor,
        ]);

        $response = $this->cliente->request('POST', '/v1/purchases', $payload, 1,
            'compra:consol:'.$primera->orden->proveedor_id.':'.$primera->factura_proveedor);

        if ($response->failed()) {
            $msg = (string) ($response->json('Errors.0.Message') ?? 'HTTP '.$response->status());
            throw new RuntimeException("SIIGO rechazó compra consolidada ({$numeros}): {$msg}");
        }
        $data = (array) $response->json();
        if (empty($data['id'])) {
            throw new RuntimeException("SIIGO respondió 200 sin ID (consolidada {$numeros}).");
        }

        DB::transaction(function () use ($recepciones, $data) {
            foreach ($recepciones as $r) {
                $r->forceFill([
                    'siigo_id' => (string) $data['id'],
                    'siigo_number' => (string) ($data['name'] ?? $data['number'] ?? ''),
                    'siigo_sync_at' => now(),
                ])->save();
            }
        });

        return $primera->refresh();
    }

    /**
     * F10 · Emite un VOUCHER (recibo de caja / comprobante de egreso) contra
     * SIIGO cuando se registra un pago cliente en Cartera. Endpoint: POST /v1/vouchers.
     *
     * Idempotente: si el pago ya tiene `siigo_id`, retorna sin re-enviar.
     * Aplica el pago contra la factura de venta correspondiente (que ya debe
     * tener su `siigo_id` — se emite por SiigoEmisionService::emitir() previamente).
     *
     * @param  \App\Modules\Cartera\Models\PagoVenta  $p
     * @return \App\Modules\Cartera\Models\PagoVenta
     */
    public function emitirVoucher(\App\Modules\Cartera\Models\PagoVenta $p): \App\Modules\Cartera\Models\PagoVenta
    {
        if ($p->siigo_id) {
            return $p; // ya emitido · idempotente
        }

        $p->loadMissing(['factura', 'contacto']);

        if (! $p->factura?->siigo_id) {
            /*
             * Hay dos motivos distintos y se tratan distinto.
             *
             * Si la factura es electrónica y todavía no subió, es cuestión de
             * orden: el pago tiene que esperar, y para eso sirve la excepción
             * —el job reintenta—.
             *
             * Si NO es electrónica, no va a subir nunca: SIIGO sólo recibe las
             * de la DIAN, y la red de seguridad diaria filtra por
             * `es_electronica = 1`. Lanzar acá convertía ese pago en un fallo
             * permanente que se reencolaba cada día para volver a morir. Medido
             * el 2026-10-09: las 23 facturas sin `siigo_id` eran TODAS no
             * electrónicas —demo y semilla—, y los 3 pagos colgados de ellas
             * llevaban días reapareciendo en `failed_jobs`.
             */
            if (! $p->factura?->es_electronica) {
                throw new PagoSinFacturaEnSiigo(
                    "El pago {$p->id} es de la factura {$p->factura?->numero}, que no es electrónica y "
                    ."por eso no va a SIIGO. El recibo tampoco."
                );
            }

            throw new RuntimeException(
                "El pago {$p->id} apunta a factura {$p->factura?->numero} que aún no está en SIIGO · " .
                "espera a que la factura se emita antes de aplicar el pago."
            );
        }
        if (empty($p->contacto?->numero_documento)) {
            throw new RuntimeException("El contacto del pago {$p->id} no tiene NIT.");
        }

        $monto = (float) $p->monto_aplicado;
        if ($monto <= 0) {
            throw new RuntimeException("El pago {$p->id} tiene monto_aplicado 0.");
        }

        // El tipo de documento del recibo vive en `siigo.doc_type_recibo`.
        // Hubo un tiempo en que acá se leía `siigo.doc_type_recibo_caja`, que
        // no estaba declarado en ningún lado: daba 0, el bloque `document` se
        // descartaba y SIIGO respondía «The field document is required». Se
        // dejó un respaldo con el nombre viejo, pero esa clave nunca existió
        // ni se puede crear desde el panel, así que sólo escondía el problema.
        $docRecibo = (int) setting('siigo.doc_type_recibo', 0);

        $siigoFactura = $this->prefijoConsecutivoSiigo($p->factura);

        $payload = [
            'document' => ['id' => $docRecibo],
            // `type` va en la raíz del recibo, no en la línea. SIIGO admite
            // DebtPayment | AdvancePayment | Advanced; acá siempre se está
            // abonando a una factura existente, así que es DebtPayment.
            'type' => 'DebtPayment',
            'date' => $p->fecha->format('Y-m-d'),
            'customer' => ['identification' => (string) $p->contacto->numero_documento],
            // Sin bloque `currency`: al mandarlo SIIGO exige además
            // `exchange_rate`, y acá siempre se cobra en pesos, que es la
            // moneda base de la empresa.
            // La factura se identifica con el prefijo y el consecutivo que le
            // puso SIIGO, no con los del ERP: son numeraciones distintas (acá
            // FV-261007-0004, allá FV-247-1215) y con las del ERP el recibo
            // quedaba apuntando a una factura inexistente.
            'items' => [[
                'due' => [
                    'prefix' => $siigoFactura['prefijo'],
                    'consecutive' => $siigoFactura['consecutivo'],
                    'quote' => 1,
                    'date' => $p->factura->fecha_emision->format('Y-m-d'),
                ],
                'value' => $monto,
            ]],
            // El tipo de pago lo decide el MÉTODO con que cobró el cliente.
            //   Antes salía siempre el mismo `siigo.payment_type_recibo`, así
            //   que en SIIGO una transferencia y un pago en efectivo entraban
            //   igual y la conciliación de bancos contra caja no cuadraba.
            //   Si el método todavía no está mapeado, se usa el global de
            //   siempre para no frenar el cobro.
            'payment' => [
                'id' => $this->tipoPagoSiigoDe($p),
                'value' => $monto,
            ],
            'observations' => "ERP pago #{$p->id} · " . ($p->medio_pago ?? 'sin medio')
                . ($p->referencia ? " · ref {$p->referencia}" : ''),
        ];
        // Filtrar defaults 0/null.
        if (empty($payload['document']['id'])) unset($payload['document']);
        if (empty($payload['payment']['id'])) {
            throw new RuntimeException(
                "Falta configurar 'siigo.payment_type_recibo' o payment_type_id en SiigoConfig."
            );
        }

        Log::channel('single')->info('[SIIGO voucher] enviando', [
            'pago_id' => $p->id, 'monto' => $monto,
            'payment_type_id' => $payload['payment']['id'],
            'medio' => $p->medio_pago,
        ]);

        $response = $this->cliente->request('POST', '/v1/vouchers', $payload, 1, "pventa:{$p->id}");

        if ($response->failed()) {
            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('message')
                ?? 'SIIGO rechazó el voucher (HTTP ' . $response->status() . ').');
            throw new RuntimeException("SIIGO rechazó voucher pago={$p->id}: {$msg}");
        }

        $data = (array) $response->json();
        if (empty($data['id'])) {
            throw new RuntimeException("SIIGO respondió 200 sin ID para voucher pago={$p->id}.");
        }

        $p->forceFill([
            'siigo_id' => (string) $data['id'],
            'siigo_number' => (string) ($data['name'] ?? $data['number'] ?? ''),
            'siigo_sync_at' => now(),
        ])->save();

        return $p;
    }

    /**
     * Prefijo y consecutivo con que SIIGO numeró la factura.
     *
     * El ERP y SIIGO llevan numeraciones distintas (FV-261007-0004 acá,
     * FV-247-1215 allá). El recibo de caja tiene que referirse a la de SIIGO o
     * apunta a una factura que no existe, así que se lee del documento real.
     *
     * @return array{prefijo: string, consecutivo: int}
     */
    private function prefijoConsecutivoSiigo(FacturaVenta $f): array
    {
        $r = $this->cliente->request('GET', '/v1/invoices/'.$f->siigo_id);

        if (! $r->successful()) {
            throw new RuntimeException(
                "No se pudo leer de SIIGO la factura {$f->numero} para aplicarle el pago: HTTP ".$r->status()
            );
        }

        // `name` llega como "FV-247-1215": el prefijo es todo menos el último
        // tramo, que es el consecutivo.
        $nombre = (string) $r->json('name');
        $numero = (int) $r->json('number');
        $prefijo = $nombre !== '' && str_contains($nombre, '-')
            ? substr($nombre, 0, strrpos($nombre, '-'))
            : 'FV';

        return ['prefijo' => $prefijo, 'consecutivo' => $numero ?: 0];
    }

    /**
     * Con qué código conoce SIIGO este ítem.
     *
     * El ERP mandaba siempre el código de barras de la variante, pero el push
     * de productos sube la variante con su código de barras SÓLO si la variante
     * se sincronizó; si lo que viajó fue el producto padre, allá quedó con la
     * `referencia`. Resultado comprobado: una factura con una variante no
     * sincronizada se rechazaba con «The code doesn't exist: 7701000000011»,
     * aunque el producto sí estuviera en SIIGO.
     *
     * Acá se devuelve el código que SIIGO tiene de verdad, y si el ítem no está
     * sincronizado se corta con un mensaje que dice qué falta, en vez de mandar
     * un código inventado y recibir un error críptico.
     */
    private function codigoSiigoDe($variante, $producto, ?string $descripcion = null): string
    {
        if ($variante?->siigo_code) {
            return (string) $variante->siigo_code;
        }
        if ($variante?->siigo_id && $variante->codigo_barras) {
            return (string) $variante->codigo_barras;
        }
        if ($producto?->siigo_id && $producto->referencia) {
            return (string) $producto->referencia;
        }

        $nombre = $descripcion ?: ($producto->nombre ?? 'sin nombre');
        throw new RuntimeException(
            "El producto «{$nombre}» todavía no está en SIIGO: sincronizalo antes de facturarlo "
            .'(Catálogo → Productos → Sincronizar con SIIGO).'
        );
    }

    /**
     * Garantiza que el cliente exista en SIIGO antes de facturarle.
     *
     * Primero lo busca por documento (puede existir allá aunque el ERP no lo
     * tenga marcado) y, si no está, lo crea. Guarda el `siigo_id` para no
     * repetir la consulta en cada factura.
     *
     * No se inventa datos: si falta algo que SIIGO exige, deja que el error
     * suba con el motivo, que es más útil que crear un tercero a medias.
     */
    private function asegurarClienteEnSiigo(?\App\Models\Contacto $c): void
    {
        if (! $c) {
            throw new RuntimeException('La factura no tiene cliente asociado.');
        }
        if ($c->siigo_id) {
            return;
        }

        $doc = trim((string) $c->numero_documento);
        if ($doc === '') {
            throw new RuntimeException("El cliente «{$c->nombreDisplay()}» no tiene documento: SIIGO no lo puede recibir.");
        }

        // 1 · ¿Ya existe allá?
        $busca = $this->cliente->request('GET', '/v1/customers?identification='.urlencode($doc));
        $encontrado = collect($busca->successful() ? ($busca->json('results') ?? []) : [])
            ->firstWhere('identification', $doc);

        if ($encontrado && ! empty($encontrado['id'])) {
            $c->forceFill(['siigo_id' => $encontrado['id'], 'siigo_sync_at' => now()])->save();

            return;
        }

        // 2 · No existe: crearlo. El mapeo es el inverso del importador
        //     (`SiigoService::guardarCliente`).
        $ciudad = \App\Modules\Siigo\Support\CiudadesDane::resolver($c->ciudad);
        if (! $ciudad['exacta']) {
            Log::channel('siigo')->warning('Ciudad sin código DANE: se usa la predeterminada', [
                'contacto_id' => $c->id, 'ciudad' => $c->ciudad, 'usada' => $ciudad['city_code'],
            ]);
        }

        $esEmpresa = $c->tipo_documento === 'NIT';
        $nombre = $esEmpresa
            ? [$c->razon_social ?: $c->nombre_completo]
            : array_slice(array_pad(explode(' ', trim($c->nombre_completo), 2), 2, ''), 0, 2);

        $payload = array_filter([
            'type' => 'Customer',
            'person_type' => $esEmpresa ? 'Company' : 'Person',
            'id_type' => $esEmpresa ? '31' : '13',
            'identification' => $doc,
            'name' => array_values(array_filter($nombre, fn ($n) => $n !== '')),
            'commercial_name' => $c->razon_social ?: null,
            'active' => (bool) $c->activo,
            // SIIGO exige city_code (DANE). El ERP guarda la ciudad como texto
            // libre y SIIGO no publica endpoint de ciudades, así que se traduce
            // con una tabla propia; si no la reconoce usa la predeterminada y
            // lo deja anotado, para no tumbar la venta por un nombre mal escrito.
            'address' => [
                'address' => $c->direccion ?: 'No reportado',
                'city' => [
                    'country_code' => 'CO',
                    'state_code' => $ciudad['state_code'],
                    'city_code' => $ciudad['city_code'],
                ],
            ],
            'contacts' => array_filter([
                array_filter([
                    'first_name' => $nombre[0] ?? $c->nombre_completo,
                    'last_name' => $nombre[1] ?? '.',
                    'email' => $c->email ?: null,
                    'phone' => $c->telefono ? ['number' => $c->telefono] : null,
                ]),
            ]),
        ], fn ($v) => $v !== null && $v !== []);

        $r = $this->cliente->request('POST', '/v1/customers', $payload, 1, "cli:{$c->id}");

        // `successful()` y no `ok()`: SIIGO responde 201 al crear el tercero y
        // `ok()` sólo acepta 200, así que daba por fallido un alta correcta.
        if (! $r->successful()) {
            $msg = (string) ($r->json('Errors.0.Message') ?? $r->json('errors.0.message')
                ?? $r->json('message') ?? ('HTTP '.$r->status()));
            throw new RuntimeException(
                "SIIGO no aceptó al cliente «{$c->nombreDisplay()}» (doc {$doc}): {$msg}"
            );
        }

        $c->forceFill([
            'siigo_id' => (string) $r->json('id'),
            'siigo_sync_at' => now(),
        ])->save();

        Log::channel('siigo')->info('Cliente creado en SIIGO', [
            'contacto_id' => $c->id, 'doc' => $doc, 'siigo_id' => $c->siigo_id,
        ]);
    }

    /**
     * A qué tipo de pago de SIIGO entra el recibo de este cobro.
     *
     * Orden: el mapeo del método con que pagó el cliente → el global
     * `siigo.payment_type_recibo` → el `payment_type_id` de la configuración.
     *
     * El primer nivel es el que faltaba: sin él, en SIIGO todos los cobros
     * caían en el mismo tipo y la conciliación de bancos contra caja quedaba
     * mal. El respaldo se mantiene para que un método sin mapear no frene el
     * cobro mientras se termina de configurar.
     */
    private function tipoPagoSiigoDe(\App\Modules\Cartera\Models\PagoVenta $p): int
    {
        if ($p->medio_pago) {
            $delMetodo = \App\Modules\Cartera\Models\MetodoPago::where('codigo', $p->medio_pago)
                ->value('siigo_payment_type_id');

            if ($delMetodo) {
                return (int) $delMetodo;
            }
        }

        return (int) setting('siigo.payment_type_recibo', 0)
            ?: (int) SiigoConfig::current()->payment_type_id;
    }

    /**
     * Sprint 4 · B.1 · Emite una NOTA CRÉDITO manual a SIIGO.
     * Endpoint: POST /v1/credit-notes
     *
     * Diferencia con la NC de Dropi: esta se dispara desde Cartera cuando
     * Aracely genera manualmente una NC (devolución parcial, error de factura,
     * descuento post-venta). La de Dropi vive en EmitirNotaCreditoDropi.
     *
     * Idempotente · si ya tiene siigo_id, retorna.
     */
    public function emitirNotaCredito(\App\Modules\Cartera\Models\NotaCredito $nc): \App\Modules\Cartera\Models\NotaCredito
    {
        if ($nc->siigo_id) return $nc;

        $nc->loadMissing(['factura', 'factura.contacto']);
        if (! $nc->factura?->siigo_id) {
            throw new RuntimeException("La NC {$nc->numeroCompleto()} apunta a factura que aún no está en SIIGO.");
        }
        if (empty($nc->factura->contacto?->numero_documento)) {
            throw new RuntimeException("El cliente de la factura no tiene NIT.");
        }

        $payload = [
            'document' => ['id' => (int) setting('siigo.doc_type_nota_credito', 0)],
            'number' => (int) $nc->numero,
            'name' => $nc->numeroCompleto(),
            'date' => now()->format('Y-m-d'),
            'invoice' => (string) $nc->factura->siigo_id,
            'cause' => 2, // 2=devolución mercancía, 3=descuento, 4=anulación
            'customer' => ['identification' => (string) $nc->factura->contacto->numero_documento],
            'items' => [[
                'code' => 'NC-' . $nc->numero,
                'description' => $nc->motivo ?: 'Nota crédito manual',
                'quantity' => 1,
                'price' => (float) $nc->valor,
                'discount' => 0,
            ]],
            'observations' => "NC manual ERP #{$nc->id} · " . ($nc->motivo ?: 'sin motivo'),
        ];
        if (empty($payload['document']['id'])) unset($payload['document']);

        Log::channel('single')->info('[SIIGO NC] enviando', [
            'nc_id' => $nc->id, 'numero' => $nc->numeroCompleto(),
        ]);

        $response = $this->cliente->request('POST', '/v1/credit-notes', $payload, 1, "nc:{$nc->id}");

        if ($response->failed()) {
            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('message')
                ?? 'SIIGO rechazó la NC (HTTP ' . $response->status() . ').');
            throw new RuntimeException("SIIGO rechazó NC {$nc->numeroCompleto()}: {$msg}");
        }

        $data = (array) $response->json();
        if (empty($data['id'])) {
            throw new RuntimeException("SIIGO respondió 200 sin ID para NC {$nc->numeroCompleto()}.");
        }

        $nc->forceFill([
            'siigo_id' => (string) $data['id'],
            'siigo_response' => $data,
            'cufe' => (string) ($data['stamp']['cufe'] ?? $data['stamp']['cude'] ?? ''),
            'emitida_at' => now(),
        ])->save();

        return $nc;
    }

    /**
     * QA-FIX #7 · Sprint 4 · B.3+ · Voucher EGRESO por pago a proveedor.
     * Endpoint: POST /v1/vouchers · type=egreso.
     * Persiste el neto pagado (después de retenciones) y anota las retenciones
     * como items separados por si SIIGO las quiere ver detalladas.
     */
    public function emitirVoucherEgreso(\App\Modules\Cartera\Models\PagoProveedor $p): \App\Modules\Cartera\Models\PagoProveedor
    {
        if ($p->siigo_voucher_id) return $p;

        // COMP-B3 · si el pago está asociado a una recepción YA sincronizada a SIIGO
        // (siigo_id = invoice_id de la factura de compra), lo marcamos como abono a
        // deuda específica para que SIIGO baje el saldo de ESA CxP exacta en vez
        // de dejar el egreso suelto como ajuste manual. Doc oficial:
        // https://developers.siigo.com/docs/siigoapi/comprobantes-egreso
        $p->loadMissing(['proveedor', 'retenciones', 'recepcion']);
        if (empty($p->proveedor?->numero_documento)) {
            throw new RuntimeException("Pago #{$p->id}: proveedor sin NIT.");
        }

        $rec = $p->recepcion;
        $enlazadoAFactura = $rec && $rec->siigo_id;

        $itemBase = [
            'account' => ['code' => CuentasSiigo::codigo($p->cuenta_puc_egreso), 'movement' => 'Credit'],
            'value' => (float) $p->monto_neto,
            'description' => "Pago proveedor {$p->proveedor->nombre_completo}"
                . ($rec ? " · Rec {$rec->numero}" : ''),
        ];
        if ($enlazadoAFactura) {
            // DebtPayment: SIIGO cruza el egreso contra la factura de compra.
            // Mandamos ambas vías: `invoice_id` (UUID SIIGO · match exacto, robusto)
            // y `due.prefix+consecutive` (fallback que SIIGO también acepta y es lo
            // que exige su validador cuando el UUID no se resuelve). Si solo mando
            // uno y SIIGO no lo encuentra, el egreso queda suelto. Doc:
            // https://developers.siigo.com/docs/siigoapi/comprobantes-egreso
            $prefix = 'FC';
            $consecutive = (int) (preg_replace('/\D/', '', (string) ($rec->factura_proveedor ?: $rec->numero)) ?: $rec->id);
            $itemBase['invoice_id'] = (string) $rec->siigo_id;
            $itemBase['due'] = [
                'prefix' => $prefix,
                'consecutive' => $consecutive,
                'quote' => 1,
                'date' => $p->fecha->format('Y-m-d'),
            ];
        }

        // Un pago a proveedor es un RECIBO DE PAGO (tipo RP), no un recibo de
        // caja: va a /v1/payment-receipts y lleva `supplier`, no `customer`.
        // Antes se mandaba a /v1/vouchers (que espera documentos RC) con un
        // documento RP, y SIIGO respondía "The payment field has an invalid value".
        $items = [$itemBase];

        if ($enlazadoAFactura) {
            // DebtPayment: el item lleva SÓLO el vencimiento que se abona y el
            // valor; la cuenta la pone SIIGO al cruzar la CxP. Mandarle `account`
            // o `invoice_id` acá hace que rechace el documento con un 400 seco.
            $payload = [
                'document' => ['id' => (int) setting('siigo.doc_type_egreso', 0)],
                'date' => $p->fecha->format('Y-m-d'),
                'type' => 'DebtPayment',
                'supplier' => ['identification' => (string) $p->proveedor->numero_documento, 'branch_office' => 0],
                'items' => [[
                    'due' => $itemBase['due'],
                    'value' => (float) $p->monto_neto,
                ]],
                'payment' => [
                    'id' => (int) setting('siigo.payment_type_egreso', setting('siigo.payment_type_recibo', 0)),
                    'value' => (float) $p->monto_neto,
                ],
            ];
            if (empty($payload['payment']['id'])) {
                throw new RuntimeException("Falta configurar 'siigo.payment_type_egreso' en settings.");
            }
        } else {
            // Sin factura que cruzar, el comprobante debe traer sus dos patas:
            // débito a la CxP del proveedor y crédito a la cuenta de salida de
            // dinero. `payment` no aplica en este modo y SIIGO lo rechaza.
            $items = [
                [
                    'account' => ['code' => CuentasSiigo::codigo(setting('siigo.cta_cxp_proveedor_siigo', '22050501')), 'movement' => 'Debit'],
                    'value' => (float) $p->monto_neto,
                    'description' => "Pago proveedor {$p->proveedor->nombre_completo}",
                ],
                $itemBase,
            ];
            $payload = [
                'document' => ['id' => (int) setting('siigo.doc_type_egreso', 0)],
                'date' => $p->fecha->format('Y-m-d'),
                'type' => 'Detailed',
                'supplier' => ['identification' => (string) $p->proveedor->numero_documento, 'branch_office' => 0],
                'items' => $items,
            ];
        }

        $payload['observations'] = "ERP pago proveedor #{$p->id} · {$p->metodo}"
            . ($enlazadoAFactura ? " · abona FC compra SIIGO {$rec->siigo_id}" : '')
            . ($p->monto_retenciones > 0 ? " · retenciones \$" . number_format($p->monto_retenciones, 2) : '');

        if (empty($payload['document']['id'])) unset($payload['document']);

        Log::channel('single')->info('[SIIGO egreso] enviando', [
            'pago_proveedor_id' => $p->id, 'neto' => $p->monto_neto,
            'modo' => $enlazadoAFactura ? 'DebtPayment' : 'Detailed',
        ]);

        $response = $this->cliente->request('POST', '/v1/payment-receipts', $payload, 1, "pprov:{$p->id}");

        if ($response->failed()) {
            // SIIGO alterna entre `Errors` y `errors` según el endpoint; si no
            // viene ninguno, mostramos el cuerpo crudo en vez de un "HTTP 400"
            // que no dice nada y obliga a adivinar.
            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('errors.0.message')
                ?? $response->json('message')
                ?? mb_substr($response->body(), 0, 300));
            throw new RuntimeException("SIIGO rechazó egreso pago #{$p->id}: {$msg}");
        }

        $data = (array) $response->json();
        if (empty($data['id'])) {
            throw new RuntimeException("SIIGO respondió 200 sin ID para egreso pago #{$p->id}.");
        }

        $p->forceFill([
            'siigo_voucher_id' => (string) $data['id'],
            'siigo_response' => $data,
            'siigo_sync_at' => now(),
        ])->save();

        return $p;
    }

    /**
     * Sprint 4 · B.2 · Emite una NOTA DÉBITO manual a SIIGO.
     * Endpoint: POST /v1/debit-notes
     * Causal típica: recargo por mora, ajuste al alza post-factura.
     */
    public function emitirNotaDebito(\App\Modules\Cartera\Models\NotaDebito $nd): \App\Modules\Cartera\Models\NotaDebito
    {
        if ($nd->siigo_id) return $nd;

        $nd->loadMissing(['factura', 'factura.contacto']);
        if (! $nd->factura?->siigo_id) {
            throw new RuntimeException("La ND {$nd->numeroCompleto()} apunta a factura que aún no está en SIIGO.");
        }
        if (empty($nd->factura->contacto?->numero_documento)) {
            throw new RuntimeException("El cliente de la factura no tiene NIT.");
        }

        $payload = [
            'document' => ['id' => (int) setting('siigo.doc_type_nota_debito', 0)],
            'number' => (int) $nd->numero,
            'name' => $nd->numeroCompleto(),
            'date' => now()->format('Y-m-d'),
            'invoice' => (string) $nd->factura->siigo_id,
            'cause' => 1, // 1=intereses/recargo (SIIGO)
            'customer' => ['identification' => (string) $nd->factura->contacto->numero_documento],
            'items' => [[
                'code' => 'ND-' . $nd->numero,
                'description' => $nd->motivo ?: 'Nota débito manual',
                'quantity' => 1,
                'price' => (float) $nd->valor,
                'discount' => 0,
            ]],
            'observations' => "ND manual ERP #{$nd->id} · " . ($nd->motivo ?: 'sin motivo'),
        ];
        if (empty($payload['document']['id'])) unset($payload['document']);

        Log::channel('single')->info('[SIIGO ND] enviando', [
            'nd_id' => $nd->id, 'numero' => $nd->numeroCompleto(),
        ]);

        // Idempotency-Key: sin ella, un reintento del job crea una SEGUNDA nota
        //   débito en SIIGO · es un documento fiscal, duplicarlo obliga a
        //   anularlo ante la DIAN. Mismo patrón que inv:/nc:/rec:/asman:/pprov:.
        $response = $this->cliente->request('POST', '/v1/debit-notes', $payload, 1, "nd:{$nd->id}");

        if ($response->failed()) {
            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('message')
                ?? 'SIIGO rechazó la ND (HTTP ' . $response->status() . ').');
            throw new RuntimeException("SIIGO rechazó ND {$nd->numeroCompleto()}: {$msg}");
        }

        $data = (array) $response->json();
        if (empty($data['id'])) {
            throw new RuntimeException("SIIGO respondió 200 sin ID para ND {$nd->numeroCompleto()}.");
        }

        $nd->forceFill([
            'siigo_id' => (string) $data['id'],
            'siigo_response' => $data,
            'cufe' => (string) ($data['stamp']['cufe'] ?? $data['stamp']['cude'] ?? ''),
            'emitida_at' => now(),
        ])->save();

        return $nd;
    }

    /**
     * Sprint 4 · B.4 · Emite un ASIENTO MANUAL a SIIGO (multi-línea).
     * Diferente al emitirAsiento (que agrupa 2 líneas por movimiento kardex):
     * este recibe un AsientoManual con N líneas que Aracely armó desde Vue.
     */
    public function emitirAsientoManual(\App\Modules\Contabilidad\Models\AsientoManual $a): \App\Modules\Contabilidad\Models\AsientoManual
    {
        if ($a->siigo_journal_id) return $a;

        $a->loadMissing('lineas');
        if (! $a->cuadra()) {
            throw new RuntimeException("Asiento #{$a->id} no cuadra · débito ≠ crédito.");
        }

        $items = $a->lineas->map(function ($l) {
            $mov = $l->debe > 0 ? 'Debit' : 'Credit';
            $valor = (float) ($l->debe > 0 ? $l->debe : $l->haber);
            $row = [
                'account' => ['code' => CuentasSiigo::codigo($l->cuenta_puc), 'movement' => $mov],
                'value' => $valor,
                'description' => (string) $l->descripcion,
            ];
            if ($l->tercero_documento) {
                $row['customer'] = ['identification' => (string) $l->tercero_documento];
            }
            return $row;
        })->all();

        $payload = [
            'document' => ['id' => (int) setting('siigo.doc_type_asiento_manual', setting('siigo.doc_type_gasto', 0))],
            'date' => $a->fecha->format('Y-m-d'),
            'items' => $items,
            'observations' => "ERP asiento manual #{$a->id} · {$a->glosa}",
        ];
        if (empty($payload['document']['id'])) unset($payload['document']);

        Log::channel('single')->info('[SIIGO asiento manual] enviando', [
            'asiento_id' => $a->id, 'lineas' => count($items), 'valor' => $a->valor_total,
        ]);

        $response = $this->cliente->request('POST', '/v1/journals', $payload, 1, "asman:{$a->id}");

        if ($response->failed()) {
            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('message')
                ?? 'SIIGO rechazó asiento manual (HTTP ' . $response->status() . ').');
            throw new RuntimeException("SIIGO rechazó asiento manual #{$a->id}: {$msg}");
        }

        $data = (array) $response->json();
        if (empty($data['id'])) {
            throw new RuntimeException("SIIGO respondió 200 sin ID para asiento manual #{$a->id}.");
        }

        $a->forceFill([
            'siigo_journal_id' => (string) $data['id'],
            'siigo_sync_at' => now(),
            'estado' => 'sincronizado',
        ])->save();

        return $a;
    }

    /**
     * F11 · Emite un ASIENTO CONTABLE en SIIGO por un movimiento de inventario.
     * Endpoint: POST /v1/journals.
     *
     * La matriz de cuentas (débito/crédito por tipo de movimiento) queda
     * documentada en las notas del asiento; los valores concretos se toman
     * de los defaults de Reglas de Negocio (cta_inventario, cta_costo,
     * cta_ajuste_stock) que Silvia confirma con el PUC Great Baby.
     *
     * Idempotente: si el movimiento ya tiene siigo_journal_id, retorna.
     *
     * @param  \App\Modules\Dropi\Models\InventarioMovimiento  $m
     */
    /**
     * Tercero con el que se asientan los ajustes de inventario en SIIGO.
     *
     * Un traslado, una merma o un sobrante no tienen contraparte externa, pero
     * SIIGO pide un tercero igual en cada linea del journal. El que corresponde
     * es la propia empresa. Se puede fijar a mano en la regla
     * `siigo.tercero_ajustes_inventario` (util si en SIIGO esta creada con otra
     * identificacion); si no, se toma el NIT de la empresa, sin puntos ni
     * digito de verificacion, que es como lo guarda SIIGO.
     */
    /**
     * Una línea del asiento de inventario.
     *
     * Las cuentas de inventario (1435…) son transaccionales en SIIGO pero
     * exigen `product` en la línea: sin él responde «The field product is
     * required» y el asiento no entra. La cantidad va DENTRO de `product`, no
     * al lado, igual que en el journal de devoluciones a proveedor. Las cuentas
     * de pérdida o sobrante (5299 / 4295) no lo piden, así que el producto sólo
     * se manda donde hace falta.
     */
    private function lineaAsiento(
        string $cuenta,
        string $movimiento,
        float $valor,
        string $tercero,
        \App\Modules\Dropi\Models\InventarioMovimiento $m,
        string $concepto,
    ): array {
        $linea = [
            'account' => ['code' => CuentasSiigo::codigo($cuenta), 'movement' => $movimiento],
            'customer' => ['identification' => $tercero],
            'value' => $valor,
            'description' => "{$concepto} · mov {$m->id}",
        ];

        if (! str_starts_with(ltrim($cuenta), '14')) {
            return $linea;
        }

        // Lo que el asiento referencia tiene que existir YA en SIIGO; el
        // asiento no lo crea. Y lo que vive allá depende del tipo de producto:
        // de uno granular SIIGO guarda las VARIANTES (el padre nunca tiene
        // `siigo_id`), de uno agregado guarda el producto. Mirar siempre el
        // padre daba un falso «no está en SIIGO» para todo producto granular.
        $variante = $m->variante;
        $producto = $m->producto ?? $variante?->producto;

        $sujeto = $variante ?: $producto;
        $codigoLocal = $variante?->codigo_barras ?? $producto?->referencia;

        if (! $sujeto?->siigo_id) {
            $nombre = $variante?->codigo_barras ?? $producto?->referencia ?? '?';
            throw new RuntimeException(
                "«{$nombre}» (movimiento {$m->id}) todavía no está en SIIGO. Sincronizalo primero "
                .'desde Catálogo; el asiento de inventario sólo lo referencia.'
            );
        }

        // Mismo código con el que se publicó: lo calcula el propio constructor
        // del payload de productos, no una copia local de la regla.
        $linea['product'] = [
            'code' => \App\Modules\Siigo\Support\ProductoPayloadBuilder::sanitizarCode(
                $codigoLocal,
                "movimiento {$m->id}"
            ),
            'quantity' => abs((float) $m->cantidad),
        ];

        return $linea;
    }

    private function terceroAjustesInventario(): string
    {
        $fijado = trim((string) setting('siigo.tercero_ajustes_inventario', ''));
        if ($fijado !== '') {
            return $fijado;
        }

        $nit = (string) (DB::table('empresa_config')->value('nit') ?? '');
        // «901.738.354-7» -> «901738354»
        $soloDigitos = preg_replace('/[^0-9]/', '', explode('-', $nit)[0] ?? '');

        if ($soloDigitos === '') {
            throw new RuntimeException(
                'No hay tercero para los ajustes de inventario: cargá el NIT en Configuración → '
                .'Empresa, o fijalo en la regla «siigo.tercero_ajustes_inventario». '
                .'SIIGO exige un tercero en cada línea del asiento.'
            );
        }

        return $soloDigitos;
    }

    public function emitirAsiento(\App\Modules\Dropi\Models\InventarioMovimiento $m): \App\Modules\Dropi\Models\InventarioMovimiento
    {
        if ($m->siigo_journal_id) return $m;

        $m->loadMissing(['ubicacion']);
        $valor = round(abs((float) $m->cantidad) * (float) ($m->costo_unit ?? 0), 2);
        if ($valor <= 0) {
            throw new RuntimeException("Movimiento kardex {$m->id} sin costo_unit · no se puede asentar.");
        }

        // BUG-CTA · UBIC-3 habilitó cuentas PUC por ubicación. Ahora el PUC
        // de inventario sale de la ubicación afectada (si tiene cta_inventario
        // propia); si no, cae al default global. Esto rompe el patrón previo
        // donde DB=CR=1435 global dejaba el asiento sin efecto neto · ahora
        // cada bodega mueve SU subcuenta y los saldos por ubicación cuadran.
        $m->loadMissing('ubicacion');
        $ctaInvUbic = $m->ubicacion?->ctaInventarioEfectiva() ?? setting('contable.cta_inventario_default', '1435');
        $ctaInvGlobal = (string) setting('contable.cta_inventario_default', '1435');
        // INV-A1 · PUC alineado con CerrarTomaFisica local:
        //   5299 = Pérdida por baja de inventario (correcto para faltantes/mermas)
        //   4295 = Diversos (aprovechamientos para sobrantes)
        //   El 5195 anterior era "transportes/fletes" — cuenta incorrecta que
        //   dejaba la contabilidad del ERP divergente del asiento SIIGO.
        $ctaPerdida  = (string) setting('siigo.cta_perdida_inventario_default', '5299');
        $ctaSobrante = (string) setting('siigo.cta_sobrante_inventario_default', '4295');

        // ajuste_toma_fisica · firma según el signo del movimiento:
        //   cantidad > 0 (sobrante contado) → DB cta_inv ubicación / CR 4295
        //   cantidad < 0 (faltante)         → DB 5299 / CR cta_inv ubicación
        $esSobranteToma = $m->tipo === 'ajuste_toma_fisica' && (float) $m->cantidad > 0;

        // Cuentas por tipo (Great Baby PUC · usar cta_inventario de la ubicación):
        //   traslado_salida      → CR en cta_inv de ORIGEN  / DB en cta_inv_default (en tránsito)
        //   traslado_entrada     → DB en cta_inv de DESTINO / CR en cta_inv_default (en tránsito)
        //   reversas             → invierten DB/CR del movimiento original
        //   merma / faltante     → DB en 5299 Pérdida / CR en cta_inv de la ubicación
        //   sobrante             → DB en cta_inv de la ubicación / CR en 4295 Diversos
        //   ajuste_toma_fisica   → según signo (sobrante o faltante)
        [$cuentaDebito, $cuentaCredito, $concepto] = match (true) {
            $m->tipo === 'traslado_salida'          => [$ctaInvGlobal, $ctaInvUbic, 'Traslado salida'],
            $m->tipo === 'traslado_entrada'         => [$ctaInvUbic, $ctaInvGlobal, 'Traslado entrada'],
            $m->tipo === 'traslado_reversa_salida'  => [$ctaInvUbic, $ctaInvGlobal, 'REVERSA traslado salida'],
            $m->tipo === 'traslado_reversa_entrada' => [$ctaInvGlobal, $ctaInvUbic, 'REVERSA traslado entrada'],
            in_array($m->tipo, ['merma', 'faltante'], true) => [$ctaPerdida, $ctaInvUbic, 'Pérdida inventario'],
            $m->tipo === 'sobrante'                 => [$ctaInvUbic, $ctaSobrante, 'Sobrante inventario'],
            $esSobranteToma                         => [$ctaInvUbic, $ctaSobrante, 'Ajuste toma física · sobrante'],
            $m->tipo === 'ajuste_toma_fisica'       => [$ctaPerdida, $ctaInvUbic, 'Ajuste toma física · faltante'],
            default                                 => throw new RuntimeException("Tipo de movimiento '{$m->tipo}' no configurado para asiento SIIGO."),
        };

        // SIIGO exige `customer.identification` en cada línea del journal, igual
        // que en las devoluciones a proveedor. Un ajuste de inventario no tiene
        // contraparte externa —la mercancía se movió adentro de la casa—, así
        // que el tercero es la propia empresa. Sin esto SIIGO responde
        // «The field customer is required» y el asiento no entra nunca.
        $tercero = $this->terceroAjustesInventario();

        $payload = [
            'document' => ['id' => (int) setting('siigo.doc_type_gasto', 0)],
            'date' => $m->created_at->format('Y-m-d'),
            'items' => [
                $this->lineaAsiento($cuentaDebito, 'Debit', $valor, $tercero, $m, $concepto),
                $this->lineaAsiento($cuentaCredito, 'Credit', $valor, $tercero, $m, $concepto),
            ],
            'observations' => "ERP mov #{$m->id} · {$m->tipo} · ubicación " . optional($m->ubicacion)->nombre,
        ];
        if (empty($payload['document']['id'])) unset($payload['document']);

        Log::channel('single')->info('[SIIGO asiento] enviando', [
            'mov_id' => $m->id, 'tipo' => $m->tipo, 'valor' => $valor,
        ]);

        $response = $this->cliente->request('POST', '/v1/journals', $payload, 1, "invmov:{$m->id}");

        if ($response->failed()) {
            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('message')
                ?? 'SIIGO rechazó el asiento (HTTP ' . $response->status() . ').');
            throw new RuntimeException("SIIGO rechazó asiento mov={$m->id}: {$msg}");
        }

        $data = (array) $response->json();
        if (empty($data['id'])) {
            throw new RuntimeException("SIIGO respondió 200 sin ID para asiento mov={$m->id}.");
        }

        $m->forceFill([
            'siigo_journal_id' => (string) $data['id'],
            'siigo_sync_at' => now(),
        ])->save();

        return $m;
    }

    // ════════════════════════════════════════════════════════════════════
    //  OPERACIONES SOBRE FACTURAS EXISTENTES EN SIIGO
    //  (POST /annul, POST /mail, GET /pdf, GET /xml, GET /stamp/errors)
    //  Verificadas contra la colección oficial Postman de SIIGO Oct-2026.
    // ════════════════════════════════════════════════════════════════════

    /**
     * Anula una factura de venta en SIIGO.
     *   Endpoint: POST /v1/invoices/{id}/annul
     *   Requiere que `siigo_id` exista en la factura.
     *
     *   Reglas DIAN: solo se puede anular una factura dentro de las primeras
     *   72h de emisión; después hay que emitir nota crédito. SIIGO devuelve
     *   422 si la factura ya está anulada.
     *
     * @throws RuntimeException si SIIGO rechaza.
     */
    /**
     * Anula una factura y deshace todo lo que había provocado.
     *
     * Antes sólo marcaba `estado = anulada`. Eso dejaba: el asiento contable en
     * pie, el saldo cobrable, y —lo más caro— el pedido atrapado en `facturado`
     * con `factura_id` apuntando a la factura muerta: no se podía volver a
     * facturar ("Pedido ya facturado") y, como `despachar()` sólo mira que
     * exista `factura_id` sin leer su estado, la mercancía salía igual contra
     * una factura anulada.
     *
     * También acepta facturas que nunca llegaron a SIIGO: antes lanzaba
     * excepción y quedaban emitidas y cobrables para siempre (hoy 23 de 24
     * facturas no tienen `siigo_id`).
     */
    public function anularFacturaEnSiigo(
        \App\Modules\Cartera\Models\FacturaVenta $factura,
        ?string $motivo = null,
    ): array {
        $respuesta = [];

        if ($factura->siigo_id) {
            $resp = $this->cliente->request('POST', "/v1/invoices/{$factura->siigo_id}/annul", []);
            if ($resp->failed()) {
                $msg = (string) ($resp->json('Errors.0.Message') ?? $resp->json('message') ?? "HTTP {$resp->status()}");
                throw new RuntimeException("SIIGO rechazó anular factura {$factura->numero}: {$msg}");
            }
            $respuesta = (array) $resp->json();
        } else {
            // Anulación sólo local: la factura nunca salió del ERP.
            $respuesta = ['local' => true, 'motivo' => 'La factura no había llegado a SIIGO.'];
        }

        DB::transaction(function () use ($factura, $motivo) {
            $factura->forceFill([
                'estado' => 'anulada',
                'anulada_at' => now(),
                'anulada_por_user_id' => auth()->id(),
                'motivo_anulacion' => $motivo,
                'saldo' => 0,          // deja de ser cobrable
            ])->save();

            // El asiento de esta factura ya no representa nada.
            \App\Modules\Cartera\Models\MovimientoContable::query()
                ->where('origen_type', \App\Modules\Cartera\Models\FacturaVenta::class)
                ->where('origen_id', $factura->id)
                ->forceDelete();

            // Libera el pedido para que se pueda volver a facturar.
            $pedido = \App\Modules\Portal\Models\PedidoCliente::where('factura_id', $factura->id)->first();
            if ($pedido && ! $pedido->despachado_at) {
                $pedido->forceFill([
                    'factura_id' => null,
                    'estado' => 'aprobado',
                    'facturado_at' => null,
                    'facturado_por_id' => null,
                ])->save();
            }
        });

        return $respuesta;
    }

    /**
     * Reenvía la factura por mail (SIIGO envía al cliente desde su servicio).
     *   Endpoint: POST /v1/invoices/{id}/mail
     *   Body:  { "email": "destinatario@..." }
     */
    public function reenviarMailFacturaSiigo(\App\Modules\Cartera\Models\FacturaVenta $factura, ?string $email = null): array
    {
        if (! $factura->siigo_id) {
            throw new RuntimeException("Factura {$factura->numero} no tiene siigo_id.");
        }
        $destino = $email ?: $factura->contacto?->email;
        if (! $destino) {
            throw new RuntimeException("Factura {$factura->numero} sin email destino · pasá uno manualmente.");
        }
        $resp = $this->cliente->request('POST', "/v1/invoices/{$factura->siigo_id}/mail", ['email' => $destino]);
        if ($resp->failed()) {
            $msg = (string) ($resp->json('Errors.0.Message') ?? "HTTP {$resp->status()}");
            throw new RuntimeException("SIIGO rechazó reenviar mail de {$factura->numero}: {$msg}");
        }
        return (array) $resp->json();
    }

    /**
     * Trae el PDF oficial SIIGO (con QR DIAN) de la factura.
     *   Endpoint: GET /v1/invoices/{id}/pdf
     *   Devuelve bytes del PDF (binario). El controller los devuelve con
     *   Content-Type: application/pdf para que el browser los muestre/descargue.
     */
    public function pdfFacturaSiigo(\App\Modules\Cartera\Models\FacturaVenta $factura): string
    {
        if (! $factura->siigo_id) {
            throw new RuntimeException("Factura {$factura->numero} no tiene siigo_id.");
        }
        $resp = $this->cliente->request('GET', "/v1/invoices/{$factura->siigo_id}/pdf");
        if ($resp->failed()) {
            throw new RuntimeException("SIIGO rechazó PDF de {$factura->numero}: HTTP {$resp->status()}");
        }
        // SIIGO devuelve JSON { "base64_content": "..." } o bytes crudos según versión API.
        $json = $resp->json();
        if (is_array($json) && isset($json['base64_content'])) {
            $decoded = base64_decode($json['base64_content'], true);
            if ($decoded === false) throw new RuntimeException("PDF SIIGO base64 inválido");
            return $decoded;
        }
        return (string) $resp->body();
    }

    /**
     * Trae el XML DIAN de la factura (requerido por contadores para archivo).
     *   Endpoint: GET /v1/invoices/{id}/xml
     */
    public function xmlFacturaSiigo(\App\Modules\Cartera\Models\FacturaVenta $factura): string
    {
        if (! $factura->siigo_id) {
            throw new RuntimeException("Factura {$factura->numero} no tiene siigo_id.");
        }
        $resp = $this->cliente->request('GET', "/v1/invoices/{$factura->siigo_id}/xml");
        if ($resp->failed()) {
            throw new RuntimeException("SIIGO rechazó XML de {$factura->numero}: HTTP {$resp->status()}");
        }
        $json = $resp->json();
        if (is_array($json) && isset($json['base64_content'])) {
            $decoded = base64_decode($json['base64_content'], true);
            if ($decoded === false) throw new RuntimeException("XML SIIGO base64 inválido");
            return $decoded;
        }
        return (string) $resp->body();
    }

    /**
     * Consulta los errores DIAN de una factura.
     *   Endpoint: GET /v1/invoices/{id}/stamp/errors
     *   Útil para debug cuando el CUFE no se emitió o la factura quedó en
     *   estado "rechazada por DIAN". Devuelve array con códigos y mensajes.
     */
    public function erroresDianFacturaSiigo(\App\Modules\Cartera\Models\FacturaVenta $factura): array
    {
        if (! $factura->siigo_id) {
            return ['sin_siigo' => true, 'mensaje' => 'La factura aún no llegó a SIIGO.'];
        }
        $resp = $this->cliente->request('GET', "/v1/invoices/{$factura->siigo_id}/stamp/errors");
        if ($resp->failed() && $resp->status() === 404) {
            return ['sin_errores' => true, 'mensaje' => 'DIAN aceptó la factura sin errores.'];
        }
        if ($resp->failed()) {
            throw new RuntimeException("SIIGO rechazó consulta errores DIAN de {$factura->numero}: HTTP {$resp->status()}");
        }
        return (array) $resp->json();
    }
}
