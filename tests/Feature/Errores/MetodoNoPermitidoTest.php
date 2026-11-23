<?php

/**
 * 405 (23-nov-2026): la clienta vio «Oops! An Error Occurred» en inglés en la
 * dirección interna de Livewire y no quedó rastro. Ahora se anota y se explica.
 */

use Illuminate\Support\Facades\Log;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

it('abrir la dirección interna de Livewire en el navegador explica qué pasó, ofrece volver y queda en el log', function () {
    Log::spy();
    $user = App\Models\User::factory()->create();

    $this->actingAs($user)
        ->get(EndpointResolver::updatePath(), ['referer' => url('/admin/compras-nueva-oc-dropi')])
        ->assertStatus(405)
        ->assertSee('La página se desactualizó')
        ->assertSee('/admin/compras-nueva-oc-dropi', false)
        ->assertDontSee('Oops');

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => str_contains($msg, '405')
        && $ctx['metodo'] === 'GET' && $ctx['usuario'] === $user->email
        && str_contains($ctx['desde'], 'compras-nueva-oc-dropi'));
});

it('sin pantalla de origen vuelve al inicio del panel, nunca a otra dirección interna', function () {
    $this->get(EndpointResolver::updatePath(), ['referer' => url(EndpointResolver::updatePath())])
        ->assertStatus(405)
        ->assertSee('href="/admin"', false);
});
