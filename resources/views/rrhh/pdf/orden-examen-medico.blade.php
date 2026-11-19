{{--
    SST-F-001 · FORMATO ORDEN DE REALIZACIÓN DE EXAMEN MÉDICO OCUPACIONAL.

    Es la carta que la persona lleva a la IPS. Hasta ahora el ERP guardaba la
    FECHA y el RESULTADO del examen, pero la carta se escribía por fuera: o sea
    que el sistema sabía que el examen se hizo y no tenía con qué pedirlo.

    El código es SST-F-001 y no GH-F-xxx porque pertenece al proceso de
    Seguridad y Salud en el Trabajo, no al de Gestión Humana. Se respeta el
    código del área.

    Los datos de la IPS salen de las reglas, no de acá: es un proveedor y se
    cambia sin tocar código.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Orden de examen médico ocupacional — {{ $persona['nombre'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #111; margin: 0; padding: 26px; line-height: 1.5; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .enc td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: middle; }
        .enc .marca { font-size: 12pt; font-weight: 700; color: #b45309; width: 26%; }
        .enc .titulo { text-align: center; font-size: 10.5pt; font-weight: 700; }
        .enc .meta { font-size: 8pt; color: #374151; width: 22%; }
        .datos { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .datos td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10pt; }
        .datos .lbl { width: 30%; color: #6b7280; text-transform: uppercase; font-size: 8pt; }
        .val { font-weight: 700; }
        p { margin: 0 0 10px; text-align: justify; }
        .dirigido { font-weight: 700; margin-bottom: 10px; }
        .destacado { border-left: 3px solid #d97706; background: #fffbeb; padding: 9px 12px; margin: 12px 0; font-size: 10pt; }
        .firmas { width: 100%; margin-top: 44px; border-collapse: collapse; }
        .firmas td { width: 50%; padding: 0 14px; vertical-align: top; }
        .linea { border-top: 1px solid #111; margin-bottom: 4px; }
        .rol { font-size: 8.5pt; color: #374151; }
        .pie { margin-top: 26px; padding-top: 8px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    <table class="datos">
        <tr><td class="lbl">Fecha</td><td class="val">{{ now()->format('d/m/Y') }}</td></tr>
        <tr><td class="lbl">Nombre colaborador</td><td class="val">{{ $persona['nombre'] }}</td></tr>
        <tr><td class="lbl">Cédula</td><td class="val">{{ $persona['documento'] ?: '—' }}</td></tr>
        <tr><td class="lbl">Cargo</td><td class="val">{{ $persona['cargo'] ?: '—' }}</td></tr>
        <tr><td class="lbl">Tipo de examen</td><td class="val">{{ $tipo->label() }}</td></tr>
    </table>

    <div class="dirigido">
        Apreciados señores (as):<br>
        {{ $ips['nombre'] }}
    </div>

    <p>
        A través de la presente, y dando cumplimiento a lo establecido en la
        <strong>Resolución 2346 de 2007</strong>, por la cual se regula la práctica de
        evaluaciones médicas ocupacionales y el manejo y contenido de las historias
        clínicas ocupacionales, solicitamos realizar a la persona identificada arriba el
        <strong>{{ $tipo->enLaCarta() }}</strong>.
    </p>

    <p><strong>Motivo:</strong> {{ $tipo->motivo() }}</p>

    <div class="destacado">
        Por favor presentarse en {{ $ips['direccion'] }}, en horarios de atención
        {{ $ips['horario'] }}.<br>
        {{ $ips['instrucciones'] }}
    </div>

    <p>
        Agradecemos remitir el concepto de aptitud ocupacional resultante a la
        Coordinación de Gestión del Talento Humano de
        {{ $empresa->razon_social }}{{ $empresa->nit ? ' (NIT '.$empresa->nit.')' : '' }}.
    </p>

    <table class="firmas">
        <tr>
            <td>
                <div style="margin-bottom: 30px;">Cordialmente,</div>
                <div class="linea"></div>
                <div class="rol">Auxiliar SST</div>
                <div class="rol">{{ $empresa->razon_social }}</div>
            </td>
            <td>
                <div style="margin-bottom: 30px;">Recibí,</div>
                <div class="linea"></div>
                <div class="rol">Firma del colaborador</div>
                <div class="rol">Fecha de recibido: ______________________</div>
            </td>
        </tr>
    </table>

    @include('rrhh.pdf.partials.pie')

</body>
</html>
