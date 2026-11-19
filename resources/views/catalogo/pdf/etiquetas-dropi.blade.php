<!DOCTYPE html>
<html><head>
    <meta charset="UTF-8">
    <title>Etiquetas Dropi</title>
    @php
        // Medidas del rollo (mm) que fija el controlador; el margen lateral es lo
        // que sobra del ancho del papel tras las columnas y sus separaciones.
        $margen = ($rollo['ancho'] - $rollo['columnas'] * $rollo['etiqueta'] - ($rollo['columnas'] - 1) * $rollo['separacion']) / 2;
    @endphp
    <style>
        {{-- Etiquetas de «Mis productos en Dropi»: SOLO el código de barras
             (Code 128, con el código impreso debajo en la misma imagen), que es
             lo que lee la pistola de empaque. Sin QR, nombre ni precio
             (19-nov-2026). Las del catálogo siguen en etiquetas-lote.
             El rollo de la térmica SAT TT460 trae 3 etiquetas de 30 × 25 mm por
             fila: cada página es una fila completa del rollo.
             DomPDF no entiende flex ni grid: el acomodo va en tabla. --}}
        * { box-sizing:border-box; margin:0; padding:0; }
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; }
        .fila { table-layout: fixed; border-collapse: collapse; margin-left: {{ $margen }}mm; }
        .fila td { height: {{ $rollo['alto'] - 1 }}mm; padding: 0; }
        .etiqueta { width: {{ $rollo['etiqueta'] }}mm; text-align: center; vertical-align: middle; }
        .separacion { width: {{ $rollo['separacion'] }}mm; }
        .salto { page-break-after: always; }
        .barras { width: {{ $rollo['etiqueta'] - 2 }}mm; }
    </style>
</head><body>
    @foreach(array_chunk($etiquetas, $rollo['columnas']) as $fila)
        <table class="fila{{ $loop->last ? '' : ' salto' }}">
            <tr>
                @for($i = 0; $i < $rollo['columnas']; $i++)
                    @if($i > 0)<td class="separacion"></td>@endif
                    <td class="etiqueta">
                        @isset($fila[$i])<img src="{{ $fila[$i]['barras'] }}" class="barras" alt="{{ $fila[$i]['codigo'] }}">@endisset
                    </td>
                @endfor
            </tr>
        </table>
    @endforeach
</body></html>
