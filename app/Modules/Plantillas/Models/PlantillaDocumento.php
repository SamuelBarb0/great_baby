<?php

namespace App\Modules\Plantillas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class PlantillaDocumento extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'plantillas_documento';

    protected $fillable = [
        'nombre', 'tipo', 'predeterminada', 'activa', 'config',
    ];

    protected $casts = [
        'predeterminada' => 'boolean',
        'activa' => 'boolean',
        'config' => 'array',
    ];

    /** Config default aplicada como fallback de campos que falten en la BD. */
    public static function defaults(): array
    {
        return [
            // Fix demo · `#111` (3 dígitos) no pasaba el validator regex de
            //   PlantillasController que exige `^#[0-9a-fA-F]{6}$`. Al guardar
            //   una plantilla nueva o defaults se disparaba "validation.regex"
            //   raw. Colores siempre en formato hex de 6 dígitos.
            'colores' => ['primario' => '#b45309', 'secundario' => '#78350f', 'texto' => '#111111', 'acento' => '#fef3c7'],
            'tipografia' => 'sans',
            'layout' => 'espacioso',
            'logo_url' => null,
            'logo_alto_px' => 60,
            'encabezado_extra' => '',
            'encabezado_alineacion' => 'izquierda',
            'mostrar_qr' => true,
            'mostrar_bloque_banco' => true,
            'mostrar_bloque_retenciones' => true,
            'mostrar_bloque_notas' => true,
            'mostrar_totales_en_letras' => false,
            'pie_html' => '',
            'terminos_condiciones' => '',
            'sello_texto' => '',
            'watermark_activo' => false,
            // MKT-7 · firma al pie (imagen opcional + nombre y cargo).
            'mostrar_firma' => false,
            'firma_url' => null,
            'firma_alto_px' => 50,
            'firma_nombre' => '',
            'firma_cargo' => '',
        ];
    }

    /** Peso máximo de una imagen embebida (logo o firma). */
    public const IMAGEN_MAX_BYTES = 1_000_000;

    /**
     * La imagen lista para dompdf, que corre con `enable_remote = false`: una
     * URL https la ignora en silencio y el logo nunca aparecía en el PDF. Se
     * descarga aquí y se entrega como data URI; si no se puede, null (el PDF
     * sale sin imagen en vez de romperse).
     */
    public static function imagenParaPdf(?string $src): ?string
    {
        $src = trim((string) $src);
        if ($src === '') {
            return null;
        }
        if (str_starts_with($src, 'data:image/')) {
            return $src;
        }
        if (! self::urlSegura($src)) {
            return null;
        }

        return Cache::remember('plantilla.img.'.sha1($src), 86400, function () use ($src) {
            try {
                $res = Http::timeout(6)->withOptions(['allow_redirects' => false])->get($src);
            } catch (Throwable) {
                return null;
            }
            $tipo = strtolower(trim(explode(';', (string) $res->header('Content-Type'))[0]));
            if (! $res->successful() || ! str_starts_with($tipo, 'image/') || $tipo === 'image/svg+xml') {
                return null;
            }
            $bytes = $res->body();
            if ($bytes === '' || strlen($bytes) > self::IMAGEN_MAX_BYTES) {
                return null;
            }

            return 'data:'.$tipo.';base64,'.base64_encode($bytes);
        });
    }

    /**
     * Solo https hacia hosts públicos o data:image. Bloquea SSRF: localhost,
     * metadata y cualquier host que RESUELVA a una IP privada o reservada.
     */
    public static function urlSegura(string $url): bool
    {
        if (str_starts_with($url, 'data:image/')) {
            return true;
        }
        if (! str_starts_with($url, 'https://')) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }
        if (in_array(strtolower($host), ['localhost', 'metadata.google.internal', 'metadata'], true)) {
            return false;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false; // no resolvió
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** Config efectiva con las imágenes ya embebidas, para renderizar el PDF. */
    public static function configParaPdf(array $cfg): array
    {
        $cfg['logo_url'] = self::imagenParaPdf($cfg['logo_url'] ?? null);
        $cfg['firma_url'] = self::imagenParaPdf($cfg['firma_url'] ?? null);

        return $cfg;
    }

    /** Config efectiva (merge default + BD). */
    public function configEfectiva(): array
    {
        return array_replace_recursive(self::defaults(), (array) $this->config);
    }

    /** La plantilla predeterminada por tipo. Cacheada. */
    public static function paraTipo(string $tipo): ?self
    {
        return Cache::remember("plantilla.pred.{$tipo}", 300, function () use ($tipo) {
            return self::where('tipo', $tipo)
                ->where('activa', true)
                ->where('predeterminada', true)
                ->first()
                ?? self::where('tipo', $tipo)->where('activa', true)->first();
        });
    }

    protected static function booted(): void
    {
        $forget = function (self $p) { Cache::forget("plantilla.pred.{$p->tipo}"); };
        static::saved($forget);
        static::deleted($forget);
    }
}
