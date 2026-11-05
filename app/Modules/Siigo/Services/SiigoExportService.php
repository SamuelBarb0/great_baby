<?php

namespace App\Modules\Siigo\Services;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Contabilidad\Models\ConciliacionBancaria;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Gerencia\Models\GastoOperativo;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Enums\TipoExportacionSiigo;
use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Support\CuentasSiigo;
use App\Modules\Siigo\Models\SiigoSyncLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Exporta al ERP fiscal (SIIGO) los documentos que NO son factura de venta:
 * compras nacionales, gastos, importaciones y conciliación bancaria.
 *
 * Sigue la matriz de {@see TipoExportacionSiigo} acordada con contabilidad:
 *  - compras / importaciones  → comprobante de compra ítem por ítem (mueve inventario)
 *  - gastos / conciliación    → asiento (journal), reutilizando los MovimientoContable internos
 *  - importaciones y conciliación se envían como BORRADOR editable (sus valores cambian).
 *
 * La factura de venta electrónica NO pasa por aquí: usa {@see SiigoEmisionService}.
 *
 * ⚠️ Pendiente de validar contra el sandbox de SIIGO cuando el cliente entregue credenciales:
 *   (1) el shape exacto de POST /v1/purchases y /v1/journals,
 *   (2) los IDs de tipo de documento por recurso — se leen de settings:
 *       siigo.doc_type_compra · siigo.doc_type_gasto · siigo.doc_type_importacion · siigo.doc_type_conciliacion
 *   SIIGO no expone un flag de "borrador" en purchases/journals (no van a la DIAN), así que
 *   el borrador se materializa creando el documento en SIIGO con una nota visible para que
 *   Silvia lo ajuste allá; el estado editable lo da la propia plataforma de SIIGO.
 *
 * @see https://developers.siigo.com/docs
 */
class SiigoExportService
{
    public function __construct(
        private readonly SiigoClient $cliente,
    ) {}

    /**
     * Exporta un documento a SIIGO.
     *
     * @param  bool|null  $borrador  fuerza el modo borrador; null usa el default del tipo.
     * @return array{ok:bool, tipo:string, borrador:bool, siigo_id:?string, siigo_numero:?string, log_id:int}
     */
    public function exportar(TipoExportacionSiigo $tipo, Model $documento, ?bool $borrador = null): array
    {
        if ($tipo === TipoExportacionSiigo::FacturaVenta) {
            throw new RuntimeException('Las facturas de venta se emiten con SiigoEmisionService, no con SiigoExportService.');
        }

        $this->validarConfig();
        $esBorrador = $borrador ?? $tipo->borradorPorDefecto();

        $inicio = microtime(true);

        $payload = match ($tipo->modo()) {
            'comprobante' => $this->construirComprobante($tipo, $documento, $esBorrador),
            'asiento' => $this->construirAsiento($tipo, $documento, $esBorrador),
            default => throw new RuntimeException("Modo de exportación no soportado: {$tipo->modo()}."),
        };

        Log::channel('siigo')->info('[SIIGO export] enviando', [
            'tipo' => $tipo->value,
            'documento' => $documento::class.'#'.$documento->getKey(),
            'borrador' => $esBorrador,
        ]);

        // Idempotency-Key estable por (tipo · documento · borrador): si el envío
        //   se reintenta tras un timeout, SIIGO devuelve el documento que ya
        //   creó en vez de duplicar un gasto, una conciliación o una OC.
        //   El cliente la hashea a ≤28 chars, así que puede ser descriptiva.
        $idemKey = "exp:{$tipo->value}:{$documento->getKey()}" . ($esBorrador ? ':b' : '');

        try {
            $response = $this->cliente->request('POST', $tipo->endpoint(), $payload, 1, $idemKey);
        } catch (\Throwable $e) {
            $log = $this->registrarLog($tipo, 'error', "Error de red hacia SIIGO: {$e->getMessage()}", [
                'documento' => $documento::class.'#'.$documento->getKey(),
                'borrador' => $esBorrador,
            ], $inicio);
            throw new RuntimeException("Error de red hacia SIIGO: {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            $msg = (string) ($response->json('Errors.0.Message')
                ?? $response->json('message')
                ?? "SIIGO rechazó el documento (HTTP {$response->status()}).");
            $log = $this->registrarLog($tipo, 'error', $msg, [
                'documento' => $documento::class.'#'.$documento->getKey(),
                'http_status' => $response->status(),
                'borrador' => $esBorrador,
            ], $inicio);
            throw new RuntimeException("SIIGO rechazó {$tipo->etiqueta()}: {$msg}");
        }

        $data = (array) $response->json();
        $siigoId = isset($data['id']) ? (string) $data['id'] : null;
        $siigoNumero = (string) ($data['name'] ?? $data['number'] ?? '');

        $this->persistirReferencia($documento, $siigoId, $esBorrador);

        $log = $this->registrarLog($tipo, 'ok', $esBorrador ? 'Enviado a SIIGO como borrador.' : 'Enviado a SIIGO.', [
            'documento' => $documento::class.'#'.$documento->getKey(),
            'siigo_id' => $siigoId,
            'siigo_numero' => $siigoNumero,
            'borrador' => $esBorrador,
        ], $inicio);

        return [
            'ok' => true,
            'tipo' => $tipo->value,
            'borrador' => $esBorrador,
            'siigo_id' => $siigoId,
            'siigo_numero' => $siigoNumero !== '' ? $siigoNumero : null,
            'log_id' => $log->id,
        ];
    }

