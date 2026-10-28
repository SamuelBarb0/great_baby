{{--
    GH-F-015 · FORMATO EVALUACIÓN PERÍODO DE PRUEBA.

    Lo llena el jefe inmediato. Antes el ERP guardaba «continúa / no continúa»
    y una nota libre; el formato pide nueve notas y dos párrafos, que es lo que
    sustenta la decisión el día que alguien la reclama.

    Las notas se pintan con la escala completa y la marca en la que se eligió,
    igual que el papel: así se lee de un vistazo si el 3 fue «bien» o «apenas»
    comparado con las demás.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Evaluación de período de prueba — {{ $e->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 24px; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .enc td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: middle; }
        .enc .marca { font-size: 12pt; font-weight: 700; color: #b45309; width: 26%; }
        .enc .titulo { text-align: center; font-size: 10.5pt; font-weight: 700; }
        .enc .meta { font-size: 8pt; color: #374151; width: 22%; }
        h2 { font-size: 9.5pt; margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        .datos { width: 100%; border-collapse: collapse; }
        .datos td { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9.5pt; }
        .datos .lbl { width: 22%; color: #6b7280; text-transform: uppercase; font-size: 8pt; }
        .notas { width: 100%; border-collapse: collapse; }
        .notas th { background: #fef3c7; color: #78350f; font-size: 8pt; padding: 5px 6px; text-align: center; }
        .notas th.izq { text-align: left; }
        .notas td { border-bottom: 1px solid #e5e7eb; padding: 5px 6px; font-size: 9pt; }
        .notas td.n { text-align: center; width: 26px; }
        .marcada { background: #b45309; color: #fff; font-weight: 700; border-radius: 2px; }
        .na { color: #9ca3af; font-style: italic; font-size: 8.5pt; }
        .texto { border: 1px solid #d1d5db; border-radius: 4px; padding: 9px 11px; min-height: 36px; font-size: 9.5pt; }
        .veredicto { margin-top: 12px; padding: 9px 12px; border-left: 3px solid; font-size: 10pt; }
        .si { border-color: #059669; background: #ecfdf5; }
        .no { border-color: #b91c1c; background: #fef2f2; }
        .firma { margin-top: 40px; width: 58%; }
        .linea { border-top: 1px solid #111; margin-bottom: 4px; }
        .rol { font-size: 8.5pt; color: #374151; }
        .pie { margin-top: 22px; padding-top: 8px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    <table class="datos">
        <tr>
            <td class="lbl">Nombre del trabajador</td><td><strong>{{ $e->nombre }}</strong></td>
            <td class="lbl">Fecha de ingreso</td><td>{{ $e->fecha_ingreso?->format('d/m/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo desempeñado</td><td>{{ $e->cargo }}</td>
            <td class="lbl">Fecha de evaluación</td><td>{{ $ev->fecha_evaluacion?->format('d/m/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Jefe inmediato</td><td>{{ $ev->jefe?->name ?? '—' }}</td>
            <td class="lbl">Cargo del jefe</td><td>{{ $ev->jefe_cargo ?: '—' }}</td>
        </tr>
    </table>

    <h2>Aspectos a evaluar</h2>
    <p style="font-size: 9pt; margin: 0 0 6px; color: #4b5563;">
        Califique de 1 a 5, siendo 5 la mayor puntuación.
    </p>

    <table class="notas">
        <thead>
            <tr>
                <th class="izq">Aspecto</th>
                @for ($n = 1; $n <= 5; $n++)<th>{{ $n }}</th>@endfor
            </tr>
        </thead>
        <tbody>
            @foreach ($ev->calificaciones() as $c)
                <tr>
                    <td>
                        {{ $c['label'] }}
                        @if ($c['si_aplica'] && $c['nota'] === null)
                            <span class="na">· no aplica</span>
                        @endif
                    </td>
                    @for ($n = 1; $n <= 5; $n++)
                        <td class="n">
                            @if ($c['nota'] === $n)<span class="marcada">&nbsp;X&nbsp;</span>@endif
                        </td>
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($ev->promedio() !== null)
        <p style="font-size: 9pt; margin-top: 7px; color: #4b5563;">
            Promedio de lo calificado: <strong>{{ number_format($ev->promedio(), 2, ',', '.') }}</strong>
            de 5. (Es un resumen; la decisión es la de abajo.)
        </p>
    @endif

    <h2>Aspectos a resaltar</h2>
    <div class="texto">{{ $ev->aspectos_resaltar ?: '—' }}</div>

    <h2>Aspectos a mejorar</h2>
    <div class="texto">{{ $ev->aspectos_mejorar ?: '—' }}</div>

    <div class="veredicto {{ $ev->resultado === 'continua' ? 'si' : 'no' }}">
        <strong>¿La persona pasa su período de prueba?</strong>
        {{ $ev->resultado === 'continua' ? 'SÍ' : 'NO' }}
        @if ($ev->motivo)
            <div style="margin-top: 5px;"><strong>¿Por qué?</strong> {{ $ev->motivo }}</div>
        @endif
    </div>

    @if ($ev->observaciones)
        <h2>Observaciones</h2>
        <div class="texto">{{ $ev->observaciones }}</div>
    @endif

    <div class="firma">
        <div class="linea"></div>
        <div class="rol">Firma del jefe inmediato</div>
        <div class="rol">{{ $ev->jefe?->name }}{{ $ev->jefe_cargo ? ' · '.$ev->jefe_cargo : '' }}</div>
    </div>

    @include('rrhh.pdf.partials.pie')

</body>
</html>
