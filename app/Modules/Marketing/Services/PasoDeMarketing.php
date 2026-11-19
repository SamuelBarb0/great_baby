<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Marketing\Models\Lanzamiento;
use App\Modules\Marketing\Models\LanzamientoTarjeta;
use App\Modules\Marketing\Models\MarketingCreativo;

/**
 * Qué sigue en Marketing, dicho para quien nunca lo ha hecho: lo que le toca hoy,
 * la tarjeta que sigue en un lanzamiento y lo que le falta a cada producto.
 *
 * Es la misma respuesta de `PasoDeCompra`. Sólo orienta: las reglas siguen en
 * `TableroLanzamiento` y en la ficha.
 */
class PasoDeMarketing
{
    private const PASOS_LANZAMIENTO = ['Preparar', 'Propuesta', 'Producir', 'Publicar', 'Seguimiento'];

    /** A qué paso del lanzamiento pertenece cada tarjeta. */
    private const PASO_DE_TARJETA = [1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 3, 7 => 3, 8 => 4, 9 => 4, 10 => 5];

    public function __construct(
        private TableroLanzamiento $tablero,
        private EtapasProducto $etapas,
    ) {}

    /** La siguiente tarjeta del tablero y quién la tiene. */
    public function deLanzamiento(Lanzamiento $lanzamiento, ?User $u): array
    {
        $lanzamiento->loadMissing('tarjetas.bloqueoResponsable');
        $abiertas = $lanzamiento->tarjetas->where('lista', '!=', 'finalizado')->sortBy('numero')->values();
        $siguiente = $abiertas->first();

        if (! $siguiente) {
            return $this->paso(self::PASOS_LANZAMIENTO, 6, '¡Listo! Este lanzamiento terminó.', 'Las 10 tarjetas están finalizadas.', null, []);
        }

        $numero = self::PASO_DE_TARJETA[$siguiente->numero] ?? 1;
        $area = LanzamientoTarjeta::AREAS[$siguiente->area] ?? $siguiente->area;
        $nombre = "{$siguiente->numero}. {$siguiente->titulo}";
        $abrir = fn (LanzamientoTarjeta $t, string $etiqueta, bool $principal = true) => [
            'tipo' => 'accion', 'accion' => 'abrir-tarjeta', 'tarjeta' => $t->id, 'etiqueta' => $etiqueta, 'principal' => $principal,
        ];

        if ($siguiente->lista === 'bloqueado') {
            return $this->paso(self::PASOS_LANZAMIENTO, $numero, "Está bloqueada: {$nombre}.",
                "Motivo: {$siguiente->bloqueo_motivo}. Hay que: {$siguiente->bloqueo_accion}.",
                $siguiente->bloqueoResponsable?->name ?? $area,
                [$abrir($siguiente, 'Ver la tarjeta')]);
        }

        if ($siguiente->lista === 'pendiente_aprobacion') {
            return $this->tablero->puedeAprobar($u)
                ? $this->paso(self::PASOS_LANZAMIENTO, $numero, "Revise y apruebe: {$nombre}.", 'Sin esta aprobación no se empieza a producir.', 'Aracely', [$abrir($siguiente, 'Revisar')])
                : $this->paso(self::PASOS_LANZAMIENTO, $numero, "Esperando que Aracely apruebe: {$nombre}.", 'Le llega un aviso apenas la apruebe o pida cambios.', 'Aracely', [$abrir($siguiente, 'Ver la tarjeta', false)]);
        }

        if ($this->tablero->puedeTrabajar($u, $siguiente)) {
            return $this->paso(self::PASOS_LANZAMIENTO, $numero, "Le toca: {$nombre}.",
                (PlantillaLanzamiento::de($siguiente->clave)['objetivo'] ?? '').' Ábrala, marque el checklist y finalícela.',
                $area, [$abrir($siguiente, 'Abrir la tarjeta')]);
        }

        // Le toca a otra área: decir a quién se espera, y dejar adelantar lo propio.
        $mia = $abiertas->first(fn (LanzamientoTarjeta $t) => $this->tablero->puedeTrabajar($u, $t) && in_array($t->lista, ['por_hacer', 'en_proceso'], true));

        return $this->paso(self::PASOS_LANZAMIENTO, $numero, "Se está esperando: {$nombre}.",
            $mia ? "La trabaja {$area}. Mientras tanto puede adelantar la suya: {$mia->numero}. {$mia->titulo}." : "La trabaja {$area}.",
            $area,
            array_values(array_filter([
                $mia ? $abrir($mia, "Adelantar la tarjeta {$mia->numero}") : null,
                $abrir($siguiente, 'Ver la tarjeta', false),
            ])));
    }

