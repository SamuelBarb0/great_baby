<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recolección · {{ $corte->etiqueta() }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; margin: 0; }
        .sub { color: #555; margin-top: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; font-size: 8px; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #111; padding: 4px; }
        td { border-bottom: 1px solid #ccc; padding: 6px 4px; vertical-align: middle; }
        tr { page-break-inside: avoid; }
        .casilla { width: 15px; height: 15px; border: 1.5px solid #111; text-align: center; font-size: 14px; line-height: 13px; color: #111; }
        .ubic { font-family: DejaVu Sans Mono, monospace; font-weight: bold; font-size: 11px; }
        .sku { font-family: DejaVu Sans Mono, monospace; font-size: 9px; color: #444; }
        .und { text-align: right; font-size: 14px; font-weight: bold; }
        .zona { background: #eee; font-weight: bold; font-size: 9px; text-transform: uppercase; padding: 4px; }
        .hecha td { color: #777; }
        .pie { margin-top: 14px; font-size: 9px; color: #555; }
    </style>
</head>
<body>
    <h1>Recolección · {{ $corte->etiqueta() }}</h1>
    <div class="sub">
        {{ count($filas) }} referencias · {{ $unidades }} unidades · en el orden del recorrido de la bodega ·
        generado {{ $generado->format('d/m/Y H:i') }}
    </div>

    @if(count($filas) === 0)
        <p style="margin-top: 20px;">Nada por recoger en este corte.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 22px;">✓</th>
                    <th style="width: 70px;">Ubicación</th>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th>SKU</th>
                    <th style="width: 40px; text-align: right;">Und.</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $zonaActual = false;
                    // Sin ninguna posición repartida en zonas, el encabezado no dice nada.
                    $hayZonas = collect($filas)->whereNotNull('zona')->isNotEmpty();
                @endphp
                @foreach($filas as $f)
                    @if($hayZonas && $f['zona'] !== $zonaActual)
                        @php $zonaActual = $f['zona']; @endphp
                        <tr><td colspan="6" class="zona">{{ $zonaActual !== null ? 'Zona ' . $zonaActual : 'Sin zona asignada' }}</td></tr>
                    @endif
                    <tr class="{{ $f['marcada'] ? 'hecha' : '' }}">
                        <td><div class="casilla">{{ $f['marcada'] ? '✔' : '' }}</div></td>
                        <td class="ubic">{{ $f['ubicacion_sugerida'] }}</td>
                        <td>
                            <strong>{{ $f['producto'] }}</strong>
                            @if($f['referencia'] !== '—')<br><span class="sku">Ref. {{ $f['referencia'] }}</span>@endif
                        </td>
                        <td>{{ $f['variante'] ?: '—' }}</td>
                        <td class="sku">{{ $f['sku'] }}</td>
                        <td class="und">
                            {{ $f['total_unidades'] }}
                            @if($f['faltan'] > 0)<br><span style="font-size: 8px; font-weight: normal;">faltan {{ $f['faltan'] }}</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @php $hechas = collect($filas)->where('marcada', true); @endphp
        <table style="margin-top: 18px;">
            <tr>
                <td style="border: 0; padding: 0; font-size: 9px; color: #555; vertical-align: bottom;">
                    @if($hechas->isNotEmpty())
                        {{ $hechas->count() }} de {{ count($filas) }} ya marcadas en el sistema al imprimir
                        por {{ $hechas->pluck('marcada_por')->filter()->unique()->implode(', ') ?: '—' }}.
                    @else
                        Ninguna referencia marcada en el sistema al imprimir.
                    @endif
                </td>
                <td style="border: 0; padding: 0; width: 210px; text-align: right; vertical-align: bottom;">
                    Alistó: ________________________
                </td>
            </tr>
        </table>
    @endif
</body>
</html>
