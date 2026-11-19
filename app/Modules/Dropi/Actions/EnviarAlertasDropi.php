<?php

namespace App\Modules\Dropi\Actions;

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\DropiSancion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Tarjeta Dropi · 8. Sanciones / Alertas.
 *
 * Los umbrales ya se contaban en el panel de Filament («Cerca del plazo
 * Dropi», «Contactar transportadora»), pero eran números para ir a mirar:
 * nadie recibía un aviso. Esto los convierte en avisos de la campana para
 * Aracely/Gerencia y bodega, una sola vez por pedido y por nivel:
 *
 *   8.2 · sin despachar a las 48 h (Dropi cancela a las 72 h) y a las 72 h.
 *   8.3 · paquetes «por fuera»: despachados hace 15 días sin entregar
 *         (contactar transportadora) y 20 (reclamar a Dropi).
 *   8.4 · diferencias de precio nuevas; devoluciones que vienen en camino
 *         (para que bodega las espere) y las que nunca llegaron (fantasma).
 *
 * Mirar hacia atrás tiene tope: sin él, la primera corrida avisaría por todo
 * lo viejo que quedó a medias de las cargas por Excel.
 */
class EnviarAlertasDropi
{
    use AsAction;

    /** Días hacia atrás que se miran por regla (evita avisar por la historia). */
    private const VENTANA_PLAZO_DIAS = 7;

    private const VENTANA_TRANSITO_DIAS = 45;

    private const VENTANA_DEVOLUCION_DIAS = 30;

