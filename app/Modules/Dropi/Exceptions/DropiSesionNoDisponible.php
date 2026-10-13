<?php

namespace App\Modules\Dropi\Exceptions;

/**
 * No hay sesión válida con Dropi (nunca se conectó, expiró o Dropi la revocó).
 * La salida es reconectar desde Dropi → Conexión Dropi en el panel.
 */
class DropiSesionNoDisponible extends \RuntimeException {}
