<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Models\Almacen;
use App\Modules\Inventario\Models\Oficina;
use App\Modules\Inventario\Support\UbicacionDelPar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El catálogo con el que clasifica la bodega: oficinas y almacenes.
 *
 * Existe porque el área entregó las dos tablas por escrito (17-nov-2026) y en
 * el mismo documento dejó dicho que van a cambiar. Quemarlas en un enum
 * obligaría a un despliegue por cada ajuste; acá las edita quien administra
 * bodegas, y las reglas —qué par admite ingreso directo— viajan en la fila.
 *
 * Usa la casilla `ubicaciones`, la misma de la pantalla de bodegas, a
 * propósito: una casilla nueva exigiría correr el sembrador de permisos antes
 * de que nadie pudiera abrir la pantalla, y ese sembrador todavía está
 * pendiente en producción.
 */
class CatalogoBodegaController extends Controller
{
    private function puerta(Request $r): void
    {
        abort_unless(
            \App\Auth\Permisos::puede($r->user(), 'ubicaciones'),
            403,
            'Esta pantalla es de quien administra las bodegas.'
        );
    }

    /**
     * Mirar el catálogo es una cosa; cambiarlo es otra.
     *
     * Las tres escrituras de esta pantalla deciden reglas que valen para
     * TODAS las sedes: qué par admite mercancía directo del contenedor, qué
     * almacén es vendible y qué bodega representa a qué par. Con la casilla
     * de mirar alcanzaba, y eso dejaba que un jefe de bodega marcara
     * «comercial» el almacén de BAJAS —y la mercancía dada de baja volvía a
     * ofrecerse al cliente— o que le cambiara el par a la bodega de otra
     * sede. Es la misma puerta que la pantalla hermana de ruteo, que nació el
     * mismo día: la decide Gerencia.
     */
    private function puertaDeEscritura(Request $r): void
    {
        abort_unless(
            \App\Auth\Permisos::esRoot($r->user()),
            403,
            'Cambiar el catálogo fija reglas para todas las sedes: lo hace Gerencia.'
        );
    }

    public function index(Request $r): Response
    {
        $this->puerta($r);

        $par = app(UbicacionDelPar::class);

        $oficinas = Oficina::with('empresa:id,nombre_comercial')
            ->orderBy('orden')->orderBy('codigo')->get();

        $almacenes = Almacen::orderBy('orden')->orderBy('codigo')->get();

        /*
         * Las bodegas de verdad: las que no cuelgan de otra. Los racks heredan
         * el par de su bodega, así que mapear rack por rack sería trabajo de
         * más y una fuente de contradicciones.
         */
        $bodegas = InventarioUbicacion::query()
            ->whereNull('bodega_id')
            ->withCount('movimientos')
            ->orderByRaw("codigo LIKE 'SIIGO-%'")
            ->orderBy('codigo')
            ->get();

        return Inertia::render('Inventario/CatalogoBodega', [
            'oficinas' => $oficinas->map(fn (Oficina $o) => [
                'id' => $o->id,
                'codigo' => $o->codigo,
                'nombre' => $o->nombre,
                'permite_ingreso_directo' => $o->permite_ingreso_directo,
                'es_respaldo_surtido' => $o->es_respaldo_surtido,
                'activa' => $o->activa,
                'empresa' => $o->empresa?->nombre_comercial,
                'observacion' => $o->observacion,
                'ubicaciones' => $o->ubicaciones()->count(),
            ])->values(),

            'almacenes' => $almacenes->map(fn (Almacen $a) => [
                'id' => $a->id,
                'codigo' => $a->codigo,
                'nombre' => $a->nombre,
                'estado_fisico' => $a->estado_fisico,
                'comercial' => $a->comercial,
                'permite_ingreso_directo' => $a->permite_ingreso_directo,
                'categoria_equivalente' => $a->categoria_equivalente,
                'activo' => $a->activo,
                'observacion' => $a->observacion,
                'ubicaciones' => $a->ubicaciones()->count(),
            ])->values(),

            'bodegas' => $bodegas->map(fn (InventarioUbicacion $b) => [
                'id' => $b->id,
                'codigo' => $b->codigo,
                'nombre' => $b->nombre,
                'oficina_id' => $b->oficina_id,
                'almacen_id' => $b->almacen_id,
                'activa' => $b->activa,
                'movimientos' => $b->movimientos_count,
                // Las importadas de SIIGO son 43 de 55 y casi ninguna se usa:
                // se marcan para que la pantalla las pueda esconder.
                'de_siigo' => str_starts_with((string) $b->codigo, 'SIIGO-'),
            ])->values(),

            'categorias' => collect(CategoriaUbicacion::cases())->map(fn ($c) => [
                'valor' => $c->value,
                'label' => $c->label(),
                'vendible' => $c->disponibleParaVenta(),
            ])->values(),

            /*
             * Qué pares se pueden recibir hoy, ya resueltos, para que la
             * pantalla muestre el efecto de lo que acaban de editar sin tener
             * que repetir la regla en JavaScript.
             */
            'paresDeIngreso' => $this->paresDeIngreso($par),

            // Para que la pantalla no ofrezca lo que el servidor va a negar.
            'puede_cambiar' => \App\Auth\Permisos::esRoot($r->user()),

            /*
             * Si las dos tablas están vacías no es que no haya nada que
             * mostrar: es que el sembrador del catálogo no se corrió. Decirlo
             * con el comando exacto, porque la pantalla sin esto se ve rota.
             */
            'sin_sembrar' => $oficinas->isEmpty() && $almacenes->isEmpty(),
        ]);
    }

