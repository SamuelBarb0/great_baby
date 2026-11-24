<!DOCTYPE html>
<html><head>
    <meta charset="UTF-8">
    <title>Etiquetas lote</title>
    <style>
        {{-- DomPDF no entiende flex ni grid: el acomodo va en tablas. --}}
        * { box-sizing:border-box; margin:0; padding:0; }
        @page { margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; }
        .grid { width: 100%; border-collapse: separate; border-spacing: 3mm; }
        .celda { width: 50%; border: 1px dashed #999; padding: 3mm; vertical-align: top; page-break-inside: avoid; }
        .etiqueta { width: 100%; border-collapse: collapse; }
        .etiqueta td { vertical-align: top; }
        .qr { width: 20mm; height: 20mm; }
        .info { padding-left: 3mm; }
        .marca { font-size: 7pt; color: #666; }
        .nombre { font-size: 9pt; font-weight: 700; line-height: 1.1; margin-top: 1mm; }
        .var { font-size: 7pt; color: #555; margin-top: 1mm; }
        .precio { font-size: 11pt; font-weight: 700; color: #b45309; margin-top: 1mm; }
        .barras-celda { padding-top: 2mm; text-align: center; }
        .barras { width: 82mm; }
    </style>
</head><body>
    <table class="grid">
        @foreach(array_chunk($etiquetas, 2) as $fila)
            <tr>
                @foreach($fila as $e)
                    <td class="celda">
                        <table class="etiqueta">
                            <tr>
                                <td style="width:20mm"><img src="{{ $e['qr'] }}" class="qr" alt="QR"></td>
                                <td class="info">
                                    <div class="marca">GREAT BABY</div>
                                    <div class="nombre">{{ \Illuminate\Support\Str::limit($e['nombre'] ?? '—', 28) }}</div>
                                    <div class="var">{{ $e['detalle'] }}</div>
                                    {{-- Sin precio cargado no se imprime «$0». --}}
                                    @if(($e['precio'] ?? 0) > 0)
                                        <div class="precio">${{ number_format($e['precio'], 0, ',', '.') }}</div>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                {{-- Code 128 con el código impreso debajo, para lector de mano. --}}
                                <td colspan="2" class="barras-celda"><img src="{{ $e['barras'] }}" class="barras" alt="{{ $e['codigo'] }}"></td>
                            </tr>
                        </table>
                    </td>
                @endforeach
                @if(count($fila) < 2)<td class="celda" style="border:none"></td>@endif
            </tr>
        @endforeach
    </table>
</body></html>
