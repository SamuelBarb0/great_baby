<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>Etiquetas {{ $orden->numero }}</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
@page { margin: 12mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #111; }
h1 { font-size: 12pt; margin-bottom: 2mm; }
p.sub { color: #555; margin-bottom: 5mm; }
table { width: 100%; border-collapse: collapse; }
td { border: 1px solid #bbb; padding: 2mm; vertical-align: middle; }
tr { page-break-inside: avoid; }
th { text-align: left; padding: 2mm; font-size: 7pt; color: #555; }
.ref { font-weight: bold; font-size: 9pt; }
.falta { color: #b3261e; }
img.etiqueta { max-width: 70mm; max-height: 42mm; }
img.barras { max-width: 62mm; }
</style>
</head><body>
<h1>Etiquetas de la orden {{ $orden->numero }}</h1>
<p class="sub">Dos stickers separados por caja: la etiqueta de importación y, aparte, el código de barras.</p>
<table>
    <thead><tr><th>Producto</th><th>Etiqueta de importación</th><th>Código de barras</th></tr></thead>
    <tbody>
    @foreach ($lineas as $l)
        <tr>
            <td style="width:30%">
                <div class="ref">{{ $l['referencia'] ?? 'Sin referencia' }}</div>
                <div>{{ $l['descripcion'] }}</div>
                @if ($l['cajas'])<div>{{ $l['cajas'] }} cajas</div>@endif
            </td>
            <td style="width:40%">
                @if ($l['etiqueta'])
                    <img class="etiqueta" src="{{ $l['etiqueta'] }}" alt="">
                @else
                    <span class="falta">Este producto no tiene etiqueta guardada.</span>
                @endif
            </td>
            <td style="width:30%">
                @if ($l['barras'])
                    <img class="barras" src="{{ $l['barras'] }}" alt="">
                @else
                    <span class="falta">Sin referencia para generar el código.</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</body></html>
