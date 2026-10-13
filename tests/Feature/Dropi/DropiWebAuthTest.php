<?php

use App\Modules\Dropi\Clients\DropiWebClient;
use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Services\DropiWebAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function jwtDePrueba(array $claims): string
{
    $b64 = fn ($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');

    return $b64(['alg' => 'HS256', 'typ' => 'JWT']) . '.' . $b64($claims) . '.firma';
}

beforeEach(function () {
    config()->set('dropi.web', [
        'email' => 'Cuenta@Ejemplo.com',
        'password' => 'secreto',
        'white_brand_id' => 1,
        'bff_url' => 'https://bff.test',
        'timeout' => 5,
    ]);
    app()->forgetInstance(DropiWebAuth::class);
    app()->singleton(DropiWebAuth::class, fn ($app) => new DropiWebAuth($app['config']->get('dropi.web')));
});

it('pide el código cuando Dropi no reconoce el equipo, y conecta con él', function () {
    $exp = now()->addHours(8)->timestamp;
    Http::fake([
        'bff.test/auth/core/beforeLoginUnknownDevice' => Http::response(['is_succesfull' => true, 'data' => ['isUnknownDevice' => true, 'email' => 'c***@ejemplo.com']]),
        'bff.test/auth/core/login' => Http::response(['is_succesfull' => true, 'data' => ['token' => jwtDePrueba(['exp' => $exp])]]),
    ]);

    $auth = app(DropiWebAuth::class);

    expect($auth->iniciar())->toBe(DropiWebSesion::ESPERANDO_CODIGO);
    expect($auth->confirmarCodigo(' 123 456 '))->toBe(DropiWebSesion::CONECTADO);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/auth/core/login')
        && $r['otp'] === '123456'
        && $r['email'] === 'cuenta@ejemplo.com'
        && $r['white_brand_id'] === 1
        && $r->hasHeader('Origin', 'https://app.dropi.co'));

    $sesion = DropiWebSesion::first();
    expect($sesion->estado)->toBe(DropiWebSesion::CONECTADO)
        ->and($sesion->expira_at->timestamp)->toBe($exp)
        ->and($sesion->tokenVigente())->not->toBeNull();
});

it('guarda el token cifrado en la base', function () {
    $token = jwtDePrueba(['exp' => now()->addHour()->timestamp]);
    Http::fake([
        'bff.test/auth/core/beforeLoginUnknownDevice' => Http::response(['is_succesfull' => true, 'data' => []]),
        'bff.test/auth/core/login' => Http::response(['is_succesfull' => true, 'data' => ['token' => $token]]),
    ]);

    expect(app(DropiWebAuth::class)->iniciar())->toBe(DropiWebSesion::CONECTADO);

    $crudo = \DB::table('dropi_web_sesion')->value('token');
    expect($crudo)->not->toContain($token)
        ->and(decrypt($crudo, false))->toBe($token);
});

it('conecta con la respuesta de Dropi ya sin la errata (is_successful)', function () {
    // Octubre 2026: Dropi corrigió `is_succesfull` → `is_successful` y el login
    // fallaba con «Dropi rechazó el inicio de sesión: OK».
    $token = jwtDePrueba(['exp' => now()->addHour()->timestamp]);
    Http::fake([
        'bff.test/auth/core/beforeLoginUnknownDevice' => Http::response(['is_successful' => true, 'status_reason' => 'OK', 'data' => ['login_otp' => false]]),
        'bff.test/auth/core/login' => Http::response(['is_successful' => true, 'data' => ['token' => $token]]),
    ]);

    expect(app(DropiWebAuth::class)->iniciar())->toBe(DropiWebSesion::CONECTADO);
});

it('deja el motivo cuando Dropi rechaza el código', function () {
    Http::fake([
        'bff.test/auth/core/login' => Http::response(['is_succesfull' => false, 'status_reason' => 'Código inválido'], 400),
    ]);
    DropiWebSesion::create(['email' => 'Cuenta@Ejemplo.com', 'estado' => DropiWebSesion::ESPERANDO_CODIGO]);

    $estado = app(DropiWebAuth::class)->confirmarCodigo('000000');

    expect($estado)->toBe(DropiWebSesion::ESPERANDO_CODIGO)
        ->and(DropiWebSesion::first()->ultimo_error)->toContain('Código inválido');
});

it('no guarda un token de configuración de 2FA como sesión', function () {
    Http::fake([
        'bff.test/auth/core/beforeLoginUnknownDevice' => Http::response(['is_succesfull' => true, 'data' => []]),
        'bff.test/auth/core/login' => Http::response(['is_succesfull' => true, 'data' => ['token' => jwtDePrueba(['token_type' => '2FA', 'aud' => 'CONFIG'])]]),
    ]);

    expect(app(DropiWebAuth::class)->iniciar())->toBe(DropiWebSesion::DESCONECTADO)
        ->and(DropiWebSesion::first()->token)->toBeNull();
});

