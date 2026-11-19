<?php

namespace App\Modules\Logistica\Support;

use App\Models\EmpresaConfig;
use Illuminate\Database\Eloquent\Builder;

/**
 * En qué orden se alista.
 *
 * El área entregó la jerarquía por escrito (don Jorge, 17-nov-2026) y son cinco
 * niveles, en este orden:
 *
 *   1. Pedidos montados por la señora Tania.
 *   2. Clientes Dropi.
 *   3. Contado con destino a Bucaramanga y su área metropolitana,
 *      de MAYOR A MENOR VALOR y después por fecha.
 *   4. Contado nacional, igual: de mayor a menor valor y después por fecha.
 *   5. Crédito: primero los recomendados por los asesores, después de mayor a
 *      menor valor, y por último por fecha.
 *
 * Antes la cola ordenaba por contado/crédito, luego Bucaramanga, luego la
 * fecha de facturación — y eso lo habíamos puesto nosotros, no el área. El
 * valor no entraba en la cuenta, así que un pedido de ochenta mil pesos le
 * ganaba a uno de tres millones por haberse facturado diez minutos antes.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * DOS NIVELES DEPENDEN DE UN DATO QUE EL SISTEMA TODAVÍA NO TIENE
 *
 * · «Pedidos de la señora Tania»: nada marca un pedido como suyo. Hay un
 *   contacto con ese nombre y hay una oficina «13 · SRA. TANIA» en el catálogo
 *   de la bodega, pero son cosas distintas y ninguna llega hasta el pedido.
 *   Se resuelve por regla: se configura QUÉ CONTACTO es ella y listo. Mientras
 *   esté en cero, el nivel simplemente no aparta a nadie.
 *
 * · «Recomendados por los asesores»: no existe la marca. Hace falta decidir
 *   con el área qué es un pedido recomendado —¿lo marca el asesor al montarlo?
 *   ¿es por cliente?— y eso es una columna y una casilla, no una regla.
 *   Mientras tanto, el crédito ordena por valor y fecha, que es el resto de lo
 *   que pidieron.
 *
 * Se dejan los dos como escalón explícito y no como un `if` escondido: el día
 * que llegue el dato, se enciende acá y la cola entera lo respeta.
 * ───────────────────────────────────────────────────────────────────────────
 */
class PrioridadDeLaCola
{
    /** Las ciudades que el área cuenta como «Bucaramanga y su área metropolitana». */
    public const AREA_METROPOLITANA = [
        'bucaramanga', 'giron', 'girón', 'floridablanca', 'piedecuesta',
    ];

    /**
     * Le pone a la consulta el orden que pidió el área.
     *
     * El orden se calcula en SQL y no en PHP a propósito: la cola se recarga
     * sola cada diez segundos y si el orden se armara después, las tarjetas
     * bailarían en cada recarga.
     */
    public function aplicar(Builder $consulta): Builder
    {
        return $consulta
            ->selectRaw($this->expresiones())
            ->orderBy('prio_nivel')          // los cinco escalones
            ->orderBy('prio_recomendado')    // dentro del crédito, el recomendado primero
            ->orderByDesc('total')           // de mayor a menor valor
            ->orderBy('facturado_at');       // y al final, el más viejo
    }

    /**
     * Las columnas calculadas. Se exponen aparte porque la pantalla también
     * las lee para pintar los chips de cada tarjeta.
     */
    public function expresiones(): string
    {
        $tania = $this->contactoDeLaSenoraTania();
        $dropi = $this->empresaDeDropi();
        $area = $this->listaSqlDelAreaMetropolitana();

        /*
         * Un solo CASE con los cinco escalones, en el orden del área. El
         * primero que coincide manda, igual que una fila de espera: nadie se
         * cuela a un escalón que ya pasó.
         */
        $nivel = "CASE\n";

        // 1 · la señora Tania
        $nivel .= $tania
            ? "    WHEN pedidos_cliente.contacto_id = {$tania} THEN 1\n"
            : "    /* nivel 1 (Sra. Tania) sin configurar: no aparta a nadie */\n";

        // 2 · Dropi
        $nivel .= $dropi
            ? "    WHEN pedidos_cliente.empresa_id = {$dropi} THEN 2\n"
            : "    /* nivel 2 (Dropi) sin empresa de e-commerce configurada */\n";

        // 3 y 4 · contado, local primero
        $nivel .= "    WHEN EXISTS (SELECT 1 FROM facturas_venta f
                      WHERE f.id = pedidos_cliente.factura_id AND f.tipo = 'contado')
             THEN CASE WHEN EXISTS (SELECT 1 FROM contactos c
                                     WHERE c.id = pedidos_cliente.contacto_id
                                       AND LOWER(COALESCE(c.ciudad,'')) IN ({$area}))
                       THEN 3 ELSE 4 END\n";

        // 5 · todo lo demás es crédito
        $nivel .= "    ELSE 5\n";
        $nivel .= 'END AS prio_nivel';

        /*
         * El recomendado por el asesor. Hoy devuelve siempre 1 —«no
         * recomendado»— porque la marca no existe todavía; cuando exista, acá
         * se cambia por su columna y el resto del orden ya la respeta.
         */
        $recomendado = '1 AS prio_recomendado';

        // Se conservan las dos de antes: la pantalla pinta con ellas los chips
        // de «contado» y «local», y hay pruebas que las miran.
        $tipo = "CASE WHEN EXISTS (SELECT 1 FROM facturas_venta f
                        WHERE f.id = pedidos_cliente.factura_id AND f.tipo = 'contado')
                 THEN 0 ELSE 1 END AS prio_tipo";

        $zona = "CASE WHEN EXISTS (SELECT 1 FROM contactos c
                        WHERE c.id = pedidos_cliente.contacto_id
                          AND LOWER(COALESCE(c.ciudad,'')) IN ({$area}))
                 THEN 0 ELSE 1 END AS prio_zona";

        return "pedidos_cliente.*,\n{$nivel},\n{$recomendado},\n{$tipo},\n{$zona}";
    }

    /** Cómo se llama cada escalón, para que la pantalla lo pueda decir. */
    public static function nombreDelNivel(int $nivel): string
    {
        return match ($nivel) {
            1 => 'Sra. Tania',
            2 => 'Dropi',
            3 => 'Contado · local',
            4 => 'Contado · nacional',
            default => 'Crédito',
        };
    }

    private function contactoDeLaSenoraTania(): ?int
    {
        $id = (int) setting('logistica.contacto_sra_tania', 0);

        return $id > 0 ? $id : null;
    }

    private function empresaDeDropi(): ?int
    {
        try {
            $id = EmpresaConfig::where('codigo', 'ecommerce')->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** La lista de ciudades, ya escapada, lista para un IN de SQL. */
    private function listaSqlDelAreaMetropolitana(): string
    {
        $crudas = (array) setting('logistica.area_metropolitana', self::AREA_METROPOLITANA);

        $limpias = collect($crudas)
            ->map(fn ($c) => mb_strtolower(trim((string) $c)))
            ->filter()
            ->unique()
            ->values();

        if ($limpias->isEmpty()) {
            $limpias = collect(self::AREA_METROPOLITANA);
        }

        // Se escapa a mano porque esto va dentro de un selectRaw. Sólo se
        // dejan pasar letras, espacios y tildes: una ciudad no lleva comillas.
        return $limpias
            ->map(fn ($c) => "'".preg_replace('/[^\p{L}\s\.\-]/u', '', $c)."'")
            ->implode(', ');
    }
}
