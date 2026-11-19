<!DOCTYPE html>
<html lang="es"><head>
    <meta charset="UTF-8"><title>Factura {{ $factura->numero }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 24px; }
        h1 { font-size: 15pt; margin: 0; color: #b45309; }
        h2 { font-size: 11pt; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #fef3c7; padding: 6px 8px; font-size: 9pt; color: #78350f; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .box { border: 1px solid #d1d5db; padding: 10px 12px; border-radius: 4px; }
        .grid { display: table; width: 100%; margin: 10px 0; }
        .grid > div { display: table-cell; padding: 4px 6px; vertical-align: top; }
        .lbl { font-size: 8pt; color: #6b7280; text-transform: uppercase; }
        .val { font-weight: 700; font-size: 11pt; }
        .totales { margin-top: 12px; margin-left: 55%; width: 45%; }
        .totales td { padding: 4px 8px; font-size: 10pt; }
        .totales .grand { border-top: 2px solid #78350f; font-weight: 700; font-size: 12pt; color: #b45309; }
    </style>
</head><body>
    <table style="width:100%;"><tr>
        <td style="border:none;padding:0;vertical-align:top;">
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
                    @if($empresa->resolucion_desde) del {{ \Illuminate\Support\Carbon::parse($empresa->resolucion_desde)->format('Y-m-d') }}@endif
                    @if($empresa->prefijo_dian) · Prefijo {{ $empresa->prefijo_dian }}@endif
                    @if($empresa->rango_desde && $empresa->rango_hasta) · Rango {{ $empresa->rango_desde }}–{{ $empresa->rango_hasta }}@endif
                </div>
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
        <td style="border:none;padding:0;vertical-align:top;text-align:right;width:140px;">
            {!! $qrSvg !!}
            @if($factura->cufe)
                <div style="font-family:monospace;font-size:6pt;margin-top:4px;word-break:break-all;color:#6b7280;">
                    <strong>CUFE:</strong> {{ substr($factura->cufe, 0, 40) }}…
                </div>
            @endif
        </td>
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
            <div class="val">{{ $factura->estado->label() }}</div>
        </div>
    </div>

    <h2>Detalle</h2>
    <table>
        <thead><tr>
            <th>Descripción</th>
            <th style="text-align:right;">Cant.</th>
            <th style="text-align:right;">P. Unit.</th>
            <th style="text-align:right;">Subtotal</th>
        </tr></thead>
        <tbody>
            @foreach($factura->items as $it)
                <tr>
                    <td>{{ $it->descripcion }}</td>
                    <td style="text-align:right;">{{ number_format((float) $it->cantidad, 2) }}</td>
                    <td style="text-align:right;">${{ number_format((float) $it->precio_unit, 0, ',', '.') }}</td>
                    <td style="text-align:right;">${{ number_format((float) $it->subtotal, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales"><tbody>
        <tr><td>Subtotal:</td><td style="text-align:right;">${{ number_format((float) $factura->subtotal, 0, ',', '.') }}</td></tr>
        @if((float) $factura->descuento > 0)
            <tr><td>Descuento:</td><td style="text-align:right;">-${{ number_format((float) $factura->descuento, 0, ',', '.') }}</td></tr>
        @endif
        @if((float) $factura->impuestos > 0)
            <tr><td>IVA:</td><td style="text-align:right;">${{ number_format((float) $factura->impuestos, 0, ',', '.') }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL:</td><td style="text-align:right;">${{ number_format((float) $factura->total, 0, ',', '.') }}</td></tr>
        @if((float) $factura->retenciones > 0)
            @foreach($factura->retencionesAplicadas as $r)
                <tr><td>{{ ['retefuente' => 'Retefuente', 'reteiva' => 'ReteIVA', 'reteica' => 'ReteICA'][$r->tipo] ?? $r->tipo }} {{ rtrim(rtrim(number_format((float) $r->tarifa_pct, 3, ',', '.'), '0'), ',') }}%:</td><td style="text-align:right;">-${{ number_format((float) $r->valor, 0, ',', '.') }}</td></tr>
            @endforeach
            <tr><td><strong>Neto a pagar:</strong></td><td style="text-align:right;"><strong>${{ number_format((float) $factura->total - (float) $factura->retenciones, 0, ',', '.') }}</strong></td></tr>
        @endif
        <tr><td>Saldo pendiente:</td><td style="text-align:right;color:#ef4444;font-weight:700;">${{ number_format((float) $factura->saldo, 0, ',', '.') }}</td></tr>
    </tbody></table>

    @if($factura->observaciones)
        <div style="margin-top:20px;font-size:9pt;color:#6b7280;padding:8px 12px;background:#f3f4f6;border-radius:4px;">
            <strong>Observaciones:</strong> {{ $factura->observaciones }}
        </div>
    @endif

    @if($factura->es_electronica && $factura->qr_url)
        <div style="margin-top:14px;padding:8px 12px;background:#f0fdf4;border-left:3px solid #16a34a;border-radius:4px;font-size:8pt;color:#166534;">
            <strong>Este documento cumple con la Facturación Electrónica DIAN.</strong>
            Verifica su validez en:
            <span style="font-family:monospace;word-break:break-all;">{{ $factura->qr_url }}</span>
        </div>
    @endif

    @if($empresa->banco_nombre)
        <div style="margin-top:10px;font-size:8pt;color:#6b7280;padding:6px 10px;background:#f9fafb;border-radius:4px;">
            <strong>Banco:</strong> {{ $empresa->banco_nombre }}
            @if($empresa->banco_cuenta) · Cuenta {{ $empresa->banco_cuenta }}@endif
            @if($empresa->banco_swift) · SWIFT {{ $empresa->banco_swift }}@endif
            @if($empresa->banco_iban) · IBAN {{ $empresa->banco_iban }}@endif
            @if($empresa->banco_moneda) · Moneda {{ $empresa->banco_moneda }}@endif
        </div>
    @endif

    <div style="margin-top:20px;padding-top:10px;border-top:1px solid #d1d5db;font-size:8pt;color:#6b7280;">
        {{ $empresa->pie_pdf ?: ('Documento generado por el ERP ' . $empresa->razon_social) }}
    </div>
</body></html>
