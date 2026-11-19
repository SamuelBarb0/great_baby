<!DOCTYPE html>
<html><head>
    <meta charset="UTF-8">
    <title>Etiquetas Dropi</title>
    <style>
        {{-- Etiquetas de «Mis productos en Dropi»: SOLO el código de barras
             (Code 128, con el código impreso debajo en la misma imagen), que es
             lo que lee la pistola de empaque. Sin QR, nombre ni precio
             (19-nov-2026). Las del catálogo siguen en etiquetas-lote.
             Una etiqueta por página del tamaño del rollo de la térmica
             SAT TT460: 30 × 25 mm (el papel lo fija el controlador).
             DomPDF no entiende flex ni grid: el centrado va en tabla. --}}
        * { box-sizing:border-box; margin:0; padding:0; }
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; }
        .etiqueta { width: 30mm; height: 24mm; border-collapse: collapse; }
        .etiqueta td { height: 24mm; text-align: center; vertical-align: middle; }
        .salto { page-break-after: always; }
        .barras { width: 28mm; }
    </style>
</head><body>
    @foreach($etiquetas as $e)
        <table class="etiqueta{{ $loop->last ? '' : ' salto' }}">
            <tr><td><img src="{{ $e['barras'] }}" class="barras" alt="{{ $e['codigo'] }}"></td></tr>
        </table>
    @endforeach
</body></html>