    // ---------------------------------------------------------------- payloads

    /**
     * Comprobante de compra (ítem por ítem) para compras nacionales e importaciones.
     * POST /v1/purchases
     */
    private function construirComprobante(TipoExportacionSiigo $tipo, Model $documento, bool $borrador): array
    {
        [$fecha, $proveedorDoc, $observacionesBase, $items] = $this->extraerComprobante($documento);

        if (empty($items)) {
            throw new RuntimeException('El documento '.$documento::class.'#'.$documento->getKey().' no tiene ítems para exportar.');
        }

        return [
            'document' => ['id' => $this->tipoDocumentoId($tipo)],
            'date' => $fecha,
            'supplier' => ['identification' => $proveedorDoc],
            'items' => $items,
            'observations' => $this->observaciones($observacionesBase, $borrador),
        ];
    }

    /**
     * Asiento contable (journal) para gastos y conciliación bancaria.
     * Reutiliza los MovimientoContable internos del documento (partida doble ya cuadrada).
     * POST /v1/journals
     */
    private function construirAsiento(TipoExportacionSiigo $tipo, Model $documento, bool $borrador): array
    {
        $lineas = $this->lineasAsiento($documento);

        if (empty($lineas)) {
            throw new RuntimeException('No hay movimientos contables asociados a '.$documento::class.'#'.$documento->getKey().' para armar el asiento.');
        }

        $fecha = $this->fechaDocumento($documento);

        return [
            'document' => ['id' => $this->tipoDocumentoId($tipo)],
            'date' => $fecha,
            'items' => $lineas,
            'observations' => $this->observaciones($this->descripcionDocumento($documento), $borrador),
        ];
    }

