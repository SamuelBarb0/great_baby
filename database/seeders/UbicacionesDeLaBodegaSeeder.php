<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Logistica\Support\ZonaDeLaBodega as Zona;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Las 435 posiciones de la bodega, tal como las entregó el área.
 *
 * Don Jorge mandó el levantamiento por escrito el 17-nov-2026: 17 racks y 3
 * zonas adicionales, en UNA SOLA bodega física, con esta configuración:
 *
 *   Racks 1 al 6      5 secciones · 3 niveles por sección · derecha e izquierda
 *   Rack 6.5          3 secciones · 8 niveles · sin lateralidad
 *   Racks 7 al 10     5 secciones · 3 niveles · derecha e izquierda
 *   Rack 10.5         6 secciones · 5 niveles · sin lateralidad
 *   Rack 11           5 secciones · 3 niveles · derecha e izquierda
 *   Rack 12 (s 1-3)   3 secciones · 8 niveles · sin lateralidad
 *   Rack 12 (s 4-6)   3 secciones · 3 niveles · derecha e izquierda
 *   Racks 13, 14, 15  1 sección · 1 nivel · derecha e izquierda
 *   Zonas             Parqueadero, Arsutex y Local · sin sección ni nivel
 *
 * La nomenclatura la pidió él: RACK · SECCIÓN · NIVEL · LATERALIDAD, que acá
 * es el código de la ubicación — `R5-S2-N3-D`, `R5-S2-N3-I`.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * LA LATERALIDAD NO TIENE COLUMNA PROPIA
 *
 * La tabla trae `pasillo`, `estante` y `nivel`, que es como estaba pensada
 * antes de este levantamiento. Se mapea así:
 *
 *     pasillo = el rack          («5», «6.5»)
 *     estante = la sección       («2»)
 *     nivel   = nivel y lado     («3-D», «3-I», o «3» donde no aplica)
 *
 * Y el código lleva la nomenclatura completa, que es con la que habla la
 * bodega. Agregarle una columna `lateralidad` a la tabla sería más limpio,
 * pero toca una tabla que usa medio sistema; si el picking por zonas termina
 * necesitándola se hace entonces, con una migración y a conciencia.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * LAS TRES ZONAS OPERATIVAS
 *
 * El área las reparte así, con un responsable cada una, y dijo explícitamente
 * que NO se contempla más de un responsable por zona —así que el 1 a 1 que ya
 * tenía el sistema alcanza y no hay que tocarlo—:
 *
 *     Zona 1   Racks 1, 2, 3, 4, 5 y 6
 *     Zona 2   Racks 6.5, 7, 8, 9, 10 y 10.5
 *     Zona 3   Racks 11, 12, 13, 14, 15 y las zonas Parqueadero, Arsutex y Local
 *
 * También dijo que los responsables se identifican como «Responsable 1, 2 y 3»,
 * sin nombres ni correos, y que NO tienen autonomía para decidir: son una
 * etiqueta de zona, no personas que operan.
 *
 * Por eso las tres cuentas nacen SIN CONTRASEÑA UTILIZABLE: identifican la
 * zona y nadie entra con ellas. Vale decirlo en voz alta: mientras los
 * responsables sean etiquetas, la bitácora va a decir «Responsable 2 movió
 * esto» y no quién fue. Para 435 posiciones eso es renunciar a saber quién
 * tocó qué, y conviene que el área lo decida a conciencia y no por omisión.
 *
 * Es idempotente: se puede correr todas las veces que haga falta. No pisa el
 * nombre ni el responsable de una posición que alguien haya editado a mano.
 */
class UbicacionesDeLaBodegaSeeder extends Seeder
{
    /**
     * El código de la bodega física donde viven las 435 posiciones.
     *
     * Es el valor por defecto: lo que manda es la regla
     * `logistica.bodega_principal`, editable en /app/reglas. Sin eso, una
     * bodega que se llame de otro modo —que es lo que pasa cuando el sistema
     * se montó con datos de prueba y después llegaron los reales— obligaba a
     * renombrarla a mano antes de poder sembrar.
     */
    public const BODEGA = 'PPAL';

