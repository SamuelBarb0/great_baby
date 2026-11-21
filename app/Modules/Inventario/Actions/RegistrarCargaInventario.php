<?php

namespace App\Modules\Inventario\Actions;

use App\Models\Contacto;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Inventario\Enums\MotivoCargaInventario;
use App\Modules\Inventario\Models\CargaInventario;

/**
 * Documento + asiento de una carga masiva de inventario.
 *
 * El importador abre la carga justo antes de escribir el primer movimiento de
 * kardex (así una re-subida del mismo Excel, que no mueve nada, no deja
 * documentos vacíos), cuelga cada fila del kardex de ella y al final la cierra:
 *
 *   1435 (inventario del producto)   DÉBITO  = Σ cantidad × costo_unit
 *   cuenta del motivo                HABER   = el mismo total  ← con tercero
 *
 * El débito se agrupa por cuenta de inventario, no por producto: 134
 * referencias de la misma línea son una sola línea del libro, y el detalle
 * por referencia ya vive en el kardex de la carga.
 *
 * Las filas sin costo mueven stock pero no valen nada en el libro: se cuentan
 * aparte para que la pantalla avise en vez de esconderlas.
 */
class RegistrarCargaInventario
{
    /**
     * @param  array{motivo:MotivoCargaInventario, observacion:string, soporte?:?string, tercero_id?:?int, ubicacion_id:int, archivo_nombre?:?string, canal?:string, user_id?:?int}  $datos
     */
    public static function abrir(array $datos): CargaInventario
    {
        $motivo = $datos['motivo'];

        return CargaInventario::create([
            'numero' => CargaInventario::siguienteNumero(),
            'motivo' => $motivo,
            'observacion' => $datos['observacion'],
            'soporte' => $datos['soporte'] ?? null,
            'tercero_id' => $datos['tercero_id'] ?? null,
            'ubicacion_id' => $datos['ubicacion_id'],
            'archivo_nombre' => $datos['archivo_nombre'] ?? null,
            'canal' => $datos['canal'] ?? 'web',
            // La carga que viene de Dropi lleva las cuentas de Dropi (hojas
            // del PUC); la del ERP, la regla de su motivo como siempre.
            'cuenta_contrapartida' => ($datos['canal'] ?? 'web') === 'dropi'
                ? \App\Modules\Dropi\Support\CuentasDropi::contrapartidaCarga($motivo)
                : $motivo->cuentaContrapartida(),
            'user_id' => $datos['user_id'] ?? auth()->id(),
        ]);
    }

    /**
     * ¿Esta columna del encabezado es el costo unitario?
     *
     * «COSTO», «COSTO UNITARIO», «Costo promedio» sí. «COSTO TOTAL» no: es
     * cantidad × costo, y tomarlo por unitario multiplicaría el valor de la
     * línea por su propia cantidad.
     */
    public static function esEncabezadoCosto(string $texto): bool
    {
        $t = mb_strtolower(trim($texto));

        return str_contains($t, 'costo') && ! str_contains($t, 'total');
    }

