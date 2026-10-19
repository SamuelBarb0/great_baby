{{--
    Los indicadores, impresos para llevar a la Junta Directiva.

    Mismo orden que en pantalla, y mismos números: los calcula la misma clase,
    así que no pueden decir cosas distintas.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Indicadores de Gestión Humana</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 24px; }
        h1 { font-size: 16pt; margin: 0 0 4px; color: #b45309; }
        h2 { font-size: 11pt; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #d1d5db; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #fef3c7; padding: 6px 8px; font-size: 9pt; color: #78350f; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; vertical-align: top; }
        .grid { display: table; width: 100%; margin: 8px 0; }
        .grid > div { display: table-cell; padding: 6px 8px; width: 25%; border: 1px solid #e5e7eb; text-align: center; }
        .lbl { font-size: 8pt; color: #6b7280; text-transform: uppercase; }
        .val { font-size: 16pt; font-weight: 700; }
        .gran { font-size: 30pt; font-weight: 700; color: #b45309; }
        .nota { font-size: 8pt; color: #6b7280; }
        .aviso { padding: 8px 10px; border-left: 3px solid #f59e0b; background: #fffbeb; font-size: 9pt; }
        .footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #d1d5db; font-size: 8pt; color: #6b7280; }
        .r { text-align: right; }
    </style>
</head>
<body>
    <h1>GREAT BABY S.A.S.</h1>
    <div style="font-size: 9pt; color: #6b7280;">NIT 901.738.354 · Bucaramanga</div>

    <h2>Indicadores de Gestión Humana · {{ $i['rango']['desde'] }} a {{ $i['rango']['hasta'] }}</h2>

    <h2>Rotación del personal</h2>
    <div style="text-align: center; margin: 10px 0;">
        <span class="gran">{{ $i['rotacion']['porcentaje'] }}%</span>
        <div class="nota">
            {{ $i['rotacion']['retiros'] }} retiro(s) sobre un promedio de
            {{ $i['rotacion']['promedio'] }} persona(s) en el período
        </div>
    </div>
    @if($i['rotacion']['advertencia'])
        <div class="aviso" style="margin-bottom: 8px;">{{ $i['rotacion']['advertencia'] }}</div>
    @endif
    <div class="grid">
        <div><div class="lbl">Al inicio</div><div class="val">{{ $i['rotacion']['al_inicio'] }}</div></div>
        <div><div class="lbl">Ingresos</div><div class="val">+{{ $i['rotacion']['ingresos'] }}</div></div>
        <div><div class="lbl">Retiros</div><div class="val">−{{ $i['rotacion']['retiros'] }}</div></div>
        <div><div class="lbl">Al final</div><div class="val">{{ $i['rotacion']['al_final'] }}</div></div>
    </div>

    <h2>Ausentismo</h2>
    @if($i['ausentismo']['disponible'])
        <div>{{ $i['ausentismo']['valor'] ?? '—' }}</div>
    @else
        {{-- Se dice que falta y por qué. Un número sin respaldo en una junta
             es peor que un hueco, porque el inventado se cree. --}}
        <div class="aviso">{{ $i['ausentismo']['motivo'] }}</div>
    @endif

    <h2>Cobertura de inducción</h2>
    <div style="font-size: 9pt;">
        <strong style="font-size: 14pt;">{{ $i['induccion']['porcentaje'] }}%</strong> ·
        {{ $i['induccion']['con_induccion'] }} de {{ $i['induccion']['activos'] }} activos ·
        {{ $i['induccion']['pendientes'] }} pendiente(s)
    </div>
    <div class="nota">{{ $i['induccion']['nota'] }}</div>

    <h2>Por qué se retira la gente</h2>
    @if(! $i['retiros']['total'])
        <div class="nota">Nadie se retiró en este período.</div>
    @else
        <table>
            <thead><tr><th>Motivo</th><th class="r" style="width: 80px;">Cantidad</th></tr></thead>
            <tbody>
                @foreach($i['retiros']['por_tipo'] as $t)
                    <tr><td>{{ $t['label'] }}</td><td class="r">{{ $t['cantidad'] }}</td></tr>
                @endforeach
                <tr style="font-weight: 700;">
                    <td>Total</td><td class="r">{{ $i['retiros']['total'] }}</td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 10px;">
            <table>
                <thead>
                    <tr><th>Persona</th><th>Cargo</th><th>Motivo</th><th>Detalle</th><th style="width: 80px;">Fecha</th></tr>
                </thead>
                <tbody>
                    @foreach($i['retiros']['detalle'] as $d)
                        <tr>
                            <td>{{ $d['nombre'] }}</td>
                            <td>{{ $d['cargo'] }}<br><span class="nota">{{ $d['area'] }}</span></td>
                            <td>{{ $d['tipo'] }}</td>
                            <td>{{ $d['motivo'] ?: '—' }}</td>
                            <td>{{ $d['fecha'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>Estructura de personal · {{ $i['estructura']['total'] }} activos</h2>
    <table>
        <thead><tr><th>Área</th><th class="r" style="width: 70px;">Personas</th></tr></thead>
        <tbody>
            @forelse($i['estructura']['por_area'] as $f)
                <tr><td>{{ $f['nombre'] }}</td><td class="r">{{ $f['cantidad'] }}</td></tr>
            @empty
                <tr><td colspan="2" class="nota">Sin datos.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table style="margin-top: 10px;">
        <thead><tr><th>Tipo de contrato</th><th class="r" style="width: 70px;">Personas</th></tr></thead>
        <tbody>
            @forelse($i['estructura']['por_contrato'] as $f)
                <tr><td>{{ $f['nombre'] }}</td><td class="r">{{ $f['cantidad'] }}</td></tr>
            @empty
                <tr><td colspan="2" class="nota">Sin datos.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Cómo estamos creciendo</h2>
    <table>
        <thead>
            <tr><th>Mes</th><th class="r">Ingresos</th><th class="r">Retiros</th><th class="r">Saldo</th></tr>
        </thead>
        <tbody>
            @foreach($i['movimiento'] as $m)
                <tr>
                    <td style="text-transform: capitalize;">{{ $m['etiqueta'] }}</td>
                    <td class="r">{{ $m['ingresos'] ?: '—' }}</td>
                    <td class="r">{{ $m['retiros'] ?: '—' }}</td>
                    <td class="r" style="font-weight: 700;">
                        {{ $m['saldo'] > 0 ? '+'.$m['saldo'] : $m['saldo'] }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generado el {{ now()->format('Y-m-d H:i') }} por {{ $generadoPor }} · GREAT BABY ERP.
    </div>
</body>
</html>