    /**
     * @return array{0:string,1:string,2:string,3:array<int,array<string,mixed>>}
     *         [fecha Y-m-d, identificación proveedor, observaciones base, items SIIGO]
     */
    private function extraerComprobante(Model $documento): array
    {
        if ($documento instanceof OrdenCompra) {
            $documento->loadMissing(['proveedor', 'items.variante.producto']);
            $items = $documento->items->map(fn ($it) => [
                'type' => 'Product',
                'code' => (string) ($it->variante?->codigo_barras ?? "ITEM-{$it->id}"),
                'description' => (string) ($it->descripcion ?? $it->variante?->producto?->nombre ?? 'Ítem'),
                'quantity' => (float) $it->cantidad,
                'price' => (float) $it->precio_unit,
                'discount' => (float) ($it->descuento_pct ?? 0),
            ])->all();

            return [
                $this->fechaDocumento($documento),
                (string) ($documento->proveedor?->numero_documento ?? ''),
                "Compra nacional interna #{$documento->getKey()}",
                $items,
            ];
        }

        if ($documento instanceof Importacion) {
            $documento->loadMissing(['lineas.variante.producto']);
            $items = $documento->lineas->map(fn ($ln) => [
                'type' => 'Product',
                'code' => (string) ($ln->variante?->codigo_barras ?? "ITEM-{$ln->id}"),
                'description' => (string) ($ln->variante?->producto?->nombre ?? 'Ítem importado'),
                'quantity' => (float) $ln->cantidad,
                // Importación: se envía el costo REAL; Silvia ajusta el valor fiscal en SIIGO (borrador).
                'price' => (float) ($ln->costo_final_unit ?? $ln->costo_fob_unit ?? 0),
            ])->all();

            return [
                $this->fechaDocumento($documento),
                (string) ($documento->proveedor?->numero_documento ?? setting('siigo.proveedor_importacion_doc', '')),
                "Importación interna #{$documento->getKey()}",
                $items,
            ];
        }

        throw new RuntimeException('Tipo de documento no soportado para comprobante: '.$documento::class);
    }

