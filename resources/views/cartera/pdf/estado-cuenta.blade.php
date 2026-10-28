<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estado de cuenta — {{ $contacto->nombreDisplay() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 24px; }
        h1 { font-size: 16pt; margin: 0 0 4px; color: #b45309; }
        h2 { font-size: 11pt; margin: 20px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #d1d5db; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #fef3c7; padding: 6px 8px; font-size: 9pt; color: #78350f; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .box { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 4px; }
        .grid { display: table; width: 100%; margin: 12px 0; }
        .grid > div { display: table-cell; padding: 4px 6px; }
        .grid .lbl { font-size: 8pt; color: #6b7280; text-transform: uppercase; }
        .grid .val { font-size: 12pt; font-weight: 700; color: #111; }
        .tramos { display: table; width: 100%; margin-top: 6px; }
        .tramos > div { display: table-cell; text-align: center; padding: 8px 4px; border: 1px solid #e5e7eb; }
        .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #d1d5db; font-size: 8pt; color: #6b7280; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 8pt; }
        .b-ok { background: #dcfce7; color: #166534; }
        .b-warn { background: #fef3c7; color: #78350f; }
        .b-err { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <h1>GREAT BABY S.A.S.</h1>
    <div style="font-size: 9pt; color: #6b7280;">NIT 901.738.354 · Bucaramanga</div>

    <h2>Estado de cuenta · {{ now()->format('Y-m-d') }}</h2>
    <div class="box">
        <div style="font-weight: 700; font-size: 12pt;">{{ $contacto->nombreDisplay() }}</div>
        <div>{{ $contacto->tipo_documento }} {{ $contacto->numero_documento }}</div>
        <div>{{ $contacto->direccion }} · {{ $contacto->ciudad }}, {{ $contacto->departamento }}</div>
        <div>{{ $contacto->email }} · {{ $contacto->telefono }}</div>
    </div>

    @if($contacto->es_cliente_b2b)
    <div class="grid" style="margin-top: 12px;">
        <div>
            <div class="lbl">Cupo</div>
            <div class="val">${{ number_format($credito['cupo'], 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="lbl">Saldo actual</div>
            <div class="val" style="color:#f59e0b;">${{ number_format($credito['saldo_cartera'], 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="lbl">Disponible</div>
            <div class="val" style="color:#059669;">${{ number_format($credito['disponible'], 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="lbl">Mora máxima</div>
            <div class="val" style="color:{{ $credito['tiene_mora_critica'] ? '#dc2626' : '#059669' }};">{{ $credito['dias_mora_max'] }} d</div>
        </div>
    </div>

    <h2>Antigüedad de saldos</h2>
    <div class="tramos">
        @foreach($antig['por_tramo'] as $key => $data)
            @php $t = App\Modules\Cartera\Enums\TramoAntiguedad::from($key); @endphp
            <div>
                <div style="font-size: 8pt; color: {{ $t->colorHex() }};">{{ $t->label() }}</div>
                <div style="font-weight: 700; color: {{ $t->colorHex() }}; margin-top: 2px;">${{ number_format($data['monto'], 0, ',', '.') }}</div>
                <div style="font-size: 7pt; color: #6b7280;">{{ $data['count'] }} fact.</div>
            </div>
        @endforeach
    </div>
    @endif

    <h2>Detalle de facturas y abonos</h2>
    {{--
        Cada factura trae DEBAJO sus abonos, con fecha, medio y valor.
        Antes acá sólo iba el saldo vigente, y con eso no se puede sostener la
        conversación con el cliente cuando dice «yo le pagué»: hay que poder
        mostrarle cuándo y cuánto, abono por abono.
    --}}
    <table>
        <thead><tr>
            <th>Número</th>
            <th>Emisión</th>
            <th>Vence</th>
            <th>Estado</th>
            <th style="text-align: right;">Total</th>
            <th style="text-align: right;">Abonado</th>
            <th style="text-align: right;">Saldo</th>
        </tr></thead>
        <tbody>
            @foreach($facturas as $f)
                @php $abonado = (float) $f->pagos->sum('monto_aplicado'); @endphp
                <tr>
                    <td style="font-family: monospace;">{{ $f->numero }}</td>
                    <td>{{ $f->fecha_emision->format('Y-m-d') }}</td>
                    <td>{{ $f->fecha_vencimiento?->format('Y-m-d') ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $f->estado->value === 'pagada' ? 'b-ok' : ($f->estado->value === 'vencida' ? 'b-err' : 'b-warn') }}">
                            {{ $f->estado->label() }}
                        </span>
                    </td>
                    <td style="text-align: right;">${{ number_format((float) $f->total, 0, ',', '.') }}</td>
                    <td style="text-align: right; color: #059669;">
                        {{ $abonado > 0 ? '$'.number_format($abonado, 0, ',', '.') : '—' }}
                    </td>
                    <td style="text-align: right; font-weight: 600;">${{ number_format((float) $f->saldo, 0, ',', '.') }}</td>
                </tr>

                @forelse($f->pagos as $p)
                    <tr style="background: #f8fafc;">
                        <td style="padding-left: 18px; color: #6b7280; font-size: 8pt;">
                            ↳ abono {{ $loop->iteration }}
                        </td>
                        <td colspan="3" style="color: #6b7280; font-size: 8pt;">
                            {{ $p->fecha?->format('Y-m-d') ?? '—' }}
                            @if($p->medio_pago) · {{ $p->medio_pago }} @endif
                            @if($p->banco) · {{ $p->banco }} @endif
                            @if($p->referencia) · ref. {{ $p->referencia }} @endif
                        </td>
                        <td></td>
                        <td style="text-align: right; color: #059669; font-size: 8pt;">
                            ${{ number_format((float) $p->monto_aplicado, 0, ',', '.') }}
                        </td>
                        <td></td>
                    </tr>
                @empty
                    @if((float) $f->saldo > 0)
                        <tr style="background: #f8fafc;">
                            <td colspan="7" style="padding-left: 18px; color: #9ca3af; font-size: 8pt;">
                                ↳ sin abonos registrados
                            </td>
                        </tr>
                    @endif
                @endforelse
            @endforeach
        </tbody>
    </table>

    @if($totalAbonado > 0)
        <div style="margin-top: 8px; text-align: right; font-size: 9pt;">
            Total abonado en el periodo:
            <strong style="color: #059669;">${{ number_format($totalAbonado, 0, ',', '.') }}</strong>
        </div>
    @endif

    <div class="footer">
        Documento generado automáticamente por el ERP GREAT BABY · MYTech Solutions S.A.S.<br>
        Los valores reflejan el estado de cuenta al momento de la impresión.
    </div>
</body>
</html>
