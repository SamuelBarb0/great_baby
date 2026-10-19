{{--
    El recorrido de un candidato, para imprimir.

    Es el papel que Elena arma hoy a mano cuando alguien le pide cuentas de una
    selección: quién se presentó, qué salió en antecedentes, qué concepto se le
    dio y por qué se descartó o se eligió.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proceso de selección — {{ $c->nombre }}</title>
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

    <h2>Proceso de selección</h2>
    <div class="box">
        <div style="font-weight: 700; font-size: 13pt;">{{ $c->nombre }}</div>
        <div>CC {{ $c->documento ?: '—' }}</div>
        <div>{{ $c->email ?: 'Sin correo' }} · {{ $c->telefono ?: 'Sin teléfono' }}</div>
        <div style="margin-top: 4px;">
            <span class="badge {{ $c->etapa->color() === 'success' ? 'b-ok' : ($c->etapa->color() === 'danger' ? 'b-err' : 'b-warn') }}">
                {{ strtoupper($c->etapa->label()) }}
            </span>
        </div>
    </div>

    <h2>La vacante</h2>
    <div class="grid">
        <div><div class="lbl">Cargo</div><div class="val">{{ $c->vacante?->titulo ?: '—' }}</div></div>
        <div><div class="lbl">Área</div><div class="val">{{ $c->vacante?->area ?: '—' }}</div></div>
        <div>
            <div class="lbl">Requisición</div>
            <div class="val">{{ $c->vacante?->requisicion?->numero ?: 'Sin requisición' }}</div>
        </div>
    </div>
    <div style="font-size: 9pt; color: #6b7280;">
        Se postuló el {{ $c->created_at?->toDateString() }}
        @if($c->fuente) · llegó por: {{ $fuente }} @endif
    </div>

    <h2>Antecedentes judiciales</h2>
    <div style="font-size: 9pt;">
        @if($c->antecedentes_revisados)
            Revisados · {{ $c->antecedentes_resultado ?: 'Sin detalle' }}
        @else
            <span class="falta">No se revisaron. No se debió citar a entrevista sin esto.</span>
        @endif
    </div>

    <h2>Valoración de Gestión Humana</h2>
    <div style="font-size: 9pt;">
        @if($c->valoracion)
            <strong>{{ $c->valoracion->label() }}</strong>
            @if($c->valoracion_observacion) · {{ $c->valoracion_observacion }} @endif
        @else
            <span class="falta">Sin valorar.</span>
        @endif
    </div>

    @if($c->elegido_at || $c->motivo_descarte)
        <h2>Desenlace</h2>
        <div style="font-size: 9pt;">
            @if($c->elegido_at)
                Elegido de la terna por {{ $c->elegidoPor?->name ?: '—' }} el {{ $c->elegido_at->format('Y-m-d H:i') }}.
                @if($orden)
                    <br>Orden de contratación {{ $orden->numero }} · {{ $orden->etiquetaEstado() }}
                    @if($orden->motivo_decision) · {{ $orden->motivo_decision }} @endif
                @endif
            @endif
            @if($c->motivo_descarte)
                <br>Descartado: {{ $c->motivo_descarte }}
            @endif
        </div>
    @endif

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
        Contiene datos personales: trátese como información reservada.
    </div>
</body>
</html>