    /**
     * Lo que le falta al producto.
     *
     * @param  array  $info  una fila de `EtapasProducto::para`
     */
    public function deProducto(Producto $p, array $info): array
    {
        $pasos = array_values(EtapasProducto::ETAPAS);
        $numero = array_search($info['etapa'], array_keys(EtapasProducto::ETAPAS), true);
        $numero = $numero === false ? 1 : $numero + 1;
        $resultado = fn (int $n, string $titulo, string $explica, array $acciones) => $this->paso($pasos, $n, $titulo, $explica, $n > count($pasos) ? null : 'Marketing', $acciones);

        if ($info['etapa'] === 'publicado') {
            return $resultado(6, '¡Publicado! Ventas ya lo ve.', 'Los vendedores lo pueden pedir y los clientes lo ven en el portal.', []);
        }

        $pedidas = array_sum($info['piezas']['pedidas']);
        $faltan = $info['piezas']['faltan'];

        if ($pedidas === 0) {
            return $resultado($numero, 'Decida qué piezas lleva este producto.',
                'Video, reel o imágenes. Con eso el sistema le lleva la cuenta de lo que falta.',
                [$this->ir('Definir piezas', 'piezas'), ...$this->muestra($info)]);
        }

        if ($faltan !== []) {
            return $resultado($numero, 'Suba lo que falta: '.$this->nombrarPiezas($faltan).'.',
                'Si un video pesa mucho, pegue el enlace de Drive.',
                [$this->ir($this->botonDeSubida($faltan), 'creativos'), ...$this->muestra($info)]);
        }

        if (! trim((string) $p->copy_comercial) || ! trim((string) $p->publico_objetivo)) {
            return $resultado($numero, 'Escriba los textos para Ventas.', 'Mínimo a quién va dirigido y el copy comercial.', [$this->ir('Escribir textos', 'textos')]);
        }

        if (! $p->activo) {
            return $resultado($numero, 'Está inactivo en el catálogo: no se puede publicar.', 'Pídale a quien maneja el catálogo que lo active.', []);
        }

        return $resultado($numero, 'Todo listo: publíquelo.', 'Desde ese momento los vendedores lo pueden pedir y los clientes lo ven en el portal.', [$this->ir('Publicar', 'publicar')]);
    }

    /**
     * «Lo que me toca»: tarjetas por contenedor y productos que esperan.
     *
     * @return list<array{titulo: string, detalle: string, boton: string, url: string}>
     */
    public function pendientes(?User $u): array
    {
        return [...$this->tarjetasPendientes($u), ...$this->productosPendientes($u)];
    }

