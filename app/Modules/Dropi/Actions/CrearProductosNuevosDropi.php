<?php

namespace App\Modules\Dropi\Actions;

use App\Auth\Permisos;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Services\CatalogoDropi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Crea en el ERP, ya vinculados, los productos NUEVOS de Dropi: los que llegan
 * en un pedido activo y no existen en el ERP (estado «nuevo» de la vista
 * previa de CatalogoDropi con pedidos esperando).
 *
 * Por qué sólo esos (23-nov-2026): en Dropi había 125 llaves sin vincular, pero
 * sólo 2 frenaban pedidos —productos que bodega creó directo en Dropi—. Los
 * demás se quedan quietos hasta que alguien diga qué son; crearlos todos
 * llenaría el catálogo de fichas que quizá ya existen con otro nombre.
 *
 * Lo que NO decide sola: lo que la vista previa deja en «revisar» (misma
 * referencia con desglose, SKU repetido en Dropi, mismo nombre con otra
 * referencia) ni las llaves que ya no están en el catálogo de Dropi.
 *
 * Cómo queda el producto: referencia = SKU de Dropi si es válido, si no
 * `DROPI-<id>`; sin variantes (todas las variaciones de Dropi descuentan del
 * mismo total); stock 0 y sin costo. El stock y el costo los pone bodega: el
 * kardex del ERP es la verdad, y copiar el de Dropi lo entraría a $0. Por eso
 * cada producto creado deja un aviso en la campana.
 *
 * ── 23-nov-2026 · la clienta decide (regla `dropi.crear_productos_nuevos`) ──
 *
 * «Cuando se crea el producto, sea en el ERP o en Dropi, que queden
 * conectados». Con la regla prendida, el automático crea TODOS los «nuevo»
 * (tengan o no pedidos); apagada, sólo los que frenan pedidos, como antes. Y
 * en «Vincular con el ERP» se puede hacer a mano: uno por uno (`crearUno`) o
 * todos los «nuevo» de una vez (`handle(todos: true)`). Lo que se parece a algo
 * del ERP («revisar») nunca se crea solo.
 */
class CrearProductosNuevosDropi
{
    use AsAction;

    /** Releer Dropi (≈1 min) a lo sumo una vez por hora si falta una llave en la foto. */
    private const RELEER_CADA_MIN = 60;

    private const CACHE_RELECTURA = 'dropi:crear-nuevos:relectura';

    public function __construct(protected CatalogoDropi $catalogo) {}

    /**
     * @return array{creados: list<array{producto_id: ?int, referencia: string, nombre: string, llaves: list<string>, pedidos_liberados: int}>, releido: bool, errores: list<string>}
     */
    public function handle(bool $simular = false, ?bool $todos = null): array
    {
        $todos ??= self::todos();
        $out = ['creados' => [], 'releido' => false, 'errores' => []];

        // Un producto recién creado en Dropi no está en la foto hasta que se
        // relee: su llave aparece «fuera del catálogo». Se relee (con tope).
        // Con «todos» también sin pedidos: el producto que bodega crea hoy en
        // Dropi tiene que llegar al ERP aunque nadie lo haya pedido.
        if (! $simular && ($todos || $this->faltanEnFoto()) && Cache::add(self::CACHE_RELECTURA, true, now()->addMinutes(self::RELEER_CADA_MIN))) {
            try {
                $this->catalogo->actualizar();
                $out['releido'] = true;
            } catch (\Throwable $e) {
                $out['errores'][] = 'No se pudo releer Dropi: ' . $e->getMessage();
            }
        }

        foreach ($this->porCrear($todos) as $grupo) {
            if ($simular) {
                $out['creados'][] = ['producto_id' => null] + $grupo + ['pedidos_liberados' => 0];

                continue;
            }

            try {
                $out['creados'][] = $this->crear($grupo);
            } catch (\Throwable $e) {
                $out['errores'][] = "{$grupo['referencia']} ({$grupo['nombre']}): {$e->getMessage()}";
                Log::warning('dropi.crear_nuevo.fallo', ['referencia' => $grupo['referencia'], 'msg' => $e->getMessage()]);
            }
        }

        if (! $simular && $out['creados']) {
            Cache::forget(CatalogoDropi::CACHE_BADGE);
        }

        return $out;
    }

    /** ¿El automático crea todos los «nuevo», o sólo los que frenan pedidos? */
    public static function todos(): bool
    {
        return (bool) setting('dropi.crear_productos_nuevos');
    }

    /**
     * «Crear en el ERP» de una fila de Vincular: el producto de Dropi entero
     * (todas sus variaciones), tenga o no pedidos. Sólo si todo él es «nuevo».
     *
     * @return array{producto_id: ?int, referencia: string, nombre: string, llaves: list<string>, pedidos_liberados: int}
     */
    public function crearUno(int $dropiId): array
    {
        $grupo = collect($this->porCrear(true))->firstWhere('dropi_id', $dropiId);
        if (! $grupo) {
            throw new \DomainException('Este producto ya no está «nuevo»: ya se vinculó o se parece a uno del ERP. Vincúlalo a mano.');
        }

        $r = $this->crear($grupo);
        Cache::forget(CatalogoDropi::CACHE_BADGE);

        return $r;
    }

