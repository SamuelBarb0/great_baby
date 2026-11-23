<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pedidos · {{ $corte->etiqueta() }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; margin: 0; }
        h1 { font-size: 15pt; margin: 0 0 2px; color: #b45309; }
        .sub { color: #555; font-size: 9pt; }
        .totales { background: #f3f4f6; padding: 8px 10px; margin: 10px 0; }
        .totales-grid { display: table; width: 100%; }
        .totales-cell { display: table-cell; width: 25%; padding: 2px 6px; }
        .totales-label { font-size: 7pt; text-transform: uppercase; color: #6b7280; }
        .totales-val { font-size: 12pt; font-weight: 700; }
        table.pedidos { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { text-align: left; background: #fef3c7; padding: 5px 6px; font-size: 8pt; color: #78350f; }
        td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; font-size: 8.5pt; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .guia { font-family: DejaVu Sans Mono, monospace; font-weight: 700; }
        .prod { margin: 0; padding: 0; list-style: none; }
        .prod li { margin-bottom: 2px; }
        .cant { font-weight: 700; }
        .sku { font-family: DejaVu Sans Mono, monospace; font-size: 7pt; color: #6b7280; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7.5pt; background: #fef3c7; color: #78350f; }
        .badge-ok { background: #dcfce7; color: #166534; }
        .badge-err { background: #fee2e2; color: #991b1b; }
        .dir { font-size: 7.5pt; color: #4b5563; }
        .footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>
    <h1>GREAT BABY S.A.S.</h1>
    <div style="font-weight: 700; font-size: 11pt;">
        {{ $soloUno ? 'Pedido' : 'Pedidos del corte' }} · Corte {{ $corte->numero }} · {{ $corte->fecha->format('Y-m-d') }}
    </div>
    <div class="sub">
        Corte {{ strtolower($corte->estado?->label() ?? 'abierto') }} · generado {{ $generado->format('d/m/Y H:i') }}
        @unless($soloUno) · lista de trabajo, no reemplaza al Manifiesto del cierre @endunless
    </div>

    @unless($soloUno)
        <div class="totales">
            <div class="totales-grid">
                <div class="totales-cell">
                    <div class="totales-label">Pedidos</div>
                    <div class="totales-val">{{ count($filas) }}</div>
                </div>
                <div class="totales-cell">
                    <div class="totales-label">Unidades</div>
                    <div class="totales-val">{{ $unidades }}</div>
                </div>
                <div class="totales-cell">
                    <div class="totales-label">Despachados</div>
                    <div class="totales-val" style="color:#059669;">
                        {{ collect($filas)->filter(fn ($f) => in_array($f['pedido']->estado?->value, ['despachado', 'entregado', 'pagado'], true))->count() }}
                    </div>
                </div>
                <div class="totales-cell">
                    <div class="totales-label">Monto GB</div>
                    <div class="totales-val">${{ number_format($monto, 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
    @endunless

    @if(count($filas) === 0)
        <p style="margin-top: 20px;">Este corte todavía no tiene pedidos.</p>
    @else
        <table class="pedidos">
            <thead>
                <tr>
                    <th style="width: 18%;">Guía</th>
                    <th style="width: 22%;">Cliente</th>
                    <th>Productos</th>
                    <th style="width: 12%;">Estado</th>
                    <th style="width: 11%; text-align: right;">Monto GB</th>
                </tr>
            </thead>
            <tbody>
                @foreach($filas as $f)
                    @php($p = $f['pedido'])
                    <tr>
                        <td>
                            <div class="guia">{{ $p->guia }}</div>
                            <div class="sku">{{ $p->transportadora }}@if($p->dropi_orden_id) · orden {{ $p->dropi_orden_id }}@endif</div>
                        </td>
                        <td>
                            {{ $p->cliente_nombre }}
                            <div class="dir">{{ collect([$p->cliente_ciudad, $p->cliente_depto])->filter()->implode(', ') }}</div>
                            @if($soloUno && $p->cliente_direccion)
                                <div class="dir">{{ $p->cliente_direccion }}</div>
                            @endif
                        </td>
                        <td>
                            @if(count($f['productos']) === 0)
                                <span class="sku">Sin productos</span>
                            @else
                                <ul class="prod">
                                    @foreach($f['productos'] as $item)
                                        <li><span class="cant">{{ $item['cantidad'] }} ×</span> {{ $item['nombre'] }}
                                            @if($item['sku'])<span class="sku">{{ $item['sku'] }}</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        <td>
                            @php($v = $p->estado?->value)
                            <span class="badge {{ in_array($v, ['pagado', 'despachado', 'entregado'], true) ? 'badge-ok' : (in_array($v, ['devuelto', 'cancelado_dropi', 'cancelado_gb'], true) ? 'badge-err' : '') }}">
                                {{ $p->estado?->label() }}
                            </span>
                        </td>
                        <td style="text-align: right; font-weight: bold;">${{ number_format((float) $p->monto_esperado_proveedor, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">Documento generado por el ERP GREAT BABY · MYTech Solutions S.A.S.</div>
</body>
</html>
