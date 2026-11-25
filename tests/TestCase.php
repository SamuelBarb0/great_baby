<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Las reglas se memorizan en una estática que dura todo el proceso: la
        //   base se limpia entre tests, la estática no, y una regla prendida en
        //   un test se colaba en los siguientes (24-nov-2026).
        \App\Support\Reglas::limpiarRequestCache();
    }
}
