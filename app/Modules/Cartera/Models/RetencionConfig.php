<?php

namespace App\Modules\Cartera\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 4 · B.3 · Regla parametrizable de retención.
 */
class RetencionConfig extends Model
{
    protected $table = 'retenciones_config';
    protected $fillable = [
        'tipo', 'concepto', 'ciudad', 'base_minima', 'tarifa_pct',
        'cuenta_puc', 'siigo_tax_id', 'activa', 'notas',
    ];
    protected $casts = [
        'base_minima' => 'decimal:2',
        'tarifa_pct' => 'decimal:3',
        'activa' => 'boolean',
    ];
}
