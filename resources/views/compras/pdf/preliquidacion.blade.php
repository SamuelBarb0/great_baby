@php
    $p = $pre['parametros'];
    $t = $pre['totales'];
    $cop = fn ($v) => '$' . number_format((float) $v, 0, ',', '.');
    $num = fn ($v, $d = 2) => number_format((float) $v, $d, ',', '.');
@endphp
<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>Pre-liquidación {{ $orden->numero }}</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
@page { margin: 12mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #111; }
.header { border-bottom: 3px solid #b45309; padding-bottom: 5mm; margin-bottom: 4mm; }
.brand { font-size: 15pt; font-weight: 700; color: #b45309; letter-spacing: 1px; }
.doc-num { float:right; text-align:right; }
.doc-num .num { font-size: 13pt; font-weight: 700; }
.section { margin-top: 4mm; }
.section h3 { font-size: 9pt; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding-bottom: 1.5mm; margin-bottom: 1.5mm; letter-spacing: .5px; }
table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
th { background: #f3f4f6; text-align: left; font-size: 6.5pt; text-transform: uppercase; padding: 1.5mm; border-bottom: 2px solid #d1d5db; }
td { padding: 1.5mm; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
td.right, th.right { text-align: right; }
.params td { border: none; padding: .8mm 2mm; }
.params .k { color: #6b7280; }
.aviso { margin-top: 3mm; padding: 2mm 3mm; background: #fef3c7; border-left: 3px solid #d97706; color: #92400e; }
.total-row td { border-top: 2px solid #111; font-weight: 700; }
.stamp { margin-top: 6mm; text-align: center; color: #9ca3af; font-size: 7pt; border-top: 1px solid #e5e7eb; padding-top: 3mm; }
</style>
</head><body>

<div class="header">
    <div class="doc-num">
        <div style="color:#6b7280;font-size:8pt;">PRE-LIQUIDACIÓN DE IMPORTACIÓN</div>
        <div class="num">{{ $orden->numero }}</div>
        <div style="color:#6b7280;">{{ now('America/Bogota')->format('Y-m-d H:i') }}</div>
    </div>
    <div class="brand">GREAT BABY</div>
    <div style="color:#6b7280;font-size:9pt;">{{ $orden->proveedor?->nombreDisplay() ?? '—' }} · {{ $orden->moneda }} @ {{ $num($pre['tasa_cambio']) }}</div>
</div>

<div class="section">
    <h3>Contenedor</h3>
    <table class="params">
        <tr>
            <td><span class="k">Cajas</span> <b>{{ $num($t['cajas'], 0) }}</b></td>
            <td><span class="k">Unidades</span> <b>{{ $num($t['unidades'], 0) }}</b></td>
            <td><span class="k">Volumen</span> <b>{{ $num($t['cbm']) }} m³</b> de {{ $num($t['capacidad_m3']) }} ({{ $t['ocupacion_pct'] !== null ? $num($t['ocupacion_pct'], 1) . ' %' : '—' }})</td>
            <td><span class="k">Valor</span> <b>{{ $orden->moneda }} {{ $num($t['valor_moneda']) }}</b></td>
            <td><span class="k">Costo puesto en Colombia</span> <b>{{ $cop($t['costo_total']) }}</b></td>
        </tr>
    </table>
    @foreach($pre['por_destino'] as $d)
        <div style="margin-top:1mm;color:#374151;">
            <b>{{ $d['nombre'] }}:</b> {{ $num($d['cajas'], 0) }} cajas · {{ $num($d['cbm']) }} m³ · {{ $orden->moneda }} {{ $num($d['valor_moneda']) }}
        </div>
    @endforeach
    @foreach($pre['avisos'] as $aviso)
        <div class="aviso">{{ $aviso }}</div>
    @endforeach
</div>

<div class="section">
    <h3>Parámetros</h3>
    <table class="params">
        <tr>
            <td><span class="k">Comisión</span> {{ $num($p['comision_pct']) }} %</td>
            <td><span class="k">Flete por m³</span> {{ $cop($p['flete_m3']) }}</td>
            <td><span class="k">Gasto Colombia por m³</span> {{ $cop($p['gasto_colombia_m3']) }}</td>
            <td><span class="k">Arancel</span> {{ $num($p['arancel_pct']) }} %</td>
            <td><span class="k">IVA</span> {{ $num($p['iva_pct']) }} %</td>
            <td><span class="k">Precios</span> costo / {{ $num($p['divisor_precio_1']) }} y / {{ $num($p['divisor_precio_2']) }}</td>
        </tr>
    </table>
</div>

<div class="section">
    <h3>Por unidad (COP)</h3>
    <table>
        <thead>
            <tr>
                <th>Referencia</th>
                <th class="right">Cajas</th>
                <th class="right">Uds/caja</th>
                <th class="right">Precio {{ $orden->moneda }}</th>
                <th class="right">CBM caja</th>
                <th class="right">China c/com.</th>
                <th class="right">Flete</th>
                <th class="right">Arancel</th>
                <th class="right">IVA</th>
                <th class="right">Gasto Col.</th>
                <th class="right">Costo</th>
                <th class="right">Precio {{ $num($p['divisor_precio_1']) }}</th>
                <th class="right">Precio {{ $num($p['divisor_precio_2']) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pre['lineas'] as $l)
                <tr>
                    <td>{{ \Illuminate\Support\Str::limit($l['descripcion'], 55) }}</td>
                    <td class="right">{{ $num($l['cajas'], 0) }}</td>
                    <td class="right">{{ $num($l['unidades_por_caja'], 0) }}</td>
                    <td class="right">{{ $num($l['precio_unit']) }}</td>
                    <td class="right">{{ $num($l['cbm_caja'], 4) }}</td>
                    @if($l['unidad'])
                        <td class="right">{{ $cop($l['unidad']['costo_china_comision']) }}</td>
                        <td class="right">{{ $cop($l['unidad']['flete']) }}</td>
                        <td class="right">{{ $cop($l['unidad']['arancel']) }}</td>
                        <td class="right">{{ $cop($l['unidad']['iva']) }}</td>
                        <td class="right">{{ $cop($l['unidad']['gasto_colombia']) }}</td>
                        <td class="right"><b>{{ $cop($l['unidad']['costo']) }}</b></td>
                        <td class="right">{{ $cop($l['unidad']['precio_1']) }}</td>
                        <td class="right">{{ $cop($l['unidad']['precio_2']) }}</td>
                    @else
                        <td colspan="8" style="color:#b45309;">Sin unidades por caja o sin CBM</td>
                    @endif
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Total</td>
                <td class="right">{{ $num($t['cajas'], 0) }}</td>
                <td></td>
                <td class="right">{{ $num($t['valor_moneda']) }}</td>
                <td class="right">{{ $num($t['cbm']) }} m³</td>
                <td colspan="5"></td>
                <td class="right">{{ $cop($t['costo_total']) }}</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="stamp">
    Estimado antes de pedir: el costo real sale de la liquidación con los gastos y la tasa efectivos.
</div>
</body></html>
