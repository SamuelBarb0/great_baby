<?php

namespace App\Modules\Compras\Services;

use App\Models\EmpresaConfig;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraItem;

/**
 * Pre-liquidación de una orden de importación: cuánto cuesta cada unidad
 * puesta en Colombia ANTES de pedirla, y si el contenedor cabe.
 *
 * Es la fórmula de la hoja «NUEVO PEDIDO» de Alejandro, columna por columna
 * (precio por unidad en la moneda del proveedor, todo lo demás en COP):
 *
 *   costo China            = precio × tasa
 *   costo China c/comisión = precio × (1 + comisión) × tasa
 *   flete                  = CBM caja × flete por m³ / unidades por caja
 *   arancel                = (flete + costo China) × arancel
 *   IVA                    = (arancel + flete + costo China) × IVA
 *   gasto Colombia         = CBM caja × gasto por m³ / unidades por caja
 *   costo                  = China c/comisión + flete + arancel + IVA + gasto
 *   precio 1               = costo / divisor 1      (su 0,7)
 *   precio 2               = precio 1 / divisor 2   (su 0,8)
 *
 * Ojo, así lo tiene él y así se copia: arancel e IVA van sobre el costo China
 * SIN comisión, y el IVA es 9,5 %. Ningún número está quemado: la tasa es la
 * de la OC y el resto vive en `parametros_preliquidacion`, de modo que el
 * modelo del agente (un solo gasto nacional por m³) cabe poniendo arancel,
 * IVA y gasto Colombia en 0.
 */
class PreliquidarOrden
{
    public const DEFAULTS = [
        'comision_pct' => 12,
        'flete_m3' => 358000,
        'gasto_colombia_m3' => 480000,
        'arancel_pct' => 7.5,
        'iva_pct' => 9.5,
        'divisor_precio_1' => 0.7,
        'divisor_precio_2' => 0.8,
        'capacidad_m3' => 68,
        // En la moneda de la OC. 0 = no se revisa (el valor real lo debe Alejandro).
        'valor_minimo' => 0,
    ];

    /**
     * Parámetros con que nace una OC de importación nueva: los de la última
     * que se pre-liquidó. Si el flete sube, se corrige una vez y la siguiente
     * orden ya lo hereda.
     *
     * @return array<string,float>
     */
    public static function parametrosIniciales(): array
    {
        $ultima = OrdenCompra::query()
            ->where('tipo', 'importacion')
            ->whereNotNull('parametros_preliquidacion')
            ->latest('id')
            ->value('parametros_preliquidacion');

        return self::normalizar(is_string($ultima) ? json_decode($ultima, true) : $ultima);
    }

    /**
     * Tasa con que nace la OC nueva: la de la última importación EN ESA
     * MONEDA. Sin filtrar por moneda, una OC en USD (≈4000) le pasaba su tasa
     * a la siguiente en RMB (≈520) y todo el costo salía 7,7 veces más alto.
     */
    public static function tasaInicial(string $moneda): ?float
    {
        return self::tasasIniciales()[$moneda] ?? null;
    }

    /** @return array<string,float> moneda → tasa de su última OC de importación */
    public static function tasasIniciales(): array
    {
        return OrdenCompra::query()
            ->where('tipo', 'importacion')
            ->where('tasa_cambio', '>', 1)
            ->orderBy('id')
            ->get(['moneda', 'tasa_cambio'])
            ->mapWithKeys(fn ($o) => [$o->moneda => (float) $o->tasa_cambio])
            ->all();
    }

    /**
     * Completa con los defaults y deja sólo números: lo que llega del JSON
     * o del formulario puede traer claves de más o strings.
     *
     * @return array<string,float>
     */
    public static function normalizar(?array $parametros): array
    {
        $p = [];
        foreach (self::DEFAULTS as $clave => $default) {
            $valor = $parametros[$clave] ?? null;
            $p[$clave] = is_numeric($valor) ? (float) $valor : (float) $default;
        }

        return $p;
    }

    /**
     * Costo por unidad de un producto. Es lo que usa la calculadora rápida y
     * lo que se repite por cada línea de la OC.
     *
     * @param  array<string,float>  $p  parámetros normalizados
     * @return array<string,float>|null  null si faltan unidades por caja o CBM
     */
    public function porUnidad(array $p, float $tasa, float $precio, int $unidadesPorCaja, float $cbmCaja): ?array
    {
        if ($unidadesPorCaja <= 0 || $cbmCaja <= 0) {
            return null;
        }

        $china = $precio * $tasa;
        $chinaComision = $precio * (1 + $p['comision_pct'] / 100) * $tasa;
        $flete = $cbmCaja * $p['flete_m3'] / $unidadesPorCaja;
        $arancel = ($flete + $china) * $p['arancel_pct'] / 100;
        $iva = ($arancel + $flete + $china) * $p['iva_pct'] / 100;
        $gasto = $cbmCaja * $p['gasto_colombia_m3'] / $unidadesPorCaja;
        $costo = $chinaComision + $flete + $arancel + $iva + $gasto;
        $precio1 = $p['divisor_precio_1'] > 0 ? $costo / $p['divisor_precio_1'] : 0.0;
        $precio2 = $p['divisor_precio_2'] > 0 ? $precio1 / $p['divisor_precio_2'] : 0.0;

        return [
            'costo_china' => $china,
            'costo_china_comision' => $chinaComision,
            'flete' => $flete,
            'arancel' => $arancel,
            'iva' => $iva,
            'gasto_colombia' => $gasto,
            'costo' => $costo,
            'precio_1' => $precio1,
            'precio_2' => $precio2,
        ];
    }

