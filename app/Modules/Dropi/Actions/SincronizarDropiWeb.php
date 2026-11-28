<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\MapearMovimientoWalletDropi;
use App\Modules\Dropi\Services\MapearPedidoDropiWeb;
use Illuminate\Support\Facades\Cache;
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

    /** Claves de caché con la última vuelta que habló con Dropi y el último fallo. */
    public const CACHE_ULTIMA = 'dropi:sync:ultima_ok';

    public const CACHE_ERROR = 'dropi:sync:ultimo_error';

    /**
     * Hasta cuándo puede seguir esta vuelta (28-nov-2026): con Dropi tardando
     * 25-30 s por llamada, «ventana» pasó 4 minutos detallando pedidos y, como
     * el cron de Hostinger no arranca otro `schedule:run` mientras el anterior
     * siga vivo, el worker de guías no se levantó y el corte de las 8:15 se
     * quedó con 70 guías en cola. Lo que no alcance queda sin huella y entra en
     * la vuelta siguiente. Null = sin tope (quien llama no es el cron).
     */
    protected ?float $limite = null;

    /**
     * @return array{modo:string, vistos:int, cambiados:int, nuevos:int, actualizados:int, errores:int, pagos:int}
     */
    public function handle(string $modo = 'recientes', int $dias = 3, int $maxPaginas = 20, int $maxDetalles = 120): array
    {
        $this->limite = microtime(true) + (int) config('dropi.web.segundos_por_vuelta', 40);
        try {
            $r = $this->sincronizar($modo, $dias, $maxPaginas, $maxDetalles);
        } catch (\Throwable $e) {
            Cache::forever(self::CACHE_ERROR, ['at' => now()->toIso8601String(), 'msg' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }

        Cache::forever(self::CACHE_ULTIMA, now()->toIso8601String());
        Cache::forget(self::CACHE_ERROR);

        return $r;
    }

    /**
     * Estado del sync para mostrarlo en pantalla: cuándo habló con Dropi por
     * última vez, el último fallo (si es posterior) y si el cron está activo.
     *
     * @return array{ultima: ?\Illuminate\Support\Carbon, error: ?array{at: \Illuminate\Support\Carbon, msg: string}, activo: bool}
     */
    public static function estado(): array
    {
        $ultima = Cache::get(self::CACHE_ULTIMA);
        $error = Cache::get(self::CACHE_ERROR);

        return [
            'ultima' => $ultima ? \Illuminate\Support\Carbon::parse($ultima) : null,
            'error' => $error ? ['at' => \Illuminate\Support\Carbon::parse($error['at']), 'msg' => (string) $error['msg']] : null,
            'activo' => (bool) config('dropi.web.sync_activo'),
        ];
    }

    /**
     * @return array{modo:string, vistos:int, cambiados:int, nuevos:int, actualizados:int, errores:int, pagos:int}
     */
    protected function sincronizar(string $modo, int $dias, int $maxPaginas, int $maxDetalles): array
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
        self::guardarStickers($lista);

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
        foreach ($ordenes as $i => $id) {
            if ($this->sinTiempo()) {
                Log::info('dropi.web.sin_tiempo', ['detallados' => $i, 'quedan' => count($ordenes) - $i]);
                break;
            }
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
            // La primera página siempre; las demás, si queda tiempo.
            if ($p > 0 && $this->sinTiempo()) {
                break;
            }
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

    /** ¿Se acabó el tiempo de esta vuelta? */
    protected function sinTiempo(): bool
    {
        return $this->limite !== null && microtime(true) >= $this->limite;
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
        self::guardarGuiaDescargada($lista->all());

        return $lista->filter(fn ($o, $id) => ($guardadas[$id] ?? null) !== self::huella($o))->values()->all();
    }

    /**
     * La ruta del sticker de la guía (`guia_urls3`) sólo viene en la lista, no
     * en el detalle. Se guarda para todo pedido de la lista que aún no la
     * tenga o la tenga distinta (guía anulada y vuelta a generar), aunque su
     * huella no haya cambiado: así entran también los que ya tenían guía.
     */
    public static function guardarStickers(array $lista): void
    {
        $rutas = collect($lista)
            ->filter(fn ($o) => isset($o['id']) && filled($o['guia_urls3'] ?? null))
            ->mapWithKeys(fn ($o) => [(string) $o['id'] => mb_substr((string) $o['guia_urls3'], 0, 255)]);
        if ($rutas->isEmpty()) {
            return;
        }

        DropiPedido::whereIn('dropi_orden_id', $rutas->keys())
            ->get(['id', 'dropi_orden_id', 'sticker_path'])
            ->each(function (DropiPedido $p) use ($rutas) {
                $ruta = $rutas[(string) $p->dropi_orden_id];
                if ($p->sticker_path !== $ruta) {
                    DropiPedido::whereKey($p->id)->update(['sticker_path' => $ruta]);
                }
            });
    }

    /**
     * «IMPRESAS / NO IMPRESAS» de Dropi (`guide_was_downloaded`, 24-nov-2026).
     * Va aparte de la huella a propósito: meterlo en ella haría que todo
     * pedido «cambiara» y se volviera a importar entero.
     */
    public static function guardarGuiaDescargada(array $lista): void
    {
        $porValor = collect($lista)
            ->filter(fn ($o) => isset($o['id']) && array_key_exists('guide_was_downloaded', $o))
            ->groupBy(fn ($o) => (bool) $o['guide_was_downloaded'] ? 1 : 0);
        foreach ($porValor as $valor => $ordenes) {
            DB::table('dropi_web_huellas')->whereIn('dropi_orden_id', $ordenes->pluck('id'))->update(['guia_descargada' => (bool) $valor]);
        }
    }

    protected function marcarSincronizados(array $pedidos): void
    {
        $filas = array_map(fn ($o) => [
            'dropi_orden_id' => $o['id'],
            'huella' => self::huella($o),
            'estatus_dropi' => mb_substr((string) ($o['status'] ?? ''), 0, 60),
            'guia_descargada' => array_key_exists('guide_was_downloaded', $o) ? (bool) $o['guide_was_downloaded'] : null,
            'etiquetas' => json_encode(self::idsEtiquetas($o)),
            'visto_at' => now(),
            'sincronizado_at' => now(),
        ], array_values($pedidos));

        if ($filas) {
            DB::table('dropi_web_huellas')->upsert($filas, ['dropi_orden_id'], ['huella', 'estatus_dropi', 'guia_descargada', 'etiquetas', 'visto_at', 'sincronizado_at']);
        }
    }
}
