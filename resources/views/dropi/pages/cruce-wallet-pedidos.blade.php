<x-filament-panels::page>
    @php
        $c = $this->cruce();
        $r = $c->resumen();
        $atrasos = $c->atrasos()->orderBy('entregado_at')->get();
        $enPlazo = $c->entregadosEnPlazo()->orderBy('entregado_at')->limit(50)->get();
        $enCamino = $c->enCamino()->orderBy('despachado_at')->limit(100)->get();
        $sinPedido = $c->pagosSinPedido();
        $diferencias = $c->diferencias()->latest('detectada_at')->limit(100)->get();
        $plazo = \App\Modules\Dropi\Services\CruceWalletPedidos::PLAZO_PAGO_DIAS;
        $fmt = fn ($v) => '$' . number_format((float) $v, 0, ',', '.');
        $ver = fn ($p) => \App\Modules\Dropi\Filament\Resources\DropiPedidoResource::getUrl('view', ['record' => $p]);
        $tarjetas = [
            ['Atrasos: entregados sin pago', $r['atrasos'], '#ef4444', "Más de {$plazo} días entregados. Reclamar a Dropi."],
            ['Pagados por Dropi', $r['pagados'], '#10b981', 'Pago de la wallet enlazado a su pedido.'],
            ['Entregados, en plazo', $r['en_plazo'], '#f59e0b', "Dropi paga en {$plazo} días como máximo."],
            ['En camino', $r['en_camino'], '#3b82f6', 'Despachados; Dropi aún no los entrega.'],
            ['Devoluciones en camino', $r['devoluciones'], '#9ca3af', 'No se cobran.'],
            ['Pagos sin pedido en el ERP', $r['sin_pedido'], '#8b5cf6', 'Dropi pagó una orden que el ERP no tiene.'],
            ['Diferencias de precio', $r['diferencias'], '#ef4444', 'Suma pagada distinta de la esperada.'],
        ];
        $th = 'text-align:left;padding:.5rem;';
        $td = 'padding:.5rem;';
        $mono = 'font-family:ui-monospace,monospace;font-weight:600;';
    @endphp

    <div style="font-size:.85rem;color:#9ca3af;margin-bottom:.25rem;">
        Wallet de Dropi desde el <strong>{{ $r['desde']->format('d/m/Y') }}</strong>
        · última sincronización: <strong>{{ $r['ultima_sync'] ? \Illuminate\Support\Carbon::parse($r['ultima_sync'])->locale('es')->diffForHumans() : 'nunca' }}</strong>.
        Lo despachado antes de esa fecha no se cruza: su pago no está importado.
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1rem;margin-bottom:1.25rem;">
        @foreach($tarjetas as [$titulo, $cifra, $color, $ayuda])
            <div style="padding:1rem;border:1px solid {{ $color }}55;border-radius:.75rem;background:{{ $color }}14;">
                <div style="font-size:.75rem;color:{{ $color }};text-transform:uppercase;font-weight:600;">{{ $titulo }}</div>
                <div style="font-size:1.75rem;font-weight:700;color:{{ $color }};margin-top:.25rem;">{{ number_format($cifra['n'], 0, ',', '.') }}</div>
                <div style="font-size:.85rem;color:#9ca3af;">{{ $fmt($cifra['monto']) }} · {{ $ayuda }}</div>
            </div>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">1 · Atrasos: entregados hace más de {{ $plazo }} días sin pago</x-slot>
        <x-slot name="description">Lo único que hay que reclamar a Dropi. Revísalos en app.dropi.co con el número de orden.</x-slot>
        @if($atrasos->isEmpty())
            <div style="color:#10b981;">✅ Sin atrasos: todo lo entregado fuera de plazo ya está pagado.</div>
        @else
            <table style="width:100%;font-size:.9rem;">
                <thead><tr style="border-bottom:1px solid rgba(156,163,175,.2);">
                    <th style="{{ $th }}">Orden Dropi</th><th style="{{ $th }}">Guía</th><th style="{{ $th }}">Cliente</th>
                    <th style="{{ $th }}">Entregado</th><th style="{{ $th }}text-align:right;">Esperado</th>
                </tr></thead>
                <tbody>
                @foreach($atrasos as $p)
                    <tr style="border-bottom:1px solid rgba(156,163,175,.1);">
                        <td style="{{ $td }}{{ $mono }}"><a href="{{ $ver($p) }}" style="color:#3b82f6;">{{ $p->dropi_orden_id }}</a></td>
                        <td style="{{ $td }}{{ $mono }}">{{ $p->guia }}</td>
                        <td style="{{ $td }}">{{ $p->cliente_nombre }} · {{ $p->cliente_ciudad }}</td>
                        <td style="{{ $td }}color:#ef4444;">{{ $p->entregado_at?->format('d/m/Y') }} · hace {{ (int) $p->entregado_at?->diffInDays(now()) }} días</td>
                        <td style="{{ $td }}text-align:right;font-weight:600;">{{ $fmt($p->monto_esperado_proveedor) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section style="margin-top:1rem;" collapsible>
        <x-slot name="heading">2 · Entregados, todavía en plazo ({{ $r['en_plazo']['n'] }})</x-slot>
        <x-slot name="description">Normal: Dropi paga entre 1 y {{ $plazo }} días después de entregar.</x-slot>
        @if($enPlazo->isEmpty())
            <div style="color:#9ca3af;">No hay entregados esperando pago.</div>
        @else
            <table style="width:100%;font-size:.9rem;">
                <thead><tr style="border-bottom:1px solid rgba(156,163,175,.2);">
                    <th style="{{ $th }}">Orden Dropi</th><th style="{{ $th }}">Cliente</th><th style="{{ $th }}">Entregado</th><th style="{{ $th }}text-align:right;">Esperado</th>
                </tr></thead>
                <tbody>
                @foreach($enPlazo as $p)
                    <tr style="border-bottom:1px solid rgba(156,163,175,.1);">
                        <td style="{{ $td }}{{ $mono }}"><a href="{{ $ver($p) }}" style="color:#3b82f6;">{{ $p->dropi_orden_id }}</a></td>
                        <td style="{{ $td }}">{{ $p->cliente_nombre }}</td>
                        <td style="{{ $td }}color:#9ca3af;">{{ $p->entregado_at?->format('d/m/Y') ?? 'sin fecha' }}</td>
                        <td style="{{ $td }}text-align:right;">{{ $fmt($p->monto_esperado_proveedor) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section style="margin-top:1rem;" collapsible collapsed>
        <x-slot name="heading">3 · En camino ({{ $r['en_camino']['n'] }})</x-slot>
        <x-slot name="description">Despachados que Dropi aún no marca como entregados. Del más viejo al más nuevo: los de más de 10 días en camino, en rojo.</x-slot>
        @if($enCamino->isEmpty())
            <div style="color:#9ca3af;">No hay pedidos en camino.</div>
        @else
            <table style="width:100%;font-size:.9rem;">
                <thead><tr style="border-bottom:1px solid rgba(156,163,175,.2);">
                    <th style="{{ $th }}">Orden Dropi</th><th style="{{ $th }}">Transportadora</th><th style="{{ $th }}">Despachado</th><th style="{{ $th }}text-align:right;">Esperado</th>
                </tr></thead>
                <tbody>
                @foreach($enCamino as $p)
                    @php $dias = (int) $p->despachado_at?->diffInDays(now()); @endphp
                    <tr style="border-bottom:1px solid rgba(156,163,175,.1);">
                        <td style="{{ $td }}{{ $mono }}"><a href="{{ $ver($p) }}" style="color:#3b82f6;">{{ $p->dropi_orden_id }}</a></td>
                        <td style="{{ $td }}">{{ $p->transportadora }}</td>
                        <td style="{{ $td }}{{ $dias > 10 ? 'color:#ef4444;font-weight:600;' : 'color:#9ca3af;' }}">{{ $p->despachado_at?->format('d/m/Y') }} · {{ $dias }} días</td>
                        <td style="{{ $td }}text-align:right;">{{ $fmt($p->monto_esperado_proveedor) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section style="margin-top:1rem;" collapsible>
        <x-slot name="heading">4 · Pagos de Dropi sin pedido en el ERP ({{ $r['sin_pedido']['n'] }})</x-slot>
        <x-slot name="description">Dropi pagó una orden que el ERP no tiene. Se enlazan solos cuando el sync trae el pedido.</x-slot>
        @if($sinPedido->isEmpty())
            <div style="color:#10b981;">✅ Todos los pagos tienen su pedido.</div>
        @else
            <table style="width:100%;font-size:.9rem;">
                <thead><tr style="border-bottom:1px solid rgba(156,163,175,.2);">
                    <th style="{{ $th }}">Fecha</th><th style="{{ $th }}">Orden Dropi</th><th style="{{ $th }}">Descripción</th><th style="{{ $th }}text-align:right;">Monto</th>
                </tr></thead>
                <tbody>
                @foreach($sinPedido as $m)
                    <tr style="border-bottom:1px solid rgba(156,163,175,.1);">
                        <td style="{{ $td }}color:#9ca3af;">{{ $m->fecha?->format('d/m/Y') }}</td>
                        <td style="{{ $td }}{{ $mono }}">{{ $m->fuente['order_id'] ?? '—' }}</td>
                        <td style="{{ $td }}">{{ \Illuminate\Support\Str::limit($m->fuente['description'] ?? $m->categoria, 80) }}</td>
                        <td style="{{ $td }}text-align:right;font-weight:600;">{{ $fmt($m->monto) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section style="margin-top:1rem;" collapsible>
        <x-slot name="heading">5 · Diferencias de precio ({{ $r['diferencias']['n'] }})</x-slot>
        <x-slot name="description">Suma pagada por Dropi distinta de lo esperado. Positivo: Dropi pagó de menos. Negativo: pagó de más.</x-slot>
        @if($diferencias->isEmpty())
            <div style="color:#10b981;">✅ Todo lo pagado cuadra con lo esperado.</div>
        @else
            <table style="width:100%;font-size:.9rem;">
                <thead><tr style="border-bottom:1px solid rgba(156,163,175,.2);">
                    <th style="{{ $th }}">Orden Dropi</th><th style="{{ $th }}">Tipo</th><th style="{{ $th }}text-align:right;">Esperado</th>
                    <th style="{{ $th }}text-align:right;">Recibido</th><th style="{{ $th }}text-align:right;">Diferencia</th>
                </tr></thead>
                <tbody>
                @foreach($diferencias as $s)
                    <tr style="border-bottom:1px solid rgba(156,163,175,.1);">
                        <td style="{{ $td }}{{ $mono }}">
                            @if($s->pedido)<a href="{{ $ver($s->pedido) }}" style="color:#3b82f6;">{{ $s->pedido->dropi_orden_id }}</a>@else — @endif
                        </td>
                        <td style="{{ $td }}"><x-filament::badge color="danger">{{ $s->tipo?->label() }}</x-filament::badge></td>
                        <td style="{{ $td }}text-align:right;">{{ $fmt($s->monto_esperado) }}</td>
                        <td style="{{ $td }}text-align:right;">{{ $fmt($s->monto_recibido) }}</td>
                        <td style="{{ $td }}text-align:right;font-weight:700;color:#ef4444;">{{ $fmt($s->diferencia) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
