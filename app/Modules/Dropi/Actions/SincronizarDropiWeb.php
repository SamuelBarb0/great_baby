<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\MapearMovimientoWalletDropi;
use App\Modules\Dropi\Services\MapearPedidoDropiWeb;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Sync casi en tiempo real con Dropi por el driver web. Dropi no avisa de los
 * cambios a los proveedores (sin webhooks), así que se pregunta en tres ritmos:
 *
 *   recientes · cada 15 s — la primera página, del más nuevo al más viejo:
 *               pedidos nuevos y cambios de los de hoy.
 *   ventana   · cada minuto — pedidos creados en los últimos días.
 *   transito  · cada 10 min — pedidos en camino, por estado: entregas,
 *               devoluciones y novedades de pedidos viejos.
 *   pagos     · cada 30 min — pedidos entregados que el ERP aún no tiene
 *               pagados: Dropi paga ~1 día después de la entrega SIN cambiar
 *               el estatus, así que la huella de la lista no lo ve.
 *
 * Wallet: el detalle de cada pedido trae sus movimientos (`history_wallet`:
 * el pago 1006 y su reverso 1009), y se concilian en la misma vuelta. Es lo
 * único de la wallet que expone la API oficial de integraciones; retiros y
 * movimientos sin pedido siguen saliendo de `dropi:web-wallet-sync`.
 *
 * La lista es barata (~1 s) pero no trae cantidades ni precios; el detalle sí,
 * así que solo se pide el detalle de los pedidos cuya huella cambió.
 * (El filtro «FECHA DE CAMBIO DE ESTATUS» de Dropi daría 504: no se usa.)
 */
class SincronizarDropiWeb
{
    use AsAction;

    /** Estados de transportadora que todavía pueden cambiar. */
    public const ESTADOS_EN_CAMINO = [
        'DESPACHADA', 'EN TRANSITO', 'EN REPARTO', 'RECOLECTADO', 'EN BODEGA TRANSPORTADORA',
        'EN BODEGA ORIGEN', 'EN BODEGA DESTINO', 'INTENTO DE ENTREGA', 'NOVEDAD', 'NOVEDAD SOLUCIONADA',
        'EN REEXPEDICION', 'DEVOLUCION',
    ];

    public function __construct(
        protected DropiWebClient $client,
        protected MapearPedidoDropiWeb $mapper,
        protected SincronizarPedidosDropi $sync,
        protected ConciliarWalletDropi $wallet,
    ) {}

    /** Días tras la entrega en que se sigue buscando el pago (Dropi paga en ~1, máx. 3). */
    public const DIAS_BUSCANDO_PAGO = 15;

