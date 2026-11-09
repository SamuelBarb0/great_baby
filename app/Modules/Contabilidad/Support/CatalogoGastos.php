<?php

namespace App\Modules\Contabilidad\Support;

/**
 * Los tipos de gasto y la cuenta de cada uno.
 *
 * Great Baby mando la lista el 2026-11-03: cada concepto con su cuenta del
 * PUC. Hasta ahora la categoria era una etiqueta suelta y la cuenta se
 * escribia a mano gasto por gasto, asi que dos papelerias podian ir a parar a
 * dos cuentas distintas y el informe por cuenta no servia.
 *
 * Se lee de las reglas y no de una constante para que el contador pueda
 * cambiar una cuenta sin que nadie toque codigo. Vivía en GastosController;
 * se sacó (7-nov) para que el panel de Dropi use el mismo catálogo.
 */
final class CatalogoGastos
{
    /** @return array<string,array{etiqueta:string,cuenta:string}> */
    public static function todos(): array
    {
        $tipos = [
            'aseo_cafeteria' => 'Aseo y cafetería',
            'papeleria' => 'Papelería',
            'arriendo' => 'Arriendo',
            'envios' => 'Envíos y fletes',
            'honorarios' => 'Honorarios',
            'publicidad' => 'Publicidad',
            'servicio_acueducto' => 'Servicio · acueducto',
            'servicio_energia' => 'Servicio · energía',
            'servicio_internet' => 'Servicio · internet',
            'servicio_celular' => 'Servicio · celular',
            'servicio_gas' => 'Servicio · gas',
        ];

        $catalogo = [];
        foreach ($tipos as $clave => $etiqueta) {
            $catalogo[$clave] = [
                'etiqueta' => $etiqueta,
                'cuenta' => (string) setting('gastos.cta_'.$clave),
            ];
        }

        // El cajon de sastre va al final y usa la cuenta de gasto generica.
        $catalogo['otros'] = [
            'etiqueta' => 'Otros',
            'cuenta' => (string) setting('contable.cta_gasto_default'),
        ];

        return $catalogo;
    }

    /** La cuenta que le toca a una categoria, o la generica si no la conoce. */
    public static function cuentaDe(string $categoria): ?string
    {
        return self::todos()[$categoria]['cuenta']
            ?? ((string) setting('contable.cta_gasto_default') ?: null);
    }
}
