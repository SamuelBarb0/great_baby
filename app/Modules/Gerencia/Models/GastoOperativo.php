<?php

namespace App\Modules\Gerencia\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GastoOperativo extends Model
{
    use SoftDeletes;
    protected $table = 'gastos_operativos';
    protected $fillable = [
        'numero', 'fecha', 'categoria', 'descripcion', 'monto',
        'proveedor', 'factura_ref', 'metodo_pago', 'tipo',
        'solicita_id', 'aprueba_id', 'estado', 'notas', 'adjuntos',
        // Los dos ejes y la cuenta de gasto: las columnas existian desde la
        // migracion de octubre pero el modelo no las veia, asi que ningun
        // flujo podia llenarlas.
        'linea_negocio', 'destino_fiscal', 'motivo_destino', 'cuenta_puc',
        // De qué caja salió la plata, cuando se paga en efectivo. Sin esto el
        // gasto no deja rastro de cuál cajón se vació y el arqueo del día no
        // tiene con qué explicar los billetes que faltan.
        'caja_id',
    ];
    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'adjuntos' => 'array',
        // Sin el cast vuelven como string y cualquier `->label()` revienta.
        'linea_negocio' => \App\Modules\Contabilidad\Enums\LineaNegocio::class,
        'destino_fiscal' => \App\Modules\Contabilidad\Enums\DestinoFiscal::class,
    ];
    public function solicita(): BelongsTo { return $this->belongsTo(User::class, 'solicita_id'); }
    public function aprueba(): BelongsTo { return $this->belongsTo(User::class, 'aprueba_id'); }
}