    private function tarjetasPendientes(?User $u): array
    {
        $filas = [];
        $lanzamientos = Lanzamiento::whereNull('finalizado_en')
            ->with(['importacion:id,numero,contenedor', 'tarjetas'])
            ->latest('id')->limit(20)->get();

        foreach ($lanzamientos as $l) {
            $contenedor = $l->importacion?->contenedor ?: $l->importacion?->numero;
            $abiertas = $l->tarjetas->where('lista', '!=', 'finalizado')->sortBy('numero');

            $porAprobar = $this->tablero->puedeAprobar($u) ? $abiertas->firstWhere('lista', 'pendiente_aprobacion') : null;
            $mia = $porAprobar ?? $abiertas->first(fn (LanzamientoTarjeta $t) => $this->tablero->puedeTrabajar($u, $t) && $t->lista !== 'pendiente_aprobacion');
            if (! $mia) {
                continue;
            }

            $filas[] = [
                'titulo' => "{$contenedor}: ".($porAprobar ? 'por aprobar' : 'le toca')." «{$mia->numero}. {$mia->titulo}»",
                'detalle' => $mia->lista === 'bloqueado' ? 'Está bloqueada.' : ($mia->lista === 'en_proceso' ? 'En proceso.' : 'Sin empezar.'),
                'boton' => 'Abrir',
                'url' => "/app/marketing/lanzamientos/{$l->id}?tarjeta={$mia->id}",
            ];
        }

        return array_slice($filas, 0, 8);
    }

    private function productosPendientes(?User $u): array
    {
        if (! MarketingCreativo::puedeGestionar($u)) {
            return [];
        }

        $productos = Producto::query()->get(['id', 'listo_publicar', 'publicado', 'piezas_video', 'piezas_reel', 'piezas_imagenes']);
        $grupos = collect($this->etapas->para($productos))
            ->filter(fn ($i) => in_array($i['etapa'], ['bodega', 'contenido'], true))
            ->groupBy(fn ($i) => ($i['contenedor']['id'] ?? 0).'|'.$i['etapa']);

        return $grupos->map(function ($filas) {
            $i = $filas->first();
            $id = $i['contenedor']['id'] ?? null;
            $nombre = $i['contenedor']['contenedor'] ?? $i['contenedor']['numero'] ?? 'Sin contenedor';
            $n = $filas->count();
            $dias = (int) $filas->max('dias_desde_llegada');
            $sinContenido = $i['etapa'] === 'bodega';

            return [
                'titulo' => "{$nombre}: {$n} ".($n === 1 ? 'producto' : 'productos').($sinContenido ? ' sin contenido' : ' con contenido, sin publicar'),
                'detalle' => $dias > 0 ? "Llegó hace {$dias} ".($dias === 1 ? 'día' : 'días').'.' : 'Llegó hoy.',
                'boton' => 'Ver',
                'url' => '/app/marketing/tablero?etapa='.$i['etapa'].($id ? "&contenedor={$id}" : ''),
                'dias' => $dias,
            ];
        })->sortByDesc('dias')->take(8)->values()->all();
    }

    private function muestra(array $info): array
    {
        return $info['etapa'] === 'bodega'
            ? [['tipo' => 'enlace', 'etiqueta' => 'Pedir una muestra a bodega', 'href' => '/app/marketing?pedir=1', 'principal' => false]]
            : [];
    }

    private function nombrarPiezas(array $faltan): string
    {
        $nombres = [];
        foreach (['video' => ['video', 'videos'], 'reel' => ['reel', 'reels'], 'imagen' => ['imagen', 'imágenes']] as $tipo => [$uno, $varios]) {
            if (($faltan[$tipo] ?? 0) > 0) {
                $nombres[] = $faltan[$tipo].' '.($faltan[$tipo] === 1 ? $uno : $varios);
            }
        }

        return implode(', ', $nombres);
    }

    private function botonDeSubida(array $faltan): string
    {
        return match (true) {
            ($faltan['video'] ?? 0) > 0 => 'Subir video',
            ($faltan['reel'] ?? 0) > 0 => 'Subir reel',
            default => 'Subir imágenes',
        };
    }

    private function ir(string $etiqueta, string $parte): array
    {
        return ['tipo' => 'accion', 'accion' => $parte, 'etiqueta' => $etiqueta, 'principal' => true];
    }

    private function paso(array $pasos, int $numero, string $titulo, string $explica, ?string $quien, array $acciones): array
    {
        return ['numero' => $numero, 'pasos' => $pasos, 'titulo' => $titulo, 'explica' => $explica, 'quien' => $quien, 'acciones' => $acciones];
    }
}
