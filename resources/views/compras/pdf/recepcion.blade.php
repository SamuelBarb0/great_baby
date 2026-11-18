<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>REC {{ $recepcion->numero }}</title>
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 12mm; }
h1 { font-size: 14pt; color:#b45309; }
.small { color:#6b7280; font-size: 9pt; }
table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
th { background:#f3f4f6; padding: 2mm; text-align:left; border-bottom:2px solid #d1d5db; font-size: 8pt; text-transform:uppercase; }
td { padding: 2mm; border-bottom:1px solid #f3f4f6; }
.right { text-align: right; }
</style>
</head><body>

<h1>RECEPCIÓN DE MERCANCÍA {{ $recepcion->numero }}</h1>
<div class="small">
    OC origen: <strong>{{ $recepcion->orden?->numero }}</strong> ·
    Proveedor: <strong>{{ $recepcion->orden?->proveedor?->nombreDisplay() }}</strong> ·
    {{-- Con el reparto, el encabezado ya no puede nombrar una sola bodega: el
         PDF es lo que firma quien recibe y diría que las 50 unidades entraron
         todas al mismo sitio. --}}
    {{-- Lo faltante no entró a ninguna bodega, así que no cuenta para decidir si
         la mercancía quedó repartida: `bodegaDondeQuedo()` devuelve null y null
         nunca es igual al id, así que una sola línea anotada como faltante hacía
         que este papel —el que se firma— dejara de nombrar la bodega y dijera
         «varias» de una recepción que entró entera a un solo sitio. --}}
    @php($repartida = $recepcion->items
        ->reject(fn ($x) => $x->bodegaDondeQuedo() === null)
        ->contains(fn ($x) => $x->bodegaDondeQuedo() !== (int) $recepcion->bodega_id))
    Bodega: <strong>{{ $repartida ? 'varias (ver detalle)' : $recepcion->bodega?->nombre }}</strong> ·
    Fecha: <strong>{{ $recepcion->fecha_recepcion?->format('Y-m-d') }}</strong>
</div>
@if($recepcion->factura_proveedor)<div class="small">Factura proveedor: {{ $recepcion->factura_proveedor }}</div>@endif
@if($recepcion->transportista)<div class="small">Transportista: {{ $recepcion->transportista }}</div>@endif

{{-- Los nombres de las bodegas que tocan estas líneas, de una sola consulta.
     Buscarlos renglón por renglón dentro del @foreach le pedía a la base una
     fila por línea sólo para imprimir un nombre. --}}
@php($nombresBodega = \App\Modules\Dropi\Models\InventarioUbicacion::whereIn(
    'id',
    $recepcion->items->map(fn ($x) => $x->bodegaDondeQuedo())->filter()->unique()->values()->all()
)->pluck('nombre', 'id'))

<table>
    <thead><tr>
        <th>#</th><th>Ítem</th><th>Variante</th>
        <th class="right">Cant recibida</th>
        <th class="right">Costo unit</th>
        <th class="right">Subtotal</th>
        <th>Lote</th><th>Vence</th><th>Va para</th>
    </tr></thead>
    <tbody>
    @foreach($recepcion->items as $i => $it)
        <tr>
            <td>{{ $i+1 }}</td>
            <td>{{ $it->producto?->nombre ?? '—' }}<br><span class="small">{{ $it->producto?->referencia }}</span></td>
            <td class="small">{{ $it->variante?->codigo_barras ?? '—' }}</td>
            <td class="right"><strong>{{ rtrim(rtrim(number_format((float)$it->cantidad_recibida, 3, ',', '.'), '0'), ',') }}</strong></td>
            <td class="right">${{ number_format((float)$it->costo_unit, 2, ',', '.') }}</td>
            <td class="right">${{ number_format((float)$it->subtotal, 0, ',', '.') }}</td>
            <td>{{ $it->lote ?? '—' }}</td>
            <td>{{ $it->fecha_vencimiento?->format('Y-m-d') ?? '—' }}</td>
            {{-- Dónde QUEDÓ, no a dónde la mandaron: lo que no llegó apto entra
                 a averías y lo faltante no entra a ninguna parte. Este papel se
                 firma, así que no puede decir que hay cajas en la principal
                 cuando están en averías. --}}
            <td class="small">
                @php($quedoEn = $it->bodegaDondeQuedo())
                @if($quedoEn === null)
                    <em>no llegó</em>
                @else
                    {{ $nombresBodega[$quedoEn] ?? '—' }}
                    @if(($it->clasificacion ?: 'apto') !== 'apto')
                        <br><em>({{ $it->clasificacion }})</em>
                    @endif
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" class="right"><strong>Total recibido:</strong></td>
            <td class="right"><strong>${{ number_format((float)$recepcion->total_recibido, 0, ',', '.') }}</strong></td>
            {{-- El encabezado tiene nueve columnas: 5 + 1 + 3. Con dos, la
                 última celda del pie quedaba sin cerrar y el borde de la tabla
                 salía cortado en el papel. --}}
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>

<div style="margin-top:20mm; display:flex; justify-content:space-between;">
    <div style="width:60mm; border-top:1px solid #111; padding-top:2mm; text-align:center;">Recibió: {{ $recepcion->receptor?->name ?? '—' }}</div>
    <div style="width:60mm; border-top:1px solid #111; padding-top:2mm; text-align:center;">Firma proveedor</div>
</div>

</body></html>
