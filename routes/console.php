<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §6 diseño Dropi — libera locks vencidos del alistador cada 2 min.
Schedule::command('dropi:liberar-locks')->everyTwoMinutes()->withoutOverlapping();

// F15 · re-matcheo periódico de wallet huérfanos (movimientos que llegaron antes
// de que el pedido apareciera en el sync).
Schedule::command('dropi:reconciliar-huerfanos')->everyThirtyMinutes()->withoutOverlapping();

// §7 TO-BE Cartera — barrido diario de cobranza WhatsApp a las 9:00.
Schedule::command('cartera:cobrar')->dailyAt('09:00')->withoutOverlapping();

// SIIGO — sync incremental de productos (B3-M6 · escalonado al minuto :02 de
// cada cuarto de hora para no colisionar con inventario/empaque; usa cursor
// updated_start ISO-8601 desde el último sync exitoso).
Schedule::command('siigo:sync-productos')
    ->cron('2-59/15 * * * *')
    ->withoutOverlapping();
Schedule::command('siigo:sync clientes')->everyThreeHours()->withoutOverlapping();
Schedule::command('siigo:sync catalogos')->dailyAt('03:00')->withoutOverlapping();
// B3-M5 · purga nocturna del log SIIGO (retención 30 días por default).
Schedule::command('siigo:purgar-logs')->dailyAt('02:30')->withoutOverlapping();
// TEST-S7 · comparador diario ERP ↔ SIIGO (03:45 Bogotá). Genera reporte en
// storage/app/siigo-diario/ y manda WhatsApp si hay discrepancias del día.
Schedule::command('siigo:comparador-diario')
    ->dailyAt('03:45')->timezone('America/Bogota')->withoutOverlapping();

// Red de seguridad: reencola todo documento que debería estar en SIIGO y no
// está. Los observers ya empujan al crear cada documento, pero eso se pierde si
// en ese momento la cola estaba caída, SIIGO no respondía o faltaba mapear una
// cuenta — y nadie vuelve a intentarlo. Corre después del comparador para que
// el reporte del día ya refleje lo reenviado.
Schedule::command('siigo:empujar-pendientes')
    ->dailyAt('04:15')->timezone('America/Bogota')->withoutOverlapping();

// M3 Inventario — barrido cada 15 min · escalonado al minuto :07.
Schedule::command('inventario:barrer')
    ->cron('7-59/15 * * * *')
    ->withoutOverlapping();

// Empaque — cierra registros en_curso abandonados (> 30 min sin actividad)
// · escalonado al minuto :12.
Schedule::command('empaque:cerrar-huerfanos')
    ->cron('12-59/15 * * * *')
    ->withoutOverlapping();

// Backup nocturno: BD (mysqldump) + fotos empaque (tar.gz), retención 14 días.
Schedule::command('gb:backup --keep=14')->dailyAt('02:00')->withoutOverlapping();

// MEJORAS-A · Reporte semanal WhatsApp a Aracely los lunes 8am (Bogotá).
Schedule::command('reporte:semanal')->weeklyOn(1, '08:00')->timezone('America/Bogota')->withoutOverlapping();

// M1 · Segmentación de clientes — semanal domingos 04:00
Schedule::command('crm:segmentar-clientes')->weeklyOn(0, '04:00')->withoutOverlapping();

// Retención fotos empaque (Habeas Data) — diario 03:30
Schedule::command('gb:purgar-fotos-empaque')->dailyAt('03:30')->withoutOverlapping();

// Cuando llegue la API de Dropi (§26) → activar sync automático:
// Schedule::command('dropi:sync --horas=1')->everyFiveMinutes()->withoutOverlapping();

// Driver web de Dropi · sync casi en tiempo real en tres ritmos (SincronizarDropiWeb).
//   Solo corre con DROPI_WEB_SYNC=true y una sesión conectada en Dropi → Conexión Dropi.
$dropiWebSync = fn () => (bool) config('dropi.web.sync_activo');
Schedule::command('dropi:web-sync recientes')->everyFifteenSeconds()->withoutOverlapping(2)->when($dropiWebSync);
Schedule::command('dropi:web-sync ventana')->everyMinute()->withoutOverlapping(10)->when($dropiWebSync);
Schedule::command('dropi:web-sync transito')->everyTenMinutes()->withoutOverlapping(30)->when($dropiWebSync);
// Pagos: Dropi paga ~1 día después de la entrega sin cambiar el estatus; se
//   revisa el detalle de los entregados sin pago (trae su `history_wallet`).
Schedule::command('dropi:web-sync pagos --max-detalles=60')->everyThirtyMinutes()->withoutOverlapping(20)->when($dropiWebSync);
// Wallet: los pagos de Dropi entran por lotes durante el día; cada 30 min basta.
Schedule::command('dropi:web-wallet-sync')->everyThirtyMinutes()->withoutOverlapping(20)->when($dropiWebSync);

/*
 * Gestión Humana · recordatorio de períodos de prueba.
 *
 * Una vez por semana, los lunes temprano: el plazo se mide en meses, así que
 * avisar a diario sería ruido, pero dejarlo sin aviso es como está hoy —se
 * vence solo y después terminar el contrato ya cuesta indemnización—.
 */
Schedule::command('rrhh:avisar-periodos-prueba')
    ->weeklyOn(1, '07:30')->timezone('America/Bogota')->withoutOverlapping();
