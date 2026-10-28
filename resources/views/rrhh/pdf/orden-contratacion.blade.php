{{--
    GH-F-018 · FORMATO AUTORIZACIÓN DE CONTRATACIÓN.

    Es el papel que firma la Junta Directiva para que alguien entre a la
    empresa. Hasta ahora la orden vivía sólo en pantalla y el formato se
    llenaba a mano aparte; acá sale impreso con lo que de verdad se autorizó.

    Dos decisiones de fondo:

      · El encabezado lleva código, versión y fecha, y el pie dice que una vez
        impreso es COPIA NO CONTROLADA. Es lo que el área ya usa en sus
        formatos y lo que hace que esto valga ante auditoría igual que el Word.

      · Las dos firmas NO son dos botones nuevos. El líder del área ya actuó
        antes —pidió el personal y eligió a esta persona de la terna— y la
        Junta actúa al autorizar. El papel muestra esos actos con su fecha, en
        vez de pedir que alguien vuelva a confirmar algo que ya confirmó.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Autorización de contratación — {{ $o->numero }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 24px; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .enc td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: middle; }
        .enc .marca { font-size: 13pt; font-weight: 700; color: #b45309; width: 28%; }
        .enc .titulo { text-align: center; font-size: 11pt; font-weight: 700; }
        .enc .meta { font-size: 8pt; color: #374151; width: 22%; }
        h2 { font-size: 10pt; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        .campos { width: 100%; border-collapse: collapse; }
        .campos td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9.5pt; }
        .campos .lbl { width: 34%; color: #6b7280; text-transform: uppercase; font-size: 8pt; }
        .val { font-weight: 700; }
        .falta { color: #991b1b; font-style: italic; font-weight: normal; }
        .obs { border: 1px solid #d1d5db; border-radius: 4px; padding: 10px 12px; min-height: 44px; font-size: 9.5pt; }
        .firmas { width: 100%; margin-top: 34px; border-collapse: collapse; }
        .firmas td { width: 50%; padding: 0 14px; vertical-align: top; text-align: center; }
        .linea { border-top: 1px solid #111; margin-bottom: 4px; }
        .rol { font-size: 8pt; color: #6b7280; text-transform: uppercase; }
        .quien { font-size: 9.5pt; font-weight: 700; }
        .cuando { font-size: 8pt; color: #6b7280; }
        .aviso { margin-top: 14px; padding: 8px 10px; border-left: 3px solid #d97706; background: #fffbeb; font-size: 8.5pt; color: #78350f; }
        .pie { margin-top: 24px; padding-top: 8px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    {{-- Encabezado de control documental, igual que en los formatos del área --}}
    @include('rrhh.pdf.partials.control')

    <div style="font-size: 9pt; color: #6b7280; margin-bottom: 10px;">
        Orden <strong>{{ $o->numero }}</strong> ·
        Fecha de diligenciamiento: {{ $o->solicitada_at?->format('d/m/Y') ?? '—' }}
    </div>

    <h2>Datos de la contratación</h2>
    <table class="campos">
        <tr>
            <td class="lbl">Nombre del empleado</td>
            <td class="val">{{ $o->candidato?->nombre ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Identificación del empleado</td>
            <td class="val">{{ $o->candidato?->documento ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo a desempeñar</td>
            <td class="val">{{ $o->cargo }}</td>
        </tr>
        <tr>
            <td class="lbl">Área</td>
            <td class="val">{{ $o->area ?: $o->vacante?->area }}
                @if (blank($o->area) && blank($o->vacante?->area))<span class="falta">sin diligenciar</span>@endif
            </td>
        </tr>
        <tr>
            <td class="lbl">Fecha de inicio</td>
            <td class="val">{{ $o->fecha_inicio?->format('d/m/Y') }}
                @if (! $o->fecha_inicio)<span class="falta">sin diligenciar</span>@endif
            </td>
        </tr>
        <tr>
            <td class="lbl">Salario a devengar</td>
            <td class="val">
                @if ($o->salario_propuesto > 0)
                    ${{ number_format((float) $o->salario_propuesto, 0, ',', '.') }}
                @else
                    <span class="falta">sin diligenciar</span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="lbl">Horario de trabajo</td>
            <td class="val">{{ $o->horario }}
                @if (! $o->horario)<span class="falta">sin diligenciar</span>@endif
            </td>
        </tr>
        <tr>
            <td class="lbl">Ciudad donde va a laborar</td>
            <td class="val">{{ $o->ciudad }}
                @if (! $o->ciudad)<span class="falta">sin diligenciar</span>@endif
            </td>
        </tr>
        <tr>
            <td class="lbl">Tipo de pago</td>
            <td class="val">{{ $periodicidades[$o->periodicidad_pago] ?? '' }}
                @if (! $o->periodicidad_pago)<span class="falta">sin diligenciar</span>@endif
            </td>
        </tr>
        <tr>
            <td class="lbl">Tipo de contrato</td>
            <td class="val">{{ $o->tipo_contrato ?: '—' }}</td>
        </tr>
    </table>

    <h2>Observaciones adicionales</h2>
    <div class="obs">{{ $o->observaciones ?: $o->justificacion }}</div>

    @if ($faltantes !== [])
        {{-- El papel sale igual, pero diciendo qué va en blanco: una casilla
             completada a mano DESPUÉS de la firma es una autorización que dice
             una cosa y un contrato que dice otra. --}}
        <div class="aviso">
            <strong>Este formato está incompleto.</strong>
            Falta: {{ implode(', ', $faltantes) }}.
            Completalo en el sistema antes de llevarlo a firma.
        </div>
    @endif

    <h2>Firmas de autorización</h2>
    <table class="firmas">
        <tr>
            <td>
                <div class="linea"></div>
                <div class="rol">Líder de área</div>
                <div class="quien">{{ $lider?->name ?? '—' }}</div>
                <div class="cuando">
                    @if ($o->requisicion)
                        Solicitó el personal · requisición {{ $o->requisicion->numero }}
                    @else
                        Ingreso por fuera del proceso de requisición
                    @endif
                </div>
            </td>
            <td>
                <div class="linea"></div>
                <div class="rol">Miembro de la Junta Directiva</div>
                <div class="quien">{{ $o->decididaPor?->name ?? '—' }}</div>
                <div class="cuando">
                    @if ($o->decidida_at)
                        {{ $o->estado === 'autorizada' ? 'Autorizó' : 'Negó' }}
                        el {{ $o->decidida_at->format('d/m/Y') }}
                    @else
                        Pendiente de autorización
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @include('rrhh.pdf.partials.pie')

</body>
</html>
