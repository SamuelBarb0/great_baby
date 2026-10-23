<?php

namespace App\Modules\Marketing\Services;

use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Inventario\Actions\EjecutarTraslado;
use App\Modules\Inventario\Enums\EstadoTraslado;
use App\Modules\Inventario\Models\Traslado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * MKT-5 · Muestras a Marketing: solicita → aprueba → bodega alista →
 * Marketing recoge → devuelve.
 *
 * Cada paso deja su responsable en el traslado y avisa por la campana a quien
 * le toca el siguiente:
 *
 *   solicitud nueva   → quienes aprueban (Aracely/Gerencia y el admin de la bodega)
 *   aprobada          → quien la pidió
 *   lista (enviada)   → quien la pidió: puede pasar a recogerla
 *   entregada         → quien la pidió, con el estado en que salió
 *   rechazada/anulada → quien la pidió, con el motivo
 *   devolución        → bodega la recibe y anota cómo volvió
 *
 * La mercancía se mueve con el traslado de siempre (`EjecutarTraslado`): acá
 * sólo se agregan las firmas y los avisos.
 */
class MuestrasMarketing
{
    public function __construct(private EjecutarTraslado $traslados)
    {
    }

    public function aprobar(Traslado $t, User $quien): Traslado
    {
        return DB::transaction(function () use ($t, $quien) {
            $t = Traslado::query()->whereKey($t->id)->lockForUpdate()->firstOrFail();

            if (! $t->esPrestamoMarketing()) {
                throw new InvalidArgumentException("Traslado {$t->numero}: sólo se aprueban solicitudes de muestras de Marketing.");
            }
            if ($t->estado !== EstadoTraslado::Borrador) {
                throw new InvalidArgumentException("Traslado {$t->numero}: sólo se aprueba mientras está en borrador.");
            }
            if ($t->aprobado_at) {
                throw new InvalidArgumentException("Traslado {$t->numero}: ya la aprobó {$t->aprobador?->name}.");
            }

            $t->aprobado_por = $quien->id;
            $t->aprobado_at = now();
            $t->save();

            $this->avisarSolicitante($t, 'aprobada', "Aprobada por {$quien->name}. Bodega la está alistando.", 'sky');

            return $t;
        });
    }

    /**
     * Bodega la entrega (préstamo) o la recibe de vuelta (devolución): mueve el
     * stock al destino y deja quién la llevó y en qué estado iba.
     */
    public function entregar(Traslado $t, int $recogidoPor, string $estadoMercancia, ?string $observaciones): Traslado
    {
        if (! array_key_exists($estadoMercancia, Traslado::ESTADOS_MERCANCIA)) {
            throw new InvalidArgumentException('Estado de la mercancía no válido.');
        }
        if ($estadoMercancia !== 'completo' && blank($observaciones)) {
            throw new InvalidArgumentException('Si no va completa y en buen estado, anotá qué pasó en las observaciones.');
        }

        return DB::transaction(function () use ($t, $recogidoPor, $estadoMercancia, $observaciones) {
            $t = $this->traslados->recibir($t);
            $t->recogido_por = $recogidoPor;
            $t->estado_mercancia = $estadoMercancia;
            $t->observaciones_entrega = $observaciones ?: null;
            $t->save();

            $estado = Traslado::ESTADOS_MERCANCIA[$estadoMercancia];
            $quien = User::find($recogidoPor)?->name ?? '—';
            if ($t->esPrestamoMarketing()) {
                $this->avisarSolicitante($t, 'entregada', "La recogió {$quien} · {$estado}."
                    .($observaciones ? " {$observaciones}" : ''), $estadoMercancia === 'completo' ? 'emerald' : 'amber');
            } else {
                $this->avisarSolicitante($t, 'devuelta', "Bodega recibió la devolución que trajo {$quien} · {$estado}."
                    .($observaciones ? " {$observaciones}" : ''), $estadoMercancia === 'completo' ? 'emerald' : 'amber');
            }

            return $t;
        });
    }

    public function avisarSolicitudNueva(Traslado $t): void
    {
        $t->loadMissing(['origen', 'solicitante']);
        $devolucion = ! $t->esPrestamoMarketing();

        foreach ($this->aprobadores($t) as $userId) {
            NotificacionErp::crear([
                'user_id' => $userId,
                'tipo' => $devolucion ? 'marketing_devolucion' : 'marketing_muestras',
                'titulo' => $devolucion
                    ? "Marketing devuelve muestras · {$t->numero}"
                    : "Marketing pide muestras · {$t->numero}",
                'mensaje' => trim(($t->solicitante?->name ?? 'Marketing').': '.($t->observaciones ?? '')),
                'url' => "/app/inventario/traslados/{$t->id}",
                'color' => 'sky',
                'icono' => 'heroicon-o-camera',
            ]);
        }
    }

    public function avisarLista(Traslado $t): void
    {
        $t->loadMissing('origen');
        $this->avisarSolicitante($t, 'lista', "Ya está alistada en {$t->origen?->codigo}: podés pasar a recogerla.", 'emerald');
    }

    public function avisarAnulada(Traslado $t, string $motivo): void
    {
        $this->avisarSolicitante($t, 'anulada', "Motivo: {$motivo}", 'red');
    }

    /**
     * Quienes aprueban y alistan: Aracely/Gerencia siempre, y el admin de
     * bodega que responde por la bodega de venta del traslado.
     *
     * @return Collection<int, int>
     */
    public function aprobadores(Traslado $t): Collection
    {
        $bodega = $t->esPrestamoMarketing() ? (int) $t->origen_id : (int) $t->destino_id;

        return User::query()->get()
            ->filter(fn (User $u) => $u->esAracely()
                || ($u->esAdminBodega() && in_array($bodega, $u->bodegasAsignadasIds(), true)))
            ->pluck('id')->values();
    }

    /** Las personas de Marketing, para elegir quién recogió o trajo la mercancía. */
    public function equipo(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => $u->esMarketing() && ! $u->esAracely())
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
    }

    private function avisarSolicitante(Traslado $t, string $paso, string $mensaje, string $color): void
    {
        if (! $t->solicitado_por) {
            return;
        }
        $titulos = [
            'aprobada' => "Tu solicitud de muestras {$t->numero} fue aprobada",
            'lista' => "Tus muestras {$t->numero} están listas para recoger",
            'entregada' => "Muestras {$t->numero} entregadas",
            'devuelta' => "Devolución {$t->numero} recibida en bodega",
            'anulada' => "Solicitud {$t->numero} rechazada o anulada",
        ];

        NotificacionErp::crear([
            'user_id' => $t->solicitado_por,
            'tipo' => "marketing_muestras_{$paso}",
            'titulo' => $titulos[$paso],
            'mensaje' => $mensaje,
            'url' => '/app/marketing',
            'color' => $color,
            'icono' => 'heroicon-o-camera',
        ]);
    }
}
