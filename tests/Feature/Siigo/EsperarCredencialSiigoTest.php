<?php

/**
 * Con la credencial de SIIGO vencida, los push esperan; no se mueren.
 *
 * El 2026-10-08 venció la llave y quedaron **1008 trabajos** en `failed_jobs`
 * —452 productos, o sea el catálogo entero, más asientos, devoluciones y
 * recepciones—. Casi todos con `MaxAttemptsExceeded`, que encima pisa el error
 * original: ni siquiera quedaba escrito que el problema había sido la llave.
 * Y de `failed_jobs` no salen solos.
 *
 * Una credencial muerta es una caída del servicio, no un problema del
 * documento que tocó pasar en ese momento. Lo que se fija acá:
 *   · con la llave muerta el job vuelve a la cola y no corre;
 *   · con la llave sana corre normal;
 *   · un 500 de SIIGO NO frena la cola (si no, un hipo apaga todo);
 *   · el techo es temporal, porque `release()` gasta intento.
 */

use App\Modules\Siigo\Jobs\Middleware\EsperarCredencialSiigo;
use App\Modules\Siigo\Jobs\PushProductoASiigo;
use App\Models\User;
use App\Modules\Siigo\Models\SiigoConfig;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::firstOrCreate(['name' => 'Gerencia', 'guard_name' => 'web']));
});

/** Doble del job de la cola: sólo interesa si lo soltaron y con cuánta espera. */
function jobFalso(): object
{
    return new class
    {
        public bool $liberado = false;

        public ?int $espera = null;

        public function release(int $segundos): string
        {
            $this->liberado = true;
            $this->espera = $segundos;

            return 'liberado';
        }
    };
}

function correrMiddleware(object $job): array
{
    $corrio = false;
    $r = (new EsperarCredencialSiigo)->handle($job, function () use (&$corrio) {
        $corrio = true;

        return 'ejecutado';
    });

    return ['corrio' => $corrio, 'resultado' => $r];
}

function ponerAuth(?bool $ok, ?string $error = null): void
{
    SiigoConfig::current()->forceFill([
        // Las credenciales van puestas a propósito: estos casos describen una
        // instalación YA configurada en distintos estados de salud. Sin ellas
        // se confunde «la llave no sirve» con «no hay llave escrita», que es
        // otra cosa y la cubre SinCredencialNoSaleNadaTest.
        'username' => 'sandbox@siigoapi.com',
        'access_key' => 'llave-de-prueba',
        'ultimo_auth_ok' => $ok,
        'ultimo_auth_error' => $error,
        'ultimo_auth_at' => now(),
    ])->save();
}

it('devuelve el job a la cola cuando SIIGO responde 401', function () {
    ponerAuth(false, 'HTTP 401 · invalid credentials');
    $job = jobFalso();

    $r = correrMiddleware($job);

    expect($r['corrio'])->toBeFalse()
        ->and($job->liberado)->toBeTrue()
        ->and($job->espera)->toBe(EsperarCredencialSiigo::ESPERA);
});

it('también espera si las credenciales ni siquiera están puestas', function () {
    ponerAuth(false, 'Credenciales SIIGO no configuradas.');
    $job = jobFalso();

    expect(correrMiddleware($job)['corrio'])->toBeFalse()
        ->and($job->liberado)->toBeTrue();
});

it('deja pasar el job cuando la credencial está sana', function () {
    ponerAuth(true);
    $job = jobFalso();

    expect(correrMiddleware($job)['corrio'])->toBeTrue()
        ->and($job->liberado)->toBeFalse();
});

it('deja pasar cuando todavía nadie probó la credencial', function () {
    // `null` es «sin probar». Frenar acá dejaría la cola parada en una
    // instalación nueva, donde la primera llamada es justamente la que prueba.
    ponerAuth(null);
    $job = jobFalso();

    expect(correrMiddleware($job)['corrio'])->toBeTrue()
        ->and($job->liberado)->toBeFalse();
});

it('un 500 de SIIGO no frena la cola', function () {
    // Un hipo del servidor también apaga `ultimo_auth_ok`. Si eso bastara
    // para aplazar, un error pasajero congelaría todos los push.
    ponerAuth(false, 'HTTP 500 · internal server error');
    $job = jobFalso();

    expect(correrMiddleware($job)['corrio'])->toBeTrue()
        ->and($job->liberado)->toBeFalse();
});

it('los push de SIIGO llevan el middleware y cortan por reloj, no por intentos', function () {
    $job = new PushProductoASiigo(1, 'crear');

    $clases = array_map(fn ($m) => $m::class, $job->middleware());

    expect($clases)->toContain(EsperarCredencialSiigo::class)
        // `release()` gasta intento, así que con un `tries` fijo una espera
        // larga igual terminaría en failed_jobs. El límite tiene que ser el reloj.
        ->and($job->retryUntil()->greaterThan(now()->addHours(11)))->toBeTrue()
        ->and($job->maxExceptions)->toBe(5);
});

it('el panel culpa a la llave y no al worker cuando la llave es el problema', function () {
    // El aviso de cola atascada decía SIEMPRE «prendé el queue:work». Con la
    // llave caída eso manda a Aracely a soporte técnico cuando el arreglo lo
    // tiene ella a dos campos de distancia, en esa misma pantalla.
    ponerAuth(false, 'HTTP 401 · Incorrect username or password');

    $cola = $this->actingAs($this->admin)->get('/app/siigo')->assertOk()
        ->viewData('page')['props']['cola'];

    expect($cola['motivo'])->toBe('credencial');
});

it('con la llave sana el aviso vuelve a señalar al worker', function () {
    ponerAuth(true);

    $cola = $this->actingAs($this->admin)->get('/app/siigo')->assertOk()
        ->viewData('page')['props']['cola'];

    expect($cola['motivo'])->toBe('worker');
});
