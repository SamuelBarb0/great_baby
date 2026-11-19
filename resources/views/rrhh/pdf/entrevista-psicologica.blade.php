{{--
    GH-F-004 · FORMATO ENTREVISTA PSICOLÓGICA · formato 4 del área.

    Tres tablas que crecen —familia, estudios y empleos— y cuatro bloques de
    texto abierto.

    Lo que más valor tiene de este formato no es ninguno de los cuatro: es el
    MOTIVO DE RETIRO de cada empleo anterior. Tres salidas por lo mismo dicen
    más que cualquier respuesta, y es un patrón que una sola entrevista no
    muestra. Por eso esa columna va en la tabla y no escondida en
    observaciones.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrevista psicológica — {{ $c->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; margin: 0; padding: 22px; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .enc td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: middle; }
        .enc .marca { font-size: 12pt; font-weight: 700; color: #b45309; width: 26%; }
        .enc .titulo { text-align: center; font-size: 10.5pt; font-weight: 700; }
        .enc .meta { font-size: 8pt; color: #374151; width: 22%; }
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.datos td { padding: 4px 7px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .lbl { color: #6b7280; text-transform: uppercase; font-size: 7.5pt; width: 18%; }
        h2 { font-size: 9pt; margin: 14px 0 5px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        table.lista { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.lista th { background: #fef3c7; color: #78350f; font-size: 7.5pt; padding: 4px 6px; text-align: left; }
        table.lista td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; font-size: 8.5pt; }
        .vacio { color: #9ca3af; font-style: italic; }
        .preg { font-size: 8.5pt; color: #6b7280; text-transform: uppercase; margin-top: 8px; }
        .rta { border-bottom: 1px solid #d1d5db; min-height: 15px; padding: 3px 2px 2px; font-size: 9pt; }
        .firma { margin-top: 36px; width: 55%; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
        .reserva { border-left: 3px solid #b45309; background: #fffbeb; padding: 7px 10px; margin: 12px 0; font-size: 8pt; color: #78350f; }
        .pie { margin-top: 18px; padding-top: 7px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    @php $bloques = \App\Modules\Rrhh\Models\EntrevistaCandidato::PSICOLOGICA_BLOQUES; @endphp

    <h2>Identificación</h2>
    <table class="datos">
        <tr>
            <td class="lbl">Nombre</td><td><strong>{{ $c->nombre }}</strong></td>
            <td class="lbl">Documento</td><td>{{ $c->documento ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo al que aspira</td><td>{{ $c->vacante?->titulo ?: '—' }}</td>
            <td class="lbl">Teléfono</td><td>{{ $c->telefono ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Fecha</td><td>{{ $e->fecha?->format('d/m/Y') }}</td>
            <td class="lbl">Correo</td><td>{{ $c->email ?: '—' }}</td>
        </tr>
    </table>

    {{-- Las tres tablas que crecen --}}
    @foreach (['familia', 'academica', 'laboral'] as $clave)
        @php $b = $bloques[$clave]; @endphp
        <h2>{{ $b['titulo'] }}</h2>
        <table class="lista">
            <thead>
                <tr>
                    @foreach ($b['tabla'] as $titulo)
                        <th>{{ $titulo }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($e->filasDe($clave) as $fila)
                    <tr>
                        @foreach (array_keys($b['tabla']) as $col)
                            <td>{{ $fila[$col] ?? '' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($b['tabla']) }}" class="vacio">Sin registros.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @foreach ($b['campos'] as $k => $pregunta)
            <div class="preg">{{ $pregunta }}</div>
            <div class="rta">{{ $e->respuesta($k) ?: '' }}</div>
        @endforeach
    @endforeach

    <h2>{{ $bloques['persona']['titulo'] }}</h2>
    @foreach ($bloques['persona']['campos'] as $k => $pregunta)
        <div class="preg">{{ $pregunta }}</div>
        <div class="rta">{{ $e->respuesta($k) ?: '' }}</div>
    @endforeach

    @if ($e->observaciones)
        <h2>Observaciones</h2>
        <div class="rta">{{ $e->observaciones }}</div>
    @endif

    {{--
        Esto lleva datos de la familia de alguien que ni siquiera trabaja acá
        todavía. El papel lo dice: circula menos de lo que uno cree.
    --}}
    <div class="reserva">
        Este documento contiene datos personales del aspirante y de su núcleo familiar.
        Su manejo está sujeto a la Ley 1581 de 2012: se conserva en la carpeta del proceso de
        selección y no se comparte fuera de Gestión Humana.
    </div>


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
