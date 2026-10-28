<?php

namespace App\Models;

use App\Modules\Cartera\Models\CondicionCredito;
use App\Modules\Cartera\Models\FacturaVenta;
use App\Modules\Catalogo\Models\ListaPrecios;
use App\Modules\Portal\Models\PedidoCliente;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Contacto unificado — cliente, proveedor, empleado, vendedor dropi.
 * Los flags booleanos definen los roles que cumple.
 * Implementa Authenticatable para el portal B2B (guard 'cliente').
 */
class Contacto extends Model implements AuditableContract, AuthenticatableContract, CanResetPasswordContract
{
    use Auditable, Authenticatable, CanResetPassword, SoftDeletes;

    protected $table = 'contactos';

    // QA-D Bloque2: fillable con campos operativos. Los REALMENTE sensibles del portal (password,
    // portal_habilitado, reset_token) SE QUITAN del fillable — deben setearse por forceFill()
    // en código admin explícito. Evita `Contacto::update($request->all())` que otorgue portal.
    protected $fillable = [
        'tipo_documento', 'numero_documento', 'nombre_completo', 'razon_social',
        'email', 'telefono', 'direccion', 'ciudad', 'departamento',
        'es_cliente', 'es_cliente_b2b', 'es_proveedor', 'es_empleado', 'es_vendedor_dropi',
        'regimen_iva', 'retenciones_default', 'activo',
        // MKT-6 · de dónde llegó (WhatsApp, página, referido…) y el detalle
        //   libre (quién lo refirió, qué campaña, qué feria).
        'fuente', 'fuente_detalle',
        // CRM segmentación (setea el motor, no el request web)
        'segmento', 'segmento_score', 'ticket_promedio', 'ultima_compra_at',
        'total_comprado_ytd', 'segmentado_at',
        // Portal B2B — operativos, no auth
        'lista_precios_id', 'ultimo_login_at',
        // Cartera de clientes · sólo lo escribe el formulario de gerencia (el
        // campo no existe en el form para un Vendedor) o el "claim" del primer
        // pedido. Ningún endpoint hace `update($request->all())` sobre Contacto.
        'vendedor_id',
        // password, portal_habilitado, reset_token, reset_token_at INTENCIONALMENTE OMITIDOS.
    ];

    protected $hidden = ['password', 'remember_token', 'reset_token'];

    /** MKT-6 · Canales por los que llega un cliente. */
    public const FUENTES = [
        'whatsapp' => 'WhatsApp',
        'pagina_web' => 'Página web',
        'referido' => 'Referido',
        'redes_sociales' => 'Redes sociales',
        'vendedor' => 'Vendedor en terreno',
        'feria' => 'Feria o evento',
        'llamada' => 'Llamada',
        'otro' => 'Otro',
    ];

    protected $casts = [
        'es_cliente' => 'boolean',
        'es_cliente_b2b' => 'boolean',
        'es_proveedor' => 'boolean',
        'es_empleado' => 'boolean',
        'es_vendedor_dropi' => 'boolean',
        'activo' => 'boolean',
        'retenciones_default' => 'array',
        'portal_habilitado' => 'boolean',
        'ultimo_login_at' => 'datetime',
        'reset_token_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function listaPrecios(): BelongsTo
    {
        return $this->belongsTo(ListaPrecios::class, 'lista_precios_id');
    }

    /**
     * Alias de `listaPrecios`. Varias pantallas cargan `with('lista:id,nombre')`
     * y sin esto Eloquent lanzaba BadMethodCallException al abrir el armador de
     * pedidos del vendedor.
     */
    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaPrecios::class, 'lista_precios_id');
    }

    /** Vendedor dueño de la cuenta. NULL = cliente libre. */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    /**
     * Clientes que un vendedor puede trabajar: los suyos y los que no tienen
     * dueño. Los de otro vendedor quedan fuera para que nadie le levante el
     * pedido —y la comisión— a un compañero.
     *
     * Gerencia no pasa por acá: ve todo (`esAracely()` primero).
     */
    public function scopeDeVendedor($q, int $vendedorId)
    {
        return $q->where(function ($w) use ($vendedorId) {
            $w->where('vendedor_id', $vendedorId)->orWhereNull('vendedor_id');
        });
    }

    /** ¿Este vendedor puede trabajar esta cuenta? */
    public function esTrabajablePor(?User $u): bool
    {
        if (! $u) return false;
        if ($u->esAracely()) return true;

        return $this->vendedor_id === null || (int) $this->vendedor_id === (int) $u->id;
    }

    public function pedidosB2b(): HasMany
    {
        return $this->hasMany(PedidoCliente::class, 'contacto_id');
    }

    public function condicionVigente(): HasOne
    {
        return $this->hasOne(CondicionCredito::class)
            ->where('activa', true)
            ->latest('vigente_desde');
    }

    public function condiciones(): HasMany
    {
        return $this->hasMany(CondicionCredito::class);
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(FacturaVenta::class);
    }

    public function nombreDisplay(): string
    {
        return $this->razon_social ?: $this->nombre_completo;
    }
}