    /** ¿Hay pedidos esperando una llave que la foto no tiene? */
    private function faltanEnFoto(): bool
    {
        return collect($this->catalogo->previsualizar()['filas'])
            ->contains(fn ($f) => $f['estado'] === 'sin_catalogo' && $f['pedidos_esperando'] > 0);
    }

    /**
     * Las llaves «nuevo» con pedidos esperando, agrupadas por producto de Dropi:
     * un producto con variaciones da UNA ficha con todas sus llaves.
     *
     * @return list<array{dropi_id: int, referencia: string, nombre: string, descripcion: ?string, llaves: list<string>}>
     */
    public function porCrear(bool $todos = false): array
    {
        $foto = collect($this->catalogo->foto()['productos'] ?? [])->keyBy('id');

        return collect($this->catalogo->previsualizar()['filas'])
            ->filter(fn ($f) => $f['dropi_id'])
            ->groupBy('dropi_id')
            // Todas sus llaves «nuevo» (la variación que se venda mañana ya
            // encuentra su producto) y al menos una con pedidos esperando. Si
            // alguna variación ya está vinculada o en «revisar», el producto ya
            // tiene algo en el ERP: no se crea otra ficha, lo decide una persona.
            ->filter(fn ($filas) => $filas->every(fn ($f) => $f['estado'] === 'nuevo')
                && ($todos || $filas->contains(fn ($f) => $f['pedidos_esperando'] > 0)))
            ->map(function ($filas, $id) use ($foto) {
                $p = $foto->get((int) $id) ?? [];

                return [
                    'dropi_id' => (int) $id,
                    'referencia' => ($p['sku_valido'] ?? false) ? $p['sku'] : "DROPI-{$id}",
                    'nombre' => $filas->first()['nombre'],
                    'descripcion' => $p['descripcion'] ?? null,
                    'llaves' => $filas->pluck('llave')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array{dropi_id: int, referencia: string, nombre: string, descripcion: ?string, llaves: list<string>} $g */
    private function crear(array $g): array
    {
        // Referencia única contando la papelera (como la ficha), y el id de Dropi
        // tampoco puede estar ya en otra ficha: en ambos casos lo decide una persona.
        if (Producto::withTrashed()->where('referencia', $g['referencia'])->exists()) {
            throw new \DomainException("ya hay un producto con la referencia {$g['referencia']} (puede estar en la papelera): vincúlalo a mano");
        }
        if (Producto::withTrashed()->where('dropi_sku', (string) $g['dropi_id'])->exists()) {
            throw new \DomainException("ya hay un producto con el id de Dropi {$g['dropi_id']}: vincúlalo a mano");
        }

        [$producto, $liberados] = DB::transaction(function () use ($g) {
            $producto = Producto::sinKardexAutomatico(fn () => Producto::create([
                'referencia' => $g['referencia'],
                'nombre' => mb_substr($g['nombre'], 0, 200),
                'descripcion' => $g['descripcion'],
                'desglose_stock' => false,
                'activo' => true,
            ]));
            $producto->forceFill(['dropi_sku' => (string) $g['dropi_id']])->save();

            $liberados = 0;
            foreach ($g['llaves'] as $llave) {
                $liberados += VincularProductoDropi::make()->aProducto($llave, $producto->id, null, ['producto_nombre' => mb_substr($g['nombre'], 0, 150)])['pedidos_liberados'];
            }

            return [$producto, $liberados];
        });

        $this->avisar($producto, $g);

        return [
            'producto_id' => $producto->id,
            'referencia' => $producto->referencia,
            'nombre' => $producto->nombre,
            'llaves' => $g['llaves'],
            'pedidos_liberados' => $liberados,
        ];
    }

    /** A quien vincula productos de Dropi: falta el stock y el costo. */
    private function avisar(Producto $p, array $g): void
    {
        $destinatarios = User::query()->get()
            ->filter(fn (User $u) => Permisos::puede($u, 'dropi_vincular_productos'))
            ->pluck('id');

        foreach ($destinatarios as $userId) {
            NotificacionErp::crear([
                'user_id' => $userId,
                'tipo' => 'dropi_producto_creado',
                'titulo' => "Se creó «{$p->nombre}» desde Dropi",
                'mensaje' => "Estaba en Dropi y no en el ERP: quedó con la referencia {$p->referencia} y vinculado. El inventario y el costo entran con la compra.",
                'url' => "/app/catalogo/productos/{$p->id}",
                'color' => 'warning',
                'icono' => 'heroicon-o-plus-circle',
            ]);
        }
    }
}
