<?php

/**
 * Cada puerta manda a su propio login, y el 404 cuenta la historia correcta.
 *
 * Hay una ruta puente que manda todo lo no autenticado a `/app/login`, «el
 * único login real». Para el equipo está bien: una sola puerta. Pero el portal
 * tiene su propia puerta y sus propias credenciales, así que un cliente con la
 * sesión vencida aterrizaba en la pantalla del personal —que dice «SISTEMA
 * ERP»— donde su correo no sirve, su clave tampoco, y no hay link de vuelta al
 * portal. Lo que concluye es que le quitaron el acceso.
 */

use App\Models\Contacto;
use Illuminate\Support\Facades\Hash;

it('un cliente sin sesión va al login DEL PORTAL, no al del personal', function () {
    $this->get('/portal/catalogo')->assertRedirect('/portal/login');
    $this->get('/portal')->assertRedirect('/portal/login');
});

it('el equipo sin sesión sigue yendo al login del ERP', function () {
    // La puerta del personal no cambia: sigue pasando por la ruta puente
    // `/login`, que de ahí manda al único login real del ERP.
    $this->get('/app/cosas-del-dia')->assertRedirect(route('login'));
});

it('con sesión de cliente el catálogo abre', function () {
    $cliente = Contacto::create([
        'tipo_documento' => 'NIT', 'numero_documento' => '900121212',
        'nombre_completo' => 'Pañalera con portal', 'email' => 'portal@cliente.test',
        'es_cliente' => true, 'es_cliente_b2b' => true, 'activo' => true,
        'portal_habilitado' => true, 'password' => Hash::make('clave-de-prueba'),
    ]);

    $this->actingAs($cliente, 'cliente')->get('/portal/catalogo')->assertOk();
});

it('la clave corta se RECHAZA DICIENDO por qué', function () {
    /*
     * El servidor exige ocho caracteres y ese mensaje viaja en
     * `errors.password`, que la pantalla del portal no pintaba: el cliente
     * escribía una clave corta, apretaba Ingresar y no pasaba nada. Sin
     * mensaje, sin recarga, sin pista.
     */
    $this->post('/portal/login', ['email' => 'quien@sea.test', 'password' => 'corta'])
        ->assertSessionHasErrors('password');

    // Y la pantalla ahora muestra todos los errores, no sólo el del correo.
    $login = file_get_contents(resource_path('js/Pages/Portal/Auth/Login.vue'));

    expect($login)->toContain('erroresVisibles')
        ->and($login)->toContain('page.props.errors ?? {}');
});

it('el 404 de una dirección inventada no manda a pedirle un link al vendedor', function () {
    /*
     * La misma pantalla sirve para los links de factura vencidos y para una
     * dirección mal escrita. Mandar a alguien a pedir un link que no necesita
     * es peor que no decirle nada.
     */
    $r = $this->get('/portal/catalogo/1');   // esta ruta no existe

    $r->assertNotFound();
    expect($r->getContent())
        ->toContain('Esta dirección no existe')
        ->not->toContain('pídele al vendedor un nuevo link');
});


it('un abort del ERP sí conserva su mensaje y manda a pedir el link', function () {
    /*
     * El otro lado de la misma pantalla: cuando es un link de factura, decirle
     * «pedile un nuevo link al vendedor» es exactamente lo que hay que decir.
     * Y se muestra el mensaje que escribió el ERP, no uno genérico: «El
     * archivo ya no está» no significa lo mismo que «expiró».
     */
    \Illuminate\Support\Facades\Route::get('/_prueba-404-documento',
        fn () => abort(404, 'Documento no disponible.'))->middleware('web');

    $r = $this->get('/_prueba-404-documento');

    $r->assertNotFound();
    expect($r->getContent())
        ->toContain('Documento no disponible')
        ->toContain('pídele al vendedor un nuevo link')
        ->not->toContain('Esta dirección no existe');
});
