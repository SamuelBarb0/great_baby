<?php

namespace App\Modules\Marketing\Services;

use App\Auth\Permisos;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Marketing\Models\Lanzamiento;
use App\Modules\Marketing\Models\LanzamientoComentario;
use App\Modules\Marketing\Models\LanzamientoItem;
use App\Modules\Marketing\Models\LanzamientoTarjeta;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tablero de lanzamiento por contenedor («TABLERO GREAT BABY.pdf», 30-oct-2026).
 *
 * Todo movimiento de tarjeta pasa por aquí, que es donde viven las reglas del
 * tablero:
 *   - Cada tarjeta tiene un responsable: quien la toma (la pasa a En proceso)
 *     queda como responsable si no había uno.
 *   - Solo pasa a Finalizado con el checklist completo.
 *   - Pendiente de aprobación → la aprueba Aracely (pasa a Finalizado) o la
 *     devuelve a En proceso con el cambio pedido. La Validación Comercial no
 *     puede saltarse ese paso.
 *   - Bloqueado exige motivo, responsable de resolverlo y acción requerida.
 *   - Con las diez tarjetas en Finalizado, el lanzamiento termina.
 *
 * Cada área trabaja sus tarjetas; Aracely y Gerencia (root) pueden con todas.
 */
class TableroLanzamiento
{
    /** Crea el lanzamiento del contenedor con sus 10 tarjetas. Si ya existe, lo devuelve. */
    public function crearPara(Importacion $importacion, ?User $por = null): Lanzamiento
    {
        return DB::transaction(function () use ($importacion, $por) {
            $existente = Lanzamiento::where('importacion_id', $importacion->id)->lockForUpdate()->first();
            if ($existente) {
                return $existente;
            }

            $lanzamiento = Lanzamiento::create(['importacion_id' => $importacion->id, 'creado_por' => $por?->id]);

            foreach (PlantillaLanzamiento::tarjetas() as $i => $t) {
                $tarjeta = $lanzamiento->tarjetas()->create([
                    'clave' => $t['clave'],
                    'numero' => $i + 1,
                    'titulo' => $t['titulo'],
                    'area' => $t['area'],
                    'lista' => 'por_hacer',
                    'requiere_aprobacion' => $t['requiere_aprobacion'],
                ]);
                foreach ($t['items'] as $pos => $item) {
                    [$texto, $grupo] = is_array($item) ? $item : [$item, null];
                    $tarjeta->items()->create(['texto' => $texto, 'grupo' => $grupo, 'posicion' => $pos]);
                }
            }

            return $lanzamiento;
        });
    }

    /** ¿Puede este usuario trabajar la tarjeta (marcar, mover, editar)? */
    public function puedeTrabajar(?User $u, LanzamientoTarjeta $t): bool
    {
        if (! $u) {
            return false;
        }
        if (Permisos::esRoot($u) || $t->responsable_id === $u->id) {
            return true;
        }

        return match ($t->area) {
            'compras' => Permisos::puede($u, 'compras_importacion') || Permisos::puede($u, 'compras_oc'),
            'marketing', 'marketing_dropshipping' => Permisos::puede($u, 'marketing'),
            'bodega' => Permisos::puede($u, 'bodega'),
            default => false, // dropshipping = Aracely, que es root
        };
    }

    /** Aprobar o devolver: lo decidió Samuel el 30-oct, «Aracely approves». */
    public function puedeAprobar(?User $u): bool
    {
        return (bool) $u?->esAracely();
    }

    public function marcarItem(LanzamientoItem $item, bool $hecho, User $u): void
    {
        $tarjeta = $item->tarjeta;
        $this->exigirTrabajo($u, $tarjeta);
        if (in_array($tarjeta->lista, ['finalizado', 'pendiente_aprobacion'], true)) {
            throw new InvalidArgumentException('El checklist no se cambia mientras la tarjeta está en '.LanzamientoTarjeta::LISTAS[$tarjeta->lista].'.');
        }

        $item->update($hecho
            ? ['hecho_por' => $u->id, 'hecho_en' => now()]
            : ['hecho_por' => null, 'hecho_en' => null]);
    }

