<?php

namespace App\Console\Commands;

use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Models\PlanCuenta;
use App\Support\Reglas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mueve los asientos viejos a la cuenta que hoy dicen las reglas.
 *
 * Hasta el 26-oct las cuentas estaban escritas a mano dentro de las acciones
 * —`'1305'`, `'2205'`, `'2408'`— mientras `Reglas::DEFAULTS` registraba las
 * subcuentas de Great Baby. Al dejar que mande la regla, lo nuevo entra en
 * `130505` y lo viejo se quedó en `1305`: el libro quedó mixto.
 *
 * Esto NO decide cuál es la cuenta correcta. La correcta la dice la contadora
 * y se escribe UNA vez en la pantalla de Reglas; este comando sólo arrastra lo
 * ya escrito hasta donde la regla apunte en ese momento.
 *
 * ── Tres candados ───────────────────────────────────────────────────────────
 *
 *   · **Modo revisión por defecto.** Sin `--aplicar` no escribe nada. Esto
 *     reescribe historia contable; que cueste un segundo comando es a propósito.
 *
 *   · **Nunca toca lo que ya viajó a SIIGO.** Un movimiento con
 *     `siigo_journal_id` ya existe allá con su cuenta; cambiarlo acá dejaría
 *     los dos libros diciendo cosas distintas, que es peor que el libro mixto.
 *     Se cuentan aparte y se informan.
 *
 *   · **Se mueve de a una regla.** Hay cuentas ambiguas: `2408` es el padre
 *     tanto del IVA generado (`240805`) como del descontable (`240810`), y
 *     cuál es cuál depende del documento, no del número. Por eso hay que
 *     nombrar la regla y, cuando haga falta, el origen.
 *
 * Uso:
 *   php artisan contabilidad:reestampar-cuentas
 *   php artisan contabilidad:reestampar-cuentas --regla=cta_cxc_default
 *   php artisan contabilidad:reestampar-cuentas --regla=cta_iva_venta_default --origen=FacturaVenta --aplicar
 */
class ReestamparCuentasPuc extends Command
{
    protected $signature = 'contabilidad:reestampar-cuentas
        {--regla= : Nombre corto de la regla, p. ej. cta_cxc_default. Sin esto sólo diagnostica.}
        {--origen= : Limita a un tipo de documento, p. ej. FacturaVenta. Para desambiguar.}
        {--aplicar : Escribe los cambios. Sin esto sólo muestra qué haría.}';

    protected $description = 'Lleva los asientos viejos a la cuenta PUC que hoy dicen las reglas';

    public function handle(): int
    {
        $regla = $this->option('regla');

        return $regla ? $this->mover($regla) : $this->diagnosticar();
    }

    /**
     * Qué hay en el libro y qué dice el plan de cuentas de cada cosa.
     *
     * Es lo que se mira antes de mover nada. La columna que más importa no es
     * el conteo sino «admite movimiento»: el plan de cuentas del cliente marca
     * qué cuentas aceptan asientos, y hoy el libro está escrito entero sobre
     * cuentas que NO los aceptan —ni las viejas ni las que traen las reglas—.
     */
    private function diagnosticar(): int
    {
        $reglas = $this->reglasDeCuenta();

        $this->info('Cuentas presentes en el libro');
        $this->newLine();

        $filas = [];

        foreach ($this->cuentasDelLibro() as $cuenta => $datos) {
            $plan = PlanCuenta::where('codigo', $cuenta)->first();
            $destinos = $this->reglasQueApuntanDebajoDe($cuenta, $reglas);

            $filas[] = [
                $cuenta,
                $plan?->nombre ? mb_substr($plan->nombre, 0, 28) : '(fuera del plan)',
                $plan?->permite_movimiento ? 'sí' : 'NO',
                number_format($datos['n'], 0, ',', '.'),
                $datos['siigo'] ? number_format($datos['siigo'], 0, ',', '.') : '—',
                $destinos === [] ? '—' : implode(' · ', array_map(
                    fn ($k, $v) => "{$k} → {$v}", array_keys($destinos), $destinos,
                )),
            ];
        }

        $this->table(
            ['Cuenta', 'Nombre', 'Admite mov.', 'Movs', 'En SIIGO', 'Reglas que apuntan debajo'],
            $filas,
        );

        $noPostables = collect($filas)->where(2, 'NO')->count();

        if ($noPostables) {
            $this->newLine();
            $this->warn("⚠ {$noPostables} de ".count($filas).' cuentas del libro NO admiten movimiento según el plan de cuentas.');
            $this->line('  Moverlas a la cuenta de la regla no arregla eso por sí solo: primero hay');
            $this->line('  que dejar la auxiliar correcta en la pantalla de Reglas.');
        }

        $this->auxiliaresPosibles();

        $this->newLine();
        $this->line('Para mover una: --regla=<nombre corto>  (agregá --aplicar cuando estés seguro)');

        return self::SUCCESS;
    }

