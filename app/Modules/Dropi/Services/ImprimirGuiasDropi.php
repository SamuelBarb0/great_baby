<?php

namespace App\Modules\Dropi\Services;

use App\Modules\Dropi\Jobs\ArmarParteLoteJob;
use App\Modules\Dropi\Jobs\DescargarStickersLoteJob;
use App\Modules\Dropi\Models\DropiLoteImpresion;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Support\RecoleccionDelCorte;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * Las guías de un corte impresas desde el ERP (reunión con la señora Lahida,
 * 24-nov-2026): hoy Aracely las imprime desde Dropi, las corta y las separa
 * por producto a mano.
 *
 * Es el REPORTE de un corte («Generar corte», 25-nov-2026): primero la lista de
 * alistamiento y después un sticker por pedido, en el orden en que se empaca
 * (el producto con más pedidos primero, y dentro el más viejo). Son 300 a 800
 * paquetes, así que se arma en segundo plano: los stickers se bajan de a 15
 * por trabajo (el worker de producción corre 55 s) y sale UN solo PDF («que
 * haga un montón y ya»; 800 stickers se arman en ~1 s y pesan ~50 MB). Al
 * terminar, los pedidos quedan «impresos», con su posición en la pila y su
 * reporte: eso es lo que llega a empaque.
 */
class ImprimirGuiasDropi
{
    /** Un solo PDF por reporte; sólo un reporte de más de esto se partiría. */
    public const POR_PARTE = 2000;

    public const POR_TRABAJO = 15;

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
     * Arma un lote con los escogidos que ya tienen guía. Null si ninguno la tiene.
     *
     * @param  list<int>  $pedidoIds
     */
    public function lanzar(array $pedidoIds, ?int $userId): ?DropiLoteImpresion
    {
        if (! DropiPedido::query()->whereIn('id', $pedidoIds)->get()->contains(fn (DropiPedido $p) => $p->tieneGuiaReal())) {
            return null;
        }

        $lote = DropiLoteImpresion::create([
            'user_id' => $userId,
            'pedidos' => array_values(array_map('intval', $pedidoIds)),
            'total' => count($pedidoIds),
            'estado' => DropiLoteImpresion::DESCARGANDO,
        ]);
        $this->iniciar($lote);

        return $lote->fresh();
    }

    /**
     * Arranca el reporte con lo que ya tiene guía, en el orden de la pila. Lo
     * que se quedó sin guía (Dropi la rechazó) va a la página «sin sticker» y
     * se puede volver a generar en otro corte. «Generar corte» lo llama cuando
     * terminan sus guías.
     */
    public function iniciar(DropiLoteImpresion $lote): void
    {
        $pedidos = DropiPedido::query()->whereIn('id', $lote->pedidos)->with('items')->get();
        $conGuia = self::ordenar($pedidos->filter(fn (DropiPedido $p) => $p->tieneGuiaReal()));
        $sinGuia = $pedidos->reject(fn (DropiPedido $p) => $p->tieneGuiaReal())
            ->map(fn (DropiPedido $p) => (string) ($p->dropi_orden_id ?: $p->guia))->values()->all();

        $lote->update([
            'pedidos' => $conGuia->pluck('id')->all(),
            'total' => $conGuia->count(),
            'descargados' => 0,
            'sin_sticker' => $sinGuia ?: null,
            'estado' => $conGuia->isEmpty() ? DropiLoteImpresion::ARMANDO : DropiLoteImpresion::DESCARGANDO,
        ]);

        if ($conGuia->isEmpty()) {
            ArmarParteLoteJob::dispatch($lote->id, 1);

            return;
        }
        foreach (array_chunk($lote->pedidos, self::POR_TRABAJO) as $ids) {
            DescargarStickersLoteJob::dispatch($lote->id, $ids);
        }
    }

    /**
     * Baja los stickers de unos pedidos del lote; el último en terminar manda a
     * armar el PDF.
     *
     * @param  list<int>  $ids
     */
    public function descargar(DropiLoteImpresion $lote, array $ids): void
    {
        $sinSticker = [];
        $pedidos = DropiPedido::query()->whereIn('id', $ids)->get();
        // Los 15 a la vez: uno tarda ~650 ms (medido en producción el 24-nov-2026),
        //   uno tras otro 800 stickers serían 9 minutos.
        $con = $pedidos->filter(fn (DropiPedido $p) => $p->stickerUrl() !== null);
        $respuestas = $con->isEmpty() ? [] : Http::pool(fn ($pool) => $con->map(
            fn (DropiPedido $p) => $pool->as((string) $p->id)->timeout(20)->get($p->stickerUrl())
        )->all());
        foreach ($pedidos as $p) {
            $r = $respuestas[(string) $p->id] ?? null;
            if ($p->stickerUrl() && ! ($r instanceof \Illuminate\Http\Client\Response && $r->successful())) {
                // Un tropiezo de la red: un reintento, solo.
                $r = rescue(fn () => Http::timeout(20)->get($p->stickerUrl()), null, false);
            }
            $bytes = $r instanceof \Illuminate\Http\Client\Response && $r->successful() ? $r->body() : null;
            // Se valida aquí que se deje leer: al armar, las partes van por posición
            //   y un sticker que se cayera ahí correría a todos los siguientes.
            if ($bytes && str_starts_with($bytes, '%PDF') && $this->legible($bytes)) {
                Storage::disk('local')->put("{$lote->carpeta()}/{$p->id}.pdf", $bytes);
            } else {
                $sinSticker[] = (string) ($p->dropi_orden_id ?: $p->guia);
            }
        }

        // Los trabajos del lote corren a la vez: el avance se suma bajo candado.
        $armar = Cache::lock("dropi:lote-impresion:{$lote->id}", 30)->block(20, function () use ($lote, $ids, $sinSticker) {
            $l = $lote->fresh();
            $l->update([
                'descargados' => $l->descargados + count($ids),
                'sin_sticker' => array_values(array_merge($l->sin_sticker ?? [], $sinSticker)),
            ]);
            if ($l->descargados >= $l->total && $l->estado === DropiLoteImpresion::DESCARGANDO) {
                $l->update(['estado' => DropiLoteImpresion::ARMANDO]);

                return true;
            }

            return false;
        });

        if ($armar) {
            ArmarParteLoteJob::dispatch($lote->id, 1);
        }
    }

