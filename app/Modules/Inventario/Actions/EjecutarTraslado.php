<?php

namespace App\Modules\Inventario\Actions;

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Inventario\Enums\EstadoTraslado;
use App\Modules\Inventario\Models\Traslado;
use App\Modules\Inventario\Models\TrasladoItem;
use App\Modules\Inventario\Services\StockService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Re-audit M3 PATRÓN γ (FUNC-C1) + PATRÓN β (SEG-A4 / DATOS-C3/C4).
 *
 * Máquina de estados REAL de traslado:
 *   Borrador --enviar()--> EnTransito --recibir()--> Recibido
 *   Borrador|EnTransito --anular()--> Anulado (crea reversa si ya envió)
 *
 * Fix vs versión previa:
 *   - Antes: la guarda era `!== Recibido` → un traslado Anulado pasaba y se
 *     re-ejecutaba, duplicando kardex. Ahora whitelist explícita por método.
 *   - Sin `lockForUpdate` sobre traslado NI sobre kardex origen → doble-click
 *     y concurrencia duplicaban movimientos. Ahora lock explícito + guard
 *     idempotente contra movs previos.
 *   - `handle()` (retro-compat con AsAction::run) delega en el flujo completo
 *     enviar→recibir, para no romper llamadas legacy. Pero expone métodos
 *     separados para el UI operativo real.
 */
class EjecutarTraslado
{
    use AsAction;

    public function __construct(protected StockService $stock)
    {
    }

    /**
     * Compat: ejecuta enviar + recibir en una sola tx (flujo legacy).
     */
    public function handle(Traslado $traslado): Traslado
    {
        return DB::transaction(function () use ($traslado) {
            if ($traslado->estado === EstadoTraslado::Borrador) {
                $traslado = $this->enviar($traslado);
            }
            if ($traslado->estado === EstadoTraslado::EnTransito) {
                $traslado = $this->recibir($traslado);
            }
            return $traslado;
        });
    }