    /** El código efectivo: lo que diga la regla, o el de arriba. */
    public static function codigoDeLaBodega(): string
    {
        $codigo = trim((string) setting('logistica.bodega_principal'));

        return $codigo !== '' ? $codigo : self::BODEGA;
    }

    /**
     * La bodega, tal como la describió el área.
     *
     * [racks, secciones, niveles, lateralidad]
     */
    private const DISENO = [
        ['racks' => ['1', '2', '3', '4', '5', '6'],   'secciones' => [1, 2, 3, 4, 5], 'niveles' => 3, 'lados' => true],
        ['racks' => ['6.5'],                          'secciones' => [1, 2, 3],       'niveles' => 8, 'lados' => false],
        ['racks' => ['7', '8', '9', '10'],            'secciones' => [1, 2, 3, 4, 5], 'niveles' => 3, 'lados' => true],
        ['racks' => ['10.5'],                         'secciones' => [1, 2, 3, 4, 5, 6], 'niveles' => 5, 'lados' => false],
        ['racks' => ['11'],                           'secciones' => [1, 2, 3, 4, 5], 'niveles' => 3, 'lados' => true],
        ['racks' => ['12'],                           'secciones' => [1, 2, 3],       'niveles' => 8, 'lados' => false],
        ['racks' => ['12'],                           'secciones' => [4, 5, 6],       'niveles' => 3, 'lados' => true],
        ['racks' => ['13', '14', '15'],               'secciones' => [1],             'niveles' => 1, 'lados' => true],
    ];

    /**
     * Las que no se dividen en sección ni nivel, con el nombre que les puso
     * el área.
     *
     * Las tres primeras vienen del levantamiento escrito del 17-nov. La ZONA
     * DE ALISTAMIENTO sale del plano del 19-nov: es el punto de consolidación
     * —está en el centro de la bodega, debajo del rack 7— donde converge lo
     * que recogen los tres operarios antes de repartirlo por pedido.
     *
     * Existe como ubicación porque la mercancía está físicamente ahí un rato:
     * salió de su estante y todavía no se despacha. Sin ella, ese tramo del
     * proceso no se puede ver en el sistema.
     *
     * Los nombres se editan después desde /app/inventario/ubicaciones; esto es
     * sólo el punto de partida.
     */
    private const ZONAS_SUELTAS = [
        'PARQUEADERO' => 'Zona Parqueadero',
        'ARSUTEX' => 'Zona Arsutex',
        'LOCAL' => 'Zona Local',
        'ALISTAMIENTO' => 'ZONA DE ALISTAMIENTO',
    ];

    /**
     * Qué rack pertenece a qué zona operativa.
     *
     * No es una constante de este archivo: lo manda la regla
     * `logistica.zonas_bodega`, editable en /app/reglas, y lo resuelve
     * `ZonaDeLaBodega`. Lo usan tres sitios —este sembrador, la hoja de
     * picking y el aviso de «compartido con zona»—, y repetido en cada uno se
     * separan al primer cambio del área.
     *
     * Devuelve ['A' => ['1','2',...], ...] con las letras del área.
     *
     * @return array<string, array<int, string>>
     */
    private function zonas(): array
    {
        return Zona::mapa();
    }

