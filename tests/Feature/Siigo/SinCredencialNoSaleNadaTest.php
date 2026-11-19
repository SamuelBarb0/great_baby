<?php

/**
 * Una instalación sin credenciales no manda nada a SIIGO.
 *
 * Encontrado el 2026-10-14 revisando pendientes: la base se había reconstruido,
 * `siigo_config` quedó vacía, `SIIGO_DRIVER=real` y había 30
 * `PushProductoASiigo` esperando en la cola.
 *
 * `credencialMuerta()` devolvía `false` porque sólo miraba
 * `ultimo_auth_ok === false`, y en una instalación donde nadie probó la
 * conexión ese campo es NULL. El middleware entonces dejaba pasar los push,
 * que salían a pegarle a SIIGO sin usuario y quemaban las 12 h de `retryUntil`
 * antes de caer en `failed_jobs` — exactamente el desperdicio que el middleware
 * existe para evitar.
 *
 * «Todavía no sé si sirve» y «no hay nada escrito» son cosas distintas: lo
 * segundo se sabe sin preguntarle a nadie.
 */

use App\Modules\Siigo\Models\SiigoConfig;

function configSiigo(array $attrs = []): SiigoConfig
{
    return SiigoConfig::create(array_merge([
        'username' => 'sandbox@siigoapi.com',
        'access_key' => 'una-llave-cualquiera',
        'ambiente' => 'sandbox',
        'activo' => true,
    ], $attrs));
}

it('sin usuario ni llave la credencial cuenta como muerta', function () {
    $c = configSiigo(['username' => null, 'access_key' => null]);

    expect($c->ultimo_auth_ok)->toBeNull()
        ->and($c->credencialMuerta())->toBeTrue();
});

it('con usuario pero sin llave también', function () {
    expect(configSiigo(['access_key' => null])->credencialMuerta())->toBeTrue();
});

it('con llave pero sin usuario también', function () {
    expect(configSiigo(['username' => ''])->credencialMuerta())->toBeTrue();
});

it('con credenciales cargadas y sin probar todavía, NO está muerta', function () {
    // «Sin probar» no es «rota»: hay algo escrito, dejá que lo intente.
    $c = configSiigo();

    expect($c->ultimo_auth_ok)->toBeNull()
        ->and($c->credencialMuerta())->toBeFalse();
});

it('con credenciales que ya autenticaron bien, tampoco', function () {
    expect(configSiigo(['ultimo_auth_ok' => true])->credencialMuerta())->toBeFalse();
});

it('un 401 con credenciales cargadas sí la marca muerta', function () {
    $c = configSiigo();
    $c->forceFill(['ultimo_auth_ok' => false, 'ultimo_auth_error' => 'HTTP 401 not_authorized'])->save();

    expect($c->credencialMuerta())->toBeTrue();
});

it('un 500 pasajero no frena la cola', function () {
    // La regla de siempre: un error del servidor de SIIGO no es culpa de la
    // llave, y aplazar todo por eso deja la cola parada sin motivo.
    $c = configSiigo();
    $c->forceFill(['ultimo_auth_ok' => false, 'ultimo_auth_error' => 'HTTP 500 server error'])->save();

    expect($c->credencialMuerta())->toBeFalse();
});

it('el middleware aplaza el push cuando no hay credenciales', function () {
    // El efecto que importa: con la config vacía, el job vuelve a la cola en
    // vez de gastarse un intento contra la pared.
    configSiigo(['username' => null, 'access_key' => null]);

    $job = new class
    {
        public bool $liberado = false;

        public function release($delay = 0)
        {
            $this->liberado = true;
        }
    };

    $corrio = false;
    (new App\Modules\Siigo\Jobs\Middleware\EsperarCredencialSiigo())
        ->handle($job, function () use (&$corrio) {
            $corrio = true;
        });

    expect($job->liberado)->toBeTrue()
        ->and($corrio)->toBeFalse();
});
