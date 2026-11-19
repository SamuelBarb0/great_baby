<?php

namespace App\Console\Commands;

use App\Modules\Contabilidad\Models\PlanCuenta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Carga las reglas de retención desde la tabla que manda el contador, y les
 * propone la cuenta PUC en vez de pedir que alguien la teclee 29 veces.
 *
 * El archivo del cliente (`Tabla_Retefuente_2026.xlsx`) trae concepto, base en
 * UVT, base en pesos y tarifa — pero NO la cuenta contable. El PUC sí la trae,
 * y encima organizada justo como hace falta: una rama por concepto
 * (236515 Honorarios, 236525 Servicios, 236540 Compras…) y dentro, una
 * subcuenta por tarifa («Servicios 6%», «Honorarios 11%»).
 *
 * Así que la cuenta se DEDUCE cruzando los dos archivos. Lo que no se pueda
 * deducir se reporta para que la contadora lo resuelva a mano: es mejor dejar
 * tres filas sin cuenta y que se vean, que inventarles una.
 *
 * Idempotente: se puede correr las veces que haga falta.
 */
class CargarRetencionesDesdeExcel extends Command
{
    protected $signature = 'contabilidad:cargar-retenciones
                            {archivo : Ruta del Excel con la tabla de retefuente}
                            {--dry-run : Muestra lo que haría sin escribir nada}';

    protected $description = 'Carga las reglas de retención desde el Excel del contador, deduciendo la cuenta PUC';

    /**
     * Concepto del Excel → rama del PUC donde vive su cuenta.
     * El orden importa: gana la primera que coincida.
     */
    private const RAMAS = [
        // Primero las claves más específicas: «Rete IVA por compras» también
        // contiene «compras», y sin este orden caería en la rama equivocada.
        'rete iva' => '2367',
        'autorrenta' => '236575',
        'autorretención de renta' => '236575',
        'autorreteica' => '236576',

        'honorarios' => '236515',
        'comisiones' => '236520',
        'arrendamiento' => '236530',
        'rendimientos financieros' => '236535',
        'intereses' => '236535',
        'compras' => '236540',
        'adquisición' => '236540',
        'enajenación' => '236540',
        'servicios' => '236525',
        'transporte' => '236525',
        'hoteles' => '236525',
        'vigilancia' => '236525',
        'salud' => '236525',
        'construcción' => '236570',
        'licenciamiento' => '236570',
        'otros ingresos' => '236570',
        'salarios' => '236505',
        'indemnizaciones' => '236505',
    ];

    public function handle(): int
    {
        $ruta = $this->argument('archivo');
        if (! is_file($ruta)) {
            $this->error("No existe el archivo: {$ruta}");
            return self::FAILURE;
        }

        $reglas = $this->leer($ruta);
        if ($reglas === []) {
            $this->error('No se encontró ninguna fila con concepto y tarifa.');
            return self::FAILURE;
        }

        $sinCuenta = [];
        $filas = [];

        foreach ($reglas as $r) {
            $cuenta = $this->deducirCuenta($r['concepto'], $r['tarifa']);
            if ($cuenta === null) {
                $sinCuenta[] = $r;
            }

            $filas[] = $r + ['cuenta_puc' => $cuenta];
        }

        $this->table(
            ['Tipo', 'Concepto', 'Base $', 'Tarifa', 'Cuenta PUC deducida'],
            array_map(fn ($f) => [
                $f['tipo'],
                mb_strimwidth($f['concepto'], 0, 46, '…'),
                number_format($f['base'], 0, ',', '.'),
                $f['tarifa'].' %',
                $f['cuenta_puc'] ?? '— sin deducir —',
            ], $filas),
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: no se escribió nada.');
            $this->avisarPendientes($sinCuenta);
            return self::SUCCESS;
        }

        $creadas = 0;
        $actualizadas = 0;

        DB::transaction(function () use ($filas, &$creadas, &$actualizadas): void {
            foreach ($filas as $f) {
                $existente = DB::table('retenciones_config')
                    ->where('tipo', $f['tipo'])
                    ->where('concepto', $f['concepto'])
                    ->first();

                $datos = [
                    'tipo' => $f['tipo'],
                    'concepto' => $f['concepto'],
                    'base_minima' => $f['base'],
                    'tarifa_pct' => $f['tarifa'],
                    'cuenta_puc' => $f['cuenta_puc'],
                    // Se cargan INACTIVAS a propósito: la contadora revisa la
                    // cuenta deducida y las va activando. Una regla equivocada
                    // activa retiene de más en cada factura y eso se arrastra.
                    'activa' => false,
                    'notas' => 'Cargada desde la tabla del contador · revisar cuenta antes de activar',
                    'updated_at' => now(),
                ];

                if ($existente) {
                    DB::table('retenciones_config')->where('id', $existente->id)->update($datos);
                    $actualizadas++;
                } else {
                    DB::table('retenciones_config')->insert($datos + ['created_at' => now()]);
                    $creadas++;
                }
            }
        });

        $this->newLine();
        $this->info("Listo · {$creadas} reglas creadas · {$actualizadas} actualizadas · todas INACTIVAS hasta que se revisen.");
        $this->avisarPendientes($sinCuenta);

        return self::SUCCESS;
    }

