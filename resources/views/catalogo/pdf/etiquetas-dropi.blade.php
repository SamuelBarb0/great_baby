<!DOCTYPE html>
<html><head>
    <meta charset="UTF-8">
    <title>Etiquetas Dropi</title>
    @php
        // Medidas del rollo (mm) que fija el controlador; el margen lateral es lo
        // que sobra del ancho del papel tras las columnas y sus separaciones.
        $margen = round(($rollo['ancho'] - $rollo['columnas'] * $rollo['etiqueta'] - ($rollo['columnas'] - 1) * $rollo['separacion']) / 2, 3);
    @endphp
    <style>
        {{-- Etiquetas de «Mis productos en Dropi»: el nombre del producto arriba
             y el código de barras (Code 128, con el código impreso debajo), que
             es lo que lee la pistola de empaque. Sin QR ni
             precio. Es el diseño de la plantilla de BarTender de la bodega
             (20-nov-2026). Las del catálogo siguen en etiquetas-lote.
             El rollo de la térmica SAT TT460 trae 3 etiquetas de 32,49 × 25 mm
             por fila, sin separación: cada página es una fila completa del rollo.
             DomPDF no entiende flex ni grid: el acomodo va en tabla.
             26-nov-2026: la pistola no leía la imagen del código (la térmica la
             imprimía punteada). Las barras van dibujadas, un punto de la
             impresora por módulo, y todo dentro del adhesivo (~29 × 22 mm),
             no de la columna: el contenido se salía por los bordes. --}}
        * { box-sizing:border-box; margin:0; padding:0; }
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; }
        .fila { table-layout: fixed; border-collapse: collapse; margin-left: {{ $margen }}mm; }
        .fila td { height: {{ $rollo['alto'] - 1 }}mm; padding: 0; }
        .etiqueta { width: {{ $rollo['etiqueta'] }}mm; text-align: center; vertical-align: middle; }
        .separacion { width: {{ $rollo['separacion'] }}mm; }
        .salto { page-break-after: always; }
        .area { width: {{ $area['ancho'] }}mm; max-height: {{ $area['alto'] }}mm; margin: 0 auto; overflow: hidden; }
        .nombre { font-size: 6.5pt; font-weight: bold; line-height: 1.1; text-transform: uppercase; margin-bottom: 0.6mm; }
        {{-- Producto agrupado: todos los colores comparten el código, el color escrito es lo que los distingue. --}}
        .color { font-size: 7pt; font-weight: bold; line-height: 1; text-transform: uppercase; margin-bottom: 0.6mm; border: 0.3mm solid #000; padding: 0.2mm 0; }
        {{-- Cada barra es un rectángulo en su posición exacta (DomPDF descuadra una tabla anidada de 60 celdas). --}}
        .barras { position: relative; margin: 0 auto; }
        .barras div { position: absolute; top: 0; background: #000; }
        .codigo { font-family: DejaVu Sans Mono, monospace; font-size: 6pt; line-height: 1; margin-top: 0.4mm; }
    </style>
</head><body>
    @foreach(array_chunk($etiquetas, $rollo['columnas']) as $fila)
        <table class="fila{{ $loop->last ? '' : ' salto' }}">
            <tr>
                @for($i = 0; $i < $rollo['columnas']; $i++)
                    @if($i > 0 && $rollo['separacion'] > 0)<td class="separacion"></td>@endif
                    <td class="etiqueta">
                        @isset($fila[$i])
                            @php $b = $fila[$i]['barras_dibujo']; @endphp
                            <div class="area">
                                {{-- Dos renglones como máximo: un nombre largo no debe empujar las barras fuera de la etiqueta. --}}
                                @if(filled($fila[$i]['nombre'] ?? null))<div class="nombre">{{ \Illuminate\Support\Str::limit($fila[$i]['nombre'], 30) }}</div>@endif
                                @if(filled($fila[$i]['color'] ?? null))<div class="color">{{ \Illuminate\Support\Str::limit($fila[$i]['color'], 18) }}</div>@endif
                                <div class="barras" style="width: {{ $b['ancho'] }}mm; height: {{ $b['alto'] }}mm;">
                                    @php $x = $b['silencio']; @endphp
                                    @foreach($b['tramos'] as [$barra, $ancho])
                                        @if($barra)<div style="left: {{ round($x, 4) }}mm; width: {{ $ancho }}mm; height: {{ $b['alto'] }}mm;"></div>@endif
                                        @php $x += $ancho; @endphp
                                    @endforeach
                                </div>
                                <div class="codigo">{{ \App\Support\Code128::codificable($fila[$i]['codigo']) }}</div>
                            </div>
                        @endisset
                    </td>
                @endfor
            </tr>
        </table>
    @endforeach
</body></html>
