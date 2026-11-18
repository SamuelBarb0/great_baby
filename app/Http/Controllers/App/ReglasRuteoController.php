<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Portal\Models\PedidoCliente;
use App\Modules\Portal\Models\ReglaRuteoCiudad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * De qué bodega sale cada ciudad.
 *
 * La tabla existe desde octubre y nunca tuvo pantalla, así que estaba vacía.
 * Las consecuencias no se veían de frente pero costaban todos los días:
 *
 *   · sin regla, el pedido nace sin bodega de origen;
 *   · sin bodega de origen no se aparta mercancía, y el sistema lo dice en un
 *     aviso que nadie leía: la misma caja se le seguía ofreciendo a otros
 *     pedidos y aparecía vendida dos veces el día del alistamiento;
 *   · y la cola de alistamiento filtra por bodega, así que esos pedidos los
 *     veía Gerencia y NUNCA bodega. En la base de pruebas eran 4 de 6.
 *
 * Mirar la pantalla la puede cualquiera que administre bodegas; cambiarla es
 * de Gerencia, porque decide el reparto entre TODAS las sedes y no sólo la
 * propia. Se usan las puertas que ya existen para no depender del sembrador
 * de permisos, que sigue pendiente de correr en producción.
 */
class ReglasRuteoController extends Controller
{
    private function puedeMirar(Request $r): void
    {
        abort_unless(
            \App\Auth\Permisos::puede($r->user(), 'ubicaciones'),
            403,
            'Esta pantalla es de quien administra las bodegas.'
        );
    }

    private function puedeCambiar(Request $r): void
    {
        abort_unless(
            \App\Auth\Permisos::esRoot($r->user()),
            403,
            'Cambiar el ruteo decide de qué sede sale cada ciudad: lo hace Gerencia.'
        );
    }

    public function index(Request $r): Response
    {
        $this->puedeMirar($r);

        $reglas = ReglaRuteoCiudad::with('ubicacion:id,codigo,nombre')
            ->orderBy('prioridad')->orderBy('id')->get();

        $hayComodin = $reglas->contains(fn ($x) => $x->ciudad === '*' && $x->activa);

        return Inertia::render('Logistica/ReglasRuteo', [
            'reglas' => $reglas->map(fn (ReglaRuteoCiudad $x) => [
                'id' => $x->id,
                'ciudad' => $x->ciudad,
                'es_comodin' => $x->ciudad === '*',
                'ubicacion_id' => $x->ubicacion_id,
                'bodega' => $x->ubicacion?->nombre,
                'bodega_codigo' => $x->ubicacion?->codigo,
                'prioridad' => $x->prioridad,
                'activa' => $x->activa,
                'notas' => $x->notas,
            ])->values(),

            'bodegas' => $this->bodegasOfrecibles(),

            /*
             * Sin comodín, toda ciudad que no tenga regla propia cae al vacío.
             * Se dice con número y no con una advertencia genérica: «hay 4
             * pedidos así» se entiende, «configure el ruteo» no.
             */
            'hay_comodin' => $hayComodin,
            'ciudades_sin_regla' => $this->ciudadesSinRegla($reglas),
            'pedidos_sin_bodega' => PedidoCliente::whereNull('ubicacion_origen_id')
                ->whereIn('estado', ['enviado', 'retenido', 'aprobado', 'facturado'])
                ->count(),
            'puede_cambiar' => \App\Auth\Permisos::esRoot($r->user()),
        ]);
    }

