<?php

namespace App\Modules\Siigo\Services;

use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Siigo\Clients\SiigoClient;
use App\Modules\Siigo\Models\SiigoConfig;
use Carbon\CarbonInterface;

/**
 * Conciliación real de facturas entre el ERP y SIIGO.
 *
 * Lo que ya existía (`SiigoComparadorDiarioCommand`,
 * `ContabilidadPendientesSiigoController`) sólo mira `whereNull('siigo_id')`:
 * compara el ERP contra sí mismo. Detecta "nunca se envió", pero no ve lo
 * que de verdad duele en una auditoría:
 *
 *   • una factura que está en los dos lados con IMPORTES DISTINTOS;
 *   • una factura emitida en SIIGO por fuera del ERP;
 *   • una factura que el ERP cree emitida pero que SIIGO ya no tiene (anulada
 *     desde el portal).
 *
 * Para eso hay que preguntarle a SIIGO, que es lo que hace esta clase.
 */
class ConciliadorFacturasSiigo
{
    /** Diferencia en pesos por debajo de la cual no vale la pena alarmar. */
    private const TOLERANCIA = 1.0;

    public function __construct(private readonly SiigoClient $cliente) {}

    /**
     * @return array{
     *   periodo: array{desde:string, hasta:string},
     *   resumen: array{erp:int, siigo:int, coinciden:int, sin_enviar:int, descuadradas:int, solo_siigo:int, ausentes_en_siigo:int},
     *   sin_enviar: list<array<string,mixed>>,
     *   descuadradas: list<array<string,mixed>>,
     *   solo_siigo: list<array<string,mixed>>,
     *   ausentes_en_siigo: list<array<string,mixed>>,
     * }
     */
    public function conciliar(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $facturasErp = FacturaVenta::query()
            ->whereBetween('fecha_emision', [$desde->toDateString(), $hasta->toDateString()])
            ->where('es_electronica', true)
            ->get(['id', 'numero', 'total', 'siigo_id', 'numero_siigo', 'fecha_emision', 'estado']);

        $enSiigo = $this->facturasDeSiigo($desde);

        $sinEnviar = [];
        $descuadradas = [];
        $ausentes = [];
        $coinciden = 0;
        $vistos = [];

        foreach ($facturasErp as $f) {
            if (empty($f->siigo_id)) {
                $sinEnviar[] = [
                    'numero' => $f->numero,
                    'fecha' => $f->fecha_emision?->toDateString(),
                    'total' => (float) $f->total,
                    'estado' => (string) ($f->estado->value ?? $f->estado),
                ];
                continue;
            }

            $vistos[$f->siigo_id] = true;
            $remota = $enSiigo[$f->siigo_id] ?? null;

            if (! $remota) {
                // El ERP la da por emitida pero SIIGO no la devuelve.
                $ausentes[] = [
                    'numero' => $f->numero,
                    'numero_siigo' => $f->numero_siigo,
                    'siigo_id' => $f->siigo_id,
                    'total' => (float) $f->total,
                ];
                continue;
            }

            $diferencia = round((float) $f->total - (float) $remota['total'], 2);
            if (abs($diferencia) > self::TOLERANCIA) {
                $descuadradas[] = [
                    'numero' => $f->numero,
                    'numero_siigo' => $remota['name'],
                    'total_erp' => (float) $f->total,
                    'total_siigo' => (float) $remota['total'],
                    'diferencia' => $diferencia,
                ];
            } else {
                $coinciden++;
            }
        }

        // Emitidas en SIIGO con NUESTRA resolución que el ERP no conoce.
        $soloSiigo = [];
        foreach ($enSiigo as $id => $r) {
            if (isset($vistos[$id])) {
                continue;
            }
            $soloSiigo[] = [
                'numero_siigo' => $r['name'],
                'fecha' => $r['date'],
                'total' => (float) $r['total'],
                'cliente' => $r['nit'],
            ];
        }

        return [
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'resumen' => [
                'erp' => $facturasErp->count(),
                'siigo' => count($enSiigo),
                'coinciden' => $coinciden,
                'sin_enviar' => count($sinEnviar),
                'descuadradas' => count($descuadradas),
                'solo_siigo' => count($soloSiigo),
                'ausentes_en_siigo' => count($ausentes),
            ],
            'sin_enviar' => $sinEnviar,
            'descuadradas' => $descuadradas,
            'solo_siigo' => $soloSiigo,
            'ausentes_en_siigo' => $ausentes,
        ];
    }

    /**
     * Facturas que SIIGO tiene desde `$desde`, acotadas a la resolución de la
     * empresa. El sandbox es compartido: sin ese filtro entrarían las facturas
     * de los demás usuarios y la conciliación no significaría nada.
     *
     * @return array<string, array{name:string, date:string, total:float, nit:string}>
     */
    private function facturasDeSiigo(CarbonInterface $desde): array
    {
        // Las de Dropi salen con su propia resolución en la misma cuenta (7-nov):
        // también son nuestras.
        $cfg = SiigoConfig::current();
        $resoluciones = array_values(array_filter([(int) $cfg->tipo_documento_id, (int) $cfg->tipo_documento_dropi_id]));
        $salida = [];
        $pagina = 1;

        do {
            $r = $this->cliente->request('GET', sprintf(
                '/v1/invoices?created_start=%s&page=%d&page_size=100',
                $desde->toDateString(), $pagina,
            ));
            if ($r->failed()) {
                break;
            }

            $json = $r->json();
            $filas = $json['results'] ?? [];

            foreach ($filas as $x) {
                $docId = (int) ($x['document']['id'] ?? 0);
                if ($resoluciones && ! in_array($docId, $resoluciones, true)) {
                    continue;
                }
                $salida[(string) $x['id']] = [
                    'name' => (string) ($x['name'] ?? ''),
                    'date' => (string) ($x['date'] ?? ''),
                    'total' => (float) ($x['total'] ?? 0),
                    'nit' => (string) ($x['customer']['identification'] ?? ''),
                ];
            }

            $total = (int) ($json['pagination']['total_results'] ?? 0);
            $vistas = $pagina * 100;
            $pagina++;
        } while ($vistas < $total && $pagina <= 20);   // tope de seguridad

        return $salida;
    }
}
