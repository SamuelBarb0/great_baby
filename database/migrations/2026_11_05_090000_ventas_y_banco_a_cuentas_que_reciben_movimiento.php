<?php

/*
 * Las ventas y el banco apuntaban a cuentas que el plan prohíbe mover.
 *
 * Continúa lo que empezó 2026_10_28_130000 con caja y bancos. Aquellas dos se
 * arreglaron porque el asiento de nómina las necesitaba; estas tres quedaron
 * «pendientes de confirmar con la contadora», y la confirmación llegó el
 * 2026-11-04.
 *
 * Lo que había:
 *
 *   cta_cxc_default      1305    grupo «Clientes nacionales»
 *   cta_ingreso_default  4135    grupo «Comercio al por mayor y al detal»
 *   cta_bancos_default   111010  «Moneda nacional · Davivienda»
 *
 * Las dos primeras tienen al libro de producción asentado contra cuentas que
 * no reciben movimiento: 21 facturas, $18.050.000 en cada lado. Y no reventaba
 * —que es lo peor— porque el mapeo a SIIGO resuelve un grupo por su «primera
 * descendiente activa»: el día que se conectara SIIGO, los ingresos enteros de
 * Great Baby entraban como 41350101 ALIMENTACIÓN E HIDRATACIÓN.
 *
 * La tercera es distinta: la 111010 Davivienda no es un grupo, es una cuenta
 * que inventamos nosotros. Great Baby no tiene Davivienda. Lo confirmó Silvia
 * —«no tenemos davivienda, sólo son dos cuentas bancolombia y una tarjeta
 * prepago»— y el extracto de septiembre lo cierra: cuenta de ahorros
 * Bancolombia 30300002664, sucursal Éxito Bucaramanga, de la que salen todos
 * los «PAGO A PROVE». En el plan es la 11200501.
 *
 * El ingreso, además, deja de decidirse sólo acá: el asiento de venta ahora
 * parte las líneas por la cuenta de cada producto o de su categoría, y la
 * regla queda de cajón para lo que no trae ninguna de las dos. Por eso su
 * nueva cuenta es 413505 «Venta de mercancías», que es hoja y se ve.
 *
 * Lo ya asentado NO se mueve acá. Para eso está
 * `php artisan contabilidad:reestampar-cuentas --aplicar`, que es donde esa
 * decisión tiene revisión previa — y reescribir contabilidad de producción la
 * autoriza la contadora, no una migración.
 *
 * Sólo se corrige si el valor guardado sigue siendo el viejo: si alguien ya
 * puso otra cuenta, manda la suya.
 */

use App\Models\ReglaNegocio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * clave => [valores viejos que se corrigen, valor nuevo]
     *
     * La CxC lleva DOS viejos a propósito. El default pasó en algún momento de
     * `1305` a `130505` sin que nadie notara que la segunda también agrupa:
     * producción tiene asientos contra las dos. Así que las dos se corrigen.
     *
     * @var array<string,array{0:list<string>,1:string}>
     */
    private const CORRIGE = [
        'contable.cta_cxc_default' => [['1305', '130505'], '13050501'],
        'contable.cta_ingreso_default' => [['4135', '413501'], '413505'],
        'contable.cta_bancos_default' => [['111010'], '11200501'],
    ];

    public function up(): void
    {
        foreach (self::CORRIGE as $clave => [$viejos, $nuevo]) {
            $this->mover($clave, $viejos, $nuevo);
        }
    }

    public function down(): void
    {
        // Se vuelve al primero de la lista, que es el valor original.
        foreach (self::CORRIGE as $clave => [$viejos, $nuevo]) {
            $this->mover($clave, [$nuevo], $viejos[0]);
        }
    }

    /**
     * @param  list<string>  $desde
     */
    private function mover(string $clave, array $desde, string $hacia): void
    {
        // Que la cuenta destino exista y admita movimiento en ESTA
        // instalación: el plan de cuentas lo carga cada empresa.
        $sirve = DB::table('plan_cuentas')
            ->where('codigo', $hacia)
            ->where('permite_movimiento', 1)
            ->exists();

        if (! $sirve) {
            return;
        }

        /*
         * Por el MODELO, no por query builder: `ReglaNegocio` cachea las
         * reglas y sólo invalida esa caché desde su observer de `saved`.
         * Un `DB::table()->update()` dejaría la caché vieja viva una hora,
         * con la base diciendo una cuenta y `setting()` devolviendo otra.
         */
        ReglaNegocio::where('clave', $clave)
            ->whereIn('valor', $desde)
            ->each(fn (ReglaNegocio $regla) => $regla->update(['valor' => $hacia]));
    }
};
