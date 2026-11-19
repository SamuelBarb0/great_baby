<?php

namespace App\Modules\Notificaciones\Services;

use App\Modules\Cartera\Models\NotaCredito;
use App\Modules\Cartera\Models\NotaDebito;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Services\WhatsAppClient;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 4 · A.6 · Notificador WhatsApp cadena de valor.
 *
 * Cuatro eventos clave del ciclo comercial disparan un WhatsApp:
 *   1. Aracely aprueba una OC → notifica al proveedor (aviso: te llegará una OC).
 *   2. Recepción confirmada  → notifica al proveedor (recibimos completo/parcial).
 *   3. NC emitida al cliente → notifica al cliente (te aplicamos una NC por X).
 *   4. ND emitida al cliente → notifica al cliente (te generamos una ND por X).
 *
 * Sin credenciales WhatsApp Cloud, el WhatsAppClient corre en modo mock y solo
 * registra en el log. Feature flag por setting `notificaciones.whatsapp_cadena_valor`
 * (default true en local, false en prod hasta que Aracely confirme templates).
 */
class NotificadorCadenaValor
{
    public function __construct(protected WhatsAppClient $wa)
    {
    }

    public function ocAprobada(OrdenCompra $oc): void
    {
        if (! $this->activo()) return;
        $oc->loadMissing('proveedor');
        if (! $this->puedeContactar($oc->proveedor)) return;
        $tel = $oc->proveedor?->telefono;
        if (! $tel) return;
        $msg = sprintf(
            "Hola %s! Great Baby te ha emitido la OC %s por $%s. Recibirás pronto la mercancía a coordinar. — GREAT BABY",
            $oc->proveedor->nombre_completo ?? 'proveedor',
            $oc->numero,
            number_format((float) $oc->total, 2)
        );
        $this->enviarSeguro($tel, $msg, 'OC aprobada', ['oc_id' => $oc->id]);
    }

    public function recepcionConfirmada(RecepcionCompra $rc): void
    {
        if (! $this->activo()) return;
        // La relación se llama `orden`, no `ordenCompra` (ver RecepcionCompra).
        // Con el nombre equivocado esto reventaba con RelationNotFoundException
        // en CADA recepción confirmada: el aviso al proveedor no salía nunca y
        // el job moría en `failed_jobs`, donde nadie mira. Encontrado el
        // 2026-10-14 recibiendo una orden de compra real.
        $rc->loadMissing('orden.proveedor');
        if (! $this->puedeContactar($rc->orden?->proveedor)) return;
        $tel = $rc->orden?->proveedor?->telefono;
        if (! $tel) return;
        $msg = sprintf(
            "Hola %s! Confirmamos recepción de la remesa %s asociada a la OC %s. Gracias. — GREAT BABY",
            $rc->orden->proveedor->nombre_completo ?? 'proveedor',
            $rc->numero,
            $rc->orden->numero
        );
        $this->enviarSeguro($tel, $msg, 'Recepción confirmada', ['recepcion_id' => $rc->id]);
    }

    public function notaCreditoEmitida(NotaCredito $nc): void
    {
        if (! $this->activo()) return;
        $nc->loadMissing('factura.contacto');
        if (! $this->puedeContactar($nc->factura?->contacto)) return;
        $tel = $nc->factura?->contacto?->telefono;
        if (! $tel) return;
        $msg = sprintf(
            "Hola %s! Te generamos la Nota Crédito %s por $%s aplicada a la factura %s. Motivo: %s. — GREAT BABY",
            $nc->factura->contacto->nombre_completo ?? 'cliente',
            $nc->numeroCompleto(),
            number_format((float) $nc->valor, 2),
            $nc->factura->numero,
            \Illuminate\Support\Str::limit((string) $nc->motivo, 80)
        );
        $this->enviarSeguro($tel, $msg, 'NC emitida', ['nc_id' => $nc->id]);
    }

    public function notaDebitoEmitida(NotaDebito $nd): void
    {
        if (! $this->activo()) return;
        $nd->loadMissing('factura.contacto');
        if (! $this->puedeContactar($nd->factura?->contacto)) return;
        $tel = $nd->factura?->contacto?->telefono;
        if (! $tel) return;
        $msg = sprintf(
            "Hola %s! Se generó la Nota Débito %s por $%s asociada a la factura %s. Motivo: %s. — GREAT BABY",
            $nd->factura->contacto->nombre_completo ?? 'cliente',
            $nd->numeroCompleto(),
            number_format((float) $nd->valor, 2),
            $nd->factura->numero,
            \Illuminate\Support\Str::limit((string) $nd->motivo, 80)
        );
        $this->enviarSeguro($tel, $msg, 'ND emitida', ['nd_id' => $nd->id]);
    }

    protected function activo(): bool
    {
        return (bool) setting('notificaciones.whatsapp_cadena_valor', app()->environment('local'));
    }

    // QA-FIX #9 · Habeas Data (Ley 1581/2012 · SIC) · sin opt-in explícito,
    // no se puede enviar WhatsApp comercial. Si el contacto pidió baja, tampoco.
    protected function puedeContactar(?\App\Models\Contacto $c): bool
    {
        if (! $c) return false;
        if (! (bool) ($c->whatsapp_opt_in ?? false)) {
            Log::channel('single')->info('[WA cadena-valor] omitido · sin opt-in Habeas Data', [
                'contacto_id' => $c->id, 'nombre' => $c->nombre_completo,
            ]);
            return false;
        }
        if ($c->whatsapp_opt_out_at) {
            Log::channel('single')->info('[WA cadena-valor] omitido · contacto pidió baja', [
                'contacto_id' => $c->id, 'opt_out_at' => $c->whatsapp_opt_out_at,
            ]);
            return false;
        }
        return true;
    }

    protected function enviarSeguro(string $tel, string $msg, string $evento, array $ctx = []): void
    {
        try {
            $r = $this->wa->enviarTexto($tel, $msg);
            Log::channel('single')->info("[WA cadena-valor] {$evento} · {$r['status']}", $ctx + ['tel' => $tel]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning("[WA cadena-valor] {$evento} falló: {$e->getMessage()}", $ctx);
        }
    }
}