    /**
     * Envía el traslado: descuenta stock del ORIGEN y lo pone EnTránsito.
     * El destino aún NO recibe (mercancía en tránsito).
     */
    public function enviar(Traslado $traslado): Traslado
    {
        // C-F2 R2 · Guard temporal REMOVIDO — ahora se soportan items agregados.
        // PATRÓN λ (FUNC-C8, SEG-C2) · guard de autorización en el Action.
        //   Antes: la única autorización estaba en el controller/Filament, así
        //   que cualquier job/artisan/listener podía disparar movimientos de
        //   stock. Ahora el propio Action lo pide.
        $this->autorizarSobreBodegas($traslado->origen_id, $traslado->destino_id);

        return DB::transaction(function () use ($traslado) {
            $traslado = Traslado::query()->whereKey($traslado->id)->lockForUpdate()->first();

            if (! in_array($traslado->estado, [EstadoTraslado::Borrador], true)) {
                throw new InvalidArgumentException(sprintf(
                    'Traslado %s (%s): solo se envía desde Borrador.',
                    $traslado->numero, $traslado->estado->value,
                ));
            }
            if ($traslado->origen_id === $traslado->destino_id) {
                throw new InvalidArgumentException('Origen y destino no pueden ser la misma bodega.');
            }
            // MKT-5 · las muestras para Marketing salen de bodega sólo aprobadas.
            if ($traslado->esPrestamoMarketing() && ! $traslado->aprobado_at) {
                throw new InvalidArgumentException(
                    "Traslado {$traslado->numero}: la solicitud de muestras de Marketing todavía no está aprobada."
                );
            }

            $items = $traslado->items()->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw new InvalidArgumentException('El traslado no tiene ítems.');
            }

            // C-F2 R2 · Lock kardex del origen — polimórfico:
            //   granular → whereIn variante_id
            //   agregado → whereIn producto_id AND variante_id NULL
            $varIds = $items->whereNotNull('variante_id')->pluck('variante_id')->all();
            $prodIds = $items->whereNull('variante_id')->pluck('producto_id')->all();
            if ($varIds) {
                InventarioMovimiento::query()
                    ->whereIn('variante_id', $varIds)
                    ->where('ubicacion_id', $traslado->origen_id)
                    ->lockForUpdate()->get();
            }
            if ($prodIds) {
                InventarioMovimiento::query()
                    ->whereIn('producto_id', $prodIds)
                    ->whereNull('variante_id')
                    ->where('ubicacion_id', $traslado->origen_id)
                    ->lockForUpdate()->get();
            }

            // Guard idempotente: si ya hay salidas del traslado, no re-crear.
            $yaEnviado = InventarioMovimiento::query()
                ->where('referencia_tipo', Traslado::class)
                ->where('referencia_id', $traslado->id)
                ->where('tipo', 'traslado_salida')->exists();
            if ($yaEnviado) {
                throw new InvalidArgumentException('El traslado ya tiene movimientos de salida registrados.');
            }

            // C-F2 R2 · Validar stock disponible por sujeto (polimórfico).
            foreach ($items as $it) {
                $req = (int) $it->cantidad_solicitada;
                $etiqueta = $it->esAgregado() ? "producto {$it->producto_id}" : "variante {$it->variante_id}";
                $this->guardEnteroPositivoEtiqueta($req, $etiqueta);

                $disp = $it->esAgregado()
                    ? $this->stock->saldoDisponibleProducto($it->producto_id, $traslado->origen_id)
                    : $this->stock->saldoDisponible($it->variante_id, $traslado->origen_id);

                if ($disp < $req) {
                    throw new InvalidArgumentException(
                        "Stock insuficiente en origen · {$etiqueta}: disponible {$disp}, requerido {$req}."
                    );
                }
            }

            $ts = now();
            foreach ($items as $it) {
                $cant = (int) $it->cantidad_solicitada;
                // C-F2 R2 · Mov polimórfico:
                //   variante_id NULL para agregados, producto_id auto por hook.
                InventarioMovimiento::create([
                    'variante_id' => $it->variante_id,
                    'producto_id' => $it->producto_id, // consistencia
                    'ubicacion_id' => $traslado->origen_id,
                    'tipo' => 'traslado_salida',
                    'cantidad' => -$cant,
                    'referencia_tipo' => Traslado::class,
                    'referencia_id' => $traslado->id,
                    'user_id' => auth()->id(),
                    'notas' => "Traslado {$traslado->numero} → {$traslado->destino?->nombre}"
                        .($it->esAgregado() ? ' · AGREGADO' : ''),
                    'created_at' => $ts,
                ]);
            }

            $traslado->estado = EstadoTraslado::EnTransito;
            $traslado->fecha_envio = $ts;
            $traslado->enviado_por = auth()->id();
            $traslado->save();

            return $traslado->fresh(['items', 'origen', 'destino']);
        });
    }

    /**
     * Recibe el traslado en destino: ingresa stock y pasa a Recibido.
     */
    public function recibir(Traslado $traslado): Traslado
    {
        // C-F2 R2 · Guard temporal REMOVIDO — se soportan items agregados.
        $this->autorizarSobreBodegas($traslado->origen_id, $traslado->destino_id);

        return DB::transaction(function () use ($traslado) {
            $traslado = Traslado::query()->whereKey($traslado->id)->lockForUpdate()->first();

            if (! in_array($traslado->estado, [EstadoTraslado::EnTransito], true)) {
                throw new InvalidArgumentException(sprintf(
                    'Traslado %s (%s): solo se recibe desde EnTransito.',
                    $traslado->numero, $traslado->estado->value,
                ));
            }
            // PATRÓN ν (FUNC-M5) · guard origen==destino repetido: si γ se
            //   bypaseó y el traslado llegó a EnTransito con origen==destino,
            //   recibir aquí crearía entrada en la misma bodega ⇒ inventario
            //   duplicado. Se re-valida en cada fase.
            if ($traslado->origen_id === $traslado->destino_id) {
                throw new InvalidArgumentException('Origen y destino no pueden ser la misma bodega.');
            }

            $items = $traslado->items()->orderBy('id')->get();

            // Guard idempotente: si ya hay entradas, no re-crear.
            $yaRecibido = InventarioMovimiento::query()
                ->where('referencia_tipo', Traslado::class)
                ->where('referencia_id', $traslado->id)
                ->where('tipo', 'traslado_entrada')->exists();
            if ($yaRecibido) {
                throw new InvalidArgumentException('El traslado ya tiene movimientos de entrada registrados.');
            }

            $ts = now();
            foreach ($items as $it) {
                $cant = (int) $it->cantidad_solicitada;
                // C-F2 R2 · Mov polimórfico (variante_id NULL para agregados).
                InventarioMovimiento::create([
                    'variante_id' => $it->variante_id,
                    'producto_id' => $it->producto_id,
                    'ubicacion_id' => $traslado->destino_id,
                    'tipo' => 'traslado_entrada',
                    'cantidad' => $cant,
                    'referencia_tipo' => Traslado::class,
                    'referencia_id' => $traslado->id,
                    'user_id' => auth()->id(),
                    'notas' => "Traslado {$traslado->numero} ← {$traslado->origen?->nombre}"
                        .($it->esAgregado() ? ' · AGREGADO' : ''),
                    'created_at' => $ts,
                ]);

                $it->cantidad_ejecutada = $cant;
                $it->save();
            }

            $traslado->estado = EstadoTraslado::Recibido;
            $traslado->fecha_ejecucion = $ts;
            $traslado->ejecutado_por = auth()->id();
            $traslado->save();

            return $traslado->fresh(['items', 'origen', 'destino']);
        });
    }

    /**
     * Re-audit M3 PATRÓN γ (FUNC-A11) · Anular traslado con reversa.
     *   - Si estaba Borrador: solo marca Anulado.
     *   - Si estaba EnTransito: reversa la salida (ingresa a origen) y anula.
     *   - Si estaba Recibido: reversa salida (ingresa origen) + entrada (sale destino).
     */
    public function anular(Traslado $traslado, string $motivo): Traslado
    {
        // C-F2 R2 · Guard temporal REMOVIDO — anular ya soporta agregados.
        $this->autorizarSobreBodegas($traslado->origen_id, $traslado->destino_id);

        return DB::transaction(function () use ($traslado, $motivo) {
            $traslado = Traslado::query()->whereKey($traslado->id)->lockForUpdate()->first();

            if ($traslado->estado === EstadoTraslado::Anulado) {
                throw new InvalidArgumentException('El traslado ya está anulado.');
            }

            $estadoPrev = $traslado->estado;
            $items = $traslado->items()->orderBy('id')->get();
            $ts = now();

            // PATRÓN ν (FUNC-C9) · reverso completo: si el traslado había
            //   ejecutado, resetear `cantidad_ejecutada` de los items para que
            //   el reporte no lo pinte como "ejecutado y anulado" a la vez.
            //   Iterar por instancia (no bulk update) para disparar Auditable.
            foreach ($items as $it) {
                if ((float) $it->cantidad_ejecutada > 0) {
                    $it->cantidad_ejecutada = 0;
                    $it->save();
                }
            }

            // PATRÓN ν (FUNC-C9) · liberar reservas atadas al traslado.
            //   ReservarStock puede haberse invocado contra Traslado (ej.
            //   pre-picking); anular sin liberar deja stock virtualmente
            //   bloqueado por un traslado que ya no existe.
            try {
                LiberarReserva::run($traslado);
            } catch (\Throwable $e) {
                // Log-only: no bloquear anulación por fallo de reserva.
                \Illuminate\Support\Facades\Log::warning('LiberarReserva falló durante anular traslado', [
                    'traslado_id' => $traslado->id, 'error' => $e->getMessage(),
                ]);
            }

            if (in_array($estadoPrev, [EstadoTraslado::EnTransito, EstadoTraslado::Recibido], true)) {
                // C-F2 R2 · Reversa salida polimórfica (devuelve stock a ORIGEN).
                foreach ($items as $it) {
                    $cant = (int) $it->cantidad_solicitada;
                    InventarioMovimiento::create([
                        'variante_id' => $it->variante_id,
                        'producto_id' => $it->producto_id,
                        'ubicacion_id' => $traslado->origen_id,
                        'tipo' => 'traslado_reversa_salida',
                        'cantidad' => $cant, // positivo: devuelve stock a origen
                        'referencia_tipo' => Traslado::class,
                        'referencia_id' => $traslado->id,
                        'user_id' => auth()->id(),
                        'notas' => "REVERSA anulación traslado {$traslado->numero}: {$motivo}",
                        'created_at' => $ts,
                    ]);
                }
            }
            if ($estadoPrev === EstadoTraslado::Recibido) {
                // C-F2 R2 · Reversa entrada polimórfica (retira stock del DESTINO).
                foreach ($items as $it) {
                    $cant = (int) $it->cantidad_solicitada;
                    InventarioMovimiento::create([
                        'variante_id' => $it->variante_id,
                        'producto_id' => $it->producto_id,
                        'ubicacion_id' => $traslado->destino_id,
                        'tipo' => 'traslado_reversa_entrada',
                        'cantidad' => -$cant,
                        'referencia_tipo' => Traslado::class,
                        'referencia_id' => $traslado->id,
                        'user_id' => auth()->id(),
                        'notas' => "REVERSA anulación traslado {$traslado->numero}: {$motivo}",
                        'created_at' => $ts,
                    ]);
                }
            }

            $traslado->estado = EstadoTraslado::Anulado;
            $traslado->anulado_at = $ts;
            $traslado->anulado_por = auth()->id();
            $traslado->motivo_anulacion = $motivo;
            $traslado->observaciones = trim(($traslado->observaciones ?? '') . "\n[ANULADO {$ts->toDateString()} por " . auth()->user()?->name . " · estado previo: {$estadoPrev->value}] {$motivo}");
            $traslado->save();

            \Illuminate\Support\Facades\Log::channel(
                array_key_exists('audit', config('logging.channels') ?? []) ? 'audit' : 'stack'
            )->info('inventario.traslado.anular', [
                'user_id' => auth()->id(), 'traslado_id' => $traslado->id, 'numero' => $traslado->numero,
                'estado_previo' => $estadoPrev->value, 'motivo' => $motivo,
            ]);

            return $traslado->fresh(['items', 'origen', 'destino']);
        });
    }

    protected function guardEnteroPositivo(int|float $cant, int $varianteId): void
    {
        $this->guardEnteroPositivoEtiqueta($cant, "variante {$varianteId}");
    }

    /**
     * C-F2 R2 · versión polimórfica del guard: etiqueta puede describir variante
     * o producto agregado según el contexto del caller.
     */
    protected function guardEnteroPositivoEtiqueta(int|float $cant, string $etiqueta): void
    {
        // Re-audit M3 ξ · kardex ya soporta decimal(14,4). Sólo validamos
        //   positividad y cota máxima razonable.
        if ($cant <= 0) {
            throw new InvalidArgumentException("Cantidad inválida para {$etiqueta}: debe ser > 0.");
        }
        if ($cant > 999999) {
            throw new InvalidArgumentException(
                "Cantidad fuera de rango razonable · {$etiqueta} cantidad {$cant}."
            );
        }
    }

    /**
     * Re-audit M3 λ (FUNC-C8, SEG-C2) · guard de autorización aplicable a
     *   TODAS las fases del Action. Se llama fuera de la tx para fallar
     *   temprano sin abrir una transacción inútil.
     *
     *   - Requiere usuario autenticado.
     *   - Aracely/Gerencia (root) pasa siempre.
     *   - Alistador: debe tener origen y destino en su `bodegas_asignadas`
     *     (fallback: si el modelo User no expone el helper, no se le permite
     *     tocar traslados vía Action — evita escalación silenciosa).
     */
    protected function autorizarSobreBodegas(int $origenId, int $destinoId): void
    {
        $u = auth()->user();
        abort_unless($u, 403, 'No autenticado.');

        // Fix R2 re-audit · alinear con matriz Permisos. Antes el hardcode
        //   {Aracely, Gerencia, Alistador} dejaba fuera al Gerente aunque
        //   'traslados' => ['Gerente','Alistador'] en Permisos. Consecuencia:
        //   sidebar/Resource abren, Ejecutar → 403 sorpresa.
        //   Root (Aracely/Gerencia) pasa siempre.
        //   Cualquier rol autorizado por matriz pasa; los roles scoped por
        //   bodega (Alistador) mantienen el guard territorial.
        if (\App\Auth\Permisos::esRoot($u)) return;

        if (! \App\Auth\Permisos::puede($u, 'traslados')) {
            abort(403, 'No autorizado para operar traslados de inventario.');
        }

        // Alistador scoped por bodega. Si el helper no existe, no pasa.
        if (method_exists($u, 'esAlistador') && $u->esAlistador()) {
            $bodegas = method_exists($u, 'bodegasAsignadasIds')
                ? (array) $u->bodegasAsignadasIds()
                : [];
            if (in_array($origenId, $bodegas, true) && in_array($destinoId, $bodegas, true)) {
                return;
            }
            abort(403, 'Alistador sólo puede mover stock entre bodegas asignadas a su perfil.');
        }

        // Gerente y demás roles no scoped por bodega ya pasaron el filtro Permisos::puede.
    }

    // C-F2 R2 · guardTemporalContraAgregados() REMOVIDO — Actions ahora soportan
    // items agregados de forma nativa vía bifurcación por it->esAgregado().

}