    /**
     * @return array{modo:string, vistos:int, cambiados:int, nuevos:int, actualizados:int, errores:int, pagos:int}
     */
    public function handle(string $modo = 'recientes', int $dias = 3, int $maxPaginas = 20, int $maxDetalles = 120): array
    {
        if ($modo === 'pagos') {
            return $this->buscarPagos($maxDetalles);
        }

        $orden = ['orderBy' => 'id', 'orderDirection' => 'desc'];
        $lista = match ($modo) {
            'recientes' => $this->client->pedidosCrudos($orden, 0, 50)['objects'],
            'ventana' => $this->paginar($orden + [
                'filter_date_by' => 'Order Date',
                'from' => now()->subDays($dias)->format('Y-m-d'),
                'until' => now()->format('Y-m-d'),
            ], $maxPaginas),
            // Dropi acepta varios estados separados por coma: una sola consulta
            //   paginada (~2 s por página de 100) en vez de una por estado.
            //   Solo los creados en los últimos 30 días: las devoluciones viejas se
            //   quedan en DEVOLUCION para siempre y hay páginas de 5 MB.
            'transito' => $this->paginar($orden + [
                'status' => implode(',', self::ESTADOS_EN_CAMINO),
                'filter_date_by' => 'Order Date',
                'from' => now()->subDays(30)->format('Y-m-d'),
                'until' => now()->format('Y-m-d'),
            ], $maxPaginas),
            default => throw new \InvalidArgumentException("Modo de sync desconocido: {$modo}"),
        };

        // Tope por vuelta: la primera pasada ve cientos de pedidos «nuevos»; el
        //   resto entra en las vueltas siguientes sin alargar esta.
        $cambiados = array_slice($this->filtrarCambiados($lista), 0, $maxDetalles);

        [$dtos, $r, $errores, $pagos] = $this->importarDetalles(array_column($cambiados, 'id'));

        // La huella se marca solo para lo que quedó guardado: si algo falla, la
        //   próxima vuelta lo reintenta.
        $guardados = DropiPedido::whereIn('dropi_orden_id', collect($dtos)->pluck('dropiOrdenId'))
            ->pluck('dropi_orden_id')->map(fn ($id) => (string) $id)->all();
        $this->marcarSincronizados(array_filter($cambiados, fn ($o) => in_array((string) $o['id'], $guardados, true)));

        return [
            'modo' => $modo,
            'vistos' => count($lista),
            'cambiados' => count($cambiados),
            'nuevos' => $r['nuevos'],
            'actualizados' => $r['actualizados'],
            'errores' => $errores + $r['errores'],
            'pagos' => $pagos,
        ];
    }

    /**
     * Pide el detalle de cada orden, guarda los pedidos y concilia los
     * movimientos de wallet que traen. Primero los pedidos: así el pago
     * encuentra su pedido y no queda huérfano.
     *
     * @param  array<int|string>  $ordenes
     * @return array{0: list<\App\Modules\Dropi\DTOs\PedidoDropiDTO>, 1: array, 2: int, 3: int}
     */
    protected function importarDetalles(array $ordenes): array
    {
        $dtos = [];
        $movimientos = [];
        $errores = 0;
        foreach ($ordenes as $id) {
            try {
                $detalle = $this->client->pedidoDetalle((int) $id);
                $dtos[] = $this->mapper->desdeDetalle($detalle);
                array_push($movimientos, ...self::movimientosWallet($detalle));
            } catch (\Throwable $e) {
                $errores++;
                Log::warning('dropi.web.detalle_error', ['orden' => $id, 'msg' => $e->getMessage()]);
            }
        }

        $r = $dtos ? $this->sync->importarPedidos($dtos) : ['nuevos' => 0, 'actualizados' => 0, 'errores' => 0];

        $pagos = 0;
        if ($movimientos) {
            $w = $this->wallet->conciliar($movimientos);
            $pagos = $w['pagos_matcheados'];
            $errores += $w['errores'];
        }

        return [$dtos, $r, $errores, $pagos];
    }

    /**
     * Movimientos de wallet del detalle de un pedido, desde la fecha de corte
     * contable (DROPI_WALLET_DESDE: lo anterior no se asienta, decisión de Samuel).
     *
     * @return list<\App\Modules\Dropi\DTOs\PagoWalletDTO>
     */
    public static function movimientosWallet(array $detalle): array
    {
        $desde = \Carbon\CarbonImmutable::parse(config('dropi.web.wallet_desde'), config('app.timezone'))->startOfDay();
        $out = [];
        foreach ($detalle['history_wallet'] ?? [] as $m) {
            if (! is_array($m) || empty($m['id']) || empty($m['created_at'])) {
                continue;
            }
            $dto = MapearMovimientoWalletDropi::map($m + ['order_id' => $detalle['id'] ?? null]);
            if ($dto->fecha->gte($desde)) {
                $out[] = $dto;
            }
        }

        return $out;
    }

