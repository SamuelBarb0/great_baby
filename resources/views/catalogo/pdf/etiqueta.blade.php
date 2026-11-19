<!DOCTYPE html>
<html><head>
    <meta charset="UTF-8">
    <style>
        {{-- DomPDF no entiende flex ni grid: el acomodo va en tablas. --}}
        @page { margin: 0; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family: DejaVu Sans, sans-serif; }
        .etiqueta { border-collapse: collapse; margin: 2mm 3mm; width: 95mm; }
        .etiqueta td { vertical-align: top; }
        .qr { width: 20mm; height: 20mm; }
        .info { padding-left: 3mm; }
        .marca { font-size: 7pt; color: #666; }
        .nombre { font-size: 9pt; font-weight: 700; margin-top: 1px; line-height: 1.1; }
        .variante { font-size: 7pt; color: #555; margin-top: 1px; }
        .precio { font-size: 11pt; font-weight: 700; color: #b45309; margin-top: 2px; }
        .barras-celda { padding-top: 1.5mm; text-align: center; }
        .barras { width: 88mm; }
    </style>
</head><body>
    <table class="etiqueta">
        <tr>
            <td style="width:20mm"><img src="{{ $qr }}" class="qr" alt="QR"></td>
            <td class="info">
                <div class="marca">GREAT BABY</div>
                <div class="nombre">{{ Str::limit($variante->producto?->nombre ?? '—', 32) }}</div>
                <div class="variante">
                    @if($variante->color_nombre) 🎨 {{ $variante->color_nombre }} @endif
                    @if($variante->diseno_nombre) · {{ $variante->diseno_nombre }} @endif
                    @if($variante->talla) · T {{ $variante->talla }} @endif
                </div>
                <div class="precio">${{ number_format((float) ($variante->producto?->precio_proveedor ?? 0), 0, ',', '.') }}</div>
            </td>
        </tr>
        <tr>
            {{-- Code 128 con el código impreso debajo, para lector de mano. --}}
            <td colspan="2" class="barras-celda"><img src="{{ $barras }}" class="barras" alt="{{ $variante->codigo_barras }}"></td>
        </tr>
    </table>
</body></html>