    /** Las combinaciones que hoy admiten mercancía directo del contenedor. */
    private function paresDeIngreso(UbicacionDelPar $par): array
    {
        $pares = [];

        foreach (Oficina::deIngreso()->orderBy('orden')->get() as $o) {
            foreach (Almacen::deIngreso()->orderBy('orden')->get() as $a) {
                $ubic = $par->buscar($o, $a);
                $pares[] = [
                    'oficina' => $o->etiqueta(),
                    'almacen' => $a->etiqueta(),
                    'bodega' => $ubic?->nombre,
                    'bodega_codigo' => $ubic?->codigo,
                    // Sin bodega mapeada todavía no es un error: se crea sola
                    // la primera vez que alguien reciba contra ese par.
                    'se_creara' => $ubic === null,
                ];
            }
        }

        return $pares;
    }

    public function guardarOficina(Request $r): RedirectResponse
    {
        $this->puertaDeEscritura($r);

        $data = $r->validate([
            'id' => ['nullable', 'integer', 'exists:logistica_oficinas,id'],
            'codigo' => ['required', 'string', 'max:4', Rule::unique('logistica_oficinas', 'codigo')->ignore($r->input('id'))],
            'nombre' => ['required', 'string', 'max:80'],
            'permite_ingreso_directo' => ['boolean'],
            'es_respaldo_surtido' => ['boolean'],
            'empresa_config_id' => ['nullable', 'integer', 'exists:empresa_config,id'],
            'activa' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'observacion' => ['nullable', 'string', 'max:200'],
        ]);

        $oficina = Oficina::findOrNew($data['id'] ?? null);
        $oficina->fill($data)->save();

        /*
         * El respaldo de surtido es uno solo. Si marcan otro, el anterior se
         * apaga: dos «de dónde saco lo que me falta» dejarían al alistador sin
         * saber a cuál ir.
         */
        if ($oficina->es_respaldo_surtido) {
            Oficina::where('id', '!=', $oficina->id)->update(['es_respaldo_surtido' => false]);
        }

        return back()->with('success', "Oficina {$oficina->etiqueta()} guardada.");
    }

