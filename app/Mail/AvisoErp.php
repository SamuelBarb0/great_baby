<?php

namespace App\Mail;

use App\Models\NotificacionErp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * El correo de un aviso del ERP.
 *
 * Es una clase y no un `Mail::send()` con la vista suelta porque así se puede
 * verificar: con la vista suelta, `Mail::fake()` no registra nada y las
 * pruebas quedan mirando al vacío sin decirlo.
 */
class AvisoErp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public NotificacionErp $aviso) {}

    public function build(): self
    {
        return $this->subject('[Great Baby] '.$this->aviso->titulo)
            ->view('emails.aviso-erp', [
                'aviso' => $this->aviso,
                'color' => $this->colorHex($this->aviso->color),
                'enlace' => $this->aviso->url
                    ? rtrim(config('app.url'), '/').$this->aviso->url
                    : null,
            ]);
    }

    /** El color de la campana, en hexadecimal, para la cabecera del correo. */
    private function colorHex(?string $color): string
    {
        return match ($color) {
            'danger', 'red' => '#b91c1c',
            'warning', 'amber' => '#b45309',
            'success', 'emerald' => '#047857',
            'info', 'sky' => '#0369a1',
            default => '#374151',
        };
    }
}