it('manda el token en X-Authorization, aguanta un 403 y marca la sesión vencida con un 401', function () {
    DropiWebSesion::create([
        'email' => 'Cuenta@Ejemplo.com', 'estado' => DropiWebSesion::CONECTADO,
        'token' => 'tok-123', 'expira_at' => now()->addHour(),
    ]);
    Http::fakeSequence('bff.test/orders/*')
        ->push(['is_succesfull' => true, 'data' => ['objects' => [['id' => 1]], 'count' => null]])
        ->push(['is_succesfull' => false, 'status_reason' => 'Access denied'], 403)
        ->push(['message' => 'Unauthorized'], 401);

    $client = app(DropiWebClient::class);
    $pagina = $client->pedidosCrudos();

    expect($pagina['objects'])->toHaveCount(1);
    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Authorization', 'Bearer tok-123'));

    // Un 403 (bloqueo del WAF de Dropi) es un error, pero no tumba la sesión.
    expect(fn () => $client->pedidosCrudos())->toThrow(\RuntimeException::class);
    expect(DropiWebSesion::first()->estado)->toBe(DropiWebSesion::CONECTADO);

    expect(fn () => $client->pedidosCrudos())->toThrow(DropiSesionNoDisponible::class);
    expect(DropiWebSesion::first()->estado)->toBe(DropiWebSesion::EXPIRADO);
});

it('se reconecta sola cuando la sesión vence y Dropi ya conoce el equipo', function () {
    DropiWebSesion::create([
        'email' => 'Cuenta@Ejemplo.com', 'estado' => DropiWebSesion::CONECTADO,
        'token' => 'tok-viejo', 'expira_at' => now()->subMinute(),
    ]);
    $nuevo = jwtDePrueba(['exp' => now()->addHours(4)->timestamp]);
    Http::fake([
        'bff.test/auth/core/beforeLoginUnknownDevice' => Http::response(['is_succesfull' => true, 'data' => ['isUnknownDevice' => false]]),
        'bff.test/auth/core/login' => Http::response(['is_succesfull' => true, 'data' => ['token' => $nuevo]]),
        'bff.test/orders/*' => Http::response(['is_succesfull' => true, 'data' => ['objects' => [['id' => 1]]]]),
    ]);

    expect(app(DropiWebClient::class)->pedidosCrudos()['objects'])->toHaveCount(1);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/orders/') && $r->hasHeader('X-Authorization', "Bearer {$nuevo}"));
    Http::assertNotSent(fn (Request $r) => $r->hasHeader('X-Authorization', 'Bearer tok-viejo'));
    expect(DropiWebSesion::first()->estado)->toBe(DropiWebSesion::CONECTADO);
});

it('si al reconectar Dropi pide el código, espera al humano y no insiste', function () {
    DropiWebSesion::create([
        'email' => 'Cuenta@Ejemplo.com', 'estado' => DropiWebSesion::EXPIRADO,
        'token' => 'tok-viejo', 'expira_at' => now()->subMinute(),
    ]);
    Http::fake([
        'bff.test/auth/core/beforeLoginUnknownDevice' => Http::response(['is_succesfull' => true, 'data' => ['isUnknownDevice' => true]]),
    ]);
    $client = app(DropiWebClient::class);

    expect(fn () => $client->pedidosCrudos())->toThrow(DropiSesionNoDisponible::class, 'código');
    expect(fn () => $client->pedidosCrudos())->toThrow(DropiSesionNoDisponible::class);

    Http::assertSentCount(1); // un solo intento: cada uno le manda otro código al correo
    expect(DropiWebSesion::first()->estado)->toBe(DropiWebSesion::ESPERANDO_CODIGO);
});

it('no se reconecta si alguien la desconectó a propósito', function () {
    DropiWebSesion::create(['email' => 'Cuenta@Ejemplo.com', 'estado' => DropiWebSesion::DESCONECTADO]);
    Http::fake();

    expect(fn () => app(DropiWebClient::class)->pedidosCrudos())->toThrow(DropiSesionNoDisponible::class);
    Http::assertNothingSent();
});

