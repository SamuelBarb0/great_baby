{{--
    DESPRENDIBLE DE PAGO.

    Sale de la línea guardada, no de un recálculo: es el soporte de lo que se
    le pagó a esta persona y tiene que decir lo mismo dentro de dos años,
    aunque le hayan subido el sueldo o ya no trabaje acá.

    Cada concepto va con su fórmula en palabras. El número solo obliga a
    creerle al sistema; con la cuenta al lado, quien recibe el desprendible
    puede revisarlo.

    No lleva recuadro de control documental: no es un formato del sistema de
    gestión del área, es el soporte de un pago.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Desprendible — {{ $l->nombre }} — {{ $nomina->periodo }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; margin: 0; padding: 24px; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .enc td { padding: 6px 8px; vertical-align: middle; }
        .marca { font-size: 13pt; font-weight: 700; color: #b45309; }
        .titulo { text-align: right; font-size: 10pt; font-weight: 700; }
        .periodo { text-align: right; font-size: 8.5pt; color: #6b7280; }
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
        .firma { margin-top: 42px; width: 60%; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
        .aviso { border-left: 3px solid #b45309; background: #fffbeb; padding: 7px 10px; margin: 12px 0; font-size: 8.5pt; color: #78350f; }
        .pie { margin-top: 20px; padding-top: 7px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @php
        $money = fn ($v) => '$'.number_format((float) $v, 2, ',', '.');
    @endphp

    <table class="enc">
        <tr>
            <td class="marca">{{ $empresa->nombre_comercial ?: $empresa->razon_social }}</td>
            <td>
                <div class="titulo">DESPRENDIBLE DE PAGO</div>
                <div class="periodo">{{ $nomina->titulo() }}</div>
            </td>
        </tr>
    </table>

    <table class="datos">
        <tr>
            <td class="lbl">Colaborador</td>
            <td><strong>{{ $l->nombre }}</strong></td>
            <td class="lbl">Documento</td>
            <td>{{ $l->documento ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo</td>
            <td>{{ $l->cargo ?: '—' }}</td>
            <td class="lbl">Días pagados</td>
            <td>{{ rtrim(rtrim(number_format((float) $l->dias_trabajados, 2, ',', '.'), '0'), ',') }}</td>
        </tr>
        <tr>
            <td class="lbl">Salario base</td>
            <td>{{ $money($l->salario_base) }}</td>
            <td class="lbl">Fecha de pago</td>
            <td>{{ $nomina->fecha_pago?->format('d/m/Y') ?: 'pendiente' }}</td>
        </tr>
    </table>

    <table class="conceptos">
        <thead>
            <tr>
                <th style="width:62%;">Concepto</th>
                <th class="num">Devengado</th>
                <th class="num">Deducido</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($l->conceptos() as $c)
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
            <td>Total devengado</td>
            <td class="num">{{ $money($l->total_devengado) }}</td>
        </tr>
        <tr>
            <td>Total deducido</td>
            <td class="num resta">{{ $money($l->total_deducido) }}</td>
        </tr>
        <tr class="neto">
            <td>NETO A PAGAR</td>
            <td class="num">{{ $money($l->neto) }}</td>
        </tr>
    </table>

    @if ($nomina->estado !== 'pagada')
        <div class="aviso">
            <strong>Borrador.</strong>
            Esta nómina todavía no está pagada ({{ strtolower($nomina->estadoLabel()) }}).
            Los valores pueden cambiar hasta que gerencia la apruebe.
        </div>
    @endif

    <div class="firma">
        <div class="linea"></div>
        <div class="rol">Firma de quien recibe</div>
        <div class="rol">{{ $l->nombre }} · C.C. {{ $l->documento ?: '—' }}</div>
    </div>

    <div class="pie">
        Las deducciones de salud y pensión se calculan sobre el ingreso base de cotización, que
        no incluye el auxilio de transporte.<br>
        Generado desde el ERP por {{ $generadoPor }} el {{ now()->format('d/m/Y H:i') }}.
    </div>

</body>
</html>
