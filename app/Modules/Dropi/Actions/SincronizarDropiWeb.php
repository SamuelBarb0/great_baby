<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiWebClient;
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
    ) {}

    /**
     * @return array{modo:string, vistos:int, cambiados:int, nuevos:int, actualizados:int, errores:int}
     */
    public function handle(string $modo = 'recientes', int $dias = 3, int $maxPaginas = 20, int $maxDetalles = 120): array
    {
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

        $dtos = [];
        $errores = 0;
        foreach ($cambiados as $o) {
            try {
                $dtos[] = $this->mapper->desdeDetalle($this->client->pedidoDetalle((int) $o['id']));
            } catch (\Throwable $e) {
                $errores++;
                Log::warning('dropi.web.detalle_error', ['orden' => $o['id'], 'msg' => $e->getMessage()]);
            }
        }

        $r = $dtos ? $this->sync->importarPedidos($dtos) : ['nuevos' => 0, 'actualizados' => 0, 'errores' => 0];

        // La huella se marca solo para lo que quedó guardado: si algo falla, la
        //   próxima vuelta lo reintenta.
        $guardados = \App\Modules\Dropi\Models\DropiPedido::whereIn('dropi_orden_id', collect($dtos)->pluck('dropiOrdenId'))
            ->pluck('dropi_orden_id')->map(fn ($id) => (string) $id)->all();
        $this->marcarSincronizados(array_filter($cambiados, fn ($o) => in_array((string) $o['id'], $guardados, true)));

        return [
            'modo' => $modo,
            'vistos' => count($lista),
            'cambiados' => count($cambiados),
            'nuevos' => $r['nuevos'],
            'actualizados' => $r['actualizados'],
            'errores' => $errores + $r['errores'],
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