it('la cuenta guardada en el CRM manda sobre la del .env, con la clave cifrada', function () {
    Http::fake([
        'bff.test/auth/core/beforeLoginUnknownDevice' => Http::response(['is_succesfull' => true, 'data' => []]),
        'bff.test/auth/core/login' => Http::response(['is_succesfull' => true, 'data' => ['token' => jwtDePrueba(['exp' => now()->addHour()->timestamp])]]),
    ]);
    $auth = app(DropiWebAuth::class);

    $auth->cambiarCuenta(' Integracion@GreatBaby.co ', 'clave-nueva');

    expect($auth->origenCuenta())->toBe('crm')
        ->and(\DB::table('dropi_web_cuenta')->value('password'))->not->toContain('clave-nueva');

    expect($auth->iniciar())->toBe(DropiWebSesion::CONECTADO);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/auth/core/login')
        && $r['email'] === 'integracion@greatbaby.co'
        && $r['password'] === 'clave-nueva');
    expect(DropiWebSesion::where('email', 'integracion@greatbaby.co')->value('estado'))->toBe(DropiWebSesion::CONECTADO);
});

it('cambiar de cuenta cierra la sesión de la anterior y no reconecta sola con la nueva', function () {
    DropiWebSesion::create([
        'email' => 'Cuenta@Ejemplo.com', 'estado' => DropiWebSesion::CONECTADO,
        'token' => 'tok-viejo', 'expira_at' => now()->addHour(),
    ]);
    Http::fake();
    $auth = app(DropiWebAuth::class);

    $auth->cambiarCuenta('otra@greatbaby.co', 'clave');

    $vieja = DropiWebSesion::where('email', 'Cuenta@Ejemplo.com')->first();
    expect($vieja->estado)->toBe(DropiWebSesion::DESCONECTADO)
        ->and($vieja->token)->toBeNull();

    // La nueva arranca desconectada: espera a que alguien pulse «Conectar».
    expect(fn () => app(DropiWebClient::class)->pedidosCrudos())->toThrow(DropiSesionNoDisponible::class);
    Http::assertNothingSent();
});

it('con la misma cuenta y la clave vacía conserva la clave; con otra cuenta la exige', function () {
    $auth = app(DropiWebAuth::class);
    $auth->cambiarCuenta('integracion@greatbaby.co', 'clave-1');

    $auth->cambiarCuenta('Integracion@GreatBaby.co', '');
    expect($auth->cuenta())->toBe(['email' => 'integracion@greatbaby.co', 'password' => 'clave-1'])
        ->and(\DB::table('dropi_web_cuenta')->count())->toBe(1);

    expect(fn () => $auth->cambiarCuenta('otra@greatbaby.co', ''))->toThrow(\InvalidArgumentException::class);
    expect($auth->cuenta()['email'])->toBe('integracion@greatbaby.co');
});

it('la auditoría registra el cambio de cuenta pero nunca la clave', function () {
    config()->set('audit.console', true); // en la web siempre audita; los tests corren en consola
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    app(DropiWebAuth::class)->cambiarCuenta('integracion@greatbaby.co', 'clave-secreta', $user->id);

    $audit = \DB::table('audits')->where('auditable_type', \App\Modules\Dropi\Models\DropiWebCuenta::class)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->new_values)->toContain('integracion@greatbaby.co')
        ->and($audit->new_values)->not->toContain('password')
        ->and($audit->new_values . $audit->old_values)->not->toContain('clave-secreta');
});

it('volver al .env borra la cuenta del CRM y cierra su sesión', function () {
    $auth = app(DropiWebAuth::class);
    $auth->cambiarCuenta('integracion@greatbaby.co', 'clave');
    DropiWebSesion::where('email', 'integracion@greatbaby.co')->update(['estado' => DropiWebSesion::CONECTADO, 'token' => encrypt('tok', false)]);

    $auth->usarCuentaDelEnv();

    expect($auth->origenCuenta())->toBe('env')
        ->and($auth->cuenta()['email'])->toBe('Cuenta@Ejemplo.com')
        ->and(DropiWebSesion::where('email', 'integracion@greatbaby.co')->value('estado'))->toBe(DropiWebSesion::DESCONECTADO);
});

it('sin cuenta en el CRM ni en el .env avisa dónde configurarla', function () {
    config()->set('dropi.web.email', '');
    app()->forgetInstance(DropiWebAuth::class);

    expect(app(DropiWebAuth::class)->origenCuenta())->toBeNull();
    expect(fn () => app(DropiWebAuth::class)->sesion())->toThrow(DropiSesionNoDisponible::class, 'Conexión Dropi');
});

it('si la reconexión falla por red, queda vencida para reintentar más tarde', function () {
    DropiWebSesion::create([
        'email' => 'Cuenta@Ejemplo.com', 'estado' => DropiWebSesion::CONECTADO,
        'token' => 'tok-viejo', 'expira_at' => now()->subMinute(),
    ]);
    Http::fake(['bff.test/*' => Http::response('Bad gateway', 502)]);

    expect(fn () => app(DropiWebClient::class)->pedidosCrudos())->toThrow(DropiSesionNoDisponible::class);
    expect(DropiWebSesion::first()->estado)->toBe(DropiWebSesion::EXPIRADO);
});
