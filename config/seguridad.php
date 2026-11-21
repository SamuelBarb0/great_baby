<?php

/*
|------------------------------------------------------------------------------
| Seguridad · valores que NO pueden estar escritos en el código
|------------------------------------------------------------------------------
|
| Este repositorio es PÚBLICO. Cualquier contraseña, token o secreto que se
| escriba en un archivo del repo queda a la vista de quien lo clone — y, peor,
| queda en el historial de Git para siempre, aunque después se borre.
|
| Por eso estos valores viven en el `.env` y se leen DESDE ACÁ.
|
| ¿Por qué un archivo de config y no `env()` directo en el seeder?
|
| Porque `php artisan config:cache` —que es parte normal de un despliegue—
| deja de leer el `.env` por completo: a partir de ahí `env()` devuelve NULL
| y sólo `config()` sigue funcionando. Un seeder que leyera `env()` pelado
| creería que nadie configuró nada y se inventaría una contraseña aleatoria,
| en silencio, justo en producción.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Contraseña inicial de las cuentas que crean los seeders
    |--------------------------------------------------------------------------
    |
    | Vacía a propósito. Si no se configura, el seeder inventa una aleatoria
    | distinta en cada corrida y la imprime una sola vez en la consola: así
    | nunca hay un valor por defecto que alguien pueda adivinar leyendo el
    | código.
    |
    | Quien despliega la pone en el `.env` cuando quiere una conocida:
    |
    |     CLAVE_INICIAL_USUARIOS=loquesea
    |
    */
    'clave_inicial_usuarios' => env('CLAVE_INICIAL_USUARIOS', ''),

];
