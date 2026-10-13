<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\MapearPedidoDropiWeb;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * «Actualizar Estatus» de Dropi, hecho desde el ERP. Escribe en Dropi, deja
 * auditoría (dropi_web_acciones, también si falla) y vuelve a traer el pedido
 * al momento para que el ERP muestre la guía que Dropi haya generado.
 *
 * Las opciones son las que la web de Dropi le da a un proveedor. Cuál aplica
 * según el estado actual lo valida el servidor de Dropi; si no aplica, su
 * mensaje de error llega tal cual a la pantalla.
 */
class ActualizarEstatusEnDropi
{
    use AsAction;

    /** valor que se manda a Dropi => etiqueta en pantalla */
    public const OPCIONES = [
        // La web de Dropi manda «CONFIRMADO (PASARÁ A PENDIENTE)» como PENDIENTE.
        'PENDIENTE' => 'Confirmar (pasa a Pendiente)',
        'GUIA_GENERADA' => 'Generar guía',
        'RECHAZADO' => 'Rechazar pedido',
        'GUIA_ANULADA' => 'Anular guía',
    ];

    public function __construct(
        protected DropiWebClient $client,
        protected MapearPedidoDropiWeb $mapper,
        protected SincronizarPedidosDropi $sync,
    ) {}

    /**
     * @return array{shipping_guide: ?string, shipping_company: ?string, sticker: ?string}
     */
    public function handle(DropiPedido $pedido, string $estatus, ?string $motivo = null, ?int $userId = null): array
    {
        if (! array_key_exists($estatus, self::OPCIONES)) {
            throw new \InvalidArgumentException("Estatus no permitido: {$estatus}");
        }
        if (! $pedido->dropi_orden_id) {
            throw new \InvalidArgumentException('Este pedido no tiene número de orden de Dropi.');
        }
        $motivo = trim((string) $motivo) ?: null;
        if ($estatus === 'RECHAZADO' && ! $motivo) {
            throw new \InvalidArgumentException('Para rechazar un pedido Dropi pide un motivo.');
        }

        $antes = DB::table('dropi_web_huellas')->where('dropi_orden_id', $pedido->dropi_orden_id)->value('estatus_dropi');
        $auditoria = [
            'pedido_id' => $pedido->id,
            'dropi_orden_id' => (string) $pedido->dropi_orden_id,
            'accion' => 'actualizar_estatus',
            'estatus_antes' => $antes,
            'estatus_pedido' => $estatus,
            'motivo' => $motivo,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            $resultado = $this->client->actualizarEstatus((int) $pedido->dropi_orden_id, $estatus, $motivo);
        } catch (\Throwable $e) {
            DB::table('dropi_web_acciones')->insert($auditoria + ['exito' => false, 'respuesta' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }

        DB::table('dropi_web_acciones')->insert($auditoria + ['exito' => true, 'respuesta' => json_encode($resultado, JSON_UNESCAPED_UNICODE)]);

        // Traer el pedido ya cambiado (guía nueva, estado) sin esperar al sync.
        //   Si esto falla, el cambio en Dropi ya quedó hecho: el sync lo recoge.
        try {
            $this->sync->importarPedidos([$this->mapper->desdeDetalle($this->client->pedidoDetalle((int) $pedido->dropi_orden_id))]);
            DB::table('dropi_web_huellas')->where('dropi_orden_id', $pedido->dropi_orden_id)->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('dropi.web.resync_tras_accion', ['orden' => $pedido->dropi_orden_id, 'msg' => $e->getMessage()]);
        }

        return $resultado;
    }
}