    /**
     * Mueve la tarjeta a otra lista. Para bloquear se pasan
     * `motivo`, `responsable_id` y `accion`.
     *
     * @param  array{motivo?: ?string, responsable_id?: ?int, accion?: ?string}  $bloqueo
     */
    public function mover(LanzamientoTarjeta $t, string $destino, User $u, array $bloqueo = []): void
    {
        if (! array_key_exists($destino, LanzamientoTarjeta::LISTAS)) {
            throw new InvalidArgumentException('Lista no válida.');
        }
        $this->exigirTrabajo($u, $t);
        if ($t->lista === $destino) {
            return;
        }
        if ($t->lista === 'finalizado') {
            throw new InvalidArgumentException('La tarjeta ya está finalizada.');
        }
        if ($t->lista === 'pendiente_aprobacion' && ! $this->puedeAprobar($u)) {
            throw new InvalidArgumentException('La tarjeta espera la aprobación de Aracely.');
        }

        $t->loadMissing('items');
        $cambios = ['lista' => $destino];

        switch ($destino) {
            case 'en_proceso':
                $cambios['responsable_id'] = $t->responsable_id ?? $u->id;
                break;

            case 'pendiente_aprobacion':
                $this->exigirChecklist($t);
                break;

            case 'finalizado':
                if ($t->requiere_aprobacion) {
                    throw new InvalidArgumentException("«{$t->titulo}» necesita la aprobación de Aracely: envíela a Pendiente de aprobación.");
                }
                $this->exigirChecklist($t);
                $cambios['finalizada_en'] = now();
                break;

            case 'bloqueado':
                $motivo = trim((string) ($bloqueo['motivo'] ?? ''));
                $accion = trim((string) ($bloqueo['accion'] ?? ''));
                $responsable = isset($bloqueo['responsable_id']) ? User::find($bloqueo['responsable_id']) : null;
                if ($motivo === '' || $accion === '' || ! $responsable) {
                    throw new InvalidArgumentException('Para bloquear la tarjeta indique el motivo, quién lo resuelve y la acción requerida.');
                }
                $cambios += [
                    'bloqueo_motivo' => $motivo,
                    'bloqueo_responsable_id' => $responsable->id,
                    'bloqueo_accion' => $accion,
                ];
                break;
        }

        // Al salir de Bloqueado el bloqueo queda en la historia, no en la tarjeta.
        if ($t->lista === 'bloqueado' && $destino !== 'bloqueado') {
            $cambios += ['bloqueo_motivo' => null, 'bloqueo_responsable_id' => null, 'bloqueo_accion' => null];
        }

        $desde = $t->lista;
        DB::transaction(function () use ($t, $cambios, $u, $desde, $destino) {
            $t->update($cambios);
            $this->registrar($t, $u, $destino === 'bloqueado' ? 'bloqueo' : 'movimiento', $destino === 'bloqueado'
                ? "Bloqueó la tarjeta. Motivo: {$t->bloqueo_motivo} · Resuelve: {$t->bloqueoResponsable?->name} · Acción: {$t->bloqueo_accion}"
                : 'Movió la tarjeta de '.LanzamientoTarjeta::LISTAS[$desde].' a '.LanzamientoTarjeta::LISTAS[$destino].'.');
        });

        $this->avisar($t, $destino, $u);
        if ($destino === 'finalizado') {
            $this->cerrarSiTermino($t->lanzamiento);
        }
    }

    public function aprobar(LanzamientoTarjeta $t, User $u): void
    {
        if (! $this->puedeAprobar($u)) {
            throw new AuthorizationException('Solo Aracely aprueba las tarjetas del tablero.');
        }
        if ($t->lista !== 'pendiente_aprobacion') {
            throw new InvalidArgumentException('Solo se aprueban tarjetas que están en Pendiente de aprobación.');
        }

        DB::transaction(function () use ($t, $u) {
            $t->update(['lista' => 'finalizado', 'aprobada_por' => $u->id, 'aprobada_en' => now(), 'finalizada_en' => now()]);
            $this->registrar($t, $u, 'aprobacion', 'Aprobó la tarjeta.');
        });

        $this->avisarResponsable($t, "Aprobada: {$t->titulo}", "{$u->name} aprobó la tarjeta.", 'success');
        $this->cerrarSiTermino($t->lanzamiento);
    }

    /** «Si requiere cambios, vuelve a En proceso»: con el cambio pedido escrito. */
    public function devolver(LanzamientoTarjeta $t, User $u, string $cambios): void
    {
        if (! $this->puedeAprobar($u)) {
            throw new AuthorizationException('Solo Aracely devuelve las tarjetas del tablero.');
        }
        if ($t->lista !== 'pendiente_aprobacion') {
            throw new InvalidArgumentException('Solo se devuelven tarjetas que están en Pendiente de aprobación.');
        }
        $cambios = trim($cambios);
        if ($cambios === '') {
            throw new InvalidArgumentException('Escriba qué cambios necesita la tarjeta.');
        }

        DB::transaction(function () use ($t, $u, $cambios) {
            $t->update(['lista' => 'en_proceso']);
            $this->registrar($t, $u, 'devolucion', "Pidió cambios: {$cambios}");
        });

        $this->avisarResponsable($t, "Cambios pedidos: {$t->titulo}", $cambios, 'warning');
    }

