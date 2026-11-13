{{--
    FORMATO DE ENTREVISTA DEL SER.

    Seis criterios calificados de 1 a 5, su promedio y la banda en que cae.

    A diferencia del saber hacer, acá el impreso SÍ dice qué significa el
    puntaje: el área definió los tres cortes y la hoja los lleva, para que
    quien la firme y quien la lea después estén mirando el mismo criterio.

    El recuadro de control lo inyecta el view composer desde el listado
    maestro, igual que los demás formatos.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrevista del ser — {{ $c->nombre }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; margin: 0; padding: 22px; }
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.datos td { padding: 4px 7px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        .lbl { color: #6b7280; text-transform: uppercase; font-size: 7.5pt; width: 20%; }
        h2 { font-size: 9pt; margin: 14px 0 4px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; color: #374151; text-transform: uppercase; }
        .nota { font-size: 7.5pt; color: #6b7280; font-style: italic; margin-bottom: 6px; }
        .rta { border-bottom: 1px solid #d1d5db; min-height: 15px; padding: 3px 2px 2px; font-size: 9pt; color: #111; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th { background: #fef3c7; color: #78350f; font-size: 7.5pt; padding: 4px 6px; text-align: left; }
        table.items th.n { text-align: center; width: 26px; }
        table.items td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; font-size: 8.5pt; }
        table.items td.n { text-align: center; }
        .marcada { font-weight: 700; color: #b45309; }
        .total { margin-top: 8px; font-size: 10pt; font-weight: 700; }
        table.bandas { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.bandas td { border: 1px solid #d1d5db; padding: 5px 7px; font-size: 8.5pt; }
        table.bandas td.corte { width: 24%; font-weight: 700; }
        .caeAca { background: #fef3c7; }
        .firma { margin-top: 36px; width: 55%; }
        .linea { border-top: 1px solid #111; margin-bottom: 3px; }
        .rol { font-size: 8pt; color: #374151; }
    </style>
</head>
<body>

    @include('rrhh.pdf.partials.control')

    <table class="datos">
        <tr>
            <td class="lbl">Candidato</td><td><strong>{{ $c->nombre }}</strong></td>
            <td class="lbl">Fecha</td><td>{{ $e->fecha?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="lbl">Cargo</td><td>{{ $c->vacante?->titulo ?: '—' }}</td>
            <td class="lbl">Área</td><td>{{ $c->vacante?->area ?: '—' }}</td>
        </tr>
    </table>

    <h2>Criterios</h2>
    <div class="nota">
        Califique de uno a cinco, donde uno es la menor puntuación y cinco la más alta,
        de acuerdo con lo observado durante la entrevista.
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Criterio</th>
                @for ($n = 1; $n <= 5; $n++)
                    <th class="n">{{ $n }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach (\App\Modules\Rrhh\Models\EntrevistaCandidato::SER_ITEMS as $k => $texto)
                @php $nota = (int) ($e->calificaciones[$k] ?? 0); @endphp
                <tr>
                    <td>{{ $loop->iteration }}. {{ $texto }}</td>
                    @for ($n = 1; $n <= 5; $n++)
                        <td class="n {{ $nota === $n ? 'marcada' : '' }}">{{ $nota === $n ? 'X' : '' }}</td>
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $promedio = $e->promedioCalculado();
        $banda = $e->banda();
    @endphp

    <div class="total">
        TOTAL: {{ $e->totalCalculado() !== null ? $e->totalCalculado().' de 30' : '______' }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        PROMEDIO: {{ $promedio !== null ? number_format($promedio, 2, ',', '.') : '______' }}
    </div>

    <h2>Resultado</h2>
    <div class="nota">
        La banda sale del promedio. Sugiere, no decide: la valoración final la firma quien entrevista,
        y apartarse de la banda es válido mientras quede explicado en las observaciones.
    </div>

    <table class="bandas">
        <tr class="{{ $banda === \App\Modules\Rrhh\Enums\BandaValoracion::Riesgo ? 'caeAca' : '' }}">
            <td class="corte">1,00 a 2,50</td>
            <td><strong>Riesgo.</strong> {{ \App\Modules\Rrhh\Enums\BandaValoracion::Riesgo->queSignifica() }}</td>
        </tr>
        <tr class="{{ $banda === \App\Modules\Rrhh\Enums\BandaValoracion::AceptableConSeguimiento ? 'caeAca' : '' }}">
            <td class="corte">2,51 a 3,49</td>
            <td><strong>Aceptable con seguimiento.</strong>
                {{ \App\Modules\Rrhh\Enums\BandaValoracion::AceptableConSeguimiento->queSignifica() }}</td>
        </tr>
        <tr class="{{ $banda === \App\Modules\Rrhh\Enums\BandaValoracion::Recomendable ? 'caeAca' : '' }}">
            <td class="corte">3,50 a 5,00</td>
            <td><strong>Recomendable.</strong>
                {{ \App\Modules\Rrhh\Enums\BandaValoracion::Recomendable->queSignifica() }}</td>
        </tr>
    </table>

    @if ($e->observaciones)
        <h2>Observaciones</h2>
        <div class="rta">{{ $e->observaciones }}</div>
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
