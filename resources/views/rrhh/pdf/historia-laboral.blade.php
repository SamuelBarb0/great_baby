{{--
    La historia laboral para imprimir.

    Elena lo pidió por dos razones distintas y las dos mandan: «todo va a ser
    exportable en PDF» fue un compromiso de la reunión, y la normativa le exige
    tener las hojas «tanto físicas como digitales» — o sea, esto se imprime y se
    archiva en la carpeta de cada quien.

    Por eso lleva TODO lo que un auditor pediría, incluida la bitácora: sin el
    rastro de quién hizo qué y cuándo, el documento no prueba nada.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historia laboral — {{ $e->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 24px; }
        h1 { font-size: 16pt; margin: 0 0 4px; color: #b45309; }
        h2 { font-size: 11pt; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #d1d5db; color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #fef3c7; padding: 6px 8px; font-size: 9pt; color: #78350f; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; vertical-align: top; }
        .box { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 4px; }
        .grid { display: table; width: 100%; margin: 8px 0; }
        .grid > div { display: table-cell; padding: 4px 8px; width: 33%; }
        .lbl { font-size: 8pt; color: #6b7280; text-transform: uppercase; }
        .val { font-size: 10pt; font-weight: 700; }
        .footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #d1d5db; font-size: 8pt; color: #6b7280; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 8pt; }
        .b-ok { background: #dcfce7; color: #166534; }
        .b-warn { background: #fef3c7; color: #78350f; }
        .b-err { background: #fee2e2; color: #991b1b; }
        .falta { color: #991b1b; font-style: italic; }
    </style>
</head>
<body>
    <h1>GREAT BABY S.A.S.</h1>
    <div style="font-size: 9pt; color: #6b7280;">NIT 901.738.354 · Bucaramanga</div>

    <h2>Historia laboral</h2>
    <div class="box">
        <div style="font-weight: 700; font-size: 13pt;">{{ $e->nombre }}</div>
        <div>{{ $e->tipo_documento }} {{ $e->numero_documento }}</div>
        <div>{{ $e->email ?: 'Sin correo' }} · {{ $e->telefono ?: 'Sin teléfono' }}</div>
        <div style="margin-top: 4px;">
            <span class="badge {{ $e->estado === 'activo' ? 'b-ok' : 'b-err' }}">{{ strtoupper($e->estado) }}</span>
        </div>
    </div>

    <h2>Vinculación</h2>
    <div class="grid">
        <div><div class="lbl">Cargo</div><div class="val">{{ $e->cargo }}</div></div>
        <div><div class="lbl">Área</div><div class="val">{{ $e->area }}</div></div>
        <div><div class="lbl">Tipo de contrato</div><div class="val">{{ $e->tipo_contrato }}</div></div>
    </div>
    <div class="grid">
        <div><div class="lbl">Fecha de ingreso</div><div class="val">{{ $e->fecha_ingreso?->toDateString() ?: '—' }}</div></div>
        <div><div class="lbl">Salario</div><div class="val">${{ number_format((float) $e->salario, 0, ',', '.') }}</div></div>
        <div><div class="lbl">Fecha de retiro</div><div class="val">{{ $e->fecha_retiro?->toDateString() ?: '—' }}</div></div>
    </div>

    @if($e->vacante)
        <div style="font-size: 9pt; color: #6b7280; margin-top: 6px;">
            Entró por la vacante «{{ $e->vacante->titulo }}»@if($e->vacante->requisicion), solicitada con la requisición {{ $e->vacante->requisicion->numero }} por {{ $e->vacante->requisicion->solicitante?->name }}@endif.
            @if($orden) Autorizada por la Junta Directiva con la orden {{ $orden->numero }}@if($orden->decididaPor) ({{ $orden->decididaPor->name }}, {{ $orden->decidida_at?->toDateString() }})@endif. @endif
        </div>
    @endif

    <h2>Seguridad social</h2>
    @if($contratacion)
        <table>
            <thead><tr><th>Afiliación</th><th>Entidad</th><th>Registrada</th></tr></thead>
            <tbody>
                @foreach($afiliaciones as $campo => $label)
                    <tr>
                        <td>{{ $label }}</td>
                        <td>{{ $contratacion->{$campo} ?: '—' }}</td>
                        <td>
                            @if($contratacion->{$campo.'_at'})
                                {{ $contratacion->{$campo.'_at'}->toDateString() }}
                            @else
                                <span class="falta">Sin registrar</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="margin-top: 8px; font-size: 9pt;">
            <strong>Examen médico de ingreso:</strong>
            @if($contratacion->examen_at)
                {{ $contratacion->examen_resultado }} · {{ $contratacion->examen_at->toDateString() }}
                @if($contratacion->examen_observacion) · {{ $contratacion->examen_observacion }} @endif
            @else
                <span class="falta">Sin registrar</span>
            @endif
            <br>
            <strong>Contrato firmado:</strong>
            {{ $contratacion->contrato_firmado_at?->toDateString() ?: 'Sin registrar' }}
        </div>
    @else
        <div class="falta" style="font-size: 9pt;">
            Esta persona no entró por el proceso de contratación del sistema, así que no hay expediente de afiliaciones.
        </div>
    @endif

    <h2>Inducción</h2>
    @if($e->induccion_at)
        <div style="font-size: 9pt;">
            Dada el {{ $e->induccion_at->toDateString() }} por {{ $e->induccionPor?->name ?: '—' }}.
        </div>
        <div style="font-size: 9pt; margin-top: 4px;">{{ $e->induccion_temas }}</div>
    @else
        <div class="falta" style="font-size: 9pt;">Pendiente.</div>
    @endif

    <h2>Período de prueba</h2>
    @if($e->prueba_hasta)
        <div style="font-size: 9pt;">Hasta el {{ $e->prueba_hasta->toDateString() }}.</div>
        <table style="margin-top: 6px;">
            <thead><tr><th>Concepto</th><th>Quién</th><th>Cuándo</th><th>Resultado</th><th>Observación</th></tr></thead>
            <tbody>
                <tr>
                    <td>Jefe del área</td>
                    <td>{{ $e->pruebaJefePor?->name ?: '—' }}</td>
                    <td>{{ $e->prueba_jefe_at?->toDateString() ?: '—' }}</td>
                    <td>{{ $e->prueba_jefe_resultado ?: 'Pendiente' }}</td>
                    <td>{{ $e->prueba_jefe_observacion ?: '—' }}</td>
                </tr>
                <tr>
                    <td>Gestión Humana</td>
                    <td>{{ $e->pruebaEvaluadaPor?->name ?: '—' }}</td>
                    <td>{{ $e->prueba_evaluada_at?->toDateString() ?: '—' }}</td>
                    <td>{{ $e->prueba_resultado ?: 'Pendiente' }}</td>
                    <td>{{ $e->prueba_observacion ?: '—' }}</td>
                </tr>
            </tbody>
        </table>
    @else
        <div class="falta" style="font-size: 9pt;">Sin fijar.</div>
    @endif

    <h2>Documentos en la carpeta</h2>
    @if($e->documentos->count())
        <table>
            <thead><tr><th>Tipo</th><th>Nombre</th><th>Cargado por</th><th>Fecha</th><th>Vence</th></tr></thead>
            <tbody>
                @foreach($e->documentos as $d)
                    <tr>
                        <td>{{ $d->tipo_label }}</td>
                        <td>{{ $d->nombre }}</td>
                        <td>{{ $d->subidoPor?->name ?: '—' }}</td>
                        <td>{{ $d->created_at?->toDateString() }}</td>
                        <td>{{ $d->fecha_vencimiento?->toDateString() ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="falta" style="font-size: 9pt;">Sin documentos cargados.</div>
    @endif

    {{--
        La bitácora va completa y al final. Es lo que convierte este papel en
        una prueba: sin el rastro de quién hizo qué y cuándo, el documento sólo
        dice cómo están las cosas hoy.
    --}}
    <h2>Rastro del proceso</h2>
    @if($bitacora->count())
        <table>
            <thead><tr><th style="width: 110px;">Cuándo</th><th style="width: 130px;">Quién</th><th>Qué pasó</th></tr></thead>
            <tbody>
                @foreach($bitacora as $b)
                    <tr>
                        <td>{{ $b->created_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $b->usuario?->name ?: 'Sistema' }}</td>
                        <td>{{ $b->resumen }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="falta" style="font-size: 9pt;">Sin movimientos registrados.</div>
    @endif

    <div class="footer">
        Generado el {{ now()->format('Y-m-d H:i') }} por {{ $generadoPor }} · GREAT BABY ERP.
        Este documento refleja la información registrada en el sistema a esa fecha.
    </div>
</body>
</html>
