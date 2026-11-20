<?php

namespace App\Modules\Siigo\Services;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Listeners\RegistrarAsientoWalletDropi;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Support\ClaveIdempotencia;
use App\Modules\Siigo\Support\CuentasSiigo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Lleva a SIIGO, como COMPROBANTE CONTABLE (POST /v1/journals), lo que la
 * wallet de Dropi asienta en el libro y ningún documento de SIIGO genera solo:
 * fletes de garantía, comisiones de tarjeta, sanciones, indemnizaciones,
 * retiros al banco, pagos que llegan antes de la factura (anticipo) y las
 * reversas de un recibo de caja que Dropi deshizo (1009).
 *
 * La venta, el cobro y la nota crédito NO pasan por acá: viajan como factura,
 * recibo de caja y nota crédito, y SIIGO hace su propio asiento. Mandarlas
 * también como comprobante duplicaría la contabilidad.
 *
 * ── Qué se manda ───────────────────────────────────────────────────────────
 *
 * Las líneas del libro de ese origen que todavía no fueron (`siigo_journal_id`
 * vacío) y van al libro fiscal. Se agrupan por FECHA: la reversa de un asiento
 * lleva la fecha del día en que se reversó y el asiento nuevo la del
 * movimiento, y cada grupo cuadra por su cuenta. Al volver, cada línea queda
 * marcada con el id del comprobante: un reintento no repite nada y un cambio
 * posterior (reversa + asiento nuevo) viaja como comprobante nuevo.
 *
 * Del recibo de caja sólo viajan las líneas `[REVERSA …]`: el recibo original
 * ya lo asentó SIIGO.
 */
class ComprobanteDropiSiigo
{
    public function __construct(private SiigoClient $cliente) {}

    /** Las líneas que faltan por mandar, agrupadas por fecha. */
    public static function pendientes(string $origenType, int $origenId): Collection
    {
        $q = MovimientoContable::query()
            ->where('origen_type', $origenType)
            ->where('origen_id', $origenId)
            ->pendienteDeSiigo()
            ->orderBy('id');

        if ($origenType === PagoVenta::class) {
            $q->where('descripcion', 'like', '[REVERSA%');
        }

        return $q->get()->groupBy(fn ($l) => $l->fecha->format('Y-m-d'));
    }

    /**
     * Manda lo pendiente. Devuelve los ids de comprobante creados.
     *
     * @return array<int,string>
     */
    public function emitir(string $origenType, int $origenId): array
    {
        $creados = [];
        foreach (self::pendientes($origenType, $origenId) as $fecha => $lineas) {
            $creados[] = $this->emitirGrupo($fecha, $lineas, $origenType, $origenId);
        }

        return $creados;
    }

    /** @param  Collection<int,MovimientoContable>  $lineas */
    private function emitirGrupo(string $fecha, Collection $lineas, string $origenType, int $origenId): string
    {
        $debe = round((float) $lineas->sum('debe'), 2);
        $haber = round((float) $lineas->sum('haber'), 2);
        if (abs($debe - $haber) > 0.01) {
            throw new RuntimeException("El asiento de {$this->nombre($origenType, $origenId)} del {$fecha} no cuadra: debe {$debe} · haber {$haber}.");
        }

        $documento = (int) setting('siigo.doc_type_wallet_dropi')
            ?: (int) setting('siigo.doc_type_asiento_manual')
            ?: (int) setting('siigo.doc_type_gasto');
        if ($documento <= 0) {
            throw new RuntimeException('Falta el tipo de comprobante contable de SIIGO para la wallet de Dropi (regla «SIIGO · Tipo documento Wallet Dropi»).');
        }

        $nitDropi = RegistrarAsientoWalletDropi::terceroDropi()?->numero_documento;

        $items = $lineas->map(function (MovimientoContable $l) use ($nitDropi) {
            $nit = $l->tercero_type === \App\Models\Contacto::class && $l->tercero_id
                ? \App\Models\Contacto::whereKey($l->tercero_id)->value('numero_documento')
                : null;
            $nit = preg_replace('/\D/', '', (string) ($nit ?: $nitDropi));
            if ($nit === '') {
                // SIIGO exige tercero en cada línea de un comprobante, y la
                // billetera y los anticipos son cuentas que lo piden.
                throw new RuntimeException('Falta el NIT de Dropi (regla «NIT de Dropi»): SIIGO exige un tercero en cada línea del comprobante.');
            }
            $debito = (float) $l->debe > 0;

            return [
                'account' => ['code' => CuentasSiigo::codigo($l->cuenta_puc), 'movement' => $debito ? 'Debit' : 'Credit'],
                'customer' => ['identification' => $nit],
                'value' => round($debito ? (float) $l->debe : (float) $l->haber, 2),
                'description' => mb_substr((string) $l->descripcion, 0, 160),
            ];
        })->values()->all();

        $payload = [
            'document' => ['id' => $documento],
            'date' => $fecha,
            'items' => $items,
            'observations' => "ERP {$this->nombre($origenType, $origenId)} · ".count($items).' líneas',
        ];

        Log::channel('single')->info('[SIIGO comprobante Dropi] enviando', [
            'origen' => class_basename($origenType), 'id' => $origenId, 'fecha' => $fecha, 'valor' => $debe,
        ]);

        // La clave sale de la PRIMERA línea del grupo: cada tanda de líneas es
        // un comprobante distinto, y un reintento de la misma tanda da la misma.
        $clave = ClaveIdempotencia::para('dwal', $lineas->first());
        $resp = $this->cliente->request('POST', '/v1/journals', $payload, 1, $clave);

        if ($resp->failed()) {
            $msg = (string) ($resp->json('Errors.0.Message') ?? $resp->json('message') ?? 'HTTP '.$resp->status());
            throw new RuntimeException("SIIGO rechazó el comprobante de {$this->nombre($origenType, $origenId)}: {$msg}");
        }
        $id = (string) ($resp->json('id') ?? '');
        if ($id === '') {
            throw new RuntimeException("SIIGO respondió sin id para el comprobante de {$this->nombre($origenType, $origenId)}.");
        }

        DB::transaction(fn () => MovimientoContable::whereIn('id', $lineas->pluck('id'))->update(['siigo_journal_id' => $id]));

        return $id;
    }

    private function nombre(string $origenType, int $origenId): string
    {
        return match ($origenType) {
            \App\Modules\Dropi\Models\DropiWalletMovimiento::class => "movimiento de wallet Dropi #{$origenId}",
            PagoVenta::class => "reversa del recibo #{$origenId}",
            default => class_basename($origenType)." #{$origenId}",
        };
    }
}
