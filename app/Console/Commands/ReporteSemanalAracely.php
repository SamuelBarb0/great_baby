<?php

namespace App\Console\Commands;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Cartera\Models\PagoVenta;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\GarantiaTicket;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MEJORAS-A · Reporte semanal por WhatsApp a Aracely los lunes 8am.
 * Registrar en app/Console/Kernel.php o Schedule::command(...)->weeklyOn(1,'8:00');
 *
 * Uso manual: php artisan reporte:semanal
 * Uso schedule: agregar en routes/console.php o Kernel
 */
class ReporteSemanalAracely extends Command
{
    protected $signature = 'reporte:semanal {--telefono= : override del teléfono destino} {--dry : imprime sin enviar}';
    protected $description = 'Envía resumen semanal a Aracely por WhatsApp';

    public function handle(): int
    {
        $desde = now('America/Bogota')->subDays(7)->startOfDay();
        $hasta = now('America/Bogota')->endOfDay();

        /*
         * KPIs de la semana.
         *
         * «Facturado» acá son los dos estados, no uno: un pedido pasa a
         * «despachado» cuando sale de bodega, así que con `where('estado',
         * 'facturado')` a secas el número BAJABA cuando la bodega despachaba,
         * y la misma venta desaparecía del reporte. Con el despacho parcial se
         * volvió peor: el pedido se queda en «facturado» mientras falte el
         * resto y pasa a «despachado» al completarse, así que entraba y salía
         * del reporte según el viaje. Es la misma pregunta que ya responden
         * así `CrmController` y el seguimiento del vendedor.
         */
        $ventasB2b = (float) PedidoCliente::whereBetween('created_at', [$desde, $hasta])
            ->whereIn('estado', ['facturado', 'despachado'])->sum('total');
        $pedidosB2bNuevos = PedidoCliente::whereBetween('created_at', [$desde, $hasta])->count();
        $ventasDropiSemana = (float) DropiPedido::whereBetween('pagado_at', [$desde, $hasta])->where('estado', 'pagado')->sum('monto_esperado_proveedor');
        $facturasEmitidas = FacturaVenta::whereBetween('fecha_emision', [$desde->toDateString(), $hasta->toDateString()])->count();
        $totalFactSemana = (float) FacturaVenta::whereBetween('fecha_emision', [$desde->toDateString(), $hasta->toDateString()])->sum('total');
        $cobrado = (float) PagoVenta::whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->sum('monto_aplicado');
        $carteraVencida = (float) FacturaVenta::where('estado', 'vencida')->whereNull('deleted_at')->sum('saldo');
        $garantiasAbiertas = GarantiaTicket::whereIn('estado', ['abierto', 'en_revision'])->count();
        $pedidosB2bPendientes = PedidoCliente::where('estado', 'enviado')->count();

        $fmt = fn ($n) => '$' . number_format($n, 0, ',', '.');

        $mensaje = "📊 *GREAT BABY · Reporte semanal*\n";
        $mensaje .= "_" . now('America/Bogota')->format('d M Y H:i') . "_\n";
        $mensaje .= "\n🛒 *Ventas (últimos 7 días)*\n";
        $mensaje .= "• B2B facturado: {$fmt($ventasB2b)} ({$pedidosB2bNuevos} pedidos nuevos)\n";
        $mensaje .= "• Dropi pagado: {$fmt($ventasDropiSemana)}\n";
        $mensaje .= "• Facturas emitidas: {$facturasEmitidas} · total {$fmt($totalFactSemana)}\n";
        $mensaje .= "\n💰 *Cobranza*\n";
        $mensaje .= "• Cobrado semana: {$fmt($cobrado)}\n";
        $mensaje .= "• Cartera vencida: {$fmt($carteraVencida)}\n";
        $mensaje .= "\n🚨 *Pendientes*\n";
        $mensaje .= "• Pedidos B2B por revisar: *{$pedidosB2bPendientes}*\n";
        $mensaje .= "• Garantías sin decidir: *{$garantiasAbiertas}*\n";
        $mensaje .= "\n👉 " . config('app.url') . "/app/cosas-del-dia";

        if ($this->option('dry')) {
            $this->line($mensaje);
            return self::SUCCESS;
        }

        $tel = $this->option('telefono') ?: (setting('whatsapp.aracely_tel') ?: env('WHATSAPP_ARACELY_TEL'));
        if (! $tel) {
            $this->error('Falta teléfono. Configurá whatsapp.aracely_tel en Reglas o WHATSAPP_ARACELY_TEL en .env');
            return self::FAILURE;
        }
        $phoneId = env('WHATSAPP_PHONE_ID');
        $token = env('WHATSAPP_TOKEN');
        if (! $phoneId || ! $token) {
            $this->warn('WhatsApp API no configurada. Mostrando mensaje:');
            $this->line($mensaje);
            return self::SUCCESS;
        }

        $res = Http::withToken($token)->post("https://graph.facebook.com/v20.0/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $tel,
            'type' => 'text',
            'text' => ['body' => $mensaje],
        ]);

        if ($res->successful()) {
            $this->info("✅ Enviado a {$tel}");
            Log::info('reporte:semanal enviado', ['tel' => $tel, 'chars' => strlen($mensaje)]);
        } else {
            $this->error("Error WhatsApp: " . $res->body());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
