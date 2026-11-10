<?php

/**
 * Los avisos que además salen por correo.
 *
 * Hasta el 2026-11-06 el ERP sólo avisaba dentro de la aplicación: quien no
 * entraba ese día no se enteraba de un pedido retenido, de una guía sin
 * entregar ni de una nómina esperando aprobación. El contrato pide avisos en
 * la app, por correo y por WhatsApp; de los tres existía uno.
 *
 * Lo que se protege acá no es que el correo salga —eso lo hace Laravel— sino
 * las dos decisiones que lo rodean, que son las que se desconfiguran solas:
 *
 *   · que nazca APAGADO, porque el servidor todavía manda al log;
 *   · que sólo salgan los tipos de la lista, porque mandar todo lo de la
 *     campana por correo es la forma más rápida de que lo manden a spam;
 *   · y que un aviso sin destinatario propio vaya a la lista de copia, no a
 *     los 23 usuarios.
 */

use App\Jobs\EnviarAvisoPorCorreo;
use App\Mail\AvisoErp;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Support\Reglas;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Reglas::limpiarRequestCache();
});

function avisoDe(string $tipo, array $extra = []): NotificacionErp
{
    return NotificacionErp::crear(array_merge([
        'tipo' => $tipo,
        'titulo' => 'Algo pasó',
        'mensaje' => 'El detalle de lo que pasó.',
        'url' => '/app/pedidos-b2b/1',
        'color' => 'warning',
    ], $extra));
}

it('con el correo apagado sólo queda la campana', function () {
    Queue::fake();

    avisoDe('pedido_retenido');

    Queue::assertNothingPushed();
    expect(NotificacionErp::count())->toBe(1);
});

it('prendido, un tipo de la lista sí se encola', function () {
    Queue::fake();
    Reglas::set('notificaciones.correo_activo', true);
    Reglas::limpiarRequestCache();

    // El tipo real es `pedido_retenido_cartera`. La lista traía
    // `pedido_retenido` a secas, que ningún aviso emite.
    avisoDe('pedido_retenido_cartera');

    Queue::assertPushed(EnviarAvisoPorCorreo::class);
});

it('un tipo que no está en la lista no manda correo aunque esté prendido', function () {
    Queue::fake();
    Reglas::set('notificaciones.correo_activo', true);
    Reglas::limpiarRequestCache();

    avisoDe('cualquier_cosa_menor');

    Queue::assertNothingPushed();
});

it('la lista de tipos se puede cambiar sin tocar código', function () {
    Queue::fake();
    Reglas::set('notificaciones.correo_activo', true);
    Reglas::set('notificaciones.tipos_por_correo', '["cualquier_cosa_menor"]');
    Reglas::limpiarRequestCache();

    avisoDe('cualquier_cosa_menor');
    Queue::assertPushed(EnviarAvisoPorCorreo::class);
});

it('un aviso de alguien le llega a esa persona', function () {
    Mail::fake();
    $u = User::factory()->create(['email' => 'ana@greatbaby.com']);

    (new EnviarAvisoPorCorreo(avisoDe('pedido_retenido', ['user_id' => $u->id])->id))->handle();

    Mail::assertSent(AvisoErp::class, fn (AvisoErp $mail) => $mail->hasTo('ana@greatbaby.com'));
});

it('un aviso general va a la lista de copia, no a todo el mundo', function () {
    Mail::fake();
    Reglas::set('notificaciones.correo_copia', 'gerencia@greatbaby.com, contabilidad@greatbaby.com');
    Reglas::limpiarRequestCache();
    User::factory()->count(3)->create();

    (new EnviarAvisoPorCorreo(avisoDe('pedido_retenido')->id))->handle();

    Mail::assertSentCount(2);
    Mail::assertSent(AvisoErp::class, fn (AvisoErp $mail) => $mail->hasTo('gerencia@greatbaby.com'));
});

it('sin lista de copia no manda nada, y no revienta', function () {
    Mail::fake();
    Reglas::set('notificaciones.correo_copia', '');
    Reglas::limpiarRequestCache();

    (new EnviarAvisoPorCorreo(avisoDe('pedido_retenido')->id))->handle();

    Mail::assertNothingSent();
});

it('ignora los correos mal escritos de la lista', function () {
    Mail::fake();
    Reglas::set('notificaciones.correo_copia', 'bueno@greatbaby.com, esto-no-es-un-correo, ;');
    Reglas::limpiarRequestCache();

    (new EnviarAvisoPorCorreo(avisoDe('pedido_retenido')->id))->handle();

    Mail::assertSentCount(1);
});

it('un aviso borrado no tumba la cola', function () {
    Mail::fake();
    $id = avisoDe('pedido_retenido')->id;
    NotificacionErp::whereKey($id)->delete();

    (new EnviarAvisoPorCorreo($id))->handle();

    Mail::assertNothingSent();
});

it('todo tipo de la lista por defecto es un aviso que el sistema manda de verdad', function () {
    /*
     * Un tipo mal escrito no da error: simplemente ese correo no sale NUNCA,
     * aunque la regla esté prendida y la casilla marcada. Gerencia prende el
     * aviso, no llega nada, y la conclusión razonable es que el correo está
     * roto.
     *
     * La lista por defecto traía cuatro tipos que ningún aviso usaba
     * —`pedido_retenido` (el real es `pedido_retenido_cartera`),
     * `nomina_por_aprobar`, `pago_vencido` y `siigo_fallo`—, o sea la mitad.
     *
     * Este test lee el código fuente a propósito: es la única forma de que la
     * lista no se vuelva a separar de la realidad sin que nadie se entere.
     */
    $crudo = \App\Support\Reglas::DEFAULTS['notificaciones.tipos_por_correo']['valor'];
    $configurados = json_decode((string) $crudo, true) ?: [];

    expect($configurados)->not->toBeEmpty();

    $emitidos = [];

    foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $archivo) {
        if ($archivo->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/'tipo'\s*=>\s*'([a-z_]+)'/", $archivo->getContents(), $m);
        $emitidos = array_merge($emitidos, $m[1]);
    }

    $muertos = array_values(array_diff($configurados, array_unique($emitidos)));

    expect($muertos)->toBe([],
        'Estos tipos están en la lista de correo y ningún aviso del sistema los usa: '
        .implode(', ', $muertos));
});
