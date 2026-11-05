<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\NotaDebito;
use App\Modules\Cartera\Models\PagoProveedor;
use App\Modules\Compras\Models\DevolucionProveedor;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Contabilidad\Models\AsientoManual;
use App\Modules\Siigo\Models\SiigoSyncLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CONT-C1 · Dashboard único de documentos pendientes de SIIGO.
 *
 * Aracely entra aquí y ve TODO lo que no llegó a SIIGO (recepciones,
 * devoluciones, pagos, NC, ND, asientos) con el último error, para reenviar
 * sin tener que ir módulo por módulo. Dos tablas por tipo:
 *   - pendientes "limpios" (nunca intentados o encolados)
 *   - pendientes con error reciente (tomado del siigo_sync_log por `detalle.*_id`)
 */
class ContabilidadPendientesSiigoController extends Controller implements HasMiddleware
{
    /**
     * A3 FIX · CRÍTICO · sin este guard cualquier user autenticado veía
     *   recepciones, pagos, NC/ND y asientos pendientes de SIIGO.
     *   Mismo gate que ContabilidadExtras (esContable).
     */
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless($r->user()?->esContable(), 403,
                'Solo el equipo contable puede ver los pendientes de SIIGO.');
            return $next($r);
        })];
    }

    public function index(): Response
    {
        /*
         * Las facturas de venta, que faltaban.
         *
         * Este tablero listaba recepciones, devoluciones, pagos a proveedor,
         * notas crédito, notas débito y asientos manuales —las seis cosas que
         * mueven plata hacia afuera— y NO listaba lo único que la empresa está
         * obligada a reportarle a la DIAN. Resultado: 25 de 25 facturas sin
         * llegar a SIIGO desde el 21 de octubre y ninguna pantalla donde se
         * notara. La única forma de verlo era mirar la tabla `jobs` a mano.
         *
         * Van primero en el listado porque son las que tienen plazo legal.
         */
        $facturas = FacturaVenta::query()
            ->whereNull('siigo_id')
            // Por destino fiscal y no por `es_electronica`: esa marca se
            // escribe al crear la factura y en produccion quedo en 0 para
            // las 20 fiscales, que asi desaparecian de esta bandeja.
            ->whereIn('destino_fiscal', ['siigo', 'reparto'])
            ->where('estado', '<>', 'anulada')
            ->orderByDesc('fecha_emision')
            ->limit(200)
            ->get(['id', 'numero', 'fecha_emision', 'total'])
            ->map(fn ($f) => $this->toRow('factura', $f->id, $f->numero, $f->fecha_emision, (float) $f->total,
                "/app/facturas/{$f->id}", "/app/facturas/{$f->id}/reenviar-siigo"));

        /*
         * `declarable()`: una compra marcada INTERNA no va a SIIGO nunca, y
         * sin este filtro se quedaba en esta lista para siempre, pidiendo un
         * envio que por diseno no se va a hacer. Eran 2 de las 4 recepciones
         * pendientes.
         */
        $recepciones = RecepcionCompra::query()
            ->declarable()
            ->whereNull('siigo_id')
            ->where('estado', 'confirmada')
            ->orderByDesc('fecha_recepcion')
            ->limit(200)
            ->get(['id', 'numero', 'fecha_recepcion', 'total_recibido', 'orden_id'])
            ->map(fn ($r) => $this->toRow('recepcion', $r->id, $r->numero, $r->fecha_recepcion, (float) $r->total_recibido,
                "/app/compras/recepcion/{$r->id}", "/app/compras/recepcion/{$r->id}/reenviar-siigo"));

        $devoluciones = DevolucionProveedor::query()
            ->declarable()
            ->whereNull('siigo_id')
            ->where('estado', 'confirmada')
            ->orderByDesc('fecha')
            ->limit(200)
            ->get(['id', 'numero', 'fecha', 'total'])
            ->map(fn ($d) => $this->toRow('devolucion', $d->id, $d->numero, $d->fecha, (float) $d->total,
                "/app/compras/devoluciones/{$d->id}", "/app/compras/devoluciones/{$d->id}/reenviar-siigo"));

        /*
         * Solo los CONFIRMADOS. La pantalla no miraba el estado, asi que un
         * pago anulado figuraba como «pendiente de SIIGO» —y mandarlo seria
         * justo lo que no hay que hacer—. La tarea nocturna si lo filtraba,
         * de modo que los dos numeros nunca coincidian.
         */
        $pagos = PagoProveedor::query()
            ->declarable()
            ->where('estado', 'confirmado')
            ->whereNull('siigo_voucher_id')
            ->orderByDesc('fecha')
            ->limit(200)
            ->get(['id', 'fecha', 'monto_neto', 'contacto_id'])
            ->map(fn ($p) => $this->toRow('pago_proveedor', $p->id, "PP-{$p->id}", $p->fecha, (float) $p->monto_neto,
                "/app/cartera/pagos-proveedor?destacar={$p->id}", "/app/cartera/pagos-proveedor/{$p->id}/reenviar-siigo"));

        $notasCredito = NotaCredito::query()
            ->whereNull('siigo_id')
            ->whereIn('estado', ['emitida', 'aceptada'])
            ->orderByDesc('emitida_at')
            ->limit(200)
            ->get(['id', 'prefijo', 'numero', 'emitida_at', 'valor'])
            ->map(fn ($n) => $this->toRow('nota_credito', $n->id, "{$n->prefijo}-{$n->numero}", $n->emitida_at, (float) $n->valor,
                "/app/cartera/notas-credito?destacar={$n->id}", "/app/cartera/notas-credito/{$n->id}/reenviar-siigo"));

        $notasDebito = NotaDebito::query()
            ->whereNull('siigo_id')
            ->whereIn('estado', ['emitida', 'aceptada'])
            ->orderByDesc('emitida_at')
            ->limit(200)
            ->get(['id', 'prefijo', 'numero', 'emitida_at', 'valor'])
            ->map(fn ($n) => $this->toRow('nota_debito', $n->id, "{$n->prefijo}-{$n->numero}", $n->emitida_at, (float) $n->valor,
                "/app/cartera/notas-debito?destacar={$n->id}", "/app/cartera/notas-debito/{$n->id}/reenviar-siigo"));

        $asientos = AsientoManual::query()
            ->whereNull('siigo_journal_id')
            ->where('estado', 'aprobado')
            ->orderByDesc('fecha')
            ->limit(200)
            ->get(['id', 'fecha', 'glosa', 'valor_total'])
            ->map(fn ($a) => $this->toRow('asiento_manual', $a->id, "AM-{$a->id}", $a->fecha, (float) $a->valor_total,
                "/app/contabilidad/asientos-manuales?destacar={$a->id}", "/app/contabilidad/asientos-manuales/{$a->id}/reenviar-siigo"));

        // Último error + conteo de fallos por recurso+id leído desde siigo_sync_log.
        $errores = $this->ultimosErrores();
        $enrich = function ($row) use ($errores) {
            $k = "{$row['tipo']}:{$row['id']}";
            if (isset($errores[$k])) {
                $row['ultimo_error'] = $errores[$k]['mensaje'];
                $row['ultimo_error_at'] = $errores[$k]['created_at'];
                $row['fallos'] = $errores[$k]['count'];
                // CONT-C7 · falla permanente = 3+ intentos fallidos en 7 días.
                //   A ese punto el job ya consumió los retries de Laravel y es
                //   seguro que requiere intervención manual (config, datos).
                $row['falla_permanente'] = $errores[$k]['count'] >= 3;
            } else {
                $row['fallos'] = 0;
                $row['falla_permanente'] = false;
            }
            return $row;
        };

        $todos = collect()
            ->concat($facturas)
            ->concat($recepciones)->concat($devoluciones)->concat($pagos)
            ->concat($notasCredito)->concat($notasDebito)->concat($asientos)
            ->map($enrich)
            // CONT-C7 · ordena primero por falla permanente (urgencia), luego por fecha.
            ->sortBy(fn ($r) => [$r['falla_permanente'] ? 0 : 1, -strtotime($r['fecha'] ?? '2000-01-01')])
            ->values();

        $fallasPermanentes = $todos->where('falla_permanente', true)->count();

        return Inertia::render('Contabilidad/PendientesSiigo', [
            'resumen' => [
                'facturas' => $facturas->count(),
                'recepciones' => $recepciones->count(),
                'devoluciones' => $devoluciones->count(),
                'pagos' => $pagos->count(),
                'notas_credito' => $notasCredito->count(),
                'notas_debito' => $notasDebito->count(),
                'asientos' => $asientos->count(),
                'total' => $todos->count(),
                'fallas_permanentes' => $fallasPermanentes,
            ],
            'pendientes' => $todos->all(),
        ]);
    }

    /*
     * Los cuatro documentos que NO tienen pantalla de detalle —pagos a
     * proveedor, notas credito, notas debito y asientos manuales— enlazan al
     * LISTADO con `?destacar=<id>`, que la pantalla usa para señalar la fila.
     * Antes apuntaban a un `show` inexistente y daban 404: justo en la
     * pantalla que existe para destrabar lo que no llego a SIIGO.
     */
    private function toRow(string $tipo, int $id, string $numero, $fecha, float $monto, string $urlVer, string $urlReenviar): array
    {
        return [
            'tipo' => $tipo,
            'id' => $id,
            'numero' => $numero,
            'fecha' => $fecha?->toDateString() ?? null,
            'monto' => $monto,
            'url_ver' => $urlVer,
            'url_reenviar' => $urlReenviar,
            'ultimo_error' => null,
            'ultimo_error_at' => null,
        ];
    }

    /**
     * Último error de SIIGO por recurso+id — leído del siigo_sync_log.
     * El schema del log guarda el id del documento origen dentro de `detalle`
     * (JSON) · ej `{"recepcion_id":4,"devolucion_id":1,"pago_id":3}`.
     * Devuelve ['recurso:id' => ['mensaje' => ..., 'created_at' => ...]].
     */
    private function ultimosErrores(): array
    {
        // Mapeo recurso del log → tipo del dashboard.
        $mapa = [
            'recepciones_compra' => ['recepcion', 'recepcion_id'],
            'devoluciones_proveedor' => ['devolucion', 'devolucion_id'],
            'pagos_proveedor' => ['pago_proveedor', 'pago_id'],
            'notas_credito' => ['nota_credito', 'nc_id'],
            'notas_debito' => ['nota_debito', 'nd_id'],
            'asientos_manuales' => ['asiento_manual', 'asiento_id'],
        ];
        $recursos = array_keys($mapa);
        $logs = SiigoSyncLog::query()
            ->whereIn('recurso', $recursos)
            ->where('estado', 'fallido')
            ->where('created_at', '>=', now()->subDays(14))
            ->orderByDesc('id')
            ->limit(2000)
            ->get(['recurso', 'mensaje', 'detalle', 'created_at']);
        $out = [];
        foreach ($logs as $l) {
            [$tipo, $campoId] = $mapa[$l->recurso];
            $detalle = is_string($l->detalle) ? json_decode($l->detalle, true) : (array) $l->detalle;
            $docId = $detalle[$campoId] ?? null;
            if (! $docId) continue;
            $k = "{$tipo}:{$docId}";
            if (! isset($out[$k])) {
                // Primer hit = más reciente (orderByDesc id arriba).
                $out[$k] = [
                    'mensaje' => mb_substr((string) $l->mensaje, 0, 300),
                    'created_at' => $l->created_at?->toIso8601String(),
                    'count' => 1,
                ];
            } else {
                $out[$k]['count']++;
            }
        }
        return $out;
    }
}
