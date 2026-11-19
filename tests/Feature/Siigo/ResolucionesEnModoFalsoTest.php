<?php

/**
 * Poder recorrer el flujo completo sin cuenta de SIIGO.
 *
 * Great Baby todavía no tiene SIIGO de producción, y el sandbox no sirve para
 * esto: es compartido —tiene 277 tipos de factura de decenas de empresas
 * ajenas— y una resolución de la DIAN está atada a un NIT, que allá no es el
 * de ellos. Sus prefijos no están ni se pueden cargar: el ERP sólo LEE tipos
 * de documento, nunca los crea.
 *
 * Así que el modo falso trae las resoluciones de verdad —FEGR para Great Baby
 * y ECOM para Dropi y el e-commerce, autorizadas el 2026-11-10—. Si los
 * prefijos fueran de fantasía, la prueba no diría nada sobre lo que va a pasar
 * el día que se conecte producción.
 */

use App\Modules\Siigo\Models\SiigoDocumentType;

it('trae las resoluciones reales de Great Baby', function () {
    config()->set('siigo.driver', 'fake');

    $this->artisan('siigo:sync-document-types --only=FV')->assertSuccessful();

    $porCodigo = SiigoDocumentType::where('type', 'FV')->pluck('name', 'code');

    expect($porCodigo)->toHaveKey('FEGR')
        ->and($porCodigo)->toHaveKey('ECOM')
        ->and($porCodigo['FEGR'])->toContain('Great Baby')
        ->and($porCodigo['ECOM'])->toContain('Dropi');
});

it('la de Arsutex llega inactiva: existe pero no se usa', function () {
    config()->set('siigo.driver', 'fake');

    $this->artisan('siigo:sync-document-types --only=FV')->assertSuccessful();

    expect(SiigoDocumentType::where('code', 'FEAR')->value('active'))->toBeFalsy();
});

it('el filtro por tipo se respeta, como lo hace SIIGO', function () {
    /*
     * El falso devolvía los SEIS tipos para cualquiera que se pidiera: la nota
     * crédito quedaba guardada como factura de venta, después como nota débito,
     * y así. El catálogo local terminaba con filas inventadas y la prueba decía
     * que todo andaba bien.
     */
    config()->set('siigo.driver', 'fake');

    $this->artisan('siigo:sync-document-types --only=FV')->assertSuccessful();

    expect(SiigoDocumentType::where('type', 'FV')->count())->toBe(3)
        ->and(SiigoDocumentType::where('type', 'FV')->pluck('code')->sort()->values()->all())
        ->toBe(['ECOM', 'FEAR', 'FEGR']);

    $this->artisan('siigo:sync-document-types --only=NC')->assertSuccessful();

    // La nota crédito es UNA, no seis.
    expect(SiigoDocumentType::where('type', 'NC')->count())->toBe(1);
});
