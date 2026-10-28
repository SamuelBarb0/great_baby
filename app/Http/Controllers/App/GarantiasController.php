<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\GarantiaTicket;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M7 · Servicio al Cliente y Garantías.
 * Estados: abierto → en_revision → aprobada|rechazada → en_reposicion → cerrada
 * Contrato: reposición a valor $0 al cliente.
 */
class GarantiasController extends Controller implements HasMiddleware
{
    public const ESTADOS = ['abierto', 'en_revision', 'aprobada', 'rechazada', 'en_reposicion', 'cerrada'];

    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            $u = $r->user();
            // C-F-QA5 · Fix CRÍTICO auditor roles: el rol se llama 'ServicioCliente' (no 'SAC').
            // Antes: hasAnyRole(['SAC','Gerente']) → ServicioCliente NUNCA pasaba.
            // La casilla manda. Antes la puerta preguntaba por el rol y la
            //   casilla de /app/roles no la consultaba nadie: el admin creia
            //   repartir accesos y solo movia lo que se ve en el menu.
            abort_unless(\App\Auth\Permisos::puede($u, 'servicio_cliente'), 403);
            return $next($r);
        })];
    }

    public function index(Request $request): Response
    {
        $estado = (string) $request->input('estado', '');
        $q = trim((string) $request->input('q', ''));

        $tickets = GarantiaTicket::with('creadoPor:id,name')
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            ->when($q, fn ($qb) => $qb->where(function ($w) use ($q) {
                $w->where('numero', 'like', "%{$q}%")
                    ->orWhere('cliente_nombre', 'like', "%{$q}%")
                    ->orWhere('cliente_telefono', 'like', "%{$q}%");
            }))
            ->orderByDesc('id')->paginate(30);

        $conteos = GarantiaTicket::selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado')->all();

        return Inertia::render('Garantias/Index', [
            'tickets' => $tickets->through(fn ($t) => [
                'id' => $t->id, 'numero' => $t->numero,
                'cliente' => $t->cliente_nombre, 'telefono' => $t->cliente_telefono,
                'estado' => $t->estado, 'cantidad' => (int) $t->cantidad,
                'creado' => $t->created_at?->format('Y-m-d H:i'),
                'creador' => $t->creadoPor?->name,
                'descripcion' => $t->descripcion_falla,
                'valor' => (float) $t->valor_reposicion,
            ]),
            'conteos' => $conteos,
            'filtros' => ['estado' => $estado ?: null, 'q' => $q ?: null],
        ]);
    }

    public function form(): Response
    {
        return Inertia::render('Garantias/Nueva', [
            'estados' => self::ESTADOS,
        ]);
    }

    public function crear(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['nullable', 'string', 'max:30'],
            'pedido_guia' => ['nullable', 'string', 'max:50'],
            'variante_id' => ['nullable', 'integer'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'descripcion_falla' => ['required', 'string', 'min:10', 'max:1000'],
            'fotos' => ['nullable', 'array', 'max:5'],
            'fotos.*' => ['file', 'image', 'max:5120'],
        ]);

        $pedidoId = null;
        if (! empty($data['pedido_guia'])) {
            $pedidoId = DropiPedido::where('guia', $data['pedido_guia'])->value('id');
        }

        // Consecutivo GT-YYMMDD-###
        $numero = 'GT-' . now()->format('ymd') . '-' . str_pad((string) (GarantiaTicket::whereDate('created_at', today())->count() + 1), 3, '0', STR_PAD_LEFT);

        $fotos = [];
        if ($r->hasFile('fotos')) {
            foreach ($r->file('fotos') as $f) {
                $fotos[] = $f->store('garantias', 'public');
            }
        }

        $t = GarantiaTicket::create([
            'numero' => $numero,
            'cliente_nombre' => $data['cliente_nombre'],
            'cliente_telefono' => $data['cliente_telefono'] ?? null,
            'pedido_original_id' => $pedidoId,
            'variante_id' => $data['variante_id'] ?? null,
            'cantidad' => $data['cantidad'],
            'descripcion_falla' => $data['descripcion_falla'],
            'fotos_evidencia' => $fotos,
            'estado' => 'abierto',
            'creado_por' => auth()->id(),
            'plazo_concepto_at' => now()->addDays(3), // 3 días para dar concepto
        ]);

        return redirect()->route('app.garantias.show', $t->id)->with('success', "Garantía {$numero} creada.");
    }

    public function show(int $ticket): Response
    {
        $t = GarantiaTicket::with(['pedidoOriginal:id,guia,cliente_nombre', 'creadoPor:id,name', 'decidioPor:id,name', 'variante.producto'])
            ->findOrFail($ticket);

        return Inertia::render('Garantias/Show', [
            'ticket' => [
                'id' => $t->id, 'numero' => $t->numero,
                'cliente_nombre' => $t->cliente_nombre, 'cliente_telefono' => $t->cliente_telefono,
                'cantidad' => (int) $t->cantidad,
                'descripcion_falla' => $t->descripcion_falla,
                'notas_decision' => $t->notas_decision,
                'estado' => $t->estado,
                'creado' => $t->created_at?->format('Y-m-d H:i'),
                'creador' => $t->creadoPor?->name,
                'decidio_por' => $t->decidioPor?->name,
                'decision_at' => $t->decision_at?->format('Y-m-d H:i'),
                'plazo_concepto_at' => $t->plazo_concepto_at?->format('Y-m-d'),
                'closed_at' => $t->closed_at?->format('Y-m-d H:i'),
                'valor_reposicion' => (float) $t->valor_reposicion,
                'pedido_original' => $t->pedidoOriginal ? [
                    'id' => $t->pedidoOriginal->id, 'guia' => $t->pedidoOriginal->guia,
                    'cliente' => $t->pedidoOriginal->cliente_nombre,
                ] : null,
                'variante' => $t->variante ? [
                    'sku' => $t->variante->codigo_barras,
                    'producto' => $t->variante->producto?->nombre,
                ] : null,
                'fotos' => collect($t->fotos_evidencia ?? [])->map(fn ($p) => Storage::disk('public')->url($p))->all(),
            ],
        ]);
    }

    public function decidir(Request $r, int $ticket): RedirectResponse
    {
        $data = $r->validate([
            'decision' => ['required', 'in:aprobar,rechazar'],
            'notas_decision' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $t = GarantiaTicket::findOrFail($ticket);
        abort_unless(in_array($t->estado, ['abierto', 'en_revision']), 422, 'Estado no permite decisión.');
        $t->update([
            'estado' => $data['decision'] === 'aprobar' ? 'aprobada' : 'rechazada',
            'notas_decision' => $data['notas_decision'],
            'decision_por' => auth()->id(),
            'decision_at' => now(),
        ]);
        return back()->with('success', 'Decisión registrada.');
    }

    public function iniciarReposicion(int $ticket): RedirectResponse
    {
        $t = GarantiaTicket::findOrFail($ticket);
        abort_unless($t->estado === 'aprobada', 422, 'Solo se puede reponer una garantía aprobada.');
        $t->update([
            'estado' => 'en_reposicion',
            'valor_reposicion' => 0, // contrato: reposición a valor $0
        ]);
        return back()->with('success', 'Reposición iniciada (valor $0 al cliente).');
    }

    public function cerrar(Request $r, int $ticket): RedirectResponse
    {
        $data = $r->validate(['notas' => ['nullable', 'string', 'max:500']]);
        $t = GarantiaTicket::findOrFail($ticket);
        abort_unless(in_array($t->estado, ['en_reposicion', 'rechazada']), 422, 'Estado no permite cerrar.');
        $t->update([
            'estado' => 'cerrada',
            'closed_at' => now(),
            'notas_decision' => trim(($t->notas_decision ?: '') . "\n[CIERRE] " . ($data['notas'] ?? '')),
        ]);
        return back()->with('success', 'Ticket cerrado.');
    }
}
