<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Contabilidad\Models\Caja;
use App\Modules\Contabilidad\Models\PlanCuenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las cajas y bancos donde está la plata.
 *
 * Las tablas existían desde el 2026-09-05 sin una línea construida encima, así
 * que no había forma de crear una caja. Esto es esa forma.
 *
 * Va bajo el acceso de `contabilidad` a propósito, en vez de inventar una
 * sección nueva: `PermisosSeeder` sólo reparte los permisos que nacen en su
 * propia corrida, así que una sección nueva exige acordarse de correrlo en el
 * despliegue —y el 2026-10-29 eso dejó a los siete roles de producción con
 * cero accesos durante semanas—. Quien lleva la contabilidad lleva las cajas.
 */
class CajasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'cajas'), 403);
                return $next($r);
            }),
        ];
    }

    /** Quien ve las cajas no necesariamente las crea o las edita. */
    protected function exigir(Request $r, string $accion): void
    {
        abort_unless(\App\Auth\Permisos::puedeAccion($r->user(), 'cajas', $accion), 403,
            'No tenés permiso para '.\App\Auth\Permisos::ACCIONES[$accion].' cajas.');
    }

    public function index(Request $r): Response
    {
        $cajas = Caja::with(['responsable:id,name', 'destinoCierre:id,codigo,nombre'])
            ->orderByRaw("FIELD(tipo,'menor','general','banco')")
            ->orderBy('codigo')
            ->get()
            ->map(fn (Caja $c) => [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'nombre' => $c->nombre,
                'tipo' => $c->tipo,
                'etiqueta_tipo' => $c->etiquetaTipo(),
                'se_arquea' => $c->seArquea(),
                'cuenta_puc' => $c->cuenta_puc,
                'saldo_inicial' => (float) $c->saldo_inicial,
                'activa' => (bool) $c->activa,
                'responsable' => $c->responsable?->name,
                'responsable_id' => $c->responsable_id,
                'destino_cierre_id' => $c->caja_destino_cierre_id,
                'destino_cierre' => $c->destinoCierre
                    ? "{$c->destinoCierre->codigo} · {$c->destinoCierre->nombre}"
                    : null,
            ]);

        return Inertia::render('Contabilidad/Cajas', [
            'cajas' => $cajas,
            'tipos' => collect(Caja::TIPOS)->map(fn ($t, $k) => ['valor' => $k, 'texto' => $t])->values(),
            // Sólo auxiliares: una caja que contabiliza contra una agrupadora
            // arma asientos que SIIGO devuelve rechazados.
            'cuentas' => PlanCuenta::query()
                ->where('permite_movimiento', true)->where('activa', true)
                ->orderBy('codigo')->limit(1000)
                ->get(['codigo', 'nombre'])
                ->map(fn ($c) => ['codigo' => $c->codigo, 'nombre' => "{$c->codigo} · {$c->nombre}"]),
            'personas' => User::orderBy('name')->get(['id', 'name']),
            // La pantalla esconde lo que el servidor va a rechazar igual: un
            // botón que da 403 es peor que un botón que no está.
            'puedo' => [
                'crear' => \App\Auth\Permisos::puedeAccion($r->user(), 'cajas', 'crear'),
                'editar' => \App\Auth\Permisos::puedeAccion($r->user(), 'cajas', 'editar'),
            ],
            // Destinos posibles para el cierre de una caja menor.
            'destinos' => Caja::activas()->whereIn('tipo', ['general', 'banco'])
                ->orderBy('codigo')->get(['id', 'codigo', 'nombre'])
                ->map(fn ($c) => ['id' => $c->id, 'nombre' => "{$c->codigo} · {$c->nombre}"]),
        ]);
    }

    public function guardar(Request $r): RedirectResponse
    {
        // Crear una caja nueva y editar una existente son permisos distintos:
        // abrir una caja en Bogotá es una decisión, corregirle el nombre no.
        $this->exigir($r, $r->filled('id') ? 'editar' : 'crear');

        $data = $r->validate([
            'id' => ['nullable', 'integer', 'exists:cajas,id'],
            'codigo' => ['required', 'string', 'max:20',
                Rule::unique('cajas', 'codigo')->ignore($r->input('id'))],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', Rule::in(array_keys(Caja::TIPOS))],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'cuenta_puc' => ['required', 'string', 'max:20',
                Rule::exists('plan_cuentas', 'codigo')
                    ->where('permite_movimiento', true)->where('activa', true)],
            'saldo_inicial' => ['nullable', 'numeric', 'min:0'],
            'caja_destino_cierre_id' => ['nullable', 'integer', 'exists:cajas,id'],
            'activa' => ['boolean'],
        ], [
            'cuenta_puc.exists' => 'Esa cuenta no existe o es una agrupadora que no admite movimiento. '
                .'Hay que usar la auxiliar que cuelga de ella.',
            'codigo.unique' => 'Ya hay una caja con ese código.',
        ]);

        $data['saldo_inicial'] ??= 0;
        $data['activa'] ??= true;

        if ($falta = $this->faltaParaGuardar($data)) {
            return back()->withErrors($falta)->withInput();
        }

        if (! empty($data['id'])) {
            Caja::whereKey($data['id'])->update(collect($data)->except('id')->all());
            $msg = "Caja «{$data['nombre']}» actualizada.";
        } else {
            Caja::create(collect($data)->except('id')->all());
            $msg = "Caja «{$data['nombre']}» creada.";
        }

        return back()->with('flash', ['type' => 'success', 'message' => $msg]);
    }

    /**
     * Lo que le falta a una caja para tener sentido, o null si está bien.
     *
     * Una caja menor sin destino de cierre es una caja que no se puede cerrar:
     * el arqueo del día la cuadra y después el saldo no tiene a dónde ir. Y
     * una caja no puede cerrarse contra sí misma, que suena absurdo hasta que
     * alguien edita el formulario con la lista abierta y se elige sola.
     */
    protected function faltaParaGuardar(array $d): ?array
    {
        if ($d['tipo'] === 'menor' && empty($d['caja_destino_cierre_id'])) {
            return ['caja_destino_cierre_id' => 'Una caja menor se cierra contra otra caja: '
                .'elegí a dónde va el saldo del día.'];
        }

        if (! empty($d['id']) && (int) ($d['caja_destino_cierre_id'] ?? 0) === (int) $d['id']) {
            return ['caja_destino_cierre_id' => 'Una caja no puede cerrarse contra sí misma.'];
        }

        return null;
    }

    /**
     * Prender y apagar una caja desde la lista.
     *
     * No se borran: una caja con arqueos detrás es historia contable. Apagarla
     * la saca de los selectores sin perder lo que pasó por ella.
     *
     * El estado se calcula acá y no se recibe, para que una pantalla vieja no
     * pise el cambio de otra persona.
     */
    public function alternar(Request $r, Caja $caja): RedirectResponse
    {
        // Apagar una caja la saca de todos los selectores: es edición.
        $this->exigir($r, 'editar');

        if ($caja->activa) {
            $dependen = Caja::where('caja_destino_cierre_id', $caja->id)->activas()->count();
            if ($dependen > 0) {
                return back()->with('flash', ['type' => 'error',
                    'message' => "No se puede apagar «{$caja->nombre}»: hay {$dependen} caja(s) "
                        .'que cierran contra ella. Cambiales el destino primero.']);
            }
        }

        $caja->activa = ! $caja->activa;
        $caja->save();

        return back()->with('flash', [
            'type' => 'success',
            'message' => $caja->activa
                ? "«{$caja->nombre}» quedó activa."
                : "«{$caja->nombre}» quedó inactiva: deja de aparecer para nuevos movimientos.",
        ]);
    }
}
