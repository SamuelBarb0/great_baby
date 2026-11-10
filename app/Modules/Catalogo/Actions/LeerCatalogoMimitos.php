<?php

namespace App\Modules\Catalogo\Actions;

use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Lee el catálogo comercial de Mimitos y lo deja listo para el ERP.
 *
 * El catálogo vive aparte, en una web con su propia API, y es el único lugar
 * donde están los 336 productos con su precio de venta y su foto. El ERP los
 * necesita para poder venderlos: hoy hay que teclearlos a mano uno por uno.
 *
 * Acá SÓLO se lee y se normaliza. Nada se guarda: eso lo decide quien llame.
 * Son dos trabajos distintos —entender el catálogo ajeno y escribir en el
 * nuestro— y conviene que sigan separados, porque el de afuera va a cambiar.
 *
 * Lo que se normaliza, y por qué:
 *
 *   · La POSICIÓN DE BODEGA sale del nombre. Viene metida ahí —«BABERO
 *     (R6-S2-N1)»— y de ahí saldría a las facturas y a los correos del cliente,
 *     a quien el rack no le dice nada. Se separa, no se tira: la bodega la usa
 *     todos los días para ir a buscar la mercancía.
 *
 *   · Lo que NO calza en el patrón se marca para revisar a mano en vez de
 *     adivinarle. Hay nombres con rango —«(R2, 3-S5-N3)»—, con zona en vez de
 *     rack —«(ZP)»— y con coletillas de negocio —«NETO SIN FLETE», «(LOCAL)»—.
 *     Una regla que les invente una posición le haría perder el viaje a bodega,
 *     que es peor que no tener el dato.
 */
class LeerCatalogoMimitos
{
    use AsAction;

    public const API = 'https://api.greatbaby.co/api/';

    /*
     * Rack · estante · nivel al final del nombre.
     *
     * Acepta rangos —«R1-S3, 4-N3», «R10.5-S1, 2, 3 - N3»— porque un producto
     * que ocupa dos estantes es cosa corriente en esa bodega: 37 de los 336.
     * Se puede aceptar justamente porque el texto se guarda TAL CUAL y no se
     * parte en tres columnas; si se partiera habría que elegir un estante y eso
     * sería inventarle una posición y hacerle perder el viaje a bodega.
     *
     * El `(LOCAL)` opcional del final es una marca de negocio, no de ubicación:
     * se deja pasar para encontrar la posición, y después se le quita al nombre
     * junto con ella.
     */
    private const POSICION = '/\s*\(\s*(R[\d.]+[\d.,\s]*-[\sS\d.,]*-[\sN\d.,]*)\)\s*(\(LOCAL\))?\s*$/iu';

    /** Cualquier otro paréntesis con pinta de ubicación: se marca, no se parte. */
    private const POSICION_RARA = '/\((?:\s*[RSNZ][^)]*)\)/iu';

    /**
     * @return array{productos:list<array<string,mixed>>, categorias:array<int,string>, total:int}
     */
    public function handle(): array
    {
        $categorias = $this->categorias();

        $crudos = [];
        $url = self::API.'productos/?visibilidad=true';

        // La API pagina de a 20. Se sigue `next` en vez de contar páginas: si
        // mañana cambian el tamaño, esto no se entera.
        while ($url) {
            /*
             * `throw: false` para que el reintento no tire su propia excepción.
             *
             * `retry()` levanta un `RequestException` al agotarse, y eso obliga
             * a quien llame a saber de HTTP. Acá el fallo se traduce a algo que
             * se pueda leer en la consola: lo que importa es que el catálogo no
             * contestó y que NO se importó nada a medias.
             */
            $r = Http::timeout(30)->retry(2, 500, throw: false)->acceptJson()->get($url);

            if ($r->failed()) {
                throw new \RuntimeException(
                    'El catálogo no respondió (HTTP '.$r->status().'). No se importó nada.'
                );
            }

            $crudos = array_merge($crudos, $r->json('results') ?? []);
            $url = $r->json('next');

            // Tope de cordura: si `next` se vuelve un bucle, no nos quedamos acá.
            if (count($crudos) > 5000) {
                break;
            }
        }

        return [
            'productos' => array_map(fn (array $p) => $this->normalizar($p, $categorias), $crudos),
            'categorias' => $categorias,
            'total' => count($crudos),
        ];
    }

    /** @return array<int,string> id => nombre */
    private function categorias(): array
    {
        $r = Http::timeout(20)->acceptJson()->get(self::API.'categorias_productos/');

        if ($r->failed()) {
            return [];
        }

        $filas = $r->json('results') ?? $r->json() ?? [];

        return collect($filas)
            ->filter(fn ($c) => isset($c['id']))
            ->mapWithKeys(fn ($c) => [(int) $c['id'] => (string) ($c['nombre'] ?? '')])
            ->all();
    }

    /** @param array<int,string> $categorias */
    private function normalizar(array $p, array $categorias): array
    {
        $nombreCrudo = trim((string) ($p['nombre'] ?? ''));

        $posicion = null;
        $nombre = $nombreCrudo;
        $revisar = null;

        if (preg_match(self::POSICION, $nombreCrudo, $m)) {
            $posicion = strtoupper(preg_replace('/\s+/', '', $m[1]));
            $nombre = trim(preg_replace(self::POSICION, '', $nombreCrudo));
        } elseif (preg_match(self::POSICION_RARA, $nombreCrudo)) {
            // Tiene algo que parece ubicación pero no calza: lo decide una
            // persona, no un patrón.
            $revisar = 'La ubicación no calza con «R-S-N»: hay que separarla a mano.';
        }

        if ($nombre === '') {
            $nombre = $nombreCrudo;
            $revisar = 'El nombre quedaría vacío al quitarle la ubicación.';
        }

        $referencia = trim((string) ($p['referencia'] ?? ''));

        if ($referencia === '') {
            $revisar = 'Sin referencia: no hay con qué casarlo contra SIIGO.';
        }

        return [
            'referencia' => $referencia,
            'nombre' => $nombre,
            'nombre_original' => $nombreCrudo,
            'posicion_bodega' => $posicion,
            'descripcion' => trim((string) ($p['descripcion'] ?? '')) ?: null,
            'categoria' => $categorias[(int) ($p['categoria'] ?? 0)] ?? null,
            // El precio de VENTA. No va al producto —ahí vive el costo— sino a
            // una lista de precios, que es donde el ERP guarda lo que se cobra.
            'precio_venta' => (float) ($p['price'] ?? 0),
            'imagenes' => array_values(array_filter((array) ($p['urls_imagenes'] ?? []))),
            'cantidad_minima' => (int) ($p['cantidadMinima'] ?? 1),
            'paquetes_de' => (int) ($p['paquetesDe'] ?? 1),
            'id_catalogo' => (int) ($p['id'] ?? 0),
            'revisar' => $revisar,
        ];
    }
}
