<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\CobranzaRegistro;
use App\Modules\Cartera\Models\CondicionCredito;
use App\Modules\Cartera\Models\SolicitudCredito;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class CreditoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $r, \Closure $next) {
                // Casilla: Condiciones de credito.
                abort_unless(\App\Auth\Permisos::puede($r->user(), 'condiciones_credito'), 403);
                return $next($r);
            }),
        ];
    }

    public function index(Request $request): Response
    {
        $tab = (string) $request->input('tab', 'condiciones');

        return Inertia::render('Cartera/Credito/Index', [
            'tab' => $tab,
            'condiciones' => $this->condiciones(),
            'cobranzas' => $this->cobranzas(),
            'excepciones' => $this->excepciones(),
        ]);
    }

    private function condiciones(): array
    {
        return CondicionCredito::query()
            ->with(['contacto:id,nombre_completo,telefono', 'aprobador:id,name'])
            ->orderByDesc('activa')
            ->orderByDesc('vigente_desde')
            ->limit(50)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'contacto_id' => $c->contacto_id,
                'contacto' => $c->contacto?->nombre_completo,
                'cupo' => (float) $c->cupo,
                'plazo_dias' => (int) $c->plazo_dias,
                'descuento_pronto_pago_pct' => (float) $c->descuento_pronto_pago_pct,
                'plazo_pronto_pago_dias' => (int) $c->plazo_pronto_pago_dias,
                'flete_asumido_gb' => (bool) $c->flete_asumido_gb,
                'activa' => (bool) $c->activa,
                'vigente_desde' => $c->vigente_desde?->toDateString(),
                'vigente_hasta' => $c->vigente_hasta?->toDateString(),
                'aprobada_por' => $c->aprobador?->name,
                'notas' => $c->notas,
            ])
            ->all();
    }

    private function cobranzas(): array
    {
        return CobranzaRegistro::query()
            ->with(['factura:id,numero', 'contacto:id,nombre_completo', 'gestor:id,name'])
            ->orderByDesc('enviado_at')
            ->limit(50)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'factura_id' => $c->factura_id,
                'factura_numero' => $c->factura?->numero,
                'contacto' => $c->contacto?->nombre_completo,
                'canal' => $c->canal,
                'tramo' => $c->tramo,
                'estado' => $c->estado,
                'mensaje' => $c->mensaje,
                'gestor' => $c->gestor?->name,
                'enviado_at' => $c->enviado_at?->toIso8601String(),
                'enviado_hace' => $c->enviado_at?->diffForHumans(),
            ])
            ->all();
    }

    private function excepciones(): array
    {
        return SolicitudCredito::query()
            ->with(['contacto:id,nombre_completo', 'solicitante:id,name', 'resolutor:id,name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'contacto' => $s->contacto?->nombre_completo,
                'monto_pedido' => (float) $s->monto_pedido,
                'motivo_retencion' => $s->motivo_retencion,
                'estado' => $s->estado,
                'nivel_actual' => $s->nivel_actual,
                'solicitante' => $s->solicitante?->name,
                'resolutor' => $s->resolutor?->name,
                'resolucion_notas' => $s->resolucion_notas,
                'resuelta_at' => $s->resuelta_at?->toIso8601String(),
                'creada' => $s->created_at?->diffForHumans(),
            ])
            ->all();
    }
}
