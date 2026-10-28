{{--
    FORMATO ENTREVISTA DE RETIRO.

    El formato que mandó el área trae el código «GH-F-XX» sin asignar: el área
    todavía no le puso número. Se imprime así, marcándolo, en vez de inventarle
    uno — un código inventado rompe la numeración del sistema de calidad.

    El bloque 5 (paz y salvo) NO se escribe a mano: sale de `rrhh_asignaciones`,
    que es donde ya está lo que se le entregó a la persona con su serial y su
    estado de devolución.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrevista de retiro — {{ $e->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; margin: 0; padding: 22px; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .enc td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: middle; }
        .enc .marca { font-size: 12pt; font-weight: 700; color: #b45309; width: 26%; }
        .enc .titulo { text-align: center; font-size: 10.5pt; font-weight: 700; }
        .enc .meta { font-size: 8pt; color: #374151; width: 22%; }
        h2 { font-size: 9pt; margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        table.datos { width: 100%; border-collapse: collapse; }
        table.datos td { padding: 4px 7px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .lbl { color: #6b7280; text-transform: uppercase; font-size: 7.5pt; width: 20%; }
        .marcado { font-weight: 700; }
        ul.motivos { list-style: none; margin: 0; padding: 0; column-count: 2; }
        ul.motivos li { font-size: 9pt; margin-bottom: 3px; }
        .caja { border: 1px solid #d1d5db; border-radius: 3px; padding: 7px 10px; min-height: 28px; font-size: 9pt; }
        table.notas { width: 100%; border-collapse: collapse; }
        table.notas th { background: #fef3c7; color: #78350f; font-size: 7.5pt; padding: 4px 5px; text-align: center; }
        table.notas th.izq { text-align: left; }
        table.notas td { border-bottom: 1px solid #e5e7eb; padding: 4px 5px; font-size: 8.5pt; vertical-align: top; }
        table.notas td.n { text-align: center; width: 20px; }
        .x { background: #b45309; color: #fff; font-weight: 700; border-radius: 2px; padding: 0 3px; }
        .bajo { background: #fef2f2; }
        .firmas { width: 100%; margin-top: 34px; border-collapse: collapse; }
        .firmas td { width: 50%; padding: 0 14px; vertical-align: top; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
        .pie { margin-top: 20px; padding-top: 7px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
        .aviso { border-left: 3px solid #b91c1c; background: #fef2f2; padding: 7px 10px; margin: 8px 0; font-size: 8.5pt; color: #7f1d1d; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    <h2>1 · Datos generales</h2>
    <table class="datos">
        <tr>
            <td class="lbl">Colaborador</td><td class="marcado">{{ $e->nombre }}</td>
            <td class="lbl">Fecha de entrevista</td><td>{{ $ev->fecha_entrevista?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo ocupado</td><td>{{ $e->cargo }}</td>
            <td class="lbl">Fecha de ingreso</td><td>{{ $e->fecha_ingreso?->format('d/m/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Área / departamento</td><td>{{ $e->area }}</td>
            <td class="lbl">Fecha de retiro</td><td>{{ $e->fecha_retiro?->format('d/m/Y') ?? 'sin registrar' }}</td>
        </tr>
        <tr>
            <td class="lbl">Tipo de contrato</td><td>{{ $e->tipo_contrato ?: '—' }}</td>
            <td class="lbl">Antigüedad</td>
            <td>
                @if ($e->fecha_ingreso)
                    {{ $e->fecha_ingreso->diff($e->fecha_retiro ?? now())->format('%y año(s), %m mes(es)') }}
                @else — @endif
            </td>
        </tr>
        <tr>
            <td class="lbl">Jefe inmediato</td>
            <td>{{ $e->vacante?->requisicion?->solicitante?->name ?? '—' }}</td>
            <td class="lbl">Motivo de retiro</td>
            <td>
                <span class="{{ $ev->salida === 'voluntaria' ? 'marcado' : '' }}">
                    [{{ $ev->salida === 'voluntaria' ? 'X' : '&nbsp;&nbsp;' }}] Voluntario
                </span>
                &nbsp;
                <span class="{{ $ev->salida === 'involuntaria' ? 'marcado' : '' }}">
                    [{{ $ev->salida === 'involuntaria' ? 'X' : '&nbsp;&nbsp;' }}] Involuntario
                </span>
            </td>
        </tr>
    </table>

    <h2>2 · Motivo principal de la salida</h2>
    <ul class="motivos">
        @foreach ($motivos as $m)
            @php $elegido = $ev->motivo_principal?->value === $m['valor']; @endphp
            <li class="{{ $elegido ? 'marcado' : '' }}">
                [{{ $elegido ? 'X' : '&nbsp;&nbsp;' }}] {{ $m['label'] }}
                @if ($elegido && $m['valor'] === 'otro' && $ev->motivo_otro)
                    — {{ $ev->motivo_otro }}
                @endif
            </li>
        @endforeach
    </ul>
    <div style="margin-top:7px;"><strong>Detalle del motivo</strong> (comentarios del colaborador):</div>
    <div class="caja">{{ $ev->detalle_motivo ?: '—' }}</div>

    <h2>3 · Evaluación de la experiencia laboral</h2>
    <p style="font-size:8.5pt;margin:0 0 5px;color:#4b5563;">
        1 es Deficiente y 5 es Excelente.
    </p>
    <table class="notas">
        <thead>
            <tr>
                <th class="izq" style="width:34%;">Criterio</th>
                @for ($n = 1; $n <= 5; $n++)<th>{{ $n }}</th>@endfor
                <th class="izq">Comentarios</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ev->criterios() as $c)
                <tr class="{{ $c['nota'] !== null && $c['nota'] <= 2 ? 'bajo' : '' }}">
                    <td>{{ $c['label'] }}</td>
                    @for ($n = 1; $n <= 5; $n++)
                        <td class="n">@if ($c['nota'] === $n)<span class="x">X</span>@endif</td>
                    @endfor
                    <td>{{ $c['comentario'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($ev->promedio() !== null)
        <p style="font-size:8.5pt;margin-top:5px;color:#4b5563;">
            Promedio de lo calificado: <strong>{{ number_format($ev->promedio(), 2, ',', '.') }}</strong> de 5.
        </p>
    @endif

    <h2>4 · Retroalimentación cualitativa</h2>
    <div style="font-size:8.5pt;margin-bottom:3px;">¿Qué fue lo que más disfrutó o valoró de su tiempo en la compañía?</div>
    <div class="caja">{{ $ev->que_valoro ?: '—' }}</div>
    <div style="font-size:8.5pt;margin:7px 0 3px;">¿Qué aspectos considera que la empresa debe mejorar?</div>
    <div class="caja">{{ $ev->que_mejorar ?: '—' }}</div>
    <div style="font-size:8.5pt;margin:7px 0 3px;">
        ¿Las funciones desarrolladas correspondían a lo ofrecido en su contratación?
        <strong>{{ $ev->funciones_correspondian === null ? '—' : ($ev->funciones_correspondian ? 'Sí' : 'No') }}</strong>
    </div>
    <div class="caja">{{ $ev->funciones_explicacion ?: '—' }}</div>
    <div style="font-size:8.5pt;margin:7px 0 3px;">
        ¿Recomendaría a la empresa como un excelente lugar para trabajar?
        <strong>{{ $ev->recomendaria === null ? '—' : ($ev->recomendaria ? 'Sí' : 'No') }}</strong>
    </div>
    <div class="caja">{{ $ev->recomendaria_por_que ?: '—' }}</div>

    <h2>5 · Paz y salvo y devolución de herramientas</h2>
    <table class="notas">
        <thead>
            <tr>
                <th class="izq">Elemento / herramienta</th>
                <th class="izq" style="width:18%;">Entregado</th>
                <th class="izq" style="width:28%;">Estado de recepción</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($asignaciones as $a)
                <tr>
                    <td>{{ $a->rotulo ?? $a->descripcion }}</td>
                    <td>{{ $a->estado === 'devuelta' ? 'Sí' : ($a->estado === 'perdida' ? 'NO devuelto' : 'Pendiente') }}</td>
                    <td>{{ $a->estado_devolucion ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No se le entregó dotación ni herramientas registradas.</td></tr>
            @endforelse
            <tr>
                <td>Cierre de correos y accesos a sistemas</td>
                <td>{{ $ev->accesos_cerrados ? 'Sí' : 'Pendiente' }}</td>
                <td>—</td>
            </tr>
        </tbody>
    </table>

    @if ($pendientes->isNotEmpty())
        <div class="aviso">
            <strong>No se puede firmar el paz y salvo:</strong>
            quedan {{ $pendientes->count() }} elemento(s) sin devolver —
            {{ $pendientes->map(fn ($a) => $a->rotulo ?? $a->descripcion)->implode(', ') }}.
        </div>
    @endif

    @if ($ev->observaciones)
        <h2>Observaciones</h2>
        <div class="caja">{{ $ev->observaciones }}</div>
    @endif

    <h2>6 · Firmas de conformidad</h2>
    <table class="firmas">
        <tr>
            <td>
                <div class="linea"></div>
                <div class="rol">Entrevistado (colaborador que se retira)</div>
                <div class="rol">{{ $e->nombre }} · C.C. {{ $e->numero_documento }}</div>
            </td>
            <td>
                <div class="linea"></div>
                <div class="rol">Entrevistador (Gestión Humana)</div>
                <div class="rol">{{ $ev->entrevistador?->name ?? '—' }}</div>
            </td>
        </tr>
    </table>

    @include('rrhh.pdf.partials.pie')

</body>
</html>
