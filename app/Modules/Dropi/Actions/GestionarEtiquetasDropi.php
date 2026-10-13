<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiPedido;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Etiquetas de Dropi desde el ERP (el ícono de etiqueta de cada pedido en su web).
 * Recibe las etiquetas que el pedido debe quedar teniendo y aplica la diferencia
 * en Dropi: pone las nuevas y quita las que sobran. Opcionalmente crea una
 * etiqueta nueva en la cuenta y la pone. Cada escritura queda en dropi_web_acciones.
 */
class GestionarEtiquetasDropi
{
    use AsAction;

    public function __construct(protected DropiWebClient $client) {}

    /**
     * @param  array<int>  $deseadas  ids de etiquetas de Dropi
     * @return array{puestas:int, quitadas:int, creada:bool}
     */
    public function handle(DropiPedido $pedido, array $deseadas, ?string $nuevaNombre = null, ?string $nuevaColor = null, ?int $userId = null): array
    {
        $orden = (int) $pedido->dropi_orden_id;
        if (! $orden) {
            throw new \InvalidArgumentException('Este pedido no tiene número de orden de Dropi.');
        }

        $deseadas = array_values(array_unique(array_map('intval', $deseadas)));
        $creada = false;

        $nuevaNombre = trim((string) $nuevaNombre);
        if ($nuevaNombre !== '') {
            $existente = collect($this->client->etiquetasCuenta())
                ->first(fn ($t) => mb_strtolower($t['nombre']) === mb_strtolower($nuevaNombre));
            if (! $existente) {
                $this->escribir($pedido, 'crear_etiqueta', $nuevaNombre, fn () => $this->client->crearEtiqueta($nuevaNombre, $nuevaColor ?: '#f59e0b'), $userId);
                $creada = true;
                $existente = collect($this->client->etiquetasCuenta())
                    ->first(fn ($t) => mb_strtolower($t['nombre']) === mb_strtolower($nuevaNombre));
            }
            if ($existente) {
                $deseadas[] = $existente['id'];
            }
        }

        $actuales = array_column($this->client->etiquetasPedido($orden), 'id');
        $poner = array_diff($deseadas, $actuales);
        $quitar = array_diff($actuales, $deseadas);

        foreach ($poner as $id) {
            $this->escribir($pedido, 'poner_etiqueta', (string) $id, fn () => $this->client->asignarEtiqueta($orden, $id), $userId);
        }
        foreach ($quitar as $id) {
            $this->escribir($pedido, 'quitar_etiqueta', (string) $id, fn () => $this->client->quitarEtiqueta($orden, $id), $userId);
        }

        // Que la ventana muestre ya lo guardado, sin esperar a la próxima vuelta del sync.
        DB::table('dropi_web_huellas')->where('dropi_orden_id', $orden)
            ->update(['etiquetas' => json_encode(array_values(array_map('intval', array_unique($deseadas))))]);

        return ['puestas' => count($poner), 'quitadas' => count($quitar), 'creada' => $creada];
    }

    /** Ejecuta una escritura en Dropi y la deja auditada, salga bien o mal. */
    protected function escribir(DropiPedido $pedido, string $accion, string $detalle, callable $llamada, ?int $userId): void
    {
        $fila = [
            'pedido_id' => $pedido->id,
            'dropi_orden_id' => (string) $pedido->dropi_orden_id,
            'accion' => $accion,
            'estatus_pedido' => mb_substr($detalle, 0, 60),
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            $llamada();
        } catch (\Throwable $e) {
            DB::table('dropi_web_acciones')->insert($fila + ['exito' => false, 'respuesta' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }

        DB::table('dropi_web_acciones')->insert($fila + ['exito' => true]);
    }
}
