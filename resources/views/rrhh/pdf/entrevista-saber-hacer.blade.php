{{--
    FORMATO DE ENTREVISTA SABER HACER · formato 3 del área.

    Catorce preguntas en tres bloques y seis ítems calificados de 1 a 5.

    El impreso trae las preguntas aunque no se hayan contestado: es el guion
    de la entrevista, y sirve tanto para llenarlo a mano antes como para
    imprimirlo después con lo que se registró.

    El recuadro de control lo inyecta el view composer desde el listado
    maestro, igual que los demás formatos.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrevista saber hacer — {{ $c->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; margin: 0; padding: 22px; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .enc td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: middle; }
        .enc .marca { font-size: 12pt; font-weight: 700; color: #b45309; width: 26%; }
        .enc .titulo { text-align: center; font-size: 10.5pt; font-weight: 700; }
        .enc .meta { font-size: 8pt; color: #374151; width: 22%; }
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.datos td { padding: 4px 7px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .lbl { color: #6b7280; text-transform: uppercase; font-size: 7.5pt; width: 20%; }
        h2 { font-size: 9pt; margin: 14px 0 4px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        .nota { font-size: 7.5pt; color: #6b7280; font-style: italic; margin-bottom: 6px; }
        .preg { font-size: 9pt; margin-top: 7px; }
        .rta { border-bottom: 1px solid #d1d5db; min-height: 15px; padding: 3px 2px 2px; font-size: 9pt; color: #111; }
        .vacia { color: #9ca3af; font-style: italic; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th { background: #fef3c7; color: #78350f; font-size: 7.5pt; padding: 4px 6px; text-align: left; }
        table.items th.n { text-align: center; width: 26px; }
        table.items td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; font-size: 8.5pt; }
        table.items td.n { text-align: center; }
        .marcada { font-weight: 700; color: #b45309; }
        .total { margin-top: 8px; font-size: 10pt; font-weight: 700; }
        .firma { margin-top: 36px; width: 55%; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
        .pie { margin-top: 18px; padding-top: 7px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    <table class="datos">
        <tr>
            <td class="lbl">Candidato</td><td><strong>{{ $c->nombre }}</strong></td>
            <td class="lbl">Fecha</td><td>{{ $e->fecha?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo</td><td>{{ $c->vacante?->titulo ?: '—' }}</td>
            <td class="lbl">Área</td><td>{{ $c->vacante?->area ?: '—' }}</td>
        </tr>
    </table>

    @foreach (\App\Modules\Rrhh\Models\EntrevistaCandidato::SABER_HACER_BLOQUES as $clave => $bloque)
        <h2>{{ $loop->iteration }}. {{ $bloque['titulo'] }}</h2>
        @if ($bloque['nota'])
            <div class="nota">{{ $bloque['nota'] }}</div>
        @endif

        @foreach ($bloque['preguntas'] as $k => $pregunta)
            <div class="preg">{{ $loop->parent->iteration }}.{{ $loop->iteration }}. {{ $pregunta }}</div>
            <div class="rta">
                @if ($e->respuesta($k))
                    {{ $e->respuesta($k) }}
                @else
                    <span class="vacia">Sin respuesta registrada.</span>
                @endif
            </div>
        @endforeach
    @endforeach


    @if (!empty($extra))
        <h2>Preguntas del jefe del área</h2>
        <div class="nota">
            Las de arriba son las que sugiere Gestión Humana. Éstas las agregó quien pidió el personal,
            para este cargo.
        </div>
        @foreach ($extra as $p)
            <div class="preg">{{ $loop->iteration }}. {{ $p['pregunta'] }}</div>
            <div class="rta">
                @if ($e->respuesta($p['clave']))
                    {{ $e->respuesta($p['clave']) }}
                @else
                    <span class="vacia">Sin respuesta registrada.</span>
                @endif
            </div>
        @endforeach
    @endif

    <h2>Evaluación de la entrevista</h2>
    <div class="nota">
        Califique de uno a cinco, donde uno es la menor puntuación y cinco la más alta,
        de acuerdo con las respuestas y la actitud del candidato.
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Ítem</th>
                @for ($n = 1; $n <= 5; $n++)
                    <th class="n">{{ $n }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach (\App\Modules\Rrhh\Models\EntrevistaCandidato::SABER_HACER_ITEMS as $k => $texto)
                @php $nota = (int) ($e->calificaciones[$k] ?? 0); @endphp
                <tr>
                    <td>{{ $loop->iteration }}. {{ $texto }}</td>
                    @for ($n = 1; $n <= 5; $n++)
                        <td class="n {{ $nota === $n ? 'marcada' : '' }}">{{ $nota === $n ? 'X' : '' }}</td>
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total">
        TOTAL: {{ $e->totalCalculado() !== null ? $e->totalCalculado().' de 30' : '______' }}
    </div>

    @if ($e->observaciones)
        <h2>Observaciones</h2>
        <div class="rta">{{ $e->observaciones }}</div>
    @endif

    <div class="firma">
        <div class="linea"></div>
        <div class="rol">Firma del entrevistador</div>
        <div class="rol">
            {{ $e->entrevistador_nombre }}@if ($e->entrevistador_cargo) · {{ $e->entrevistador_cargo }} @endif
        </div>
    </div>

    @include('rrhh.pdf.partials.pie')

</body>
</html>
