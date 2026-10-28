<?php

/**
 * Ningún `setting()` puede contradecir la regla que está registrada.
 *
 * `Reglas::get()` resuelve en este orden:
 *
 *   1. la fila de `reglas_negocio`, si existe;
 *   2. el default que se escribió EN LA LLAMADA;
 *   3. el valor de `Reglas::DEFAULTS`.
 *
 * El paso 2 antes del 3 es la trampa. Cuando alguien escribe
 * `setting('contable.cta_cxc_default', '1305')` y DEFAULTS dice '130505', el
 * literal gana mientras no exista la fila — y la fila sólo nace cuando alguien
 * abre la pantalla de Reglas, que dispara `seedDefaults()`.
 *
 * O sea: la suite corre sobre una base recién migrada, sin filas, y ve '1305';
 * producción tiene las filas sembradas y escribe '130505'. Las pruebas pasaban
 * verificando una cuenta y el sistema real usaba otra. Seis cuentas contables
 * estaban así.
 *
 * Esta prueba no pide que nadie borre los defaults —un literal que coincide
 * con la regla es redundante pero inofensivo—. Pide que no CONTRADIGAN.
 */

use App\Support\Reglas;

/** Todas las llamadas `setting('clave', <literal escalar>)` del código. */
function llamadasConDefaultLiteral(): array
{
    $base = dirname(__DIR__, 2).'/app';
    $out = [];

    $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));

    foreach ($archivos as $archivo) {
        if ($archivo->getExtension() !== 'php') {
            continue;
        }

        $txt = file_get_contents($archivo->getPathname());

        // Sólo literales escalares. Una expresión o un `setting()` anidado no
        // se puede comparar en estático, y no es el caso que rompe.
        preg_match_all(
            "/setting\(\s*'([a-z0-9_.]+)'\s*,\s*('[^']*'|true|false|-?[0-9][0-9_]*(?:\.[0-9]+)?)\s*\)/i",
            $txt, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        foreach ($m as $hit) {
            $out[] = [
                'clave' => $hit[1][0],
                'literal' => trim($hit[2][0], "'"),
                'crudo' => $hit[2][0],
                'donde' => str_replace($base, 'app', $archivo->getPathname())
                    .':'.(substr_count(substr($txt, 0, $hit[0][1]), "\n") + 1),
            ];
        }
    }

    return $out;
}

/** ¿El literal dice lo mismo que la regla, en el tipo de la regla? */
function diceLoMismo(string $tipo, mixed $registrado, string $literal, string $crudo): bool
{
    if ($tipo === 'bool' || in_array(strtolower($crudo), ['true', 'false'], true)) {
        return filter_var($registrado, FILTER_VALIDATE_BOOLEAN) === filter_var($literal, FILTER_VALIDATE_BOOLEAN);
    }

    if (in_array($tipo, ['int', 'float'], true)) {
        return abs((float) $registrado - (float) str_replace('_', '', $literal)) < 0.000001;
    }

    return (string) $registrado === $literal;
}

it('ningún default escrito en el código contradice a Reglas::DEFAULTS', function () {
    $choques = [];

    foreach (llamadasConDefaultLiteral() as $l) {
        $meta = Reglas::DEFAULTS[$l['clave']] ?? null;

        // Una clave sin registrar es otro problema (su prueba está abajo).
        if ($meta === null) {
            continue;
        }

        if (! diceLoMismo($meta['tipo'], $meta['valor'], $l['literal'], $l['crudo'])) {
            $choques[] = sprintf(
                "  %s\n    código: %s   ·   Reglas::DEFAULTS: %s\n    → quitá el default de la llamada: setting('%s')",
                $l['donde'], $l['crudo'], var_export($meta['valor'], true), $l['clave'],
            );
        }
    }

    expect($choques)->toBe([], "\n\nHay ".count($choques)." llamada(s) cuyo default contradice la regla registrada.\n"
        ."Mientras no exista la fila en `reglas_negocio` gana el literal, así que la suite\n"
        ."comprueba un valor y producción usa otro.\n\n".implode("\n\n", $choques)."\n");
});

it('ninguna regla CONTABLE lleva default escrito en la llamada', function () {
    /*
     * Más estricto que la prueba de arriba, y sólo para el grupo `contable`.
     *
     * Un literal que hoy coincide con la regla es inofensivo… hasta que la
     * contadora cambia la regla desde la pantalla. Ahí el literal vuelve a
     * ganar en toda base sin fila sembrada —o sea, en la suite— y las pruebas
     * se quedan verificando la cuenta vieja mientras producción usa la nueva.
     * Es exactamente cómo empezó esto, con seis cuentas.
     *
     * En plata eso es un asiento contra la cuenta equivocada, así que para
     * estas claves el default no se permite ni cuando coincide. El resto de
     * los grupos se queda con la regla más floja de arriba.
     */
    $culpables = [];

    foreach (llamadasConDefaultLiteral() as $l) {
        if (str_starts_with($l['clave'], 'contable.')) {
            $culpables[] = "  {$l['donde']}  →  setting('{$l['clave']}')";
        }
    }

    expect($culpables)->toBe([], "\n\nLas reglas contables se leen SIN segundo argumento:\n"
        .implode("\n", $culpables)."\n");
});

it('toda clave que el código consulta está registrada en Reglas::DEFAULTS', function () {
    /*
     * Una clave sin registrar no aparece en la pantalla de Reglas, así que
     * nadie la puede cambiar, y `Reglas::set()` la rechaza con «Regla
     * desconocida». Queda clavada en el literal del código para siempre.
     *
     * Así estaba `contabilidad.cta_bancos_default`: el grupo correcto es
     * `contable`, el nombre tenía una errata, y el comentario de al lado decía
     * «Silvia lo configura al arranque» sobre algo que Silvia no podía ver.
     */
    $sinRegistrar = [];

    foreach (llamadasConDefaultLiteral() as $l) {
        if (! array_key_exists($l['clave'], Reglas::DEFAULTS)) {
            $sinRegistrar[$l['clave']] = $l['donde'];
        }
    }

    // Las que ya estaban así antes de esta prueba. No se agregan más: cada una
    // es un parámetro que el cliente no puede tocar sin un programador.
    $conocidas = [
        'empresa.rango_hasta',
        'empresa.rango_hasta_nc',
        'empresa.rango_hasta_nd',
        'cartera.token_publico_dias_vida',
        'credito.bloquear_por_factura_vencida',
        'inventario.permitir_cerrar_propia_toma',
        'siigo.iva_pct_default',
    ];

    $nuevas = array_diff_key($sinRegistrar, array_flip($conocidas));

    expect($nuevas)->toBe([], "\n\nClave(s) consultadas con `setting()` que no están en `Reglas::DEFAULTS`:\n  "
        .implode("\n  ", array_map(fn ($k, $v) => "$k  ($v)", array_keys($nuevas), $nuevas))."\n");
});
