{{--
    LIQUIDACIÓN DEFINITIVA DE CONTRATO.

    Lo firma quien se va, así que cada renglón lleva su fórmula: el número solo
    obliga a creerle a la empresa, y es justo el documento donde la persona
    tiene más razones para querer revisar la cuenta.

    Sale de la fila guardada y no de un recálculo. Dentro de dos años tiene que
    decir lo mismo, aunque a la ficha le hayan cambiado el salario.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Liquidación — {{ $liq->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; margin: 0; padding: 24px; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .enc td { padding: 6px 8px; vertical-align: middle; }
        .marca { font-size: 13pt; font-weight: 700; color: #b45309; }
        .titulo { text-align: right; font-size: 10pt; font-weight: 700; }
        .sub { text-align: right; font-size: 8.5pt; color: #6b7280; }
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.datos td { padding: 4px 7px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .lbl { color: #6b7280; text-transform: uppercase; font-size: 7.5pt; width: 18%; }
        table.conceptos { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.conceptos th { background: #fef3c7; color: #78350f; font-size: 7.5pt; padding: 5px 7px; text-align: left; }
        table.conceptos th.num { text-align: right; }
        table.conceptos td { border-bottom: 1px solid #e5e7eb; padding: 5px 7px; font-size: 9pt; }
        table.conceptos td.num { text-align: right; white-space: nowrap; }
        .formula { font-size: 7.5pt; color: #6b7280; }
        .resta { color: #b91c1c; }
        .totales { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .totales td { padding: 5px 8px; font-size: 9.5pt; }
        .totales td.num { text-align: right; white-space: nowrap; }
        .neto { background: #fffbeb; font-weight: 700; font-size: 11pt; border-top: 2px solid #b45309; }
        .caja { border: 1px solid #d1d5db; border-radius: 3px; padding: 7px 10px; font-size: 8.5pt; margin-top: 10px; }
        .paz { margin-top: 16px; padding: 9px 11px; border: 1px solid #9ca3af; font-size: 8.5pt; line-height: 1.5; }
        .firmas { width: 100%; margin-top: 40px; border-collapse: collapse; }
        .firmas td { width: 50%; padding: 0 14px 0 0; vertical-align: top; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
        .aviso { border-left: 3px solid #b45309; background: #fffbeb; padding: 7px 10px; margin: 12px 0; font-size: 8.5pt; color: #78350f; }
        .pie { margin-top: 18px; padding-top: 7px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @php $money = fn ($v) => '$'.number_format((float) $v, 2, ',', '.'); @endphp

    <table class="enc">
        <tr>
            <td class="marca">{{ $empresa->nombre_comercial ?: $empresa->razon_social }}</td>
            <td>
                <div class="titulo">LIQUIDACIÓN DEFINITIVA DE CONTRATO</div>
                <div class="sub">Prestaciones sociales</div>
            </td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td class="lbl">Colaborador</td><td><strong>{{ $liq->nombre }}</strong></td>
            <td class="lbl">Documento</td><td>{{ $liq->documento ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo</td><td>{{ $liq->cargo ?: '—' }}</td>
            <td class="lbl">Tiempo servido</td><td>{{ $liq->tiempoServido() }}</td>
        </tr>
        <tr>
            <td class="lbl">Fecha de ingreso</td><td>{{ $liq->fecha_ingreso?->format('d/m/Y') }}</td>
            <td class="lbl">Fecha de retiro</td><td>{{ $liq->fecha_retiro?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="lbl">Salario base</td><td>{{ $money($liq->salario_base) }}</td>
            <td class="lbl">Auxilio de transporte</td>
            <td>{{ $liq->auxilio_base > 0 ? $money($liq->auxilio_base) : 'no devenga' }}</td>
        </tr>
    </table>

    <table class="conceptos">
        <thead>
            <tr>
                <th style="width:62%;">Concepto</th>
                <th class="num">A favor</th>
                <th class="num">Descuento</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($liq->conceptos() as $c)
                <tr>
                    <td>
                        {{ $c['concepto'] }}
                        @if ($c['formula'])
                            <div class="formula">{{ $c['formula'] }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $c['resta'] ? '' : $money($c['valor']) }}</td>
                    <td class="num resta">{{ $c['resta'] ? $money($c['valor']) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr>
            <td>Total a favor</td>
            <td class="num">{{ $money($liq->total_a_favor) }}</td>
        </tr>
        <tr>
            <td>Total descuentos</td>
            <td class="num resta">{{ $money($liq->total_descuentos) }}</td>
        </tr>
        <tr class="neto">
            <td>NETO A PAGAR</td>
            <td class="num">{{ $money($liq->neto) }}</td>
        </tr>
    </table>

    @if ($liq->observaciones)
        <div class="caja"><strong>Observaciones:</strong> {{ $liq->observaciones }}</div>
    @endif

    @if ($liq->estado !== 'pagada')
        <div class="aviso">
            <strong>Borrador.</strong>
            Esta liquidación está en {{ strtolower($liq->estadoLabel()) }} y los valores
            pueden cambiar hasta que gerencia la apruebe.
        </div>
    @endif

    {{--
        El paz y salvo va en el mismo papel que la cuenta, y no en uno aparte,
        para que nadie firme el recibido sin tener el desglose enfrente.
    --}}
    <div class="paz">
        Declaro que recibí a entera satisfacción la suma de
        <strong>{{ $money($liq->neto) }}</strong> por concepto de la liquidación definitiva
        de mi contrato de trabajo, revisada en el detalle que aparece arriba, y que
        la empresa queda a paz y salvo por los conceptos aquí liquidados.
        Esta declaración no cubre conceptos distintos de los enumerados en este documento.
    </div>

    <table class="firmas">
        <tr>
            <td>
                <div class="linea"></div>
                <div class="rol">Firma del colaborador</div>
                <div class="rol">{{ $liq->nombre }} · C.C. {{ $liq->documento ?: '—' }}</div>
            </td>
            <td>
                <div class="linea"></div>
                <div class="rol">Por la empresa</div>
                <div class="rol">
                    @if ($liq->aprobada_at)
                        Aprobada por {{ $liq->aprobadaPor?->name }} el {{ $liq->aprobada_at->format('d/m/Y') }}
                    @else
                        Pendiente de aprobación
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="pie">
        Cesantías, intereses y prima se liquidan sobre salario más auxilio de transporte;
        las vacaciones, sobre el salario solo. Todo sobre el año comercial de 360 días.<br>
        Generado desde el ERP por {{ $generadoPor }} el {{ now()->format('d/m/Y H:i') }}.
    </div>

</body>
</html>
