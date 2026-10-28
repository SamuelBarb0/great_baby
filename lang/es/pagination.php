<?php

/*
 * El paginador mostraba «pagination.previous» y «pagination.next» tal cual, en
 * TODAS las pantallas paginadas del ERP.
 *
 * El motivo: el `.env` tiene `APP_LOCALE=es` pero el proyecto no tenía carpeta
 * `lang/`, así que `__('pagination.previous')` no encontraba traducción y
 * Laravel devuelve la clave cruda. Con el locale en `en` nunca se habría visto,
 * porque ahí sí existen los textos por defecto del framework.
 *
 * Las flechas van como entidades HTML porque el componente las pinta con
 * `v-html`, igual que hace el paginador de Blade.
 */

return [
    'previous' => '&laquo; Anterior',
    'next' => 'Siguiente &raquo;',
];