    /**
     * Mapea los MovimientoContable del documento a líneas de journal de SIIGO.
     * Si el documento es un GastoOperativo sin asiento previo, arma uno mínimo (2 líneas).
     *
     * @return array<int,array<string,mixed>>
     */
    private function lineasAsiento(Model $documento): array
    {
        $movs = MovimientoContable::query()
            ->where('origen_type', $documento::class)
            ->where('origen_id', $documento->getKey())
            ->get();

        if ($movs->isNotEmpty()) {
            return $movs->map(function (MovimientoContable $m) {
                $esDebito = (float) $m->debe > 0;
                $item = [
                    'account' => ['code' => CuentasSiigo::codigo($m->cuenta_puc), 'movement' => $esDebito ? 'Debit' : 'Credit'],
                    'description' => (string) ($m->descripcion ?? ''),
                    'value' => (float) ($esDebito ? $m->debe : $m->haber),
                ];
                // SIIGO exige el tercero (NIT) en las cuentas que lo requieren (CxC/CxP).
                if ($ident = $this->identificacionTercero($m)) {
                    $item['customer'] = ['identification' => $ident];
                }

                return $item;
            })->all();
        }

        // Fallback: gasto operativo sin asiento interno → débito gasto / crédito banco.
        if ($documento instanceof GastoOperativo) {
            $monto = (float) $documento->monto;
            $ctaGasto = (string) setting('siigo.cta_gasto_default', '5195');
            $ctaBanco = (string) setting('siigo.cta_banco_default');
            return [
                ['account' => ['code' => CuentasSiigo::codigo($ctaGasto), 'movement' => 'Debit'], 'description' => (string) $documento->descripcion, 'value' => $monto],
                ['account' => ['code' => CuentasSiigo::codigo($ctaBanco), 'movement' => 'Credit'], 'description' => (string) $documento->descripcion, 'value' => $monto],
            ];
        }

        // Fallback: conciliación bancaria → ajusta la diferencia contra una partida conciliatoria.
        if ($documento instanceof ConciliacionBancaria) {
            $dif = round((float) $documento->diferencia, 2);
            if (abs($dif) < 0.01) {
                return [];
            }
            $ctaBanco = (string) ($documento->cuenta_puc ?: setting('siigo.cta_banco_default'));
            $ctaClearing = (string) setting('siigo.cta_conciliacion_default', '139535');
            // dif>0: el extracto tiene más que el sistema → falta registrar ingreso al banco.
            $bancoDebita = $dif > 0;
            $valor = abs($dif);
            $desc = 'Ajuste conciliación '.$documento->banco.' '.$this->fechaDocumento($documento);
            return [
                ['account' => ['code' => CuentasSiigo::codigo($ctaBanco), 'movement' => $bancoDebita ? 'Debit' : 'Credit'], 'description' => $desc, 'value' => $valor],
                ['account' => ['code' => CuentasSiigo::codigo($ctaClearing), 'movement' => $bancoDebita ? 'Credit' : 'Debit'], 'description' => 'Partida conciliatoria por aclarar', 'value' => $valor],
            ];
        }

        return [];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Identificación (NIT/CC) del tercero de un movimiento contable, si existe.
     * tercero es un morph que puede apuntar a Contacto/User o a tipos no-clase
     * ('dropi', 'anonimo'); solo resolvemos cuando es una clase válida.
     */
    private function identificacionTercero(MovimientoContable $m): ?string
    {
        $type = $m->tercero_type;
        if (empty($type) || ! class_exists($type)) {
            return null;
        }

        try {
            $tercero = $m->tercero;
        } catch (\Throwable) {
            return null;
        }

        $ident = $tercero?->numero_documento
            ?? $tercero?->identificacion
            ?? $tercero?->nit
            ?? null;

        $ident = is_string($ident) ? trim($ident) : (string) ($ident ?? '');

        return $ident !== '' ? $ident : null;
    }

    private function tipoDocumentoId(TipoExportacionSiigo $tipo): int
    {
        $clave = $tipo->settingTipoDocumento();
        $id = (int) setting($clave, 0);

        if ($id <= 0) {
            throw new RuntimeException(
                "Falta el ID de tipo de documento de SIIGO para «{$tipo->etiqueta()}». ".
                "Configúralo en settings con la clave «{$clave}»."
            );
        }

        return $id;
    }

    private function observaciones(string $base, bool $borrador): string
    {
        return $borrador
            ? '[BORRADOR — revisar y ajustar valores en SIIGO] '.$base
            : $base;
    }

    private function fechaDocumento(Model $documento): string
    {
        $fecha = $documento->fecha ?? $documento->created_at ?? now();

        return $fecha instanceof \DateTimeInterface
            ? $fecha->format('Y-m-d')
            : (string) \Illuminate\Support\Carbon::parse($fecha)->format('Y-m-d');
    }

    private function descripcionDocumento(Model $documento): string
    {
        return (string) ($documento->descripcion
            ?? $documento->numero
            ?? class_basename($documento).' #'.$documento->getKey());
    }

    /**
     * Guarda la referencia de SIIGO en el documento si el modelo expone las columnas
     * (siigo_id / siigo_borrador). Silencioso si no las tiene, para no acoplar modelos.
     */
    private function persistirReferencia(Model $documento, ?string $siigoId, bool $borrador): void
    {
        $cambios = [];
        if ($siigoId !== null && $this->tieneColumna($documento, 'siigo_id')) {
            $cambios['siigo_id'] = $siigoId;
        }
        if ($this->tieneColumna($documento, 'siigo_borrador')) {
            $cambios['siigo_borrador'] = $borrador;
        }
        if ($this->tieneColumna($documento, 'siigo_exportado_at')) {
            $cambios['siigo_exportado_at'] = now();
        }

        if ($cambios !== []) {
            $documento->forceFill($cambios)->save();
        }
    }

    private function tieneColumna(Model $documento, string $columna): bool
    {
        return $documento->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($documento->getTable(), $columna);
    }

    private function validarConfig(): void
    {
        $config = SiigoConfig::current();

        if (! $config->activo) {
            throw new RuntimeException('La integración con SIIGO está desactivada. Actívala en Configuración → Integración SIIGO.');
        }
    }

    /**
     * @param  array<string,mixed>  $detalle
     */
    private function registrarLog(TipoExportacionSiigo $tipo, string $estado, string $mensaje, array $detalle, float $inicio): SiigoSyncLog
    {
        return SiigoSyncLog::create([
            'recurso' => 'export:'.$tipo->value,
            'estado' => $estado,
            'nuevos' => $estado === 'ok' ? 1 : 0,
            'actualizados' => 0,
            'errores' => $estado === 'ok' ? 0 : 1,
            'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
            'mensaje' => mb_substr($mensaje, 0, 500),
            'detalle' => $detalle,
            'user_id' => auth()->id(),
        ]);
    }
}
