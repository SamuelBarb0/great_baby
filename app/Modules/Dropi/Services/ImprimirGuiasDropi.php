<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Support\RecoleccionDelCorte;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

/**
 * Las guías de un corte en UN solo PDF (reunión con la señora Lahida,
 * 24-nov-2026): hoy Aracely las imprime desde Dropi, las corta y las separa
 * por producto a mano.
 *
 * Primero la lista de alistamiento de esos pedidos y después un sticker por
 * pedido, en el orden en que se empaca: el producto con más pedidos primero
 * (cien «celular táctil» seguidos), y dentro de cada producto el más viejo
 * primero. Al imprimir, los pedidos quedan marcados «impresos»: el siguiente
 * corte no los repite.
 */
class ImprimirGuiasDropi
{
    private const TTL_MIN = 30;

    /** Guarda la selección y devuelve el token de la URL de impresión. */
    public static function preparar(array $pedidoIds): string
    {
        $token = Str::random(32);
        Cache::put("dropi:imprimir-guias:{$token}", array_values(array_map('intval', $pedidoIds)), now()->addMinutes(self::TTL_MIN));

        return $token;
    }

    /** @return list<int>|null */
    public static function seleccion(string $token): ?array
    {
        return Cache::get("dropi:imprimir-guias:{$token}");
    }

    /**
     * El orden de empaque: por el producto con más pedidos y, dentro, el más viejo.
     *
     * @param  Collection<int, DropiPedido>  $pedidos  con items
     * @return Collection<int, DropiPedido>
     */
    public static function ordenar(Collection $pedidos): Collection
    {
        $principal = fn (DropiPedido $p) => (string) ($p->items->sortBy('id')->first()?->sku_dropi ?? '');
        $cuantos = $pedidos->countBy($principal);
        // Más viejo = cuándo se hizo en Dropi, no cuándo lo trajo el sync.
        $cuando = fn (DropiPedido $p) => ($p->dropi_creado_at ?? $p->created_at)?->getTimestamp() ?? 0;

        return $pedidos->sort(fn ($a, $b) => [-$cuantos[$principal($a)], $principal($a), $cuando($a), $a->id]
            <=> [-$cuantos[$principal($b)], $principal($b), $cuando($b), $b->id])->values();
    }

    /**
     * @param  list<int>  $pedidoIds
     * @return array{pdf: string, impresos: int, sin_sticker: list<string>}
     */
    public function generar(array $pedidoIds, ?int $userId): array
    {
        $pedidos = self::ordenar(DropiPedido::query()->whereIn('id', $pedidoIds)
            ->with(['items.variante.producto', 'items.producto'])->get());

        $out = new Fpdi();
        $out->SetAutoPageBreak(false);

        // 1. La lista de alistamiento de estos pedidos.
        $filas = array_map(fn ($f) => $f + ['marcada' => false, 'faltan' => 0], array_values(app(RecoleccionDelCorte::class)->totalesDe($pedidos)));
        $lista = Pdf::loadView('dropi.pdf.recoleccion', [
            'corte' => null,
            'titulo' => count($pedidos) . ' guías · ' . now()->timezone('America/Bogota')->format('d/m/Y g:i a'),
            'filas' => $filas,
            'unidades' => array_sum(array_column($filas, 'total_unidades')),
            'generado' => now(),
        ])->setPaper('letter')->output();
        $this->anexar($out, $lista);

        // 2. Un sticker por pedido, en el orden de empaque.
        $sinSticker = [];
        $impresos = [];
        foreach ($pedidos as $p) {
            $url = $p->stickerUrl();
            $bytes = $url ? rescue(fn () => Http::timeout(20)->retry(2, 500)->get($url)->throw()->body(), null, false) : null;
            if (! $bytes || ! str_starts_with($bytes, '%PDF') || ! $this->anexar($out, $bytes)) {
                $sinSticker[] = (string) ($p->dropi_orden_id ?: $p->guia);

                continue;
            }
            $impresos[] = $p->id;
        }

        // 3. Lo que no se pudo, al final y a la vista.
        if ($sinSticker) {
            $out->AddPage('P', 'Letter');
            $out->SetFont('Helvetica', 'B', 14);
            $out->Cell(0, 10, $this->latin1('Pedidos sin sticker (' . count($sinSticker) . ')'), 0, 1);
            $out->SetFont('Helvetica', '', 10);
            $out->MultiCell(0, 6, $this->latin1('Dropi todavía no publicó su guía o no se pudo descargar. Vuelve a imprimirlos en un momento: ' . implode(', ', $sinSticker)));
        }

        if ($impresos) {
            DropiPedido::whereIn('id', $impresos)->update(['guia_impresa_at' => now(), 'guia_impresa_por' => $userId]);
        }

        return ['pdf' => $out->Output('S'), 'impresos' => count($impresos), 'sin_sticker' => $sinSticker];
    }

    /** Pega todas las páginas de un PDF; false si no se pudo leer. */
    private function anexar(Fpdi $out, string $bytes): bool
    {
        try {
            $n = $out->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($bytes));
            for ($i = 1; $i <= $n; $i++) {
                $t = $out->importPage($i);
                $s = $out->getTemplateSize($t);
                $out->AddPage($s['orientation'], [$s['width'], $s['height']]);
                $out->useTemplate($t);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('dropi.imprimir_guias.pdf_ilegible', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    private function latin1(string $s): string
    {
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }
}
