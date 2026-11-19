<?php

use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * La cuenta contable que manda, leída de la regla y no escrita a mano.
 *
 * Las pruebas de contabilidad afirmaban '1305', '1105', '2365'… mientras
 * `Reglas::DEFAULTS` registraba 130505, 110505 y 236575. Coincidían con el
 * código porque el código pasaba EL MISMO literal como default de `setting()`,
 * y en la base de pruebas —recién migrada, sin filas en `reglas_negocio`— ese
 * literal gana. En producción la fila sí existe, así que el sistema escribía
 * la cuenta de seis dígitos y la suite verificaba la de cuatro: las pruebas
 * estaban en verde sobre cuentas que producción nunca usó.
 *
 * Leyendo la regla, la prueba sigue diciendo lo que de verdad importa —que la
 * CxC se acredita, que la retención no se pierde— sin casarse con un número
 * que todavía está por confirmar con la contadora.
 */
function cta(string $nombre): string
{
    return (string) setting("contable.{$nombre}");
}
