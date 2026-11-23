<?php

use App\Support\Reglas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Las cinco transportadoras con las que trabaja el área.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * POR QUÉ HACE FALTA UNA MIGRACIÓN Y NO ALCANZA CON EL DEFAULT
 *
 * `Reglas::seedDefaults()` NUNCA pisa el `valor` de una fila que ya existe, y
 * hace bien: lo que el área configuró desde la pantalla es suyo. Pero eso
 * significa que cambiar el literal de `Reglas::DEFAULTS` no le cambia nada a
 * una base que ya tiene la fila sembrada — o sea, a producción.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * QUÉ HACE
 *
 * 1. `logistica.transportadoras` es nueva: se crea con las cinco que el área
 *    nombró el 20-nov-2026 (Estelar, Exprecar, Veloenvíos, Servientrega e
 *    Interrapidísimo). Sirve para que el nombre se ELIJA al despachar y no se
 *    teclee: era texto libre y la misma empresa entraba escrita de tres formas
 *    distintas, así que no había manera de medir cuál se demora.
 *
 * 2. `logistica.rastreo_urls` se MEZCLA, no se reemplaza. Se agregan las tres
 *    que faltaban con la url VACÍA —no tienen rastreo público, se buscó— y se
 *    respeta cualquier url que ya estuviera configurada, incluidas las de
 *    transportadoras que el área ya no usa pero que pueden tener guías viejas
 *    en el sistema.
 *
 * Una url vacía no es un olvido: `PedidoCliente::urlDeRastreo()` la entiende
 * como «esta transportadora no publica rastreo» y no pinta el enlace, en vez
 * de pintar uno que lleva a una página muerta.
 */
return new class extends Migration
{
    private const TABLA = 'reglas_negocio';

    /** Las que el área nombró y no tienen página de rastreo pública. */
    private const SIN_RASTREO = ['ESTELAR', 'EXPRECAR', 'VELOENVIOS'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLA)) {
            return;
        }

        $this->crearSiFalta('logistica.transportadoras');
        $this->agregarLasQueFaltanAlRastreo();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLA)) {
            return;
        }

        /*
         * Sólo se va la regla nueva. Las llaves vacías que se agregaron a
         * `rastreo_urls` se quedan: si alguien les puso una url mientras
         * tanto, quitarlas le borraría el trabajo, y una llave de más con una
         * url vacía no le hace daño a nadie.
         */
        DB::table(self::TABLA)->where('clave', 'logistica.transportadoras')->delete();
    }

    private function crearSiFalta(string $clave): void
    {
        if (DB::table(self::TABLA)->where('clave', $clave)->exists()) {
            return;
        }

        $meta = Reglas::DEFAULTS[$clave] ?? null;

        if (! $meta) {
            Log::warning("No se pudo sembrar la regla {$clave}: no está en Reglas::DEFAULTS.");

            return;
        }

        DB::table(self::TABLA)->insert([
            'clave' => $clave,
            'grupo' => $meta['grupo'],
            'tipo' => $meta['tipo'],
            'valor' => is_array($meta['valor']) ? json_encode($meta['valor']) : (string) $meta['valor'],
            'etiqueta' => $meta['etiqueta'] ?? $clave,
            'descripcion' => $meta['descripcion'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function agregarLasQueFaltanAlRastreo(): void
    {
        $fila = DB::table(self::TABLA)->where('clave', 'logistica.rastreo_urls')->first();

        if (! $fila) {
            $this->crearSiFalta('logistica.rastreo_urls');

            return;
        }

        $urls = json_decode((string) $fila->valor, true);

        if (! is_array($urls)) {
            Log::warning('`logistica.rastreo_urls` no tiene un JSON válido: no se toca.', ['valor' => $fila->valor]);

            return;
        }

        $agregadas = [];

        foreach (self::SIN_RASTREO as $nombre) {
            if (array_key_exists($nombre, $urls)) {
                continue;
            }

            $urls[$nombre] = '';
            $agregadas[] = $nombre;
        }

        if (! $agregadas) {
            return;
        }

        DB::table(self::TABLA)->where('clave', 'logistica.rastreo_urls')->update([
            'valor' => json_encode($urls, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