    /**
     * Qué auxiliar podría ir en cada regla, según el plan de cuentas.
     *
     * Es la hoja de trabajo para la contadora. La mayoría de las reglas apunta
     * hoy a una cuenta mayor que no acepta asientos; debajo de cada una hay
     * una o varias auxiliares que sí.
     *
     * Donde hay UNA SOLA, no hay nada que decidir: es la única que acepta
     * movimiento en esa rama. Donde hay varias, la elección es de ella —y a
     * veces no es una sola: ingreso, costo e inventario están abiertas por
     * categoría de producto, así que el default sólo aplica al producto que no
     * traiga la suya.
     */
    private function auxiliaresPosibles(): void
    {
        $this->newLine();
        $this->info('Auxiliares que aceptan movimiento bajo cada regla');
        $this->newLine();

        $filas = [];

        foreach ($this->reglasDeCuenta() as $corto => $valor) {
            $hojas = PlanCuenta::where('codigo', 'like', $valor.'%')
                ->where('permite_movimiento', true)
                ->orderBy('codigo')
                ->get(['codigo', 'nombre']);

            $filas[] = [
                $corto,
                $valor,
                match (true) {
                    $hojas->isEmpty() => 'sin auxiliar',
                    $hojas->count() === 1 => '← ÚNICA',
                    default => $hojas->count().' opciones',
                },
                $hojas->take(3)->map(fn ($h) => $h->codigo.' '.mb_substr($h->nombre, 0, 24))->implode(' · ')
                    .($hojas->count() > 3 ? ' …' : ''),
            ];
        }

        $this->table(['Regla', 'Apunta a', 'Auxiliares', 'Cuáles'], $filas);

        $unicas = collect($filas)->where(2, '← ÚNICA')->pluck(0);

        if ($unicas->isNotEmpty()) {
            $this->line('  Las marcadas ÚNICA no necesitan criterio: es la sola cuenta que acepta');
            $this->line('  movimiento en esa rama. Las demás sí hay que elegirlas.');
        }
    }

