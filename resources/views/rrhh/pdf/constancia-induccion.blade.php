{{--
    CONSTANCIA DE INDUCCIÓN.

    Lo firma quien la recibió, y por eso lleva el temario completo: el valor de
    este papel no es decir «se le dio la inducción» —eso no demuestra nada—
    sino decir QUÉ se le explicó, quién se lo explicó y qué quedó pendiente.

    Los temas sin cubrir salen marcados en vez de omitirse: una constancia que
    sólo muestra lo que se vio hace creer que el temario era ése.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Constancia de inducción — {{ $e->nombre }}</title>
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
        h2 { font-size: 9pt; margin: 13px 0 5px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        table.temas { width: 100%; border-collapse: collapse; }
        table.temas th { background: #fef3c7; color: #78350f; font-size: 7.5pt; padding: 4px 6px; text-align: left; }
        table.temas th.c { text-align: center; width: 34px; }
        table.temas td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; font-size: 8.5pt; }
        table.temas td.c { text-align: center; }
        .si { color: #047857; font-weight: 700; }
        .no { color: #b91c1c; font-weight: 700; }
        .pendiente td { background: #fef2f2; }
        .declara { margin-top: 14px; padding: 9px 11px; border: 1px solid #9ca3af; font-size: 8.5pt; line-height: 1.5; }
        .aviso { border-left: 3px solid #b45309; background: #fffbeb; padding: 7px 10px; margin: 12px 0; font-size: 8.5pt; color: #78350f; }
        .firmas { width: 100%; margin-top: 36px; border-collapse: collapse; }
        .firmas td { width: 50%; padding: 0 14px 0 0; vertical-align: top; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
        .pie { margin-top: 18px; padding-top: 7px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    <table class="datos">
        <tr>
            <td class="lbl">Colaborador</td><td><strong>{{ $e->nombre }}</strong></td>
            <td class="lbl">Documento</td><td>{{ $e->numero_documento }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo</td><td>{{ $e->cargo }}</td>
            <td class="lbl">Área</td><td>{{ $e->area }}</td>
        </tr>
        <tr>
            <td class="lbl">Fecha de ingreso</td><td>{{ $e->fecha_ingreso?->format('d/m/Y') ?: '—' }}</td>
            <td class="lbl">Fecha de la inducción</td><td>{{ $i->fecha?->format('d/m/Y') }}</td>
        </tr>
    </table>

    <h2>Temas vistos</h2>
    <table class="temas">
        <thead>
            <tr>
                <th>Tema</th>
                <th class="c">Visto</th>
                <th style="width:24%;">Quién lo explicó</th>
                <th style="width:26%;">Observación</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($i->temario() as $t)
                <tr class="{{ $t['cubierto'] ? '' : 'pendiente' }}">
                    <td>{{ $t['tema'] }}</td>
                    <td class="c {{ $t['cubierto'] ? 'si' : 'no' }}">{{ $t['cubierto'] ? 'SÍ' : 'NO' }}</td>
                    <td>{{ $t['responsable'] ?: ($t['cubierto'] ? $i->responsable_nombre : '') }}</td>
                    <td>{{ $t['observacion'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($i->pendientes())
        <div class="aviso">
            <strong>Inducción incompleta.</strong>
            Quedan {{ count($i->pendientes()) }} temas por ver:
            {{ implode(', ', $i->pendientes()) }}.
        </div>
    @endif

    @if ($i->observaciones)
        <h2>Observaciones</h2>
        <div style="font-size:9pt;">{{ $i->observaciones }}</div>
    @endif

    {{--
        La declaración es la razón de ser del papel: sin ella la constancia
        dice lo que afirma la empresa, no lo que reconoce la persona.
    --}}
    <div class="declara">
        Declaro que recibí la inducción en los temas marcados como vistos en este documento,
        que se me explicaron los riesgos propios de mi puesto de trabajo y los elementos de
        protección que debo usar, y que se resolvieron las dudas que planteé.
        Entiendo a quién debo acudir para reportar una novedad o una condición insegura.
    </div>

    <table class="firmas">
        <tr>
            <td>
                <div class="linea"></div>
                <div class="rol">Firma del colaborador</div>
                <div class="rol">{{ $e->nombre }} · C.C. {{ $e->numero_documento }}</div>
                <div class="rol">
                    @if ($i->firmada())
                        Firmada el {{ $i->firmada_at->format('d/m/Y') }}
                    @else
                        <span class="no">Sin firmar</span>
                    @endif
                </div>
            </td>
            <td>
                <div class="linea"></div>
                <div class="rol">Quien dio la inducción</div>
                <div class="rol">
                    {{ $i->responsable_nombre }}@if ($i->responsable_cargo) · {{ $i->responsable_cargo }} @endif
                </div>
            </td>
        </tr>
    </table>

    @include('rrhh.pdf.partials.pie')

</body>
</html>