    /**
     * Lee un costo escrito a mano en el Excel, en formato colombiano o no.
     *
     *   45000 · 45000.5 · "$ 45.000" · "45.000" · "12.500,50" · "12,5"
     *
     * El punto con exactamente tres dígitos detrás es separador de miles
     * («45.000» son cuarenta y cinco mil, no cuarenta y cinco). Devuelve null si
     * la celda está vacía, no es un número o no es positiva: esa fila se queda
     * con el costo del catálogo.
     */
    public static function parsearCosto(string $crudo): ?float
    {
        $s = preg_replace('/[^0-9.,\-]/', '', trim($crudo));
        if ($s === '' || $s === null || str_starts_with($s, '-')) return null;

        $tienePunto = str_contains($s, '.');
        $tieneComa = str_contains($s, ',');

        if ($tienePunto && $tieneComa) {
            // El último separador es el decimal.
            $s = strrpos($s, ',') > strrpos($s, '.')
                ? str_replace(',', '.', str_replace('.', '', $s))
                : str_replace(',', '', $s);
        } elseif ($tieneComa) {
            $s = preg_match('/,\d{3}$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($tienePunto && (substr_count($s, '.') > 1 || preg_match('/\.\d{3}$/', $s))) {
            $s = str_replace('.', '', $s);
        }

        if (! is_numeric($s)) return null;
        $v = round((float) $s, 4);

        return $v > 0 ? $v : null;
    }

    /**
     * Totaliza la carga a partir de su kardex y escribe el asiento.
     * Idempotente: si la carga ya tiene asiento no escribe otro.
     */
    public static function cerrar(CargaInventario $carga): CargaInventario
    {
        $movs = InventarioMovimiento::query()
            ->where('referencia_tipo', CargaInventario::class)
            ->where('referencia_id', $carga->id)
            ->get(['id', 'producto_id', 'cantidad', 'costo_unit']);

        $productos = Producto::query()
            ->whereIn('id', $movs->pluck('producto_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $esDropi = $carga->canal === 'dropi';
        $porCuenta = [];
        $totalValor = 0.0;
        $sinCosto = 0;
        foreach ($movs as $m) {
            $valor = round(abs((float) $m->cantidad) * (float) ($m->costo_unit ?? 0), 2);
            if ($valor <= 0) {
                $sinCosto++;
                continue;
            }
            // Dropi: la hoja de inventario de la familia del producto, la misma
            // de la que su venta saca el costo. El resto, como siempre.
            $cuenta = $esDropi
                ? (string) (\App\Modules\Dropi\Support\CuentasDropi::inventario($productos->get($m->producto_id)) ?: setting('contable.cta_inventario_default'))
                : ($productos->get($m->producto_id)?->cta('inventario')
                    ?? (string) setting('contable.cta_inventario_default'));
            $porCuenta[$cuenta] = ($porCuenta[$cuenta] ?? 0) + $valor;
            $totalValor += $valor;
        }
        $totalValor = round($totalValor, 2);

        $carga->update([
            'total_unidades' => (float) $movs->sum(fn ($m) => (float) $m->cantidad),
            'total_valor' => $totalValor,
            'referencias' => $movs->count(),
            'referencias_sin_costo' => $sinCosto,
        ]);

        $yaAsentada = MovimientoContable::query()
            ->where('origen_type', CargaInventario::class)
            ->where('origen_id', $carga->id)
            ->exists();
        if ($totalValor <= 0 || $yaAsentada) {
            return $carga;
        }

        $fecha = now('America/Bogota')->toDateString();
        $motivo = $carga->motivo;
        $cc = setting('contable.centro_costo_default');
        $base = [
            'fecha' => $fecha,
            'origen_type' => CargaInventario::class,
            'origen_id' => $carga->id,
            'centro_costo' => ($cc !== null && $cc !== '') ? (string) $cc : null,
            'user_id' => $carga->user_id,
        ];
        // La carga nace en Inventario y el estampado la dejaría en Great Baby.
        if ($esDropi) {
            $base['linea_negocio'] = \App\Modules\Contabilidad\Enums\LineaNegocio::Dropi;
        }

        $lineas = [];
        foreach ($porCuenta as $cuenta => $valor) {
            $lineas[] = $base + [
                'cuenta_puc' => (string) $cuenta,
                'debe' => round($valor, 2),
                'haber' => 0,
                'descripcion' => mb_substr("{$carga->numero} · Ingreso de inventario · {$motivo->label()}", 0, 255),
            ];
        }

        // El residuo del redondeo por cuenta se absorbe en la contrapartida:
        // se acredita exactamente lo que se debitó.
        $totalDebitos = round(array_sum(array_column($lineas, 'debe')), 2);
        $lineas[] = $base + [
            'cuenta_puc' => $carga->cuenta_contrapartida,
            'tercero_type' => $carga->tercero_id ? Contacto::class : null,
            'tercero_id' => $carga->tercero_id,
            'debe' => 0,
            'haber' => $totalDebitos,
            'descripcion' => mb_substr(
                "{$carga->numero} · {$motivo->label()}"
                .($carga->soporte ? " · soporte {$carga->soporte}" : '')
                ." · {$carga->observacion}",
                0, 255
            ),
        ];

        MovimientoContable::registrarAsientoAtomico($lineas);

        return $carga;
    }
}
