<?php

namespace App\Jobs;

use App\Models\NotificacionErp;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Manda por correo un aviso que ya quedó en la campana.
 *
 * Hasta el 2026-11-06 el ERP sólo avisaba dentro de la aplicación. El contrato
 * pide «motor de actividades y notificaciones en la app, correo y WhatsApp», y
 * de los tres sólo existía el primero: quien no entraba al sistema ese día no
 * se enteraba de nada — ni de un pedido retenido, ni de una guía sin entregar,
 * ni de una nómina esperando aprobación.
 *
 * Va en cola a propósito. Un SMTP lento no puede dejar colgado a quien aprobó
 * un pedido: el aviso es una consecuencia de su acción, no parte de ella.
 *
 * Y falla en silencio, con registro. Que no salga un correo es molesto; que
 * reviente la operación por eso, no.
 */
class EnviarAvisoPorCorreo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300];

    public function __construct(public int $avisoId) {}

    public function handle(): void
    {
        $aviso = NotificacionErp::find($this->avisoId);
        if (! $aviso) {
            return;
        }

        $destinos = $this->destinatarios($aviso);
        if ($destinos === []) {
            Log::info('aviso-por-correo: sin destinatarios', ['aviso' => $aviso->id, 'tipo' => $aviso->tipo]);

            return;
        }

        foreach ($destinos as $correo) {
            try {
                Mail::to($correo)->send(new \App\Mail\AvisoErp($aviso));
            } catch (\Throwable $e) {
                // Un correo que no sale no puede tumbar los demás ni la cola.
                Log::warning('aviso-por-correo: no se pudo enviar', [
                    'aviso' => $aviso->id, 'para' => $correo, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * A quién le llega.
     *
     * Si el aviso es de alguien, a esa persona. Si es para todos —`user_id`
     * nulo— va a la lista de copia que configure Gerencia, porque mandarle a
     * los 23 usuarios cada aviso del sistema es la forma más rápida de que
     * todos lo manden a spam.
     *
     * @return array<int,string>
     */
    private function destinatarios(NotificacionErp $aviso): array
    {
        if ($aviso->user_id) {
            $correo = User::whereKey($aviso->user_id)->value('email');

            return $correo ? [$correo] : [];
        }

        $lista = (string) setting('notificaciones.correo_copia');

        return collect(preg_split('/[,;\s]+/', $lista))
            ->map(fn ($c) => trim($c))
            ->filter(fn ($c) => $c !== '' && filter_var($c, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }
}
