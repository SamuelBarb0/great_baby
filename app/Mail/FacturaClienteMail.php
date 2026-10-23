<?php

namespace App\Mail;

use App\Models\EmpresaConfig;
use App\Modules\Cartera\Models\FacturaVenta;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FacturaClienteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public FacturaVenta $factura,
        public string $linkPublico,
    ) {}

    public function envelope(): Envelope
    {
        $empresa = EmpresaConfig::porId($this->factura->empresa_id);
        $asunto = "Tu factura {$this->factura->numero}"
            . ($this->factura->numero_siigo ? " (DIAN {$this->factura->numero_siigo})" : '')
            . " · {$empresa->razon_social}";

        return new Envelope(subject: $asunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.factura-cliente',
            with: [
                'factura' => $this->factura,
                'empresa' => EmpresaConfig::porId($this->factura->empresa_id),
                'linkPublico' => $this->linkPublico,
            ],
        );
    }
}
