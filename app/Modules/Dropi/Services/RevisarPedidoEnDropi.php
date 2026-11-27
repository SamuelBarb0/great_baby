<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cómo está HOY en Dropi un pedido que el ERP todavía no ha despachado, y el
 * ERP al día con eso (26-nov-2026). El sync lee la lista de Dropi, y una orden
 * que el dropshipper edita no sale más en ella: Dropi crea otra y la vieja
 * queda en «Orden no encontrada». El ERP la seguía teniendo pendiente: salía en
 * el alistador, en los cortes y en las guías. Por eso se pregunta pedido por
 * pedido. Lo usan la validación de cada guía (GenerarGuiasEnBloqueJob) y la
 * revisión periódica de lo que no ha salido (dropi:revisar-pendientes).
 */
class RevisarPedidoEnDropi
{
    /** Sigue pendiente en Dropi: no se toca. */
    public const PENDIENTE = 'pendiente';

    /** Dropi ya no tiene la orden (reemplazada al editarla, o borrada). */
    public const NO_EXISTE = 'no_existe';

    /** Dropi la tiene en otro estatus (cancelado, rechazado, con guía…). */
    public const OTRO_ESTATUS = 'otro_estatus';

    /** No se pudo preguntar: se reintenta en la próxima vuelta. */
    public const ERROR = 'error';

    public function __construct(
        protected DropiWebClient $client,
        protected MapearPedidoDropiWeb $mapper,
        protected SincronizarPedidosDropi $sync,
    ) {}

    /**
     * Sólo pregunta: no cambia nada.
     *
     * @return array{tipo: string, estatus: ?string, motivo: ?string, detalle: ?array}
     */
    public function diagnosticar(DropiPedido $p): array
    {
        try {
            $detalle = $this->client->pedidoDetalle((int) $p->dropi_orden_id);
        } catch (\Throwable $e) {
            return self::noExisteEnDropi($e->getMessage())
                ? ['tipo' => self::NO_EXISTE, 'estatus' => null, 'motivo' => 'Dropi ya no tiene la orden.', 'detalle' => null]
                : ['tipo' => self::ERROR, 'estatus' => null, 'motivo' => self::motivoDropi($e->getMessage()), 'detalle' => null];
        }

        $estatus = mb_strtoupper(trim((string) ($detalle['status'] ?? '')));

        return [
            'tipo' => $estatus === 'PENDIENTE' ? self::PENDIENTE : self::OTRO_ESTATUS,
            'estatus' => $estatus,
            'motivo' => null,
            'detalle' => $detalle,
        ];
    }

    /**
     * Pregunta y pone el ERP al día: sin la orden en Dropi, cancelada por Dropi;
     * en otro estatus, como la trae el sync.
     *
     * @param  string  $origen  queda en la bitácora del pedido
     * @return array{tipo: string, estatus: ?string, motivo: ?string, detalle: ?array}
     */
    public function revisar(DropiPedido $p, string $origen): array
    {
        $r = $this->diagnosticar($p);
        match ($r['tipo']) {
            self::NO_EXISTE => $this->darDeBaja($p, $origen),
            self::OTRO_ESTATUS => $this->ponerAlDia($p, $r['detalle'], $r['estatus']),
            self::PENDIENTE => $this->marcarRevisado($p, 'PENDIENTE'),
            default => null,
        };

        return $r;
    }

    /** Dropi ya no tiene la orden: sale del ERP como cancelada por Dropi, sin usuario (como una «REEMPLAZADA» del sync). */
    public function darDeBaja(DropiPedido $p, string $origen): void
    {
        try {
            $p->transicionar(EstadoPedidoDropi::CanceladoDropi, 'api', null, [
                'origen' => $origen, 'motivo' => 'Dropi ya no tiene la orden ' . $p->dropi_orden_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('dropi.revisar.dar_de_baja', ['pedido_id' => $p->id, 'msg' => $e->getMessage()]);
        }
        $this->marcarRevisado($p, 'NO ENCONTRADA');
    }

    /** En Dropi está en otro estatus: el ERP lo trae como está allá (lo mismo que haría el sync). */
    public function ponerAlDia(DropiPedido $p, array $detalle, string $estatus): void
    {
        try {
            $this->sync->importarPedidos([$this->mapper->desdeDetalle($detalle)]);
        } catch (\Throwable $e) {
            Log::warning('dropi.revisar.poner_al_dia', ['pedido_id' => $p->id, 'msg' => $e->getMessage()]);
        }
        // Huella vacía: la próxima vuelta del sync lo vuelve a detallar si cambia.
        DB::table('dropi_web_huellas')->updateOrInsert(
            ['dropi_orden_id' => (int) $p->dropi_orden_id],
            ['estatus_dropi' => mb_substr($estatus, 0, 60), 'huella' => '', 'sincronizado_at' => now()],
        );
        $p->refresh();
    }

    /** Cuándo se le preguntó a Dropi: la revisión periódica empieza por lo que lleva más tiempo sin mirar. */
    private function marcarRevisado(DropiPedido $p, string $estatus): void
    {
        $existe = DB::table('dropi_web_huellas')->where('dropi_orden_id', (int) $p->dropi_orden_id)->exists();
        DB::table('dropi_web_huellas')->updateOrInsert(
            ['dropi_orden_id' => (int) $p->dropi_orden_id],
            ['estatus_dropi' => $estatus, 'sincronizado_at' => now()] + ($existe ? [] : ['huella' => '']),
        );
    }

    /** La orden que la reemplazó: mismo teléfono, creada ese mismo día, y viva. */
    public static function reemplazoDe(DropiPedido $p): ?DropiPedido
    {
        if (blank($p->cliente_telefono)) {
            return null;
        }
        $cuando = $p->dropi_creado_at ?? $p->created_at;

        return DropiPedido::query()
            ->where('cliente_telefono', $p->cliente_telefono)
            ->whereKeyNot($p->id)
            ->whereNotIn('estado', [EstadoPedidoDropi::CanceladoDropi, EstadoPedidoDropi::CanceladoGb])
            ->when($cuando, fn ($q) => $q->whereBetween('dropi_creado_at', [$cuando->copy()->subDay(), $cuando->copy()->addDays(2)]))
            ->orderByDesc('dropi_orden_id')
            ->first();
    }

    /** «Orden no encontrada» al leerla, o «fue reemplazada» al pedir la guía. */
    public static function noExisteEnDropi(string $msg): bool
    {
        $m = mb_strtolower($msg);

        return str_contains($m, 'no encontrada') || str_contains($m, 'reemplazada por una nueva');
    }

    /** Lo que dijo Dropi, sin el «Dropi no cambió el pedido 123:» del cliente. */
    public static function motivoDropi(string $msg): string
    {
        $m = preg_replace('/^Dropi no (cambió|dejó|devolvió)[^:]*:\s*/u', '', $msg);
        $m = preg_replace('/^Error al actualizar la orden:\s*/u', '', (string) $m);

        return mb_substr(trim((string) $m), 0, 160);
    }
}