    public function guardarAlmacen(Request $r): RedirectResponse
    {
        $this->puertaDeEscritura($r);

        $data = $r->validate([
            'id' => ['nullable', 'integer', 'exists:logistica_almacenes,id'],
            'codigo' => ['required', 'string', 'max:4', Rule::unique('logistica_almacenes', 'codigo')->ignore($r->input('id'))],
            'nombre' => ['required', 'string', 'max:80'],
            'estado_fisico' => ['required', 'string', 'max:20'],
            'comercial' => ['boolean'],
            'permite_ingreso_directo' => ['boolean'],
            'categoria_equivalente' => ['required', Rule::in(array_column(CategoriaUbicacion::cases(), 'value'))],
            'activo' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'observacion' => ['nullable', 'string', 'max:200'],
        ]);

        /*
         * `comercial` y la categoría tienen que decir lo mismo.
         *
         * El motor viejo decide si una bodega vende por su categoría, y lo
         * hace en el hook de guardado del modelo: ahí `disponible_para_venta`
         * se recalcula desde `categoria`, pase lo que pase. Así que un almacén
         * marcado «comercial» con una categoría que no vende se guardaba sin
         * protestar y la bodega nacía NO vendible: el catálogo decía una cosa
         * y el inventario hacía otra, sin un solo aviso.
         *
         * No se corrige a escondidas: se pide elegir, porque las dos opciones
         * son razonables y la decisión es del área.
         */
        $categoria = CategoriaUbicacion::from($data['categoria_equivalente']);
        $comercial = (bool) ($data['comercial'] ?? false);

        if ($comercial !== $categoria->disponibleParaVenta()) {
            $vendibles = collect(CategoriaUbicacion::cases())
                ->filter(fn ($c) => $c->disponibleParaVenta())
                ->map(fn ($c) => $c->label())->implode(', ');

            return back()->withErrors([
                'comercial' => $comercial
                    ? "Marcaste «se vende» pero la categoría «{$categoria->label()}» no vende, "
                        ."y manda la categoría: elegí una de éstas — {$vendibles} — o destildá «se vende»."
                    : "La categoría «{$categoria->label()}» sí vende, así que la bodega iba a quedar "
                        .'vendible igual. Marcá «se vende», o elegí una categoría que no venda.',
            ]);
        }

        $almacen = Almacen::findOrNew($data['id'] ?? null);
        $almacen->fill($data)->save();

        return back()->with('success', "Almacén {$almacen->etiqueta()} guardado.");
    }

    /**
     * Le dice a una bodega que ya existe qué par representa.
     *
     * Es el paso que evita que el sistema cree una bodega «10-01» al lado de
     * la PRINCIPAL que ya tiene todo el stock. Partir el inventario en dos
     * filas que significan lo mismo es el daño más caro de este cambio, así
     * que se hace a mano y mirando.
     */
    public function mapear(Request $r): RedirectResponse
    {
        $this->puertaDeEscritura($r);

        $data = $r->validate([
            'bodega_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'oficina_id' => ['nullable', 'integer', 'exists:logistica_oficinas,id'],
            'almacen_id' => ['nullable', 'integer', 'exists:logistica_almacenes,id'],
        ]);

        $bodega = InventarioUbicacion::findOrFail($data['bodega_id']);

        // Con `abort(422)` el mensaje no llega a la pantalla en una petición de
        // Inertia: se devuelve como error de campo, que sí se pinta.
        if ($bodega->bodega_id !== null) {
            return back()->withErrors([
                'bodega_id' => 'Sólo se mapean bodegas. Un rack hereda el par de la bodega en la que vive.',
            ]);
        }

        $oficinaId = $data['oficina_id'] ?? null;
        $almacenId = $data['almacen_id'] ?? null;

        /*
         * Dos bodegas con el mismo par partirían el stock en dos sin que nadie
         * se entere: la recepción escribiría en una y el alistador buscaría en
         * la otra.
         */
        if ($oficinaId && $almacenId) {
            $choca = InventarioUbicacion::where('oficina_id', $oficinaId)
                ->where('almacen_id', $almacenId)
                ->where('id', '!=', $bodega->id)
                ->first();

            if ($choca !== null) {
                return back()->withErrors([
                    'bodega_id' => "Ese par ya es de «{$choca->nombre}» ({$choca->codigo}). "
                        .'Dos bodegas con la misma oficina y el mismo almacén partirían el stock en dos.',
                ]);
            }
        }

        $bodega->forceFill([
            'oficina_id' => $oficinaId,
            'almacen_id' => $almacenId,
        ])->save();

        $dicho = $oficinaId && $almacenId
            ? "{$bodega->nombre} quedó como "
                .Oficina::find($oficinaId)?->etiqueta().' / '.Almacen::find($almacenId)?->etiqueta()
            : "{$bodega->nombre} quedó sin par asignado.";

        return back()->with('success', $dicho);
    }
}