    /**
     * Las bodegas que tiene sentido ofrecer como origen.
     *
     * De las 55 filas de ubicaciones, 43 son espejos del catálogo compartido
     * de SIIGO —«TK0007UREA», «Miyuki Test», «Bodega pruebas luis»— y muchas
     * son de otras empresas, porque el sandbox se comparte. Rutear una ciudad
     * a una de ésas dejaría los pedidos en una bodega que no existe en el
     * piso. Se ofrecen las sedes propias y vendibles.
     */
    private function bodegasOfrecibles(): array
    {
        return InventarioUbicacion::query()
            ->whereNull('bodega_id')
            ->where('activa', true)
            ->where('disponible_para_venta', true)
            ->where('codigo', 'not like', 'SIIGO-%')
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'label' => "{$b->codigo} · {$b->nombre}",
            ])->all();
    }

    /**
     * Ciudades de clientes que hoy no matchean ninguna regla.
     *
     * Es la lista con la que se arma el ruteo la primera vez: en vez de
     * adivinar, se ve de dónde vienen los pedidos de verdad y se decide.
     */
    private function ciudadesSinRegla($reglas): array
    {
        // La misma normalización del resolvedor —sin mayúsculas y sin tildes—
        // o la pantalla dice que «Medellín» está cubierta mientras el pedido
        // de «Medellin» nace sin bodega.
        $cubiertas = $reglas->where('activa', true)
            ->reject(fn ($x) => $x->ciudad === '*')
            ->map(fn ($x) => ReglaRuteoCiudad::normalizar($x->ciudad))
            ->all();

        /*
         * El tope se aplica DESPUÉS de descartar las cubiertas.
         *
         * Cortando a 40 antes, si las 40 ciudades con más clientes ya tenían
         * regla, la lista salía vacía —«no falta nada»— habiendo ciudades sin
         * cubrir detrás. Y esta lista es justamente con la que se arma el
         * ruteo la primera vez.
         */
        return \App\Models\Contacto::query()
            ->where('es_cliente', true)
            ->whereNotNull('ciudad')
            ->where('ciudad', '<>', '')
            ->selectRaw('ciudad, COUNT(*) as clientes')
            ->groupBy('ciudad')
            ->orderByDesc('clientes')
            ->get()
            ->reject(fn ($c) => in_array(ReglaRuteoCiudad::normalizar($c->ciudad), $cubiertas, true))
            ->take(40)
            ->map(fn ($c) => ['ciudad' => $c->ciudad, 'clientes' => (int) $c->clientes])
            ->values()
            ->all();
    }

    public function guardar(Request $r): RedirectResponse
    {
        $this->puedeCambiar($r);

        $data = $r->validate([
            'id' => ['nullable', 'integer', 'exists:reglas_ruteo_ciudad,id'],
            'ciudad' => ['required', 'string', 'max:120'],
            'ubicacion_id' => ['required', 'integer', 'exists:inventario_ubicaciones,id'],
            'prioridad' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'activa' => ['boolean'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);

        $data['ciudad'] = trim($data['ciudad']);

        /*
         * Dos reglas activas para la misma ciudad son una trampa: gana la de
         * menor prioridad y la otra queda ahí, pareciendo que hace algo.
         */
        /*
         * El choque se busca en PHP y no con un LOWER() en SQL: la comparación
         * que importa es la que usa el resolvedor, y ésa ignora tildes. Con
         * `LOWER(TRIM(ciudad))` entraban «Medellin» y «Medellín» como dos
         * reglas distintas —así quedaron las dos que hay hoy en la base— y
         * nadie veía que estaban peleando por la misma ciudad.
         */
        $buscada = ReglaRuteoCiudad::normalizar($data['ciudad']);

        $repetida = ReglaRuteoCiudad::query()
            ->when($data['id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->get(['id', 'ciudad'])
            ->first(fn ($x) => ReglaRuteoCiudad::normalizar($x->ciudad) === $buscada);

        /*
         * Con `abort(422)` el mensaje NO llega a la pantalla: Inertia espera la
         * bolsa de errores de validación y un abort pelado le deja un cuerpo
         * que no sabe leer, así que el modal se queda abierto sin decir nada y
         * la persona vuelve a pulsar Guardar. Se devuelve como error de campo,
         * que es el patrón que el resto del sistema ya usa.
         */
        if ($repetida !== null) {
            return back()->withErrors([
                'ciudad' => "Ya hay una regla para «{$data['ciudad']}». Editá esa en vez de crear otra.",
            ]);
        }

        // Rutear a un rack en vez de a la bodega dejaría el pedido colgando de
        // un estante: la cola y la reserva razonan con la sede.
        $destino = InventarioUbicacion::findOrFail($data['ubicacion_id']);

        if ($destino->bodega_id !== null) {
            return back()->withErrors([
                'ubicacion_id' => "«{$destino->nombre}» es una posición dentro de otra bodega. "
                    .'El origen de un pedido es la bodega.',
            ]);
        }

        /*
         * La prioridad vacía no es un null: es «la de siempre».
         *
         * La columna es `NOT NULL` con default 100, y la casilla del formulario
         * es opcional —`v-model.number` deja cadena vacía, que Laravel convierte
         * a null antes de validar—. Como la regla dice `nullable`, ese null
         * pasaba la validación entera y moría en el INSERT con un 500: la
         * persona dejaba un campo opcional en blanco y el sistema se caía sin
         * decir nada que se entienda. Se quita del arreglo: en una regla nueva
         * manda el default de la columna, y en una que se edita se respeta la
         * que ya tenía.
         */
        if (array_key_exists('prioridad', $data) && $data['prioridad'] === null) {
            unset($data['prioridad']);
        }

        $regla = ReglaRuteoCiudad::findOrNew($data['id'] ?? null);
        $regla->fill($data)->save();

        return back()->with('success', "«{$regla->ciudad}» sale de {$destino->nombre}.");
    }

    public function alternar(Request $r, int $regla): RedirectResponse
    {
        $this->puedeCambiar($r);

        $fila = ReglaRuteoCiudad::findOrFail($regla);
        $fila->update(['activa' => ! $fila->activa]);

        return back()->with('success',
            $fila->activa ? "«{$fila->ciudad}» quedó activa." : "«{$fila->ciudad}» quedó apagada.");
    }

    public function eliminar(Request $r, int $regla): RedirectResponse
    {
        $this->puedeCambiar($r);

        $fila = ReglaRuteoCiudad::findOrFail($regla);
        $ciudad = $fila->ciudad;
        $fila->delete();

        /*
         * Borrar no reescribe el pasado: los pedidos que ya salieron con esa
         * bodega la conservan. Cambiarles el origen movería stock de una sede
         * a otra sin que nadie lo hubiera despachado.
         */
        return back()->with('success',
            "Regla de «{$ciudad}» eliminada. Los pedidos que ya se rutearon no cambian.");
    }
}
