<?php

namespace App\Modules\Siigo\Services;

use App\Models\EmpresaConfig;
use App\Modules\Cartera\Models\FacturaVenta;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * QR DIAN — Anexo Técnico Factura Electrónica de Venta versión 1.9.
 * Campos obligatorios: NumFac, FecFac, HorFac, NitFac, DocAdq, ValFac, ValIva,
 * ValOtroIm, ValTolFac, CUFE + URL de consulta oficial.
 *
 * @see https://www.dian.gov.co/impuestos/factura-electronica/Documents/Anexo-Tecnico-Factura-Electronica-de-Venta-vr-1-9.pdf
 */
class QrDianService
{
    private const URL_CONSULTA_DIAN = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=';

    public function generarSvg(FacturaVenta $factura, int $tamano = 130): string
    {
        if (empty($factura->cufe)) {
            return '';
        }

        try {
            $qr = Builder::create()
                ->writer(new SvgWriter())
                ->data($this->contenidoQr($factura))
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size($tamano)
                ->margin(2)
                ->build();

            return $qr->getString();
        } catch (\Throwable) {
            return '';
        }
    }

    public function generarPngDataUri(FacturaVenta $factura, int $tamano = 300): string
    {
        if (empty($factura->cufe)) {
            return '';
        }

        try {
            $qr = Builder::create()
                ->writer(new PngWriter())
                ->data($this->contenidoQr($factura))
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size($tamano)
                ->margin(2)
                ->build();

            return $qr->getDataUri();
        } catch (\Throwable) {
            return '';
        }
    }

    public function urlConsultaDian(string $cufe): string
    {
        return self::URL_CONSULTA_DIAN . $cufe;
    }

    /**
     * Contenido textual del QR — 10 campos oficiales + URL.
     */
    public function contenidoQr(FacturaVenta $factura): string
    {
        $empresa = EmpresaConfig::porId($factura->empresa_id);
        $numero = (string) ($factura->numero_siigo ?? $factura->numero);
        $fecha = $factura->fecha_emision?->format('Y-m-d') ?? '';
        $horaFuente = $factura->emitida_at ?? $factura->created_at ?? now();
        $hora = $horaFuente->format('H:i:sP');
        $nitEmisor = $empresa->nitSinDv();
        $docCliente = (string) ($factura->contacto?->numero_documento ?? '');

        $subtotal = $this->fmt((float) $factura->subtotal);
        $iva = $this->fmt((float) $factura->impuestos);
        $otros = $this->fmt(0);
        $total = $this->fmt((float) $factura->total);
        $cufe = (string) $factura->cufe;

        return implode("\n", [
            'NumFac: ' . $numero,
            'FecFac: ' . $fecha,
            'HorFac: ' . $hora,
            'NitFac: ' . $nitEmisor,
            'DocAdq: ' . $docCliente,
            'ValFac: ' . $subtotal,
            'ValIva: ' . $iva,
            'ValOtroIm: ' . $otros,
            'ValTolFac: ' . $total,
            'CUFE: ' . $cufe,
            self::URL_CONSULTA_DIAN . $cufe,
        ]);
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
