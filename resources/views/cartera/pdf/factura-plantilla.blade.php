@php
    /** @var \App\Modules\Cartera\Models\FacturaVenta $factura */
    /** @var \App\Models\EmpresaConfig $empresa */
    /** @var array $cfg */
    /** @var string $qrSvg */
    $font = ['sans' => 'DejaVu Sans, sans-serif', 'serif' => 'DejaVu Serif, serif', 'mono' => 'DejaVu Sans Mono, monospace'][$cfg['tipografia'] ?? 'sans'];
    $espaciado = ($cfg['layout'] ?? 'espacioso') === 'compacto' ? 10 : 24;
    $alignEnc = ($cfg['encabezado_alineacion'] ?? 'izquierda');
    $alignCss = ['izquierda' => 'left', 'centrado' => 'center', 'derecha' => 'right'][$alignEnc] ?? 'left';
@endphp
<!DOCTYPE html>
<html lang="es"><head>
    <meta charset="UTF-8"><title>Factura {{ $factura->numero }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: {{ $font }}; font-size: 10pt; color: {{ $cfg['colores']['texto'] }}; margin: 0; padding: {{ $espaciado }}px; }
        h1 { font-size: 15pt; margin: 0; color: {{ $cfg['colores']['primario'] }}; }
        h2 { font-size: 11pt; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; color: {{ $cfg['colores']['secundario'] }}; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: {{ $cfg['colores']['acento'] }}; padding: 6px 8px; font-size: 9pt; color: {{ $cfg['colores']['secundario'] }}; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .box { border: 1px solid #d1d5db; padding: 10px 12px; border-radius: 4px; }
        .grid { display: table; width: 100%; margin: 10px 0; }
        .grid > div { display: table-cell; padding: 4px 6px; vertical-align: top; }
        .lbl { font-size: 8pt; color: #6b7280; text-transform: uppercase; }
        .val { font-weight: 700; font-size: 11pt; }
        .totales { margin-top: 12px; margin-left: 55%; width: 45%; }
        .totales td { padding: 4px 8px; font-size: 10pt; }
        .totales .grand { border-top: 2px solid {{ $cfg['colores']['secundario'] }}; font-weight: 700; font-size: 12pt; color: {{ $cfg['colores']['primario'] }}; }
        .encabezado-extra { text-align: {{ $alignCss }}; font-size: 9pt; color: #4b5563; margin: 6px 0; white-space: pre-wrap; }
        .pie { border-top: 1px solid #d1d5db; margin-top: 24px; padding-top: 8px; font-size: 8pt; color: #6b7280; text-align: center; }
        .firma { margin-top: 28px; width: 40%; text-align: center; page-break-inside: avoid; }
        .firma-linea { border-top: 1px solid {{ $cfg['colores']['texto'] }}; margin-top: 4px; padding-top: 4px; font-size: 9pt; font-weight: 700; min-height: 12px; }
        .firma-cargo { font-size: 8pt; color: #6b7280; }
        .terminos { margin-top: 14px; font-size: 8pt; color: #6b7280; white-space: pre-wrap; padding: 8px; background: #f9fafb; border-left: 3px solid {{ $cfg['colores']['primario'] }}; }
        @if($cfg['watermark_activo'] ?? false)
        .watermark { position: fixed; top: 40%; left: 15%; width: 70%; text-align: center; font-size: 72pt; color: rgba(0,0,0,0.06); font-weight: 900; transform: rotate(-25deg); z-index: -1; }
        @endif
    </style>
</head><body>

    @if($cfg['watermark_activo'] ?? false)
        <div class="watermark">{{ $cfg['sello_texto'] ?: 'BORRADOR' }}</div>
    @endif

    <table style="width:100%;"><tr>
        <td style="border:none;padding:0;vertical-align:top;">
            @if(!empty($cfg['logo_url']))
                <img src="{{ $cfg['logo_url'] }}" style="height:{{ (int) ($cfg['logo_alto_px'] ?? 60) }}px; margin-bottom:6px;">
            @endif
            <h1>{{ $empresa->razon_social }}</h1>
            <div style="font-size:9pt;color:#6b7280;">
                NIT {{ $empresa->nit }}
                @if($empresa->direccion) · {{ $empresa->direccion }}@endif
                @if($empresa->ciudad) · {{ $empresa->ciudad }}@endif
            </div>
            @if($empresa->telefono || $empresa->email)
                <div style="font-size:9pt;color:#6b7280;">
                    @if($empresa->telefono){{ $empresa->telefono }}@endif
                    @if($empresa->telefono && $empresa->email) · @endif
                    @if($empresa->email){{ $empresa->email }}@endif
                </div>
            @endif
            @if($empresa->resolucion_dian)
                <div style="font-size:8pt;color:#6b7280;margin-top:2px;">
                    Res. DIAN {{ $empresa->resolucion_dian }}
                    @if($empresa->prefijo_dian) · Prefijo {{ $empresa->prefijo_dian }}@endif
                </div>
            @endif
            @if(!empty($cfg['encabezado_extra']))
                <div class="encabezado-extra">{{ $cfg['encabezado_extra'] }}</div>
            @endif
            <div style="margin-top:8px;font-weight:700;font-size:12pt;">
                {{ $factura->es_electronica ? 'FACTURA ELECTRÓNICA DE VENTA' : 'FACTURA DE VENTA' }}
            </div>
            <div style="font-family:monospace;">
                {{ $factura->numero_siigo ?: $factura->numero }}
                @if($factura->numero_siigo)
                    <span style="color:#6b7280;font-size:8pt;">(interno {{ $factura->numero }})</span>
                @endif
            </div>
        </td>
        @if(($cfg['mostrar_qr'] ?? true) && $qrSvg)
        <td style="border:none;padding:0;vertical-align:top;text-align:right;width:140px;">
            {!! $qrSvg !!}
            @if($factura->cufe)
                <div style="font-family:monospace;font-size:6pt;margin-top:4px;word-break:break-all;color:#6b7280;">
                    <strong>CUFE:</strong> {{ substr($factura->cufe, 0, 40) }}…
                </div>
            @endif
        </td>
        @endif
    </tr></table>

    <h2>Cliente</h2>
    <div class="box">
        <strong>{{ $factura->contacto?->nombreDisplay() ?? 'Cliente sin datos' }}</strong><br>
        {{ $factura->contacto?->tipo_documento ?? '' }} {{ $factura->contacto?->numero_documento ?? '—' }}<br>
        {{ $factura->contacto?->direccion ?: '—' }} · {{ $factura->contacto?->ciudad ?: '—' }}<br>
        {{ $factura->contacto?->telefono ?: '—' }} · {{ $factura->contacto?->email ?: '—' }}
    </div>

    <div class="grid">
        <div>
            <div class="lbl">Fecha emisión</div>
            <div class="val">{{ $factura->fecha_emision->format('Y-m-d') }}</div>
        </div>
        <div>
            <div class="lbl">Vencimiento</div>
            <div class="val">{{ $factura->fecha_vencimiento->format('Y-m-d') }}</div>
        </div>
        <div>
            <div class="lbl">Estado</div>
            <div class="val">{{ ucfirst(is_object($factura->estado) ? $factura->estado->value : $factura->estado) }}</div>
        </div>
    </div>

    <h2>Detalle</h2>
    <table>
        <thead>
            <tr><th style="width:50%">Descripción</th><th style="text-align:right;">Cant</th><th style="text-align:right;">Precio</th><th style="text-align:right;">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach($factura->items as $it)
                <tr>
                    <td>
                        {{ $it->descripcion ?? $it->variante?->producto?->nombre ?? '—' }}
                        @if($it->variante?->codigo_barras)
                            <br><small style="color:#9ca3af;">{{ $it->variante->codigo_barras }}</small>
                        @endif
                    </td>
                    <td style="text-align:right;">{{ number_format((float)$it->cantidad, 0, ',', '.') }}</td>
                    <td style="text-align:right;">${{ number_format((float)$it->precio_unit, 0, ',', '.') }}</td>
                    <td style="text-align:right;">${{ number_format((float)$it->subtotal, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr><td>Subtotal</td><td style="text-align:right;">${{ number_format((float)$factura->subtotal, 0, ',', '.') }}</td></tr>
        <tr><td>Impuestos</td><td style="text-align:right;">${{ number_format((float)$factura->impuestos, 0, ',', '.') }}</td></tr>
        @if((float)$factura->descuento > 0)
        <tr><td>Descuento</td><td style="text-align:right;">-${{ number_format((float)$factura->descuento, 0, ',', '.') }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL</td><td style="text-align:right;">${{ number_format((float)$factura->total, 0, ',', '.') }}</td></tr>
        @if(($cfg['mostrar_bloque_retenciones'] ?? true) && (float)$factura->retenciones > 0)
            @foreach($factura->retencionesAplicadas as $r)
            <tr><td>{{ ['retefuente' => 'Retefuente', 'reteiva' => 'ReteIVA', 'reteica' => 'ReteICA'][$r->tipo] ?? $r->tipo }} {{ rtrim(rtrim(number_format((float)$r->tarifa_pct, 3, ',', '.'), '0'), ',') }}%</td><td style="text-align:right;">-${{ number_format((float)$r->valor, 0, ',', '.') }}</td></tr>
            @endforeach
            <tr><td><strong>Neto a pagar</strong></td><td style="text-align:right;"><strong>${{ number_format((float)$factura->total - (float)$factura->retenciones, 0, ',', '.') }}</strong></td></tr>
        @endif
        <tr><td>Saldo</td><td style="text-align:right;">${{ number_format((float)$factura->saldo, 0, ',', '.') }}</td></tr>
    </table>

    @if(($cfg['mostrar_totales_en_letras'] ?? false))
        @php
            $enLetras = '';
            try {
                $enLetras = \App\Support\NumeroALetras::convertir((float) $factura->total);
            } catch (\Throwable $e) { /* silente: si falla, se oculta el bloque */ }
        @endphp
        @if($enLetras)
            <div style="margin-top:8px;font-size:9pt;font-style:italic;color:#4b5563;">
                Son: {{ $enLetras }}
            </div>
        @endif
    @endif

    @if(($cfg['mostrar_bloque_banco'] ?? true) && $empresa->banco_nombre)
        <h2>Datos bancarios</h2>
        <div class="box" style="font-size:9pt;">
            {{ $empresa->banco_nombre }} · Cuenta {{ $empresa->banco_cuenta }} · {{ $empresa->banco_moneda ?: 'COP' }}
        </div>
    @endif

    @if(($cfg['mostrar_bloque_notas'] ?? true) && $factura->observaciones)
        <h2>Observaciones</h2>
        <div class="box">{{ $factura->observaciones }}</div>
    @endif

    @if(($cfg['mostrar_firma'] ?? false) && (!empty($cfg['firma_url']) || !empty($cfg['firma_nombre'])))
        <div class="firma">
            @if(!empty($cfg['firma_url']))
                <img src="{{ $cfg['firma_url'] }}" style="height:{{ (int) ($cfg['firma_alto_px'] ?? 50) }}px;">
            @endif
            <div class="firma-linea">{{ $cfg['firma_nombre'] ?? '' }}</div>
            @if(!empty($cfg['firma_cargo']))
                <div class="firma-cargo">{{ $cfg['firma_cargo'] }}</div>
            @endif
        </div>
    @endif

    @if(!empty($cfg['terminos_condiciones']))
        <div class="terminos">{{ $cfg['terminos_condiciones'] }}</div>
    @endif

    @if(!empty($cfg['pie_html']))
        <div class="pie">{!! e($cfg['pie_html']) !!}</div>
    @endif

</body></html>