    public function run(): void
    {
        $codigo = self::codigoDeLaBodega();

        $bodega = InventarioUbicacion::whereNull('bodega_id')
            ->where('codigo', $codigo)
            ->first();

        if (! $bodega) {
            $this->command?->error("No existe la bodega «{$codigo}».");
            $this->command?->newLine();
            $this->command?->line('  Hay dos salidas, las dos sin tocar código:');
            $this->command?->line('   1. Crearla en /app/inventario/ubicaciones con ese código, o');
            $this->command?->line('   2. Si la bodega principal ya existe con otro nombre, escribir SU código');
            $this->command?->line('      en /app/reglas → Logística → «Código de la bodega donde están los racks».');
            $this->command?->newLine();

            $candidatas = InventarioUbicacion::whereNull('bodega_id')
                ->where('activa', true)
                ->where('codigo', 'not like', 'SIIGO-%')
                ->withCount('movimientos')
                ->orderByDesc('movimientos_count')
                ->take(5)
                ->get(['id', 'codigo', 'nombre']);

            if ($candidatas->isNotEmpty()) {
                $this->command?->line('  Las bodegas con más movimiento hoy, por si alguna es la principal:');

                foreach ($candidatas as $c) {
                    $this->command?->line("   <fg=gray>·</> {$c->codigo} — {$c->nombre} ({$c->movimientos_count} movimientos)");
                }
            }

            return;
        }

        $responsables = $this->responsablesDeZona();
        $deQuienEs = $this->responsablePorRack($responsables);

        $creadas = 0;
        $respetadas = 0;

        foreach ($this->posiciones() as $pos) {
            $u = InventarioUbicacion::firstOrNew(['codigo' => $pos['codigo']]);

            if ($u->exists) {
                $respetadas++;

                continue;
            }

            $u->fill([
                'bodega_id' => $bodega->id,
                'nombre' => $pos['nombre'],
                'pasillo' => $pos['rack'],
                'estante' => $pos['seccion'],
                'nivel' => $pos['nivel'],
                /*
                 * El parqueadero, Arsutex y el local NO son estantería.
                 *
                 * Son donde se descarga un contenedor antes de subirlo a los
                 * racks. Nacieron como «venta» en el primer sembrado, y eso le
                 * promete al cliente la carga que está en el piso sin contar ni
                 * revisar — justo lo que va a pasar el día que llegue un
                 * contenedor. El stock se sigue viendo en el kardex; lo que no
                 * se hace es ofrecerlo.
                 *
                 * `disponible_para_venta` lo recalcula el modelo a partir de la
                 * categoría, así que basta con poner la categoría bien.
                 */
                'categoria' => $pos['seccion'] === null
                    ? CategoriaUbicacion::Cuarentena
                    : CategoriaUbicacion::Venta,
                'activa' => true,
                'responsable_user_id' => $deQuienEs[$pos['rack']] ?? null,
                'ciudad' => $bodega->ciudad,
                'notas' => $pos['zona']
                    ? "Zona {$pos['zona']} · levantamiento del área, 17-nov-2026."
                    : 'Sin zona asignada · el rack no está en ninguna zona de la regla logistica.zonas_bodega.',
            ])->save();

            $creadas++;
        }

        $this->command?->newLine();
        $this->command?->info("Bodega «{$bodega->nombre}»: {$creadas} posiciones creadas, {$respetadas} ya existían y no se tocaron.");

        foreach ($responsables as $letra => $user) {
            $cuantas = InventarioUbicacion::where('responsable_user_id', $user?->id)->count();
            $this->command?->line("  <fg=gray>·</> Zona {$letra} → ".($user?->name ?? 'sin responsable')." ({$cuantas} posiciones)");
        }

        $this->command?->line(
            '  <fg=gray>·</> El reparto de racks por zona lo manda la regla '.
            '<options=bold>logistica.zonas_bodega</> (/app/reglas), no este archivo.'
        );

        $this->command?->comment(
            'Las cuentas «Responsable N» no tienen contraseña utilizable: identifican la zona, '.
            'nadie entra con ellas. Por lo mismo, la bitácora va a decir «Responsable 2» y no quién fue.'
        );
    }

