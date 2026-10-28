{{--
    REQUISICIÓN DE PERSONAL · formato 1 del área.

    Era el único de los doce formatos que no se podía imprimir: el flujo
    estaba completo —solicitar, aprobar, abrir la vacante— pero el papel no
    existía, y el líder que pide el personal firma papel.

    Las casillas que el sistema todavía no captura salen en blanco y el pie
    dice cuáles son, en vez de imprimirlas como si estuvieran vacías por
    decisión de quien llenó la requisición.

    ── Lo que este formato NO imprime ──────────────────────────────────────

    El papel pide sexo y rango de edad. La Ley 931 de 2004 prohíbe fijar
    límites de edad en una oferta de empleo, y el sexo como requisito sólo
    cabe cuando es esencial para el cargo. Mientras el área no lo decida, el
    ERP no los captura ni los imprime.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Requisición {{ $x->numero }}</title>
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
        .lbl { color: #6b7280; text-transform: uppercase; font-size: 7.5pt; width: 20%; }
        .falta { color: #b91c1c; font-style: italic; }
        h2 { font-size: 9pt; margin: 13px 0 5px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        .opciones { font-size: 9pt; }
        .op { display: inline-block; margin-right: 14px; }
        .caja { display: inline-block; width: 11px; height: 11px; border: 1px solid #111; text-align: center; line-height: 10px; font-size: 8pt; font-weight: 700; }
        .marcada { background: #fef3c7; color: #78350f; }
        .texto { border: 1px solid #d1d5db; border-radius: 3px; padding: 7px 9px; min-height: 30px; font-size: 9pt; }
        .vacio { color: #9ca3af; font-style: italic; }
        .aviso { border-left: 3px solid #b45309; background: #fffbeb; padding: 7px 10px; margin: 12px 0; font-size: 8.5pt; color: #78350f; }
        .estado { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 8pt; font-weight: 700; }
        .firma { margin-top: 36px; width: 55%; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
        .pie { margin-top: 18px; padding-top: 7px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    @php
        $modelo = \App\Modules\Rrhh\Models\Requisicion::class;
        $caja = fn (bool $marcada) => '<span class="caja '.($marcada ? 'marcada' : '').'">'
            .($marcada ? 'X' : '&nbsp;').'</span>';
        $oVacio = fn (?string $v) => filled($v)
            ? e($v)
            : '<span class="falta">sin diligenciar</span>';
    @endphp

    <table class="datos">
        <tr>
            <td class="lbl">Requisición</td><td><strong>{{ $x->numero }}</strong></td>
            <td class="lbl">Fecha de solicitud</td><td>{{ $x->created_at?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="lbl">Nombre del cargo</td><td><strong>{{ $x->cargo }}</strong></td>
            <td class="lbl">N.º de vacantes</td><td>{{ $x->numero_vacantes }}</td>
        </tr>
        <tr>
            <td class="lbl">Área o proceso</td><td>{!! $oVacio($x->area) !!}</td>
            <td class="lbl">Ciudad</td><td>{!! $oVacio($x->ciudad) !!}</td>
        </tr>
        <tr>
            <td class="lbl">Razón comercial</td><td>{!! $oVacio($x->razon_comercial) !!}</td>
            <td class="lbl">Tipo de contrato</td><td>{!! $oVacio($x->tipo_contrato) !!}</td>
        </tr>
    </table>

    <h2>Tipo de selección</h2>
    <div class="opciones">
        @foreach ($modelo::TIPOS_SELECCION as $clave => $label)
            <span class="op">{!! $caja($x->tipo_seleccion === $clave) !!} {{ $label }}</span>
        @endforeach
    </div>

    <h2>Motivo de la selección</h2>
    <div class="opciones">
        @foreach ($modelo::MOTIVOS_SELECCION as $clave => $label)
            <span class="op">
                {!! $caja($x->motivo_seleccion === $clave) !!} {{ $label }}@if ($clave === 'otro' && $x->motivo_seleccion === 'otro')<u>&nbsp;{{ $x->motivo_seleccion_otro }}&nbsp;</u>@endif
            </span>
        @endforeach
    </div>

    <h2>Especificaciones de la solicitud</h2>
    <table class="datos">
        <tr>
            <td class="lbl">Experiencia</td><td>{!! $oVacio($x->experiencia) !!}</td>
            <td class="lbl">Educación</td><td>{!! $oVacio($x->estudios) !!}</td>
        </tr>
        <tr>
            <td class="lbl">Salario</td>
            <td>
                @if ($x->salario_min || $x->salario_max)
                    ${{ number_format((float) $x->salario_min, 0, ',', '.') }}
                    @if ($x->salario_max) &ndash; ${{ number_format((float) $x->salario_max, 0, ',', '.') }} @endif
                @else
                    <span class="falta">sin diligenciar</span>
                @endif
            </td>
            <td class="lbl">Horario</td><td>{!! $oVacio($x->horario) !!}</td>
        </tr>
        <tr>
            <td class="lbl">Fecha requerida</td>
            <td colspan="3">{{ $x->fecha_requerida?->format('d/m/Y') ?: '—' }}</td>
        </tr>
    </table>

    <h2>Especificaciones del perfil</h2>

    <div class="lbl" style="width:auto; margin-bottom:3px;">Funciones por desempeñar</div>
    <div class="texto">
        {{ $x->funciones ?: '' }}
        @if (blank($x->funciones)) <span class="vacio">Sin diligenciar.</span> @endif
    </div>

    <div class="lbl" style="width:auto; margin:8px 0 3px;">Habilidades y competencias</div>
    <div class="texto">
        {{ $x->competencias ?: '' }}
        @if (blank($x->competencias)) <span class="vacio">Sin diligenciar.</span> @endif
    </div>

    <div class="lbl" style="width:auto; margin:8px 0 3px;">Formación o estudios necesarios</div>
    <div class="texto">
        {{ $x->estudios ?: '' }}
        @if (blank($x->estudios)) <span class="vacio">Sin diligenciar.</span> @endif
    </div>

    @if ($x->motivo)
        <div class="lbl" style="width:auto; margin:8px 0 3px;">Justificación</div>
        <div class="texto">{{ $x->motivo }}</div>
    @endif

    @if ($x->faltantesDelFormato())
        <div class="aviso">
            <strong>El formato sale con casillas vacías:</strong>
            falta {{ implode(', ', $x->faltantesDelFormato()) }}.
        </div>
    @endif

    <h2>Estado de la solicitud</h2>
    <table class="datos">
        <tr>
            <td class="lbl">Estado</td>
            <td><strong>{{ $x->estado->label() }}</strong></td>
            <td class="lbl">Enviada</td>
            <td>{{ $x->enviada_at?->format('d/m/Y H:i') ?: '—' }}</td>
        </tr>
        @if ($x->aprobada_at)
            <tr>
                <td class="lbl">Aprobada</td>
                <td colspan="3">
                    {{ $x->aprobada_at->format('d/m/Y H:i') }}
                    @if ($x->aprobadaPor) · {{ $x->aprobadaPor->name }} @endif
                </td>
            </tr>
        @endif
        @if ($x->rechazada_at)
            <tr>
                <td class="lbl">Rechazada</td>
                <td colspan="3">
                    {{ $x->rechazada_at->format('d/m/Y H:i') }}
                    @if ($x->motivo_rechazo) · {{ $x->motivo_rechazo }} @endif
                </td>
            </tr>
        @endif
    </table>

    <div class="firma">
        <div class="linea"></div>
        <div class="rol">Firma del líder de área que realiza la requisición</div>
        <div class="rol">{{ $x->solicitante?->name ?: '—' }}</div>
    </div>

    @include('rrhh.pdf.partials.pie')

</body>
</html>
