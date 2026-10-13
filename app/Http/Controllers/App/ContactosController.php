<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Contacto;
use App\Modules\Cartera\Enums\EstadoFactura;
use App\Modules\Cartera\Models\FacturaVenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class ContactosController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                abort_unless($r->user()?->esContable(), 403);  // A1/A3 FIX · unificado con sidebar (Contador/Gerente)
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        $q = trim((string) $request->input('q', ''));
        $rol = (string) $request->input('rol', 'todos'); // todos|cliente|b2b|proveedor|empleado|vendedor

        $query = Contacto::query();

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('nombre_completo', 'like', "%{$q}%")
                    ->orWhere('numero_documento', 'like', "%{$q}%")
                    ->orWhere('telefono', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        match ($rol) {
            'cliente' => $query->where('es_cliente', true),
            'b2b' => $query->where('es_cliente_b2b', true),
            'proveedor' => $query->where('es_proveedor', true),
            'empleado' => $query->where('es_empleado', true),
            'vendedor' => $query->where('es_vendedor_dropi', true),
            default => null,
        };

        $paginado = $query->orderBy('nombre_completo')->paginate(30);

        return Inertia::render('Cartera/Contactos/Index', [
            'contactos' => $paginado->through(fn ($c) => [
                'id' => $c->id,
                'nombre' => $c->nombre_completo,
                'documento' => trim(($c->tipo_documento ?? '') . ' ' . ($c->numero_documento ?? '')),
                'telefono' => $c->telefono,
                'email' => $c->email,
                'ciudad' => $c->ciudad,
                'activo' => (bool) $c->activo,
                'roles' => array_values(array_filter([
                    $c->es_cliente ? 'Cliente' : null,
                    $c->es_cliente_b2b ? 'B2B' : null,
                    $c->es_proveedor ? 'Proveedor' : null,
                    $c->es_empleado ? 'Empleado' : null,
                    $c->es_vendedor_dropi ? 'Vendedor Dropi' : null,
                ])),
            ])->withQueryString(),
            'filtros' => ['q' => $q, 'rol' => $rol],
            'totales' => [
                'todos' => Contacto::count(),
                'cliente' => Contacto::where('es_cliente', true)->count(),
                'b2b' => Contacto::where('es_cliente_b2b', true)->count(),
                'proveedor' => Contacto::where('es_proveedor', true)->count(),
                'empleado' => Contacto::where('es_empleado', true)->count(),
                'vendedor' => Contacto::where('es_vendedor_dropi', true)->count(),
            ],
        ]);
    }

    /** API JSON — buscador rápido (para modales/autocompletes) */
    public function buscarApi(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $scope = (string) $request->input('scope', 'contactos');
        if (strlen($q) < 2) return response()->json([]);

        // H1 · scope=facturas: busca por número/prefijo de factura O por nombre/doc
        // del cliente y devuelve pares {factura_id, numero, contacto, saldo}.
        // Usado por Cobranzas Index para autocomplete sin ID numérico expuesto.
        if ($scope === 'facturas') {
            $rows = FacturaVenta::query()
                ->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])
                ->where(function ($qq) use ($q) {
                    $qq->where('numero', 'like', "%{$q}%")
                        ->orWhereHas('contacto', function ($c) use ($q) {
                            $c->where('nombre_completo', 'like', "%{$q}%")
                              ->orWhere('numero_documento', 'like', "%{$q}%");
                        });
                })
                ->with('contacto:id,nombre_completo')
                ->orderByDesc('fecha_emision')
                ->limit(10)
                ->get(['id', 'numero', 'contacto_id', 'saldo', 'fecha_vencimiento'])
                ->map(fn ($f) => [
                    'factura_id' => $f->id,
                    'numero' => $f->numero,
                    'contacto' => $f->contacto?->nombre_completo ?? '—',
                    'saldo' => (float) $f->saldo,
                    'vence' => optional($f->fecha_vencimiento)->toDateString(),
                ])->values();
            return response()->json($rows);
        }

        return response()->json(
            Contacto::where(function ($qq) use ($q) {
                $qq->where('nombre_completo', 'like', "%{$q}%")
                    ->orWhere('numero_documento', 'like', "%{$q}%");
            })->limit(10)->get(['id', 'nombre_completo as nombre', 'numero_documento as documento'])
        );
    }

    public function show(Contacto $contacto): Response
    {
        $facturas = FacturaVenta::where('contacto_id', $contacto->id)
            ->orderByDesc('fecha_emision')->limit(20)
            ->get(['id', 'numero', 'fecha_emision', 'fecha_vencimiento', 'total', 'saldo', 'estado']);

        $saldoTotal = FacturaVenta::where('contacto_id', $contacto->id)
            ->whereNotIn('estado', [EstadoFactura::Pagada, EstadoFactura::Anulada])->sum('saldo');

        $facturadoTotal = FacturaVenta::where('contacto_id', $contacto->id)
            ->where('estado', '!=', EstadoFactura::Anulada)->sum('total');

        return Inertia::render('Cartera/Contactos/Show', [
            'contacto' => [
                'id' => $contacto->id,
                'nombre' => $contacto->nombre_completo,
                'razon_social' => $contacto->razon_social,
                'documento' => trim(($contacto->tipo_documento ?? '') . ' ' . ($contacto->numero_documento ?? '')),
                'telefono' => $contacto->telefono,
                'email' => $contacto->email,
                'direccion' => $contacto->direccion,
                'ciudad' => $contacto->ciudad,
                'departamento' => $contacto->departamento,
                'regimen_iva' => $contacto->regimen_iva,
                'activo' => (bool) $contacto->activo,
                'roles' => [
                    'cliente' => (bool) $contacto->es_cliente,
                    'b2b' => (bool) $contacto->es_cliente_b2b,
                    'proveedor' => (bool) $contacto->es_proveedor,
                    'empleado' => (bool) $contacto->es_empleado,
                    'vendedor' => (bool) $contacto->es_vendedor_dropi,
                ],
                'siigo_id' => $contacto->siigo_id,
                'siigo_sync_at' => $contacto->siigo_sync_at?->toIso8601String(),
                // Cartera de clientes · quién atiende la cuenta.
                'vendedor_id' => $contacto->vendedor_id,
                'vendedor' => $contacto->vendedor?->name,
            ],
            // Sólo gerencia reasigna. Un Vendedor entra acá con `ver.contactos`
            // y si pudiera cambiar este campo se quedaría la cuenta —y la
            // comisión— de un compañero.
            'puede_asignar_vendedor' => \App\Auth\Permisos::esRoot(request()->user()),
            'vendedores' => \App\Auth\Permisos::esRoot(request()->user())
                ? \App\Models\User::whereHas('roles', fn ($q) => $q->where('name', 'Vendedor'))
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name])
                : [],
            'facturas' => $facturas->map(fn ($f) => [
                'id' => $f->id,
                'numero' => $f->numero,
                'fecha' => $f->fecha_emision?->toDateString(),
                'vence' => $f->fecha_vencimiento?->toDateString(),
                'total' => (float) $f->total,
                'saldo' => (float) $f->saldo,
                'estado' => $f->estado?->value ?? (string) $f->estado,
                'estado_label' => is_object($f->estado) && method_exists($f->estado, 'label') ? $f->estado->label() : (string) $f->estado,
                'estado_color' => is_object($f->estado) && method_exists($f->estado, 'color') ? $f->estado->color() : 'gray',
            ]),
            'metricas' => [
                'saldo_pendiente' => (float) $saldoTotal,
                'facturado_historico' => (float) $facturadoTotal,
                'facturas_count' => (int) FacturaVenta::where('contacto_id', $contacto->id)->count(),
            ],
        ]);
    }

    /**
     * Crear o editar un contacto.
     *
     * Vivía sólo en el panel Filament. Al dejar `/admin` para Dropi el ERP se
     * quedaba sin forma de dar de alta un cliente o un proveedor, que es de lo
     * primero que se hace cuando entra una cuenta nueva.
     */
    public function form(Request $r, ?Contacto $contacto = null): Response
    {
        return Inertia::render('Cartera/Contactos/Form', [
            'contacto' => $contacto?->exists ? [
                'id' => $contacto->id,
                'tipo_documento' => $contacto->tipo_documento,
                'numero_documento' => $contacto->numero_documento,
                'nombre_completo' => $contacto->nombre_completo,
                'razon_social' => $contacto->razon_social,
                'email' => $contacto->email,
                'telefono' => $contacto->telefono,
                'direccion' => $contacto->direccion,
                'ciudad' => $contacto->ciudad,
                'departamento' => $contacto->departamento,
                'es_cliente' => (bool) $contacto->es_cliente,
                'es_cliente_b2b' => (bool) $contacto->es_cliente_b2b,
                'es_proveedor' => (bool) $contacto->es_proveedor,
                'es_empleado' => (bool) $contacto->es_empleado,
                'regimen_iva' => $contacto->regimen_iva,
                'retenciones_default' => (array) ($contacto->retenciones_default ?? []),
                'lista_precios_id' => $contacto->lista_precios_id,
                'activo' => (bool) $contacto->activo,
            ] : null,
            // Primero las listas de Great Baby, después las que llegaron del
            // catálogo de SIIGO. Ordenadas sólo por nombre, el desplegable
            // abría en «14.999» y «Asistente Gerente» —listas de otra empresa
            // del sandbox compartido— y las propias (Mayorista, Detal,
            // Distribuidor) quedaban enterradas. Es el mismo problema que tenía
            // el selector de bodega del importador de inventario.
            // Se muestra cuántos productos tiene cada lista con precio vigente.
            //
            // Sin ese número, elegir «Mayorista» parece lo obvio para un
            // cliente B2B — y hoy esa lista tiene 2 productos, mientras que
            // Detal, Dropi y Distribuidor están en cero. El cliente queda
            // asignado a una lista vacía y el vendedor descubre el problema
            // recién cuando le busca mercancía y no le aparece nada.
            'listas' => \App\Modules\Catalogo\Models\ListaPrecios::query()
                ->withCount(['precios as con_precio_count' => fn ($q) => $q
                    ->where(fn ($w) => $w->whereNull('vigente_hasta')
                        ->orWhere('vigente_hasta', '>=', now()->toDateString()))])
                ->orderByRaw('siigo_id IS NOT NULL')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'siigo_id'])
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'nombre' => $l->nombre,
                    'productos' => (int) $l->con_precio_count,
                    'grupo' => $l->siigo_id ? 'Listas del catálogo de SIIGO' : 'Listas de Great Baby',
                ]),

            // La ciudad era texto libre. SIIGO no acepta el nombre: pide el
            // código DANE, y cuando el ERP no reconoce lo escrito factura con
            // la ciudad por defecto (Bogotá) y sólo lo anota en el log. Así
            // que un «Medellin» sin tilde salía bien, pero un «Mosquera,
            // Cund.» o un «Bogota D.C» mal tecleado emitía la factura con la
            // ciudad equivocada sin que nadie se enterara. Ahora el formulario
            // ofrece las que el ERP sabe traducir y avisa si la escrita no
            // está — avisa, no bloquea: puede haber un cliente en un municipio
            // que todavía no figura en la tabla.
            'ciudades_dane' => \App\Modules\Siigo\Support\CiudadesDane::listado(),
        ]);
    }

    public function guardar(Request $r, ?Contacto $contacto = null): RedirectResponse
    {
        $existe = $contacto?->exists ?? false;

        $datos = $r->validate([
            'tipo_documento' => ['required', 'in:CC,CE,NIT,PP'],
            'numero_documento' => ['required', 'string', 'max:30',
                \Illuminate\Validation\Rule::unique('contactos', 'numero_documento')->ignore($contacto?->id)],
            'nombre_completo' => ['required', 'string', 'max:180'],
            'razon_social' => ['nullable', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'ciudad' => ['nullable', 'string', 'max:80'],
            'departamento' => ['nullable', 'string', 'max:80'],
            'es_cliente' => ['boolean'],
            'es_cliente_b2b' => ['boolean'],
            'es_proveedor' => ['boolean'],
            'es_empleado' => ['boolean'],
            'regimen_iva' => ['nullable', 'in:responsable,no_responsable'],
            // Qué nos retiene este cliente al pagar (AplicarRetencionesVenta).
            'retenciones_default' => ['nullable', 'array:fuente,ica,iva'],
            'retenciones_default.*' => ['boolean'],
            'lista_precios_id' => ['nullable', 'integer', 'exists:listas_precios,id'],
            'activo' => ['boolean'],
        ], [
            'numero_documento.unique' => 'Ya existe un contacto con ese documento.',
        ]);

        // Un cliente B2B sin lista de precios no se puede facturar ni se le
        // puede armar un pedido: el armador del vendedor lo filtra justamente
        // por `lista_precios_id`. Mejor avisar acá que dejarlo invisible.
        if (! empty($datos['es_cliente_b2b']) && empty($datos['lista_precios_id'])) {
            return back()->withInput()->with('error',
                'Un cliente B2B necesita lista de precios: sin ella no aparece para armarle pedidos.');
        }

        if ($existe) {
            $contacto->update($datos);
        } else {
            $contacto = Contacto::create($datos);
        }

        return redirect('/app/contactos/'.$contacto->id)->with('success',
            $existe ? 'Contacto actualizado.' : 'Contacto creado.');
    }

    /**
     * Asigna (o libera) el vendedor dueño de la cuenta.
     *
     * Vacío = cliente libre: lo toma el primer vendedor que le venda. Esto
     * decide quién puede levantarle pedidos y, por lo tanto, de quién es la
     * comisión, así que queda reservado a gerencia.
     */
    public function asignarVendedor(Request $r, Contacto $contacto): \Illuminate\Http\RedirectResponse
    {
        abort_unless(\App\Auth\Permisos::esRoot($r->user()), 403,
            'Sólo gerencia reasigna la cartera de clientes.');

        $datos = $r->validate([
            'vendedor_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $vendedorId = $datos['vendedor_id'] ?? null;

        // Que no se le asigne la cuenta a alguien que no es vendedor: quedaría
        // bloqueada para todos (nadie podría levantarle un pedido).
        if ($vendedorId !== null) {
            $u = \App\Models\User::find($vendedorId);
            abort_unless($u?->hasRole('Vendedor'), 422,
                'Ese usuario no tiene el rol Vendedor.');
        }

        $contacto->forceFill(['vendedor_id' => $vendedorId])->save();

        return back()->with('success', $vendedorId
            ? 'Cuenta asignada a ' . \App\Models\User::find($vendedorId)->name . '.'
            : 'Cuenta liberada: la toma el primer vendedor que le venda.');
    }
}
