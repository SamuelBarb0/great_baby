<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EntregaCargo;
use App\Modules\Rrhh\Models\EntregaCargoItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Fase F2 · La entrega de cargo y el paz y salvo.
 *
 * Es lo que evita que el conocimiento se vaya con la persona. El formato
 * recorre tres áreas y cada una firma lo suyo: el jefe dice qué hacía y dónde
 * quedan sus cosas, Gestión Humana recibe la dotación, y tesorería dice si
 * queda algo de plata.
 *
 * La puerta es ancha a propósito —entra quien pueda abrir cualquiera de los
 * tres bloques— porque es un documento compartido. Lo que SÍ es estrecho es
 * firmar: cada área sólo puede confirmar la suya.
 */
class EntregaCargoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $puedeAlguna = collect(EntregaCargo::AREAS)
                ->contains(fn (array $a) => Permisos::puede($r->user(), $a['permiso']));

            abort_unless($puedeAlguna, 403);

            return $next($r);
        })];
    }

    /**
     * Guarda la cabecera y las seis listas.
     *
     * Los renglones se reemplazan completos en vez de ir uno por uno: la
     * pantalla manda la lista entera y así borrar una fila es quitarla de la
     * lista, no acordarse de llamar a otro endpoint.
     */
    public function guardar(Request $r, int $empleado): RedirectResponse
    {
        $e = Empleado::findOrFail($empleado);

        $mios = EntregaCargo::bloquesQuePuedeEscribir($r->user());

        abort_if($mios === [], 403, 'No tenés ningún bloque de este formato a tu cargo.');

        $data = $r->validate([
            'fecha' => ['required', 'date'],
            'recibe_id' => ['nullable', 'integer', 'exists:users,id'],
            'recibe_nombre' => ['nullable', 'string', 'max:160'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            /*
             * Cada área escribe SUS bloques y nada más.
             *
             * Antes la validación aceptaba cualquier clave de `BLOQUES`: el
             * jefe podía reescribir el paz y salvo de tesorería y tesorería
             * las funciones del jefe. Firmar lo propio no sirve de nada si
             * otro puede cambiar lo que firmaste.
             */
            'items.*.bloque' => ['required', 'string', 'in:'.implode(',', $mios)],
            'items.*.nombre' => ['required', 'string', 'max:250'],
            'items.*.detalle' => ['nullable', 'string', 'max:250'],
        ], [], [
            // Sin artículo: lo pone el mensaje de `lang/es/validation.php`.
            'items.*.nombre' => 'renglón',
        ]);

        // Las conformidades que se caen se arman adentro y se leen afuera.
        $caidas = [];

        DB::transaction(function () use ($e, $data, $r, $mios, &$caidas) {
            $entrega = EntregaCargo::updateOrCreate(
                ['empleado_id' => $e->id],
                collect($data)->only(['fecha', 'recibe_id', 'recibe_nombre', 'observaciones'])->all(),
            );

            /*
             * Se reemplazan SÓLO los bloques de quien guarda.
             *
             * Antes se borraban todos los renglones y se reescribían con lo
             * que mandara la pantalla: quien tuviera cualquiera de los tres
             * permisos podía barrer el formato entero. Ahora lo de las otras
             * áreas ni se toca.
             */
            $entrega->items()->whereIn('bloque', $mios)->delete();

            $tocados = [];

            foreach ($data['items'] ?? [] as $i => $item) {
                if (blank($item['nombre'])) {
                    continue;
                }

                $tocados[] = $item['bloque'];

                EntregaCargoItem::create([
                    'entrega_id' => $entrega->id,
                    'bloque' => $item['bloque'],
                    'nombre' => $item['nombre'],
                    'detalle' => $item['detalle'] ?? null,
                    'orden' => $i,
                ]);
            }

            /*
             * Si cambió un bloque que ya estaba conforme, esa conformidad se
             * cae — y SÓLO esa.
             *
             * La conformidad dice «revisé esto»; si el texto cambia después,
             * deja de ser cierta. Las otras dos firmas se quedan: borrarlas
             * todas haría que nadie quisiera corregir nada después de la
             * primera firma, que es lo que el formato en papel viene a evitar.
             */
            $afectados = array_unique(array_merge($tocados, $mios));

            foreach ($afectados as $bloque) {
                $area = EntregaCargo::areaDelBloque($bloque);

                if ($area && $entrega->{"{$area}_confirmo_at"}) {
                    $entrega->update([
                        "{$area}_confirmo_at" => null,
                        "{$area}_confirmo_por_id" => null,
                    ]);
                    $caidas[$area] = EntregaCargo::AREAS[$area]['titulo'];
                }
            }

            $e->anotar('entrega_cargo', 'Entrega de cargo actualizada por '.$r->user()->name);
        });

        return back()->with('success', $caidas === []
            ? "Entrega de cargo de {$e->nombre} guardada."
            : "Entrega de cargo guardada. Se cayó la conformidad de "
                .implode(' y ', $caidas).': cambió lo que ya habían dado por bueno.');
    }

    /**
     * Firma el bloque de un área.
     *
     * Cada quien firma lo suyo y nada más: el jefe no puede dar por bueno el
     * paz y salvo de tesorería ni al revés. Es el control entero del formato.
     */
    public function confirmar(Request $r, int $empleado, string $area): RedirectResponse
    {
        abort_unless(array_key_exists($area, EntregaCargo::AREAS), 404);

        $def = EntregaCargo::AREAS[$area];

        abort_unless(Permisos::puede($r->user(), $def['permiso']), 403,
            "El bloque de {$def['titulo']} lo firma esa área.");

        $e = Empleado::findOrFail($empleado);
        $entrega = EntregaCargo::where('empleado_id', $e->id)->firstOrFail();

        /*
         * Tesorería no firma sobre una entrega vacía: si no hay conceptos ni
         * se dijo que no hay nada, el paz y salvo no dice nada.
         *
         * Las otras dos sí pueden firmar sin renglones —puede no haber
         * pendientes ni dotación—, y obligar a escribir «ninguno» sería hacer
         * ruido para cumplir.
         */
        if ($area === 'tesoreria' && $entrega->items->where('bloque', 'paz_salvo')->isEmpty()
            && blank($entrega->observaciones)) {
            return back()->withErrors([
                'tesoreria' => 'Antes de firmar, dejá al menos un concepto de paz y salvo '
                    .'o una observación que diga que no hay nada pendiente.',
            ]);
        }

        $entrega->update([
            "{$area}_confirmo_at" => now(),
            "{$area}_confirmo_por_id" => $r->user()->id,
        ]);

        $e->anotar('entrega_cargo_firma', "{$def['titulo']} dio conformidad a la entrega de cargo");

        $faltan = $entrega->fresh()->faltanFirmas();

        return back()->with('success', $faltan === []
            ? 'Entrega de cargo completa: las tres áreas dieron conformidad.'
            : 'Conformidad registrada. Falta: '.implode(', ', $faltan).'.');
    }

    /**
     * El impreso.
     *
     * Los equipos y la dotación salen de `rrhh_asignaciones`, no de acá: el
     * formato los separa igual que `Asignacion::TIPOS` —equipo y herramienta
     * en el bloque del jefe, dotación en el de Gestión Humana— y ahí ya están
     * con su serial y su estado de devolución.
     */
    public function pdf(Request $r, int $empleado)
    {
        $e = Empleado::with('asignaciones')->findOrFail($empleado);

        $entrega = EntregaCargo::with([
            'items', 'recibe:id,name',
            'jefeConfirmoPor:id,name', 'ghConfirmoPor:id,name', 'tesoreriaConfirmoPor:id,name',
        ])->where('empleado_id', $e->id)->firstOrFail();

        return Pdf::loadView('rrhh.pdf.entrega-cargo', [
            'e' => $e,
            'entrega' => $entrega,
            'bloques' => EntregaCargo::BLOQUES,
            // El formato parte los activos en dos bloques con firmas distintas.
            'equipos' => $e->asignaciones->whereIn('tipo', ['equipo', 'herramienta', 'otro']),
            'dotacion' => $e->asignaciones->where('tipo', 'dotacion'),
            'empresa' => \App\Models\EmpresaConfig::current(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->stream('entrega-cargo-'.str($e->nombre)->slug().'.pdf');
    }
}
