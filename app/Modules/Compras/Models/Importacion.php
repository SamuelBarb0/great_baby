<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\Compras\Enums\EstadoImportacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Importacion extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'compras_importaciones';

    // Re-audit M2 R3 PATRÓN N (SEG-C1 / DATOS-C3) · $guarded + booted::saving
    //   con inmutabilidad post-Liquidada — antes el $fillable dejaba tasa/moneda/
    //   fecha_liquidacion mutables tras liquidar y Aracely podía cambiar la tasa
    //   post-cierre, o retro-fechar la liquidación a un periodo cerrado.
    protected $guarded = ['id'];

    protected $casts = [
        'fecha_zarpe' => 'date',
        'eta' => 'date',
        'fecha_llegada' => 'date',
        'fecha_nacionalizacion' => 'date',
        'fecha_liquidacion' => 'date',
        'estado' => EstadoImportacion::class,
        // De quién es el contenedor: su liquidación va al libro de esa línea.
        'linea_negocio' => \App\Modules\Contabilidad\Enums\LineaNegocio::class,
        'tasa_cambio_liquidacion' => 'decimal:6',
        'valor_fob' => 'decimal:2',
        'valor_gastos' => 'decimal:2',
        'valor_arancel' => 'decimal:2',
        'valor_iva_importacion' => 'decimal:2',
        'valor_total_costo' => 'decimal:2',
    ];

    /**
     * Re-audit M2 R3 PATRÓN N · inmutabilidad post-Liquidada.
     *   Bloquea manipulación de tasa/moneda/fecha_liquidacion tras liquidar.
     */
    protected static function booted(): void
    {
        static::saving(function (Importacion $imp) {
            if (! $imp->exists) return;

            $original = $imp->getOriginal('estado');
            $estadoAnterior = $original instanceof EstadoImportacion
                ? $original
                : ($original ? EstadoImportacion::tryFrom($original) : null);

            // Guard: si ya está Liquidada, congelamos casi todo. Solo `observaciones`
            // (para agregar notas post-facto) puede cambiar.
            if ($estadoAnterior === EstadoImportacion::Liquidada) {
                // Los campos de SIIGO tienen que poder escribirse DESPUÉS de
                // liquidar: el comprobante se manda a SIIGO justamente cuando
                // la importación ya quedó liquidada, y al volver hay que anotar
                // el id del journal. Con el guard cerrado pasaba lo peor: el
                // comprobante SÍ se creaba en SIIGO, el ERP no podía marcarlo,
                // el job se reintentaba y duplicaba el asiento allá. Es el
                // mismo caso que ya se arregló en RecepcionCompra.
                $mutablesPostLiquidacion = [
                    'observaciones', 'updated_at',
                    'siigo_journal_id', 'siigo_sync_at',
                ];
                foreach ($imp->getDirty() as $campo => $_) {
                    if (! in_array($campo, $mutablesPostLiquidacion, true)) {
                        throw new \RuntimeException(sprintf(
                            'Importación %s (liquidada): campo "%s" es inmutable.',
                            $imp->getOriginal('numero'), $campo,
                        ));
                    }
                }
            }
        });
    }

    public function ordenes(): BelongsToMany
    {
        return $this->belongsToMany(OrdenCompra::class, 'compras_importacion_ordenes', 'importacion_id', 'orden_id')
            ->withPivot('peso_kg', 'volumen_m3')
            ->withTimestamps();
    }

    public function gastos(): HasMany
    {
        return $this->hasMany(GastoImportacion::class, 'importacion_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(ImportacionDocumento::class)->latest('id');
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(ImportacionEtapa::class);
    }

    public function declaraciones(): HasMany
    {
        return $this->hasMany(ImportacionDeclaracion::class)->orderBy('id');
    }

    public function inspecciones(): HasMany
    {
        return $this->hasMany(ImportacionInspeccion::class)->latest('fecha');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(ImportacionLinea::class, 'importacion_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function liquidador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liquidada_por');
    }

    /**
     * Re-audit M2 PATRÓN C + I · consecutivo atómico en TZ Colombia
     *   (mismo patrón que OrdenCompra::siguienteNumero — evita race y salto de año).
     */
    public static function siguienteNumero(): string
    {
        $year = now('America/Bogota')->year;
        return \Illuminate\Support\Facades\DB::transaction(function () use ($year) {
            $ultimo = static::query()
                ->where('numero', 'like', "IMP-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('numero');
            $seq = $ultimo ? ((int) substr($ultimo, -6)) + 1 : 1;
            return sprintf('IMP-%d-%06d', $year, $seq);
        });
    }

    /**
     * Cuánto falta para que llegue, en palabras que sirvan en la bodega.
     *
     * La pantalla tenía la fecha estimada de llegada escondida detrás de «Ver
     * más datos», y nunca decía cuánto faltaba: quien organiza la bodega leía
     * «ETA: 11/dic/2026» y tenía que hacer la cuenta de cabeza contra hoy,
     * todos los días. Y esa cuenta es la que decide cuándo empieza a hacer
     * espacio, a pedir gente y a planear la descarga.
     *
     * Devuelve null cuando todavía no hay fecha estimada: entonces no se
     * inventa un número, se dice que falta ponerla.
     *
     * @return array{dias:int, estado:string, frase:string}|null
     */
    public function cuantoFaltaParaLlegar(): ?array
    {
        if ($this->fecha_llegada) {
            $dias = (int) round($this->fecha_llegada->diffInDays(now()));

            return [
                'dias' => $dias,
                'estado' => 'llego',
                'frase' => $dias === 0 ? 'Llegó hoy' : "Llegó hace {$dias} día".($dias === 1 ? '' : 's'),
            ];
        }

        if (! $this->eta) {
            return null;
        }

        // En días enteros: `diffInDays` es flotante en Carbon 3 y «faltan
        // 22.6 días» no le sirve a nadie para planear una descarga.
        $dias = (int) round(now()->startOfDay()->diffInDays($this->eta->startOfDay(), false));

        if ($dias < 0) {
            $atraso = abs($dias);

            return [
                'dias' => $atraso,
                'estado' => 'atrasado',
                'frase' => "Atrasado {$atraso} día".($atraso === 1 ? '' : 's').' sobre la fecha estimada',
            ];
        }

        /*
         * Cuántos días antes se da el contenedor por encima.
         *
         * Lo manda la regla `logistica.dias_aviso_contenedor` y no un 7 clavado
         * acá: de ese número depende con cuánta anticipación la bodega pide la
         * gente para descargar, y eso no lo decide el programador —depende de
         * cuánto se demore conseguir personal, que cambia con la temporada—.
         *
         * Se lee SIN default en la llamada: en este ERP el default de
         * `setting()` le gana al de la tabla de reglas, así que pasarlo acá
         * haría que cambiar la regla no sirviera de nada.
         */
        $aviso = max(0, (int) setting('logistica.dias_aviso_contenedor'));
        $espacio = max($aviso, (int) setting('logistica.dias_espacio_contenedor'));

        return [
            'dias' => $dias,
            'estado' => $dias <= $aviso ? 'cerca' : 'en_camino',
            // Los dos umbrales viajan con la respuesta para que la pantalla no
            // tenga su propia copia: si se configuran en 10 y 20, el consejo
            // que lee don Jorge cambia con ellos.
            'dias_aviso' => $aviso,
            'dias_espacio' => $espacio,
            'frase' => match (true) {
                $dias === 0 => 'Llega hoy',
                $dias === 1 => 'Llega mañana',
                default => "Llega en {$dias} días",
            },
        ];
    }

    public function diasEnTransito(): ?int
    {
        if (! $this->fecha_zarpe) {
            return null;
        }
        $fin = $this->fecha_llegada ?? now();

        // Entero: `diffInDays` es flotante en Carbon 3 y el contenedor en
        // tránsito se mostraba con decimales («12.7083 días»).
        return (int) round($this->fecha_zarpe->diffInDays($fin));
    }
}