    /** Lee el Excel y se queda con las filas que tienen concepto y tarifa. */
    private function leer(string $ruta): array
    {
        $reader = new XlsxReader();
        $reader->open($ruta);
        $reglas = [];

        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                $c = array_map(fn ($x) => trim((string) $x->getValue()), $fila->getCells());
                if (count($c) < 4) continue;

                $concepto = $c[0];
                $tarifa = $this->porcentaje($c[3] ?? '');

                // La fila de salarios remite al Art. 383 ET (tabla progresiva),
                // no a una tarifa fija: no es una regla que se pueda calcular acá.
                if ($concepto === '' || $tarifa === null) continue;
                if (str_contains(mb_strtolower($c[3] ?? ''), 'art')) continue;

                $reglas[] = [
                    'tipo' => str_contains(mb_strtolower($concepto), 'rete iva') ? 'reteiva' : 'retefuente',
                    'concepto' => $concepto,
                    'base' => $this->pesos($c[2] ?? ''),
                    'tarifa' => $tarifa,
                ];
            }
            break;  // sólo la primera hoja
        }

        $reader->close();

        return $reglas;
    }

    /** «2,5%» → 2.5 · «0,10%» → 0.1 · lo que no sea un número → null */
    private function porcentaje(string $v): ?float
    {
        $v = str_replace(['%', ' '], '', $v);
        $v = str_replace(',', '.', $v);

        return is_numeric($v) ? (float) $v : null;
    }

    /** «$524.000» → 524000.0 · «100%» (sin base) → 0.0 */
    private function pesos(string $v): float
    {
        if (str_contains($v, '%')) return 0.0;  // «100%»: aplica desde el primer peso

        $v = preg_replace('/[^\d]/', '', $v);

        return $v === '' ? 0.0 : (float) $v;
    }

    /**
     * La cuenta sale de cruzar la rama del concepto con la tarifa.
     *
     * Ej.: «Servicios generales (declarantes renta)» al 4 % → rama 236525 y,
     * dentro de ella, la subcuenta cuyo nombre lleva «4%» → 23652503.
     */
    private function deducirCuenta(string $concepto, float $tarifa): ?string
    {
        $c = mb_strtolower($concepto);

        $rama = null;
        foreach (self::RAMAS as $clave => $prefijo) {
            if (str_contains($c, $clave)) {
                $rama = $prefijo;
                break;
            }
        }

        if ($rama === null) return null;

        // Las subcuentas de devolución no sirven: son el contra-asiento.
        $candidatas = PlanCuenta::where('codigo', 'like', $rama.'%')
            ->whereRaw('LENGTH(codigo) = 8')
            ->where('nombre', 'not like', '%evoluc%')
            ->where('nombre', 'not like', '%D. fiscal%')
            ->get();

        $tarifaTexto = rtrim(rtrim(number_format($tarifa, 2, ',', ''), '0'), ',');

        // 1 · la subcuenta de esa tarifa exacta, si existe
        foreach ($candidatas as $cta) {
            $nombre = str_replace(' ', '', mb_strtolower($cta->nombre));
            if (str_contains($nombre, str_replace(' ', '', $tarifaTexto).'%')) {
                return $cta->codigo;
            }
        }

        /*
         * 2 · si no, la GENÉRICA de la rama — la que no lleva porcentaje en el
         * nombre («23651501 Honorarios», «23653001 Arrendamientos»).
         *
         * Great Baby sólo abrió subcuenta para las tarifas que de verdad
         * practica; el resto se contabiliza contra la cuenta del concepto. Sin
         * esta caída, 19 de las 29 reglas quedaban sin cuenta y había que
         * asignarlas a mano una por una.
         */
        $generica = $candidatas->first(fn ($c) => ! str_contains($c->nombre, '%'));

        return $generica?->codigo;
    }

    private function avisarPendientes(array $sinCuenta): void
    {
        if ($sinCuenta === []) {
            $this->info('Todas las reglas quedaron con cuenta PUC deducida.');
            return;
        }

        $this->newLine();
        $this->warn(count($sinCuenta).' regla(s) quedaron SIN cuenta · hay que asignarlas a mano:');
        foreach ($sinCuenta as $r) {
            $this->line("   · {$r['concepto']} ({$r['tarifa']} %)");
        }
    }
}
