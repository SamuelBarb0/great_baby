{{--
    GH-F-007 · FORMATO SOLICITUD DE DOCUMENTOS PARA CONTRATACIÓN.

    Es la carta que se le entrega a quien pasó la selección, con la lista de lo
    que tiene que traer. Hasta ahora salía del Word del área; el ERP tenía una
    lista propia de diez renglones genéricos que no coincidía con esa.

    La lista sale de `DocumentoEmpleado::CATALOGO`, que es la MISMA que ofrece
    el formulario de carga y la que calcula los pendientes. Si estuvieran
    separadas, la carta pediría una cosa y la pantalla esperaría otra — que es
    exactamente lo que pasaba.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Solicitud de documentos — {{ $persona['nombre'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 26px; line-height: 1.45; }
        .enc { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .enc td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: middle; }
        .enc .marca { font-size: 12pt; font-weight: 700; color: #b45309; width: 26%; }
        .enc .titulo { text-align: center; font-size: 10.5pt; font-weight: 700; }
        .enc .meta { font-size: 8pt; color: #374151; width: 22%; }
        .datos { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .datos td { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9.5pt; }
        .datos .lbl { width: 24%; color: #6b7280; text-transform: uppercase; font-size: 8pt; }
        p { margin: 0 0 10px; text-align: justify; }
        ol { margin: 10px 0 12px; padding-left: 20px; }
        li { margin-bottom: 7px; }
        .nota { color: #4b5563; font-size: 9pt; }
        .aplica { display: inline-block; margin-left: 4px; padding: 1px 5px; border-radius: 3px; background: #e5e7eb; color: #374151; font-size: 7.5pt; text-transform: uppercase; }
        .aviso { border-left: 3px solid #b91c1c; background: #fef2f2; padding: 9px 12px; margin: 14px 0; font-size: 9.5pt; color: #7f1d1d; }
        .firmas { width: 100%; margin-top: 40px; border-collapse: collapse; }
        .firmas td { width: 50%; padding: 0 14px; vertical-align: top; }
        .linea { border-top: 1px solid #111; margin-bottom: 4px; }
        .rol { font-size: 8.5pt; color: #374151; }
        .pie { margin-top: 24px; padding-top: 8px; border-top: 1px solid #d1d5db; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    <table class="datos">
        <tr><td class="lbl">Fecha</td><td>{{ now()->format('d/m/Y') }}</td></tr>
        <tr><td class="lbl">Aspirante</td><td><strong>{{ $persona['nombre'] }}</strong></td></tr>
        <tr><td class="lbl">Identificación</td><td>{{ $persona['documento'] ?: '—' }}</td></tr>
        <tr><td class="lbl">Cargo</td><td>{{ $persona['cargo'] ?: '—' }}</td></tr>
    </table>

    <p>
        Teniendo presente que usted ha aprobado parte de las etapas del proceso de
        selección y es necesario continuar con el proceso de incorporación, debe
        presentar a la Coordinación de Gestión del Talento Humano los siguientes
        documentos:
    </p>

    <ol>
        @foreach ($documentos as $clave => $d)
            <li>
                {{ $d['label'] }}
                @if ($d['exigencia'] === 'si_aplica')
                    <span class="aplica">si aplica</span>
                @endif
                @if ($d['nota'])
                    <div class="nota">{{ $d['nota'] }}</div>
                @endif
                @if (in_array($clave, $yaEntregados, true))
                    <div class="nota"><strong>Ya recibido.</strong></div>
                @endif
            </li>
        @endforeach
    </ol>

    <div class="aviso">
        <strong>Nota:</strong> el no cumplimiento en la presentación y/o aprobación de la
        documentación será objeto de exclusión del proceso de selección.
    </div>

    <table class="firmas">
        <tr>
            <td>
                <div class="linea"></div>
                <div class="rol">Asistente de Gestión Humana</div>
                <div class="rol">{{ $generadoPor }}</div>
            </td>
            <td>
                <div class="linea"></div>
                <div class="rol">Aspirante</div>
                <div class="rol">Fecha de recibido: ______________________</div>
            </td>
        </tr>
    </table>

    @include('rrhh.pdf.partials.pie')

</body>
</html>
