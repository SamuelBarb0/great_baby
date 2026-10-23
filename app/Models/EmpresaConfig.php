<?php

namespace App\Models;

use App\Modules\Siigo\Models\SiigoConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una razón social: Great Baby (la principal), Arsutex o la del e-commerce.
 *
 * Hasta el multiempresa había una sola fila y todo leía `current()`. Sigue
 * existiendo y sigue devolviendo la principal, así que lo que no sabe de
 * empresas (reportes, contabilidad, plantillas) ve exactamente lo de antes.
 * Lo que sí distingue —pedidos, facturas, su PDF y su emisión a SIIGO— usa
 * `deFactura()` / `porId()`.
 */
class EmpresaConfig extends Model
{
    protected $table = 'empresa_config';

    protected $guarded = ['id'];

    protected $casts = [
        'es_principal' => 'boolean',
        'activa' => 'boolean',
    ];

    /** La principal (Great Baby). */
    public static function current(): self
    {
        $c = static::query()->orderByDesc('es_principal')->orderBy('id')->first();
        return $c ?? static::create([
            'codigo' => 'great_baby',
            'es_principal' => true,
            'razon_social' => 'GREAT BABY S.A.S.',
            'nit' => '901.738.354-7',
            'regimen' => 'Responsable de IVA',
        ]);
    }

    /** La empresa de un id guardado en un pedido o factura; NULL = la principal. */
    public static function porId(?int $id): self
    {
        return ($id ? static::find($id) : null) ?? static::current();
    }

    public static function porCodigo(string $codigo): ?self
    {
        return static::where('codigo', $codigo)->first();
    }

    /** @return Collection<int, self> */
    public static function activas(): Collection
    {
        return static::where('activa', true)->orderByDesc('es_principal')->orderBy('id')->get();
    }

    public function siigo(): HasOne
    {
        return $this->hasOne(SiigoConfig::class, 'empresa_id');
    }

    public function nombreCorto(): string
    {
        return $this->nombre_comercial ?: $this->razon_social;
    }

    public function nitSinDv(): string
    {
        $nit = (string) $this->nit;
        $pos = strpos($nit, '-');
        $sin = $pos !== false ? substr($nit, 0, $pos) : $nit;
        return (string) preg_replace('/[^0-9]/', '', $sin);
    }
}
