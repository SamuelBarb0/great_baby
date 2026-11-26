<?php

namespace App\Modules\Dropi\Jobs;

use App\Modules\Dropi\Actions\GenerarGuiaDropi;
use App\Modules\Dropi\Actions\SincronizarPedidosDropi;
use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Services\ImprimirGuiasDropi;
use App\Modules\Dropi\Services\MapearPedidoDropiWeb;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Generar guías» de «Pedidos Dropi»: lo que Aracely hace en Dropi con
 * «Actualizar las órdenes → GUIA_GENERADA», para todos los escogidos (reunión
 * con la señora Lahida, 24-nov-2026: la guía sale cuando se arma el corte).
 *
 * Un job por pedido: son 2-3 llamadas a Dropi cada uno y el worker de
 * producción corta lo que pasa del minuto, así que 200 guías en un solo job no
 * terminarían. `lanzar()` los encola y deja el avance en caché para la pantalla.
 *
 * Antes de pedir la guía se pregunta a Dropi cómo está el pedido HOY (26-nov-
 * 2026): el sync no vuelve a mirar los pedidos de más de 3 días ni da de baja
 * los que Dropi reemplaza, y el corte de las 9:19 pidió guías de pedidos ya
 * rechazados o reemplazados (y despachados con su orden nueva). Sólo lo que en
 * Dropi sigue PENDIENTE pide guía: una guía es real (la transportadora la
 * registra). Lo demás se pone al día en el ERP y queda explicado en el reporte.
 *
 * Con «Generar corte» (25-nov-2026) cada guía es de un reporte: la última en
 * terminar, bien o mal, arranca el reporte (ImprimirGuiasDropi::iniciar).
 */
class GenerarGuiasEnBloqueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Su propia cola: SIIGO trabado dejó las guías del 26-nov sin salir 8 minutos. */
    public const COLA = 'guias';

    public int $tries = 1;

    /**
     * Consultar, generar y buscar el sticker son hasta tres llamadas de 20 s.
     * Por debajo del `retry_after` de la cola (90 s), o el trabajo se repetiría
     * mientras sigue corriendo.
     */
    public int $timeout = 80;

    public function __construct(public int $pedidoId, public ?int $userId, public ?int $loteId = null)
    {
        $this->onQueue(self::COLA);
    }

    /** Encola una guía por pedido y reinicia el avance de este usuario. */
    public static function lanzar(array $pedidoIds, ?int $userId, ?int $loteId = null): int
    {
        $ids = array_values(array_unique(array_map('intval', $pedidoIds)));
        Cache::put(self::claveAvance($userId), [
            'total' => count($ids), 'hechas' => 0, 'saltadas' => 0, 'errores' => [], 'desde' => now()->toIso8601String(),
        ], now()->addHours(12));
        foreach ($ids as $id) {
            self::dispatch($id, $userId, $loteId);
        }

        return count($ids);
    }

    public static function claveAvance(?int $userId): string
    {
        return "dropi:guias-en-bloque:{$userId}";
    }

    /** @return array{total:int, hechas:int, saltadas:int, errores:list<string>, desde:string}|null */
    public static function avance(?int $userId): ?array
    {
        return Cache::get(self::claveAvance($userId));
    }

    public function handle(GenerarGuiaDropi $generar, DropiWebClient $client, MapearPedidoDropiWeb $mapper, SincronizarPedidosDropi $sync): void
    {
        try {
            $this->generar($generar, $client, $mapper, $sync);
        } finally {
            $this->contarEnReporte();
        }
    }

    /** Se cayó (tiempo agotado): igual cuenta, o el reporte esperaría para siempre. */
    public function failed(\Throwable $e): void
    {
        $p = DropiPedido::find($this->pedidoId);
        if ($p) {
            $this->resultado($p, 'error', 'El trabajo se cortó esperando a Dropi: ' . mb_substr($e->getMessage(), 0, 120));
        }
        $this->contarEnReporte();
    }

    private function generar(GenerarGuiaDropi $generar, DropiWebClient $client, MapearPedidoDropiWeb $mapper, SincronizarPedidosDropi $sync): void
    {
        $p = DropiPedido::with('huella')->find($this->pedidoId);
        if (! $p || ! $p->dropi_orden_id) {
            $this->anotar(fn (array $a) => ['saltadas' => $a['saltadas'] + 1] + $a);
            if ($p) {
                $this->resultado($p, 'error', 'El pedido no tiene número de orden de Dropi.');
            }

            return;
        }

        // Ya tiene guía: no se pide otra, sólo su sticker.
        if ($p->tieneGuiaReal()) {
            $this->usarLaQueTiene($p, $generar);

            return;
        }

        if (! $p->listoParaDespachar()) {
            $this->anotar(fn (array $a) => ['saltadas' => $a['saltadas'] + 1] + $a);
            $this->resultado($p, 'sin_inventario', 'En el ERP está en «' . ($p->estado?->label() ?? '—') . '».');

            return;
        }

        try {
            $detalle = $client->pedidoDetalle((int) $p->dropi_orden_id);
        } catch (\Throwable $e) {
            if (self::noExisteEnDropi($e->getMessage())) {
                $this->darDeBaja($p, 'reemplazado', 'Dropi ya no tiene la orden.');
            } else {
                $this->fallo($p, 'error', 'No se pudo consultar en Dropi: ' . self::motivoDropi($e->getMessage()));
            }

            return;
        }

        $estatus = mb_strtoupper(trim((string) ($detalle['status'] ?? '')));
        if ($estatus !== 'PENDIENTE') {
            $this->ponerAlDia($p, $detalle, $estatus, $mapper, $sync, $generar);

            return;
        }

        try {
            $g = $generar->handle($p, $this->userId);
            $this->anotar(fn (array $a) => ['hechas' => $a['hechas'] + 1] + $a);
            $this->resultado($p, 'generada', null, $g['guia'] ?? $p->fresh()?->guia);
        } catch (\Throwable $e) {
            Log::warning('dropi.guias_en_bloque.fallo', ['pedido_id' => $p->id, 'msg' => $e->getMessage()]);
            $msg = self::motivoDropi($e->getMessage());
            match (true) {
                self::noExisteEnDropi($msg) => $this->darDeBaja($p, 'reemplazado', "Dropi: «{$msg}»."),
                str_contains(mb_strtolower($msg), 'cobertura') => $this->fallo($p, 'sin_cobertura', $msg),
                default => $this->fallo($p, 'error', $msg),
            };
        }
    }

    /**
     * En Dropi ya no está pendiente: el ERP lo trae como está allá (lo mismo que
     * haría el sync) y se explica. Si ya tiene guía, entra al reporte con ella.
     */
    private function ponerAlDia(DropiPedido $p, array $detalle, string $estatus, MapearPedidoDropiWeb $mapper, SincronizarPedidosDropi $sync, GenerarGuiaDropi $generar): void
    {
        try {
            $sync->importarPedidos([$mapper->desdeDetalle($detalle)]);
            DB::table('dropi_web_huellas')->updateOrInsert(
                ['dropi_orden_id' => (int) $p->dropi_orden_id],
                ['estatus_dropi' => $estatus, 'huella' => ''],
            );
        } catch (\Throwable $e) {
            Log::warning('dropi.guias_en_bloque.poner_al_dia', ['pedido_id' => $p->id, 'msg' => $e->getMessage()]);
        }
        $p->refresh();

        if (in_array($estatus, ['CANCELADO', 'RECHAZADO', 'REEMPLAZADA'], true)) {
            $this->anotar(fn (array $a) => ['saltadas' => $a['saltadas'] + 1] + $a);
            $this->resultado($p, 'cancelado', "En Dropi está «{$estatus}».");
        } elseif ($p->tieneGuiaReal()) {
            $this->usarLaQueTiene($p, $generar, "En Dropi está «{$estatus}».");
        } else {
            $this->anotar(fn (array $a) => ['saltadas' => $a['saltadas'] + 1] + $a);
            $this->resultado($p, 'en_otro_estado', "En Dropi está «{$estatus}».");
        }
    }

    /** La guía que ya tenía, con su sticker: entra al reporte como cualquier otra. */
    private function usarLaQueTiene(DropiPedido $p, GenerarGuiaDropi $generar, ?string $detalle = null): void
    {
        try {
            $g = $generar->handle($p, $this->userId);
        } catch (\Throwable $e) {
            $g = ['guia' => $p->guia];
        }
        $this->anotar(fn (array $a) => ['hechas' => $a['hechas'] + 1] + $a);
        $this->resultado($p, 'ya_tenia', $detalle, $g['guia'] ?? $p->guia);
    }

    /**
     * Dropi ya no tiene la orden: la reemplazó por otra al editarla (el mismo
     * cliente, otra orden) o la borró. Sale del ERP como cancelada por Dropi,
     * igual que el sync cancela una «REEMPLAZADA», y se busca la nueva.
     */
    private function darDeBaja(DropiPedido $p, string $tipo, string $detalle): void
    {
        try {
            // Lo canceló Dropi, como cuando el sync trae una «REEMPLAZADA»: sin usuario.
            $p->transicionar(EstadoPedidoDropi::CanceladoDropi, 'api', null, [
                'origen' => 'generar_corte', 'motivo' => 'Dropi ya no tiene la orden ' . $p->dropi_orden_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('dropi.guias_en_bloque.dar_de_baja', ['pedido_id' => $p->id, 'msg' => $e->getMessage()]);
        }

        $nueva = self::reemplazoDe($p);
        if ($nueva) {
            $detalle .= " La nueva es la orden {$nueva->dropi_orden_id}"
                . ($nueva->tieneGuiaReal() ? ", con guía {$nueva->guia} ({$nueva->estado?->label()})." : ', todavía sin guía.');
        }

        $this->anotar(fn (array $a) => ['saltadas' => $a['saltadas'] + 1] + $a);
        $this->resultado($p, $tipo, $detalle);
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

    /** $motivo: lo que dijo Dropi, sin adornos (va al aviso y al reporte). */
    private function fallo(DropiPedido $p, string $tipo, string $motivo): void
    {
        $error = "Orden {$p->dropi_orden_id}: " . mb_substr($motivo, 0, 160);
        $this->anotar(fn (array $a) => ['errores' => [...$a['errores'], $error]] + $a);
        $this->resultado($p, $tipo, str_starts_with($motivo, 'No se pudo') ? $motivo : "Dropi: «{$motivo}».");
    }

    /** «Orden no encontrada» al leerla, o «fue reemplazada» al pedir la guía. */
    public static function noExisteEnDropi(string $msg): bool
    {
        $m = mb_strtolower($msg);

        return str_contains($m, 'no encontrada') || str_contains($m, 'reemplazada por una nueva');
    }

    /** Lo que dijo Dropi, sin el «Dropi no cambió el pedido 123:» del cliente. */
    private static function motivoDropi(string $msg): string
    {
        $m = preg_replace('/^Dropi no (cambió|dejó|devolvió)[^:]*:\s*/u', '', $msg);
        $m = preg_replace('/^Error al actualizar la orden:\s*/u', '', (string) $m);

        return mb_substr(trim((string) $m), 0, 160);
    }

    /** Qué pasó con este pedido, en el reporte (lo que lee Aracely). */
    private function resultado(DropiPedido $p, string $tipo, ?string $detalle = null, ?string $guia = null): void
    {
        if (! $this->loteId) {
            return;
        }
        $fila = [
            'pedido_id' => $p->id,
            'orden' => (string) $p->dropi_orden_id,
            'cliente' => (string) $p->cliente_nombre,
            'tipo' => $tipo,
            'detalle' => $detalle,
            'guia' => $guia,
        ];
        Cache::lock("dropi:lote-impresion:{$this->loteId}", 30)->block(20, function () use ($p, $fila) {
            $l = DropiLoteImpresion::find($this->loteId);
            if ($l) {
                $resultados = collect($l->resultados ?? [])->reject(fn ($r) => ($r['pedido_id'] ?? null) === $p->id)->values()->all();
                $l->update(['resultados' => [...$resultados, $fila]]);
            }
        });
    }

    /** Una guía menos por esperar; con la última, el reporte arranca. */
    private function contarEnReporte(): void
    {
        // Cada pedido cuenta una vez, aunque pase por el finally y por failed().
        if (! $this->loteId || ! Cache::add("dropi:lote-impresion:{$this->loteId}:contado:{$this->pedidoId}", 1, now()->addDay())) {
            return;
        }
        $arrancar = Cache::lock("dropi:lote-impresion:{$this->loteId}", 30)->block(20, function () {
            $l = DropiLoteImpresion::find($this->loteId);
            if (! $l || $l->estado !== DropiLoteImpresion::GENERANDO_GUIAS) {
                return false;
            }
            $quedan = max(0, $l->guias_pendientes - 1);
            $l->update(['guias_pendientes' => $quedan]);

            return $quedan === 0;
        });
        if ($arrancar) {
            app(ImprimirGuiasDropi::class)->iniciar(DropiLoteImpresion::findOrFail($this->loteId));
        }
    }

    /** Los jobs corren a la vez: el avance se actualiza bajo candado. */
    private function anotar(callable $cambio): void
    {
        $clave = self::claveAvance($this->userId);
        Cache::lock("{$clave}:lock", 10)->block(5, function () use ($clave, $cambio) {
            $a = Cache::get($clave);
            if ($a) {
                Cache::put($clave, $cambio($a), now()->addHours(12));
            }
        });
    }
}