    /** Mueve los movimientos de las cuentas padre a la que dice la regla. */
    private function mover(string $corto): int
    {
        $clave = "contable.{$corto}";

        if (! array_key_exists($clave, Reglas::DEFAULTS)) {
            $this->error("No existe la regla «{$clave}».");
            $this->line('  Las disponibles: '.implode(', ', array_keys($this->reglasDeCuenta())));

            return self::FAILURE;
        }

        $destino = (string) setting($clave);
        $aplicar = (bool) $this->option('aplicar');
        $origen = $this->option('origen');

        $candidatas = $this->ancestrasDe($destino, array_keys($this->cuentasDelLibro()));

        if ($candidatas === []) {
            $this->info("Nada que mover: ningún movimiento está en una cuenta por encima de {$destino}.");

            return self::SUCCESS;
        }

        // Una cuenta padre que alimenta DOS reglas no se puede repartir por el
        // número; hay que decir de qué documento viene.
        foreach ($candidatas as $c) {
            $otras = array_diff_key($this->reglasQueApuntanDebajoDe($c, $this->reglasDeCuenta()), [$corto => null]);

            if ($otras !== [] && ! $origen) {
                $this->error("La cuenta {$c} también alimenta: ".implode(', ', array_keys($otras)).'.');
                $this->line('  Es ambigua por el número. Agregá --origen=<Modelo> para decir de qué documento.');

                return self::FAILURE;
            }
        }

        if (! $aplicar) {
            $this->warn('MODO REVISIÓN · no se escribe nada. Agregá --aplicar para mover.');
            $this->newLine();
        }

        $plan = PlanCuenta::where('codigo', $destino)->first();

        if ($plan && ! $plan->permite_movimiento) {
            $this->warn("⚠ {$destino} NO admite movimiento según el plan de cuentas.");
            $this->line('  Vas a mover los asientos a una cuenta que SIIGO rechaza. Confirmá la');
            $this->line('  auxiliar con la contadora antes de aplicar.');
            $this->newLine();
        }

        $total = 0;
        $enSiigo = 0;

        foreach ($candidatas as $c) {
            $base = MovimientoContable::where('cuenta_puc', $c)
                ->when($origen, fn ($q) => $q->where('origen_type', 'like', "%{$origen}"));

            $yaEnSiigo = (clone $base)->whereNotNull('siigo_journal_id')->count();
            $movibles = (clone $base)->whereNull('siigo_journal_id');
            $n = (clone $movibles)->count();

            $enSiigo += $yaEnSiigo;
            $total += $n;

            $this->line("  {$c} → {$destino} · {$n} movimiento(s)"
                .($yaEnSiigo ? "  (+{$yaEnSiigo} ya en SIIGO, se dejan como están)" : ''));

            if ($aplicar && $n > 0) {
                DB::transaction(fn () => $movibles->update(['cuenta_puc' => $destino]));
            }
        }

        $this->newLine();

        if ($enSiigo) {
            $this->warn("{$enSiigo} movimiento(s) quedaron en la cuenta vieja porque ya existen en SIIGO.");
            $this->line('  Cambiarlos acá dejaría los dos libros distintos. Si hay que corregirlos,');
            $this->line('  se hace del lado de SIIGO y después acá.');
        }

        $this->info($aplicar
            ? "{$total} movimiento(s) movido(s) a {$destino}."
            : "{$total} movimiento(s) se moverían a {$destino}. Volvé a correrlo con --aplicar.");

        return self::SUCCESS;
    }

    // ───────────────────────────── internos ─────────────────────────────

    /** Las reglas de cuenta, por nombre corto → valor vigente. */
    private function reglasDeCuenta(): array
    {
        $out = [];

        foreach (Reglas::DEFAULTS as $clave => $meta) {
            if (str_starts_with($clave, 'contable.cta_')) {
                $out[substr($clave, 9)] = (string) setting($clave);
            }
        }

        return $out;
    }

    /**
     * Las cuentas que hay en el libro, con cuántos movimientos y cuántos
     * ya viajaron a SIIGO.
     */
    private function cuentasDelLibro(): array
    {
        return MovimientoContable::query()
            ->selectRaw('cuenta_puc, COUNT(*) n, SUM(siigo_journal_id IS NOT NULL) siigo')
            ->groupBy('cuenta_puc')
            ->orderBy('cuenta_puc')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->cuenta_puc => ['n' => (int) $r->n, 'siigo' => (int) $r->siigo]])
            ->all();
    }

    /**
     * Qué reglas apuntan a una subcuenta de ésta.
     *
     * La relación se mira por prefijo, que es como está construido el PUC
     * colombiano: 1305 → 130505 → 13050501. Se pide que sea ESTRICTAMENTE más
     * larga para no contarse a sí misma.
     *
     * @return array<string,string> nombre corto de la regla → su valor
     */
    private function reglasQueApuntanDebajoDe(string $cuenta, array $reglas): array
    {
        return array_filter(
            $reglas,
            fn (string $valor) => strlen($valor) > strlen($cuenta) && str_starts_with($valor, $cuenta),
        );
    }

    /**
     * De las cuentas del libro, cuáles son ancestros del destino.
     *
     * @param  array<string>  $presentes
     * @return array<string>
     */
    private function ancestrasDe(string $destino, array $presentes): array
    {
        return array_values(array_filter(
            $presentes,
            fn (string $c) => strlen($c) < strlen($destino) && str_starts_with($destino, $c),
        ));
    }
}