    /** @param  array{responsable_id?: ?int, vence_en?: ?string}  $datos */
    public function actualizar(LanzamientoTarjeta $t, User $u, array $datos): void
    {
        $this->exigirTrabajo($u, $t);
        $t->update(array_intersect_key($datos, array_flip(['responsable_id', 'vence_en'])));
    }

    public function comentar(LanzamientoTarjeta $t, User $u, string $cuerpo): void
    {
        $cuerpo = trim($cuerpo);
        if ($cuerpo === '') {
            throw new InvalidArgumentException('El comentario está vacío.');
        }
        $this->registrar($t, $u, 'comentario', $cuerpo);
    }

    // ───────────────────────── internos ─────────────────────────

    private function exigirTrabajo(User $u, LanzamientoTarjeta $t): void
    {
        if (! $this->puedeTrabajar($u, $t)) {
            throw new AuthorizationException("La tarjeta «{$t->titulo}» es de ".LanzamientoTarjeta::AREAS[$t->area].'.');
        }
    }

    private function exigirChecklist(LanzamientoTarjeta $t): void
    {
        $faltan = $t->items->whereNull('hecho_en')->count();
        if ($faltan > 0) {
            throw new InvalidArgumentException("Al checklist de «{$t->titulo}» le faltan {$faltan} paso(s).");
        }
    }

    private function registrar(LanzamientoTarjeta $t, User $u, string $tipo, string $cuerpo): void
    {
        LanzamientoComentario::create(['tarjeta_id' => $t->id, 'user_id' => $u->id, 'tipo' => $tipo, 'cuerpo' => $cuerpo]);
    }

    private function cerrarSiTermino(Lanzamiento $l): void
    {
        $abiertas = $l->tarjetas()->where('lista', '!=', 'finalizado')->exists();
        if ($abiertas || $l->finalizado_en) {
            return;
        }
        $l->update(['finalizado_en' => now()]);

        foreach ($this->usuariosConRol(['Aracely', 'Marketing']) as $destino) {
            $this->notificar($destino, 'Lanzamiento terminado', "Las 10 tarjetas del contenedor {$this->contenedor($l)} están finalizadas.", $l, 'success');
        }
    }

    private function avisar(LanzamientoTarjeta $t, string $destino, User $u): void
    {
        $l = $t->lanzamiento;

        // Tarjeta 1: «Notificar a Araceli. Notificar a Anny.» Al confirmarse el
        // contenedor se les avisa a las dos áreas que el lanzamiento arrancó.
        if ($destino === 'finalizado' && $t->clave === 'recepcion') {
            foreach ($this->usuariosConRol(['Aracely', 'Marketing']) as $destinoUser) {
                $this->notificar($destinoUser, 'Contenedor confirmado', "Compras confirmó el contenedor {$this->contenedor($l)}: arranca el lanzamiento.", $l, 'info');
            }
        }

        if ($destino === 'pendiente_aprobacion') {
            foreach ($this->usuariosConRol(['Aracely']) as $aprobador) {
                $this->notificar($aprobador, "Por aprobar: {$t->titulo}", "{$u->name} la envió a aprobación ({$this->contenedor($l)}).", $l, 'warning');
            }
        }

        if ($destino === 'bloqueado' && $t->bloqueo_responsable_id) {
            $this->notificar(User::find($t->bloqueo_responsable_id), "Bloqueada: {$t->titulo}", "Le toca resolverlo: {$t->bloqueo_accion}", $l, 'danger');
        }
    }

    private function avisarResponsable(LanzamientoTarjeta $t, string $titulo, string $mensaje, string $color): void
    {
        if ($t->responsable_id) {
            $this->notificar(User::find($t->responsable_id), $titulo, $mensaje, $t->lanzamiento, $color);
        }
    }

    private function notificar(?User $u, string $titulo, string $mensaje, Lanzamiento $l, string $color): void
    {
        if (! $u) {
            return;
        }
        NotificacionErp::crear([
            'user_id' => $u->id,
            'tipo' => 'marketing_lanzamiento',
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'url' => "/app/marketing/lanzamientos/{$l->id}",
            'icono' => 'heroicon-o-rocket-launch',
            'color' => $color,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function usuariosConRol(array $roles)
    {
        // `User::role()` revienta si un rol no existe en esta base (p. ej. sin Marketing sembrado).
        $existentes = \Spatie\Permission\Models\Role::whereIn('name', $roles)->where('guard_name', 'web')->pluck('name')->all();

        return $existentes ? User::role($existentes)->get() : collect();
    }

    private function contenedor(Lanzamiento $l): string
    {
        $imp = $l->importacion;

        return trim(($imp?->numero ?? '').($imp?->contenedor ? " · {$imp->contenedor}" : ''));
    }
}
