{{--
    FORMATO ENTREGA DE CARGO · PAZ Y SALVO.

    El formato del área no trae código de control documental; se imprime
    marcándolo, igual que la entrevista de retiro, en vez de inventarle uno.

    Los equipos y la dotación NO se escriben acá: salen de `rrhh_asignaciones`,
    separados igual que en el papel —equipo y herramienta los recibe el jefe,
    la dotación la recibe Gestión Humana— y con el serial y el estado que ya
    tienen registrados.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrega de cargo — {{ $e->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; margin: 0; padding: 22px; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .enc td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: middle; }
        .enc .marca { font-size: 12pt; font-weight: 700; color: #b45309; width: 26%; }
        .enc .titulo { text-align: center; font-size: 10.5pt; font-weight: 700; }
        .enc .meta { font-size: 8pt; color: #374151; width: 22%; }
        h2 { font-size: 9pt; margin: 14px 0 5px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        .area { font-size: 7.5pt; color: #6b7280; font-weight: normal; text-transform: none; }
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.datos td { padding: 4px 7px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .lbl { color: #6b7280; text-transform: uppercase; font-size: 7.5pt; width: 20%; }
        table.lista { width: 100%; border-collapse: collapse; }
        table.lista th { background: #fef3c7; color: #78350f; font-size: 7.5pt; padding: 4px 6px; text-align: left; }
        table.lista td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; font-size: 8.5pt; }
        .vacio { color: #9ca3af; font-style: italic; }
        .caja { border: 1px solid #d1d5db; border-radius: 3px; padding: 7px 10px; min-height: 26px; font-size: 9pt; }
        .firmas { width: 100%; margin-top: 16px; border-collapse: collapse; }
        .firmas td { width: 50%; padding: 0 14px 0 0; vertical-align: top; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
        .ok { color: #047857; font-size: 8pt; }
        .falta { color: #b91c1c; font-size: 8pt; }
        .aviso { border-left: 3px solid #b91c1c; background: #fef2f2; padding: 7px 10px; margin: 10px 0; font-size: 8.5pt; color: #7f1d1d; }
        .pie { margin-top: 18px; padding-top: 7px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    <table class="datos">
        <tr>
            <td class="lbl">Quien entrega</td><td><strong>{{ $e->nombre }}</strong> · C.C. {{ $e->numero_documento }}</td>
            <td class="lbl">Cargo entregado</td><td>{{ $e->cargo }}</td>
        </tr>
        <tr>
            <td class="lbl">Quien recibe</td>
            <td>{{ $entrega->quienRecibe() ?: '—' }}</td>
            <td class="lbl">Área</td><td>{{ $e->area }}</td>
        </tr>
    </table>

    @php $ninguno = fn ($c) => $c->isEmpty(); @endphp

    {{-- ── Bloque del jefe inmediato ───────────────────────────────────── --}}
    @foreach (['funcion', 'pendiente', 'archivo_digital', 'archivo_fisico'] as $b)
        <h2>
            {{ $bloques[$b]['titulo'] }}
            <span class="area">· jefe inmediato</span>
        </h2>
        <table class="lista">
            <thead>
                <tr><th style="width:58%;">Nombre</th><th>{{ $bloques[$b]['detalle'] }}</th></tr>
            </thead>
            <tbody>
                @forelse ($entrega->de($b) as $item)
                    <tr><td>{{ $item->nombre }}</td><td>{{ $item->detalle }}</td></tr>
                @empty
                    <tr><td colspan="2" class="vacio">Sin registros.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach

    <h2>Entrega de equipos, máquinas y herramientas <span class="area">· jefe inmediato</span></h2>
    <table class="lista">
        <thead>
            <tr><th style="width:44%;">Nombre</th><th style="width:26%;">Identificador</th><th>Estado</th></tr>
        </thead>
        <tbody>
            @forelse ($equipos as $a)
                <tr>
                    <td>{{ $a->descripcion }}</td>
                    <td>{{ $a->identificador ?: '—' }}</td>
                    <td>{{ $a->estado === 'devuelta' ? 'Devuelto · '.($a->estado_devolucion ?: 'sin novedad') : 'PENDIENTE' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="vacio">No se le entregaron equipos ni herramientas.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="firmas">
        <tr>
            <td>
                <div class="linea"></div>
                <div class="rol">Firma de quien entrega</div>
                <div class="rol">{{ $e->nombre }}</div>
            </td>
            <td>
                <div class="linea"></div>
                <div class="rol">Firma del jefe inmediato</div>
                @if ($entrega->jefe_confirmo_at)
                    <div class="ok">
                        Conforme · {{ $entrega->jefeConfirmoPor?->name }} ·
                        {{ $entrega->jefe_confirmo_at->format('d/m/Y') }}
                    </div>
                @else
                    <div class="falta">Sin conformidad en el sistema</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── Bloque de Gestión Humana ────────────────────────────────────── --}}
    <h2>Entrega de dotación <span class="area">· Gestión Humana</span></h2>
    <table class="lista">
        <thead>
            <tr><th style="width:44%;">Nombre</th><th style="width:26%;">Identificador</th><th>Estado</th></tr>
        </thead>
        <tbody>
            @forelse ($dotacion as $a)
                <tr>
                    <td>{{ $a->descripcion }}</td>
                    <td>{{ $a->identificador ?: '—' }}</td>
                    <td>{{ $a->estado === 'devuelta' ? 'Devuelto · '.($a->estado_devolucion ?: 'sin novedad') : 'PENDIENTE' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="vacio">No se le entregó dotación.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="firmas">
        <tr>
            <td>
                <div class="linea"></div>
                <div class="rol">Firma de quien entrega</div>
                <div class="rol">{{ $e->nombre }}</div>
            </td>
            <td>
                <div class="linea"></div>
                <div class="rol">Firma de Gestión Humana</div>
                @if ($entrega->gh_confirmo_at)
                    <div class="ok">
                        Conforme · {{ $entrega->ghConfirmoPor?->name }} ·
                        {{ $entrega->gh_confirmo_at->format('d/m/Y') }}
                    </div>
                @else
                    <div class="falta">Sin conformidad en el sistema</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── Bloque de tesorería ─────────────────────────────────────────── --}}
    <h2>Paz y salvo <span class="area">· tesorería</span></h2>
    <table class="lista">
        <thead>
            <tr><th style="width:44%;">Concepto</th><th>Observaciones</th></tr>
        </thead>
        <tbody>
            @forelse ($entrega->de('paz_salvo') as $item)
                <tr><td>{{ $item->nombre }}</td><td>{{ $item->detalle }}</td></tr>
            @empty
                <tr><td colspan="2" class="vacio">Sin conceptos registrados.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="firmas">
        <tr>
            <td>
                <div class="linea"></div>
                <div class="rol">Firma de quien entrega</div>
                <div class="rol">{{ $e->nombre }}</div>
            </td>
            <td>
                <div class="linea"></div>
                <div class="rol">Firma de tesorería</div>
                @if ($entrega->tesoreria_confirmo_at)
                    <div class="ok">
                        Conforme · {{ $entrega->tesoreriaConfirmoPor?->name }} ·
                        {{ $entrega->tesoreria_confirmo_at->format('d/m/Y') }}
                    </div>
                @else
                    <div class="falta">Sin conformidad en el sistema</div>
                @endif
            </td>
        </tr>
    </table>

    @if ($entrega->de('contacto')->isNotEmpty())
        <h2>{{ $bloques['contacto']['titulo'] }}</h2>
        <table class="lista">
            <thead><tr><th style="width:44%;">Nombre</th><th>{{ $bloques['contacto']['detalle'] }}</th></tr></thead>
            <tbody>
                @foreach ($entrega->de('contacto') as $item)
                    <tr><td>{{ $item->nombre }}</td><td>{{ $item->detalle }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($entrega->observaciones)
        <h2>Observaciones generales</h2>
        <div class="caja">{{ $entrega->observaciones }}</div>
    @endif

    @if (! $entrega->completa())
        <div class="aviso">
            <strong>Entrega incompleta.</strong>
            Falta la conformidad de: {{ implode(', ', $entrega->faltanFirmas()) }}.
        </div>
    @endif

    @php $repetidas = $entrega->firmoVariosBloques(); @endphp

    @if ($repetidas !== [])
        {{--
            El formato pide tres revisiones independientes. Si una misma
            persona dio dos, el documento lo dice: queda firmado igual, pero
            quien lo lea sabe cuánta revisión hubo de verdad.
        --}}
        <div class="aviso" style="border-left-color:#b45309; background:#fffbeb; color:#78350f;">
            <strong>Conformidades de una misma persona.</strong>
            {{ implode(', ', $repetidas) }} dio más de una de las tres.
        </div>
    @endif

    @include('rrhh.pdf.partials.pie')

</body>
</html>
