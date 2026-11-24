<?php

namespace App\Modules\Dropi\Actions;

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Models\DropiManifiesto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Trae de Dropi los manifiestos («Logistic Management → Salidas») y guarda su
 * PDF y su Excel en el ERP (reunión con la señora Lahida, 24-nov-2026: «cuando
 * se pierde un paquete, vamos a ese día y le tachamos a la transportadora el
 * paquete»). Sólo lee: las salidas se siguen creando con Ecom Scanner.
 *
 * Va de lo más nuevo hacia atrás hasta `$dias`; lo que ya está con sus dos
 * archivos no se vuelve a descargar.
 */
class SincronizarManifiestosDropi
{
    use AsAction;

    private const POR_PAGINA = 50;

    private const MAX_PAGINAS = 40;

    public function __construct(protected DropiWebClient $dropi) {}

    /** @return array{vistos:int, nuevos:int, archivos:int, errores:list<string>} */
    public function handle(int $dias = 2, bool $archivos = true): array
    {
        $desde = CarbonImmutable::now('America/Bogota')->subDays($dias)->startOfDay();
        $out = ['vistos' => 0, 'nuevos' => 0, 'archivos' => 0, 'errores' => []];

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $lote = $this->dropi->salidas($pagina * self::POR_PAGINA, self::POR_PAGINA)['objetos'];
            $masViejo = null;

            foreach ($lote as $s) {
                $creado = CarbonImmutable::parse((string) $s['created_at'], 'America/Bogota');
                $masViejo = $creado;
                if ($creado->lt($desde)) {
                    continue;
                }
                $out['vistos']++;

                $m = DropiManifiesto::firstOrNew(['dropi_id' => (int) $s['id']]);
                $out['nuevos'] += $m->exists ? 0 : 1;
                $m->fill([
                    'transportadora' => $s['distribution_company']['name'] ?? null,
                    'bodega_dropi_id' => $s['warehouse_id'] ?? null,
                    'usuario_dropi' => trim(($s['user']['name'] ?? '') . ' ' . ($s['user']['surname'] ?? '')) ?: null,
                    'dropi_creado_at' => $creado,
                    'guias' => self::guias($s),
                    'pedidos' => count($s['history_logistic_management'] ?? []),
                ])->save();

                if ($archivos && (! $m->pdf_path || ! $m->excel_path)) {
                    $this->descargar($m, $out);
                }
            }

            if (count($lote) < self::POR_PAGINA || ($masViejo && $masViejo->lt($desde))) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array{orden:string, guia:?string}> */
    public static function guias(array $salida): array
    {
        return collect($salida['history_logistic_management'] ?? [])
            ->map(fn ($h) => [
                'orden' => (string) ($h['order_id'] ?? ''),
                'guia' => filled($g = $h['history_orders']['shipping_guide'] ?? null) ? strtoupper(trim((string) $g)) : null,
            ])
            ->values()->all();
    }

    private function descargar(DropiManifiesto $m, array &$out): void
    {
        $mes = $m->dropi_creado_at?->format('Y-m') ?? 'sin-fecha';
        $errores = [];
        foreach (['pdf' => 'pdf_path', 'excel' => 'excel_path'] as $tipo => $campo) {
            if ($m->{$campo}) {
                continue;
            }
            try {
                $ruta = "dropi/manifiestos/{$mes}/salida-{$m->dropi_id}." . ($tipo === 'pdf' ? 'pdf' : 'xlsx');
                Storage::disk('local')->put($ruta, $this->dropi->manifiestoArchivo((int) $m->dropi_id, $tipo));
                $m->{$campo} = $ruta;
                $out['archivos']++;
                Sleep::for(500)->milliseconds(); // Dropi limita las llamadas seguidas.
            } catch (\Throwable $e) {
                $errores[] = $e->getMessage();
                Log::warning('dropi.manifiestos.archivo', ['salida' => $m->dropi_id, 'tipo' => $tipo, 'msg' => $e->getMessage()]);
            }
        }
        $m->error_archivos = $errores ? mb_substr(implode(' | ', $errores), 0, 1000) : null;
        $m->save();
        array_push($out['errores'], ...array_map(fn ($e) => "Salida {$m->dropi_id}: {$e}", $errores));
    }
}
