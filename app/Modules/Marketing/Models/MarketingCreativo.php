<?php

namespace App\Modules\Marketing\Models;

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Dropi\Models\Producto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * MKT-2 · Un creativo de marketing (foto, video, reel) ligado a un producto
 * y/o al contenedor del que salió.
 *
 * Se sube UNA vez y cada área lo ve según su permiso:
 *   interno  · solo Marketing (material en edición).
 *   solo_ver · vendedores (mayorista) y dropship lo ven en la app, sin botón
 *              de descarga.
 *   descarga · además lo pueden bajar para mandárselo a sus clientes.
 *
 * El archivo es liviano y vive en el disco privado; lo pesado (videos largos,
 * carpetas de sesión) va como enlace externo (Drive, Dropbox, YouTube).
 */
class MarketingCreativo extends Model
{
    use SoftDeletes;

    public const TIPOS = ['video', 'reel', 'imagen', 'otro'];

    public const PERMISOS = ['interno', 'solo_ver', 'descarga'];

    /** Tope del archivo subido (KB). Lo que pese más, por enlace. */
    public const MAX_KB = 51200;

    public const DISCO = 'local';

    protected $table = 'marketing_creativos';

    protected $fillable = [
        'producto_id', 'importacion_id', 'post_id', 'tipo', 'titulo',
        'archivo_path', 'archivo_nombre', 'mime', 'tamano', 'enlace_externo',
        'permiso', 'subido_por',
    ];

    protected $casts = [
        'tamano' => 'integer',
    ];

    protected static function booted(): void
    {
        // El archivo se va con el registro. Con borrado suave se queda (se
        // puede restaurar); solo el borrado definitivo lo elimina del disco.
        static::forceDeleted(function (MarketingCreativo $c) {
            if ($c->archivo_path) {
                Storage::disk(self::DISCO)->delete($c->archivo_path);
            }
        });
    }

    public function producto(): BelongsTo { return $this->belongsTo(Producto::class); }
    public function importacion(): BelongsTo { return $this->belongsTo(Importacion::class); }
    public function post(): BelongsTo { return $this->belongsTo(MarketingPost::class, 'post_id'); }
    public function autor(): BelongsTo { return $this->belongsTo(User::class, 'subido_por'); }

    /** Quien administra los creativos: sube, cambia permisos, borra. */
    public static function puedeGestionar(?User $u): bool
    {
        return Permisos::puede($u, 'marketing') || Permisos::puede($u, 'marketing_parrilla');
    }

    /** Lo que cada quien puede ver: Marketing todo; Ventas lo compartido. */
    public function scopeVisiblesPara(Builder $q, ?User $u): Builder
    {
        if (self::puedeGestionar($u)) {
            return $q;
        }
        if (Permisos::puede($u, 'marketing_material')) {
            return $q->whereIn('permiso', ['solo_ver', 'descarga']);
        }

        return $q->whereRaw('1 = 0');
    }

    public function puedeVer(?User $u): bool
    {
        return self::puedeGestionar($u)
            || (Permisos::puede($u, 'marketing_material') && $this->permiso !== 'interno');
    }

    public function puedeDescargar(?User $u): bool
    {
        return self::puedeGestionar($u)
            || (Permisos::puede($u, 'marketing_material') && $this->permiso === 'descarga');
    }

    public function esImagen(): bool { return str_starts_with((string) $this->mime, 'image/'); }
    public function esVideo(): bool { return str_starts_with((string) $this->mime, 'video/'); }

    /** Lo que la pantalla necesita de un creativo, con lo que este usuario puede hacer. */
    public function paraVista(?User $u): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'permiso' => $this->permiso,
            'producto' => $this->producto ? ['id' => $this->producto->id, 'nombre' => $this->producto->nombre, 'referencia' => $this->producto->referencia] : null,
            'contenedor' => $this->importacion ? ['id' => $this->importacion->id, 'numero' => $this->importacion->numero, 'contenedor' => $this->importacion->contenedor] : null,
            'post_id' => $this->post_id,
            'archivo' => (bool) $this->archivo_path,
            'archivo_nombre' => $this->archivo_nombre,
            'mime' => $this->mime,
            'tamano' => $this->tamano,
            'es_imagen' => $this->esImagen(),
            'es_video' => $this->esVideo(),
            'enlace_externo' => $this->enlace_externo,
            'ver_url' => $this->archivo_path ? route('app.marketing.creativo.ver', $this->id) : null,
            'descargar_url' => $this->archivo_path && $this->puedeDescargar($u) ? route('app.marketing.creativo.descargar', $this->id) : null,
            'autor' => $this->autor?->name,
            'fecha' => $this->created_at?->format('Y-m-d'),
        ];
    }
}