    /** @return array<string, int> avisos por regla (pedidos incluidos) */
    public function handle(): array
    {
        $cfg = config('dropi.alertas');
        $aviso = (int) ($cfg['aviso_previo_horas'] ?? 48);
        $limite = (int) ($cfg['plazo_dropi_horas'] ?? 72);
        $contactar = (int) ($cfg['entrega_contactar_dias'] ?? 15);
        $reclamar = (int) setting('dropi.dias_retorno_mercancia', 20);
        $tolerancia = (int) setting('dropi.dias_tolerancia_devolucion', 7);

        $previos = [
            EstadoPedidoDropi::Pending, EstadoPedidoDropi::PendienteInventario,
            EstadoPedidoDropi::Alistando, EstadoPedidoDropi::Empacado,
        ];
        $nacio = 'COALESCE(dropi_creado_at, created_at)';

        $r = [];

        // 8.2 · el más grave primero: quien pasó las 72 h no recibe además el de 48.
        $r['plazo72'] = $this->avisar('plazo72',
            DropiPedido::whereIn('estado', $previos)
                ->whereRaw("{$nacio} <= ?", [now()->subHours($limite)])
                ->whereRaw("{$nacio} >= ?", [now()->subDays(self::VENTANA_PLAZO_DIAS)]),
            fn ($n) => "{$n} pedido(s) Dropi pasaron {$limite} h sin despachar",
            'Dropi los cancela y sanciona: despáchalos ya o confirma con Dropi.',
            'danger', 'heroicon-o-exclamation-triangle', ['plazo48']);

        $r['plazo48'] = $this->avisar('plazo48',
            DropiPedido::whereIn('estado', $previos)
                ->whereRaw("{$nacio} <= ?", [now()->subHours($aviso)])
                ->whereRaw("{$nacio} > ?", [now()->subHours($limite)]),
            fn ($n) => "{$n} pedido(s) Dropi llevan +{$aviso} h sin despachar",
            "A las {$limite} h Dropi los cancela y sanciona.",
            'warning', 'heroicon-o-clock');

        // 8.3 · paquetes por fuera.
        $enCamino = fn () => DropiPedido::where('estado', EstadoPedidoDropi::Despachado)
            ->where('ignorar_alerta_transito', false)
            ->where('despachado_at', '>=', now()->subDays(self::VENTANA_TRANSITO_DIAS));

        $r['transito20'] = $this->avisar('transito20',
            $enCamino()->where('despachado_at', '<=', now()->subDays($reclamar)),
            fn ($n) => "{$n} paquete(s) llevan +{$reclamar} días por fuera sin entregar",
            'Posible pérdida: abrir reclamo a Dropi/transportadora.',
            'danger', 'heroicon-o-map-pin', ['transito15']);

        $r['transito15'] = $this->avisar('transito15',
            $enCamino()->where('despachado_at', '<=', now()->subDays($contactar))
                ->where('despachado_at', '>', now()->subDays($reclamar)),
            fn ($n) => "{$n} paquete(s) llevan +{$contactar} días por fuera",
            'Contactar a la transportadora antes de que se pierdan.',
            'warning', 'heroicon-o-phone');

        // 8.4 · devoluciones: las que vienen (bodega las espera) y las que no llegaron.
        $sinRecibir = fn () => DropiPedido::whereNotNull('devuelto_at')
            ->where('devuelto_at', '>=', now()->subDays(self::VENTANA_DEVOLUCION_DIAS))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('dropi_devoluciones')
                    ->whereColumn('dropi_devoluciones.pedido_id', 'dropi_pedidos.id')
                    ->whereNull('dropi_devoluciones.deleted_at');
            });

        $r['fantasma'] = $this->avisar('fantasma',
            $sinRecibir()->where('devuelto_at', '<=', now()->subDays($tolerancia)),
            fn ($n) => "{$n} devolución(es) no han llegado a bodega tras {$tolerancia} días",
            'Dropi las marcó como devueltas: reclamar o registrar «NO llegó físicamente».',
            'danger', 'heroicon-o-question-mark-circle');

        $r['devolucion'] = $this->avisar('devolucion',
            $sinRecibir()->where('devuelto_at', '>', now()->subDays($tolerancia)),
            fn ($n) => "{$n} devolución(es) vienen de vuelta a bodega",
            'Escanéalas al llegar en «Registrar devolución».',
            'info', 'heroicon-o-arrow-uturn-left');

        $r['diferencias'] = $this->avisarDiferencias();

        return $r;
    }

    /**
     * Un aviso por regla y corrida, con los pedidos que todavía no lo habían
     * recibido; los marca para no repetir.
     *
     * @param  list<string>  $tambienMarca  niveles menores que ya no hace falta avisar
     */
    private function avisar(string $clave, $query, \Closure $titulo, string $mensaje, string $color, string $icono, array $tambienMarca = []): int
    {
        $pedidos = $query->get(['id', 'guia', 'alertas_enviadas'])
            ->reject(fn ($p) => in_array($clave, $p->alertas_enviadas ?? [], true))
            ->values();

        if ($pedidos->isEmpty()) {
            return 0;
        }

        $guias = $pedidos->pluck('guia')->take(8)->implode(', ')
            .($pedidos->count() > 8 ? ' y '.($pedidos->count() - 8).' más' : '');

        foreach ($this->destinatarios() as $userId) {
            NotificacionErp::crear([
                'user_id' => $userId,
                'tipo' => "dropi_{$clave}",
                'titulo' => $titulo($pedidos->count()),
                'mensaje' => "{$mensaje} Guías: {$guias}",
                'url' => '/app/dropi',
                'color' => $color,
                'icono' => $icono,
            ]);
        }

        foreach ($pedidos as $p) {
            $marcas = array_values(array_unique(array_merge($p->alertas_enviadas ?? [], [$clave], $tambienMarca)));
            DB::table('dropi_pedidos')->where('id', $p->id)->update(['alertas_enviadas' => json_encode($marcas)]);
        }

        return $pedidos->count();
    }

    /** 8.4 · diferencias de precio que detectó la conciliación y nadie ha visto. */
    private function avisarDiferencias(): int
    {
        $nuevas = DropiSancion::with('pedido:id,guia')
            ->whereIn('tipo', ['diferencia_precio', 'sobrepago', 'pago_sobre_devuelto'])
            ->whereNull('notificada_at')
            ->get();
        if ($nuevas->isEmpty()) {
            return 0;
        }

        $total = $nuevas->sum(fn ($s) => abs((float) $s->diferencia));
        foreach ($this->destinatarios(soloAdministrativo: true) as $userId) {
            NotificacionErp::crear([
                'user_id' => $userId,
                'tipo' => 'dropi_diferencia',
                'titulo' => "{$nuevas->count()} diferencia(s) en lo que pagó Dropi",
                'mensaje' => '$'.number_format($total, 0, ',', '.').' en total. Guías: '
                    .$nuevas->map(fn ($s) => $s->pedido?->guia)->filter()->take(8)->implode(', '),
                'url' => '/app/dropi/discrepancias',
                'color' => 'warning',
                'icono' => 'heroicon-o-scale',
            ]);
        }
        DropiSancion::whereIn('id', $nuevas->pluck('id'))->update(['notificada_at' => now()]);

        return $nuevas->count();
    }

    /**
     * Aracely/Gerencia siempre; bodega para lo operativo (plazos, paquetes,
     * devoluciones). Las diferencias de plata no son de bodega.
     *
     * @return Collection<int, int>
     */
    private function destinatarios(bool $soloAdministrativo = false): Collection
    {
        return User::query()->get()
            ->filter(fn (User $u) => $u->esAracely() || (! $soloAdministrativo && $u->esAdminBodega()))
            ->pluck('id')->values();
    }
}
