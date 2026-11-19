<?php

/**
 * Que el nombre del prop coincida de los dos lados.
 *
 * El controlador manda `bloquea_sin_stock` y la pantalla lo declaraba
 * `bloqueaSinStock`. Vue NO convierte snake_case a camelCase —eso sólo pasa
 * con kebab-case dentro de las plantillas—, así que el prop se quedaba siempre
 * en su default `false` y el tope de cantidad del portal nunca se activaba,
 * por más que Gerencia prendiera la regla.
 *
 * Es el peor tipo de defecto de esta clase: no hay error en ninguna consola,
 * la casilla aparece marcada en Reglas, y el candado simplemente no cierra.
 * Nadie lo descubre hasta que alguien pide más de lo que hay.
 *
 * Por eso el test compara el código fuente de los dos lados en vez de la
 * pantalla renderizada: lo que se rompe es el nombre, no el comportamiento, y
 * un nombre no se puede probar haciendo clic.
 */
it('el prop del tope de stock se llama igual en el controlador y en la pantalla', function () {
    $controlador = file_get_contents(app_path('Http/Controllers/Portal/PortalCatalogoController.php'));
    $pantalla = file_get_contents(resource_path('js/Pages/Portal/Catalogo/Show.vue'));

    // Lo que manda el servidor.
    expect($controlador)->toContain("'bloquea_sin_stock' =>");

    // Lo que declara y usa la pantalla, con el MISMO nombre.
    expect($pantalla)->toContain('bloquea_sin_stock: { type: Boolean')
        ->and($pantalla)->toContain('props.bloquea_sin_stock')
        // Y que no haya quedado ningún USO de la versión camelCase. Se buscan
        // las formas en que se usaría de verdad, no la palabra suelta: el
        // comentario que explica el defecto la nombra a propósito.
        ->and($pantalla)->not->toContain('props.bloqueaSinStock')
        ->and($pantalla)->not->toContain('bloqueaSinStock:')
        ->and($pantalla)->not->toContain('"bloqueaSinStock');
});

it('la regla que enciende el tope existe y nace apagada', function () {
    /*
     * Nace apagada a propósito: el inventario todavía no es confiable y
     * bloquear por datos malos es peor que no bloquear. Pero cuando alguien la
     * prenda, tiene que hacer algo.
     */
    expect(\App\Support\Reglas::DEFAULTS)->toHaveKey('portal.bloquear_sin_stock')
        ->and(\App\Support\Reglas::DEFAULTS['portal.bloquear_sin_stock']['valor'])->toBeFalse();
});