    /**
     * Vuelta `pagos`: pedidos entregados en los últimos días que el ERP todavía
     * no tiene pagados. Se pide su detalle directo (sin huella) para leer el
     * pago; los más recientes primero, con tope por vuelta por el límite de
     * peticiones de Dropi.
     */
    protected function buscarPagos(int $maxDetalles): array
    {
        $ordenes = DropiPedido::query()
            ->where('estado', EstadoPedidoDropi::Entregado)
            ->whereNotNull('dropi_orden_id')
            // Sin fecha de entrega (cargados por Excel) cuenta la del pedido: si
            // no, los de agosto entrarían en todas las vueltas y gastarían el cupo.
            ->whereRaw('COALESCE(entregado_at, created_at) >= ?', [now()->subDays(self::DIAS_BUSCANDO_PAGO)])
            ->orderByRaw('COALESCE(entregado_at, created_at) DESC')
            ->limit($maxDetalles)
            ->pluck('dropi_orden_id')
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->all();

        [, $r, $errores, $pagos] = $this->importarDetalles($ordenes);

        return [
            'modo' => 'pagos',
            'vistos' => count($ordenes),
            'cambiados' => count($ordenes),
            'nuevos' => $r['nuevos'],
            'actualizados' => $r['actualizados'],
            'errores' => $errores + $r['errores'],
            'pagos' => $pagos,
        ];
    }

    public static function huella(array $o): string
    {
        return sha1(implode('|', [
            $o['status'] ?? '', $o['shipping_guide'] ?? '', $o['shipping_company'] ?? '', $o['total_order'] ?? '',
            implode(',', self::idsEtiquetas($o)),
        ]));
    }

    /** @return array<int> */
    public static function idsEtiquetas(array $o): array
    {
        $ids = array_map(fn ($t) => (int) ($t['id'] ?? $t['tag_id'] ?? 0), $o['tags'] ?? []);
        sort($ids);

        return $ids;
    }

    /** @return array<int, array> */
    protected function paginar(array $filtros, int $maxPaginas): array
    {
        $todos = [];
        for ($p = 0; $p < $maxPaginas; $p++) {
            try {
                $objetos = $this->client->pedidosCrudos($filtros, $p, 100)['objects'];
            } catch (\App\Modules\Dropi\Exceptions\DropiSesionNoDisponible $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Una página lenta no tumba la vuelta: se procesa lo que ya llegó.
                Log::warning('dropi.web.pagina_error', ['filtros' => $filtros, 'pagina' => $p, 'msg' => mb_substr($e->getMessage(), 0, 200)]);
                break;
            }
            array_push($todos, ...$objetos);
            if (count($objetos) < 100) {
                break;
            }
        }

        return $todos;
    }

    /** @return array<int, array> los pedidos de la lista cuya huella no coincide con la guardada */
    protected function filtrarCambiados(array $lista): array
    {
        $lista = collect($lista)->filter(fn ($o) => isset($o['id']))->keyBy('id');
        if ($lista->isEmpty()) {
            return [];
        }

        $guardadas = DB::table('dropi_web_huellas')
            ->whereIn('dropi_orden_id', $lista->keys())
            ->pluck('huella', 'dropi_orden_id');

        DB::table('dropi_web_huellas')->whereIn('dropi_orden_id', $lista->keys())->update(['visto_at' => now()]);

        return $lista->filter(fn ($o, $id) => ($guardadas[$id] ?? null) !== self::huella($o))->values()->all();
    }

    protected function marcarSincronizados(array $pedidos): void
    {
        $filas = array_map(fn ($o) => [
            'dropi_orden_id' => $o['id'],
            'huella' => self::huella($o),
            'estatus_dropi' => mb_substr((string) ($o['status'] ?? ''), 0, 60),
            'etiquetas' => json_encode(self::idsEtiquetas($o)),
            'visto_at' => now(),
            'sincronizado_at' => now(),
        ], array_values($pedidos));

        if ($filas) {
            DB::table('dropi_web_huellas')->upsert($filas, ['dropi_orden_id'], ['huella', 'estatus_dropi', 'etiquetas', 'visto_at', 'sincronizado_at']);
        }
    }
}