    /**
     * Una parte del PDF: la primera abre con la lista de alistamiento de TODO
     * el lote; la última cierra con los que no tuvieron sticker. Al armar la
     * última, los pedidos quedan impresos y a la cola de empaque.
     */
    public function armarParte(DropiLoteImpresion $lote, int $parte): void
    {
        $disco = Storage::disk('local');
        $conSticker = array_values(array_filter($lote->pedidos, fn ($id) => $disco->exists("{$lote->carpeta()}/{$id}.pdf")));
        $partes = max(1, (int) ceil(count($conSticker) / self::POR_PARTE));

        $out = new Fpdi();
        $out->SetAutoPageBreak(false);

        if ($parte === 1) {
            $pedidos = DropiPedido::query()->whereIn('id', $conSticker)->with(['items.variante.producto', 'items.producto'])->get();
            $filas = array_map(fn ($f) => $f + ['marcada' => false, 'faltan' => 0], array_values(app(RecoleccionDelCorte::class)->totalesDe($pedidos)));
            $this->anexar($out, Pdf::loadView('dropi.pdf.recoleccion', [
                'corte' => null,
                'titulo' => $lote->titulo(),
                'filas' => $filas,
                'unidades' => array_sum(array_column($filas, 'total_unidades')),
                'generado' => now(),
            ])->setPaper('letter')->output());
        }

        foreach (array_slice($conSticker, ($parte - 1) * self::POR_PARTE, self::POR_PARTE) as $id) {
            $this->anexar($out, $disco->get("{$lote->carpeta()}/{$id}.pdf"));
        }

        $ultima = $parte >= $partes;
        if ($ultima && $lote->sin_sticker) {
            $out->AddPage('P', 'Letter');
            $out->SetFont('Helvetica', 'B', 14);
            $out->Cell(0, 10, $this->latin1('Pedidos sin sticker (' . count($lote->sin_sticker) . ')'), 0, 1);
            $out->SetFont('Helvetica', '', 10);
            $out->MultiCell(0, 6, $this->latin1('Dropi todavía no publicó su guía o no se pudo descargar. Vuelve a imprimirlos en un momento: ' . implode(', ', $lote->sin_sticker)));
        }

        $ruta = "{$lote->carpeta()}/lote-{$lote->id}-parte-{$parte}-de-{$partes}.pdf";
        $disco->put($ruta, $out->Output('S'));
        $lote->update(['partes' => [...($lote->partes ?? []), $ruta]]);

        if (! $ultima) {
            ArmarParteLoteJob::dispatch($lote->id, $parte + 1);

            return;
        }

        $this->marcarImpresos($conSticker, $lote);
        foreach ($conSticker as $id) {
            $disco->delete("{$lote->carpeta()}/{$id}.pdf");
        }
        $lote->update(['estado' => DropiLoteImpresion::LISTO]);
    }

    /**
     * Impresos y a la cola de empaque, en el orden de la pila: cada uno toma
     * la posición siguiente a la última impresa, así un lote nuevo va detrás
     * del anterior. Uno que se reimprime pasa a la pila nueva.
     *
     * @param  list<int>  $ids  en el orden del PDF
     */
    private function marcarImpresos(array $ids, DropiLoteImpresion $lote): void
    {
        Cache::lock('dropi:orden-empaque', 30)->block(10, function () use ($ids, $lote) {
            $base = (int) DropiPedido::withTrashed()->max('orden_empaque');
            DB::transaction(function () use ($ids, $lote, $base) {
                foreach ($ids as $i => $id) {
                    DropiPedido::whereKey($id)->update([
                        'guia_impresa_at' => now(), 'guia_impresa_por' => $lote->user_id,
                        'orden_empaque' => $base + $i + 1, 'lote_impresion_id' => $lote->id,
                    ]);
                }
            });
        });
    }

    private function legible(string $bytes): bool
    {
        try {
            return (new Fpdi())->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($bytes)) > 0;
        } catch (\Throwable) {
            return false;
        }
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