    /**
     * Pre-liquidación completa de la OC: una fila por línea, totales del
     * contenedor y la división Great Baby / e-commerce.
     *
     * @return array{parametros:array<string,float>, tasa_cambio:float, lineas:array<int,array>, totales:array, por_destino:array<int,array>, avisos:array<int,string>}
     */
    public function handle(OrdenCompra $orden): array
    {
        $orden->loadMissing('items');
        $p = self::normalizar($orden->parametros_preliquidacion);
        $tasa = (float) $orden->tasa_cambio;

        $principal = EmpresaConfig::query()->where('es_principal', true)->value('id');
        $nombres = EmpresaConfig::query()->pluck('nombre_comercial', 'id');

        $lineas = [];
        $destinos = [];
        $tot = ['cajas' => 0, 'unidades' => 0.0, 'cbm' => 0.0, 'valor_moneda' => 0.0, 'costo_total' => 0.0, 'lineas_sin_datos' => 0];

        foreach ($orden->items as $item) {
            /** @var OrdenCompraItem $item */
            $cajas = (int) $item->cajas;
            $cbm = $item->cbmTotal();
            $unidad = $this->porUnidad($p, $tasa, (float) $item->precio_unit, (int) $item->unidades_por_caja, (float) $item->cbm_caja);
            $cantidad = (float) $item->cantidad;
            $valor = $cantidad * (float) $item->precio_unit;

            $lineas[] = [
                'id' => $item->id,
                'descripcion' => $item->descripcion,
                'producto_id' => $item->producto_id,
                'empresa_destino_id' => $item->empresa_destino_id,
                'cajas' => $cajas,
                'unidades_por_caja' => (int) $item->unidades_por_caja,
                'cantidad' => $cantidad,
                'precio_unit' => (float) $item->precio_unit,
                'cbm_caja' => (float) $item->cbm_caja,
                'cbm_total' => round($cbm, 6),
                'valor_moneda' => round($valor, 2),
                'unidad' => $unidad ? array_map(fn ($v) => round($v, 2), $unidad) : null,
                'costo_total' => $unidad ? round($unidad['costo'] * $cantidad, 2) : null,
            ];

            $tot['cajas'] += $cajas;
            $tot['unidades'] += $cantidad;
            $tot['cbm'] += $cbm;
            $tot['valor_moneda'] += $valor;
            $tot['costo_total'] += $unidad ? $unidad['costo'] * $cantidad : 0;
            if (! $unidad) {
                $tot['lineas_sin_datos']++;
            }

            $destino = $item->empresa_destino_id ?: $principal;
            $destinos[$destino] ??= ['empresa_id' => $destino, 'nombre' => $nombres[$destino] ?? 'Great Baby', 'cajas' => 0, 'cbm' => 0.0, 'valor_moneda' => 0.0];
            $destinos[$destino]['cajas'] += $cajas;
            $destinos[$destino]['cbm'] += $cbm;
            $destinos[$destino]['valor_moneda'] += $valor;
        }

        $avisos = [];
        if ($p['capacidad_m3'] > 0 && $tot['cbm'] > $p['capacidad_m3']) {
            $avisos[] = sprintf('El pedido ocupa %.2f m³: se pasa %.2f m³ de la capacidad del contenedor (%s m³).',
                $tot['cbm'], $tot['cbm'] - $p['capacidad_m3'], rtrim(rtrim(number_format($p['capacidad_m3'], 2, '.', ''), '0'), '.'));
        }
        if ($p['valor_minimo'] > 0 && $tot['valor_moneda'] < $p['valor_minimo']) {
            $avisos[] = sprintf('El pedido suma %s %s, por debajo del mínimo de %s %s.',
                number_format($tot['valor_moneda'], 2, ',', '.'), $orden->moneda,
                number_format($p['valor_minimo'], 2, ',', '.'), $orden->moneda);
        }
        if ($tot['lineas_sin_datos'] > 0) {
            $avisos[] = "{$tot['lineas_sin_datos']} línea(s) sin unidades por caja o sin CBM: quedan fuera del costo.";
        }

        return [
            'parametros' => $p,
            'tasa_cambio' => $tasa,
            'lineas' => $lineas,
            'totales' => [
                'cajas' => $tot['cajas'],
                'unidades' => $tot['unidades'],
                'cbm' => round($tot['cbm'], 4),
                'capacidad_m3' => $p['capacidad_m3'],
                'ocupacion_pct' => $p['capacidad_m3'] > 0 ? round($tot['cbm'] / $p['capacidad_m3'] * 100, 1) : null,
                'valor_moneda' => round($tot['valor_moneda'], 2),
                'costo_total' => round($tot['costo_total'], 2),
                'lineas_sin_datos' => $tot['lineas_sin_datos'],
            ],
            'por_destino' => array_values(array_map(fn ($d) => [
                ...$d,
                'cbm' => round($d['cbm'], 4),
                'valor_moneda' => round($d['valor_moneda'], 2),
            ], $destinos)),
            'avisos' => $avisos,
        ];
    }
}