    /**
     * Las 435, una por una.
     *
     * @return iterable<array{codigo:string,nombre:string,rack:string,seccion:?string,nivel:?string,zona:?string}>
     */
    private function posiciones(): iterable
    {
        foreach (self::DISENO as $bloque) {
            foreach ($bloque['racks'] as $rack) {
                foreach ($bloque['secciones'] as $seccion) {
                    foreach (range(1, $bloque['niveles']) as $nivel) {
                        $lados = $bloque['lados'] ? ['D' => 'Derecha', 'I' => 'Izquierda'] : [null => null];

                        foreach ($lados as $sigla => $lado) {
                            $codigo = "R{$rack}-S{$seccion}-N{$nivel}".($sigla ? "-{$sigla}" : '');

                            yield [
                                'codigo' => $codigo,
                                'nombre' => "Rack {$rack} · Sección {$seccion} · Nivel {$nivel}".($lado ? " · {$lado}" : ''),
                                'rack' => $rack,
                                'seccion' => (string) $seccion,
                                'nivel' => (string) $nivel.($sigla ? "-{$sigla}" : ''),
                                'zona' => $this->zonaDe($rack),
                            ];
                        }
                    }
                }
            }
        }

        foreach (self::ZONAS_SUELTAS as $zona => $nombre) {
            yield [
                'codigo' => "Z-{$zona}",
                'nombre' => $nombre,
                'rack' => $zona,
                'seccion' => null,
                'nivel' => null,
                'zona' => $this->zonaDe($zona),
            ];
        }
    }

    /**
     * La letra de la zona de un rack, o «sin zona» si la regla no lo reparte.
     *
     * Antes caía por defecto en la zona 3, que era mentir: un rack nuevo que
     * nadie repartió quedaba a nombre de un responsable que no sabe que lo
     * tiene. Mejor que la nota diga que está sin zona y se vea.
     */
    private function zonaDe(string $rack): ?string
    {
        return Zona::deRack($rack);
    }

    /**
     * Las tres cuentas que responden por cada zona.
     *
     * Nacen como etiqueta —nombre genérico y contraseña aleatoria— porque al
     * sembrar todavía no se sabe quién responde por qué. Pero dejaron de serlo
     * en cuanto el área puso los nombres: el 23-nov-2026 se les asignó persona
     * y clave propia, una por zona, y entraron a trabajar con ellas.
     *
     * Por eso el `if (! $u->exists)` de abajo NO es un detalle: volver a
     * sembrar no puede pisar el nombre ni la contraseña de una persona que ya
     * usa su cuenta.
     *
     * Llevan el papel de Alistador porque el sistema exige papel de bodega
     * para ser responsable de una ubicación, y porque es el más acotado de los
     * tres. Lo que NO les da ese papel es poder contar: eso va por
     * `User::ubicacionesQuePuedeContar()`, que hereda la responsabilidad sin
     * pedir rol — si colgara de la casilla `bodega`, estrenarían también los
     * traslados entre bodegas, que el área reservó al jefe de logística.
     *
     * @return array<int, ?User>
     */
    private function responsablesDeZona(): array
    {
        $rol = Role::firstOrCreate(['name' => 'Alistador', 'guard_name' => 'web']);
        $salida = [];

        foreach (array_keys($this->zonas()) as $i => $letra) {
            // El número sale de la POSICIÓN en el reparto, no de una tabla
            // aparte: si el área abre una zona D, su responsable nace como
            // «Responsable 4» sin tocar código.
            $numero = $i + 1;
            $email = Zona::correoDeEtiqueta($numero);

            $u = User::firstOrNew(['email' => $email]);

            if (! $u->exists) {
                $u->fill([
                    'name' => "Responsable {$numero}",
                    // Sin contraseña utilizable: es una etiqueta, no un login.
                    'password' => bcrypt(bin2hex(random_bytes(24))),
                    'email_verified_at' => now(),
                ])->save();
            }

            if (! $u->hasRole($rol->name)) {
                $u->assignRole($rol);
            }

            $salida[$letra] = $u->fresh();
        }

        return $salida;
    }

    /** @return array<string, ?int> */
    private function responsablePorRack(array $responsables): array
    {
        $mapa = [];

        foreach ($this->zonas() as $letra => $racks) {
            foreach ($racks as $rack) {
                $mapa[$rack] = $responsables[$letra]?->id;
            }
        }

        return $mapa;
    }
}
