<?php

namespace App\Support;

use RuntimeException;

/**
 * Un paso de un flujo que no se puede dar ahora (ya estaba hecho, falta otra
 * firma, ya está en SIIGO…). Lleva el mensaje para la persona y el tipo de
 * aviso, para que /app y el panel de Dropi digan lo mismo.
 */
class PasoNoPermitido extends RuntimeException
{
    public function __construct(string $mensaje, public readonly string $tipo = 'error')
    {
        parent::__construct($mensaje);
    }

    public static function info(string $mensaje): self
    {
        return new self($mensaje, 'info');
    }
}
