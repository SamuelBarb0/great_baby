<?php

namespace App\Modules\Compras\Services;

use App\Auth\Permisos;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EtapaContenedor;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\ImportacionDeclaracion;
use App\Modules\Compras\Models\ImportacionEtapa;
use App\Modules\Compras\Models\ImportacionInspeccion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Por dónde va cada contenedor, cuánto lleva ahí y si ya se atrasó.
 */
class SeguimientoContenedor
{
    /** Con menos contenedores medidos, un promedio dice más del azar que del proceso. */
    public const MUESTRAS_MINIMAS = 3;

    /** Cada cuántos días se repite el aviso de un contenedor que sigue atrasado. */
    public const REPETIR_AVISO_CADA = 3;

    /** Con cuántos días de anticipación se le avisa a quien recibe. */
    public const AVISAR_LLEGADA_ANTES = 3;

    /** @var array<string, array{promedio: float, muestras: int}>|null */
    private ?array $promedios = null;

    /**
     * Liga una orden al contenedor. Una orden va en un solo contenedor: en dos,
     * la liquidación metería su mercancía dos veces al kardex.
     */
    public function ligarOrden(Importacion $imp, OrdenCompra $orden): void
    {
        $this->exigirAbierta($imp);

        if (! $orden->esImportacion()) {
            throw new InvalidArgumentException("La orden {$orden->numero} no es de importación.");
        }
        $estado = is_object($orden->estado) ? $orden->estado->value : $orden->estado;
        if (! in_array($estado, ['aprobada', 'parcial', 'recibida'], true)) {
            throw new InvalidArgumentException("La orden {$orden->numero} todavía no está aprobada.");
        }

        $otro = $orden->belongsToMany(Importacion::class, 'compras_importacion_ordenes', 'orden_id', 'importacion_id')->first();
        if ($otro) {
            throw new InvalidArgumentException("La orden {$orden->numero} ya va en el contenedor {$otro->numero}.");
        }

        // La liquidación va entera al libro del contenedor: una orden de la otra
        // línea saldría del 1465 de una empresa y entraría al 1435 de la otra (8-nov).
        $lineaOrden = $orden->linea_negocio ?? \App\Modules\Contabilidad\Enums\LineaNegocio::GreatBaby;
        $lineaContenedor = $imp->linea_negocio ?? \App\Modules\Contabilidad\Enums\LineaNegocio::GreatBaby;
        if ($lineaOrden !== $lineaContenedor) {
            throw new InvalidArgumentException("La orden {$orden->numero} es de {$lineaOrden->label()} y este contenedor de {$lineaContenedor->label()}.");
        }

        // Un contenedor se liquida con una sola tasa: las órdenes comparten moneda.
        if ($imp->ordenes()->exists()) {
            if ($orden->moneda !== $imp->moneda_origen) {
                throw new InvalidArgumentException("La orden {$orden->numero} está en {$orden->moneda} y este contenedor en {$imp->moneda_origen}.");
            }
        } else {
            // La tasa va con su moneda. El formulario de contenedor propone USD a
            // 4.000: si la orden es en RMB y esa tasa se quedara, cada yuan
            // valdría 4.000 pesos.
            if ($imp->moneda_origen !== $orden->moneda || (float) $imp->tasa_cambio_liquidacion <= 0) {
                $imp->tasa_cambio_liquidacion = $orden->tasa_cambio;
            }
            $imp->moneda_origen = $orden->moneda;
            $imp->save();
        }

        $orden->loadMissing('items');
        $imp->ordenes()->attach($orden->id, [
            'volumen_m3' => round($orden->items->sum(fn ($i) => (float) $i->cajas * (float) $i->cbm_caja), 4),
            'peso_kg' => round($orden->items->sum(fn ($i) => (float) $i->cajas * (float) $i->peso_caja_kg), 3),
        ]);
        app(\App\Modules\Marketing\Services\TableroLanzamiento::class)->alDiaConCompras($imp);
    }

    public function soltarOrden(Importacion $imp, OrdenCompra $orden): void
    {
        $this->exigirAbierta($imp);
        $imp->ordenes()->detach($orden->id);
    }

    /**
     * Marca una etapa, o corrige su fecha si ya estaba. Se puede saltar etapas:
     * los contenedores que ya vienen andando entran al sistema a mitad de camino.
     */
    public function marcarEtapa(Importacion $imp, EtapaContenedor $etapa, Carbon $fecha, ?string $notas, ?int $userId): ImportacionEtapa
    {
        if ($fecha->copy()->startOfDay()->gt($this->hoy())) {
            throw new InvalidArgumentException('La fecha no puede ser futura: una etapa se marca cuando ya pasó.');
        }

        $marcadas = $this->fechas($imp);
        foreach ($marcadas as $valor => $otra) {
            $otraEtapa = EtapaContenedor::from($valor);
            if ($otraEtapa->posicion() < $etapa->posicion() && $fecha->copy()->startOfDay()->lt($otra)) {
                throw new InvalidArgumentException("La fecha es anterior a «{$otraEtapa->label()}» ({$otra->toDateString()}).");
            }
            if ($otraEtapa->posicion() > $etapa->posicion() && $fecha->copy()->startOfDay()->gt($otra)) {
                throw new InvalidArgumentException("La fecha es posterior a «{$otraEtapa->label()}» ({$otra->toDateString()}).");
            }
        }

        if ($etapa->posicion() >= EtapaContenedor::Levante->posicion()) {
            $pendientes = $imp->declaraciones()->whereNull('fecha_levante')->count();
            if ($pendientes > 0) {
                throw new InvalidArgumentException("Faltan {$pendientes} declaración(es) sin levante.");
            }
        }

        $fila = ImportacionEtapa::updateOrCreate(
            ['importacion_id' => $imp->id, 'etapa' => $etapa->value],
            ['fecha' => $fecha->toDateString(), 'notas' => $notas, 'registrado_por' => $userId],
        );

        $this->sincronizar($imp);

        return $fila;
    }

    public function quitarEtapa(Importacion $imp, EtapaContenedor $etapa): void
    {
        ImportacionEtapa::where('importacion_id', $imp->id)->where('etapa', $etapa->value)->first()?->delete();
        if ($imp->estado !== EstadoImportacion::Liquidada && $imp->estado !== EstadoImportacion::Cerrada && $etapa->campoFecha()) {
            $imp->{$etapa->campoFecha()} = null;
        }
        $this->sincronizar($imp);
    }

    /**
     * Pasa las etapas a las fechas y al estado del contenedor. Una importación
     * liquidada o cerrada ya no se toca: el modelo la congela a propósito.
     */
    public function sincronizar(Importacion $imp): void
    {
        if (in_array($imp->estado, [EstadoImportacion::Liquidada, EstadoImportacion::Cerrada], true)) {
            return;
        }

        $filas = ImportacionEtapa::where('importacion_id', $imp->id)->get();
        foreach ($filas as $fila) {
            if ($campo = $fila->etapa->campoFecha()) {
                $imp->{$campo} = $fila->fecha;
            }
        }

        $ultima = $filas->sortBy(fn ($f) => $f->etapa->posicion())->last();
        $imp->estado = $ultima?->etapa->estado() ?? EstadoImportacion::EnTransito;
        $imp->save();
    }

    /**
     * Días reales entre cada etapa y la anterior, medidos en todos los
     * contenedores que pasaron por las dos.
     *
     * @return array<string, array{promedio: float, muestras: int}>
     */
    public function promedios(): array
    {
        if ($this->promedios !== null) {
            return $this->promedios;
        }

        $dias = [];
        foreach (ImportacionEtapa::query()->get()->groupBy('importacion_id') as $filas) {
            $porEtapa = $filas->keyBy(fn ($f) => $f->etapa->value);
            foreach (EtapaContenedor::cases() as $etapa) {
                $anterior = $etapa->anterior();
                if ($anterior && isset($porEtapa[$etapa->value], $porEtapa[$anterior->value])) {
                    $dias[$etapa->value][] = (int) $porEtapa[$anterior->value]->fecha->diffInDays($porEtapa[$etapa->value]->fecha);
                }
            }
        }

        return $this->promedios = collect($dias)->map(fn ($v) => [
            'promedio' => round(array_sum($v) / count($v), 1),
            'muestras' => count($v),
        ])->all();
    }

    /** Cuánto debería tardar en llegar a esta etapa: la historia manda si alcanza. */
    public function diasEsperados(EtapaContenedor $etapa): ?int
    {
        $medido = $this->promedios()[$etapa->value] ?? null;
        if ($medido && $medido['muestras'] >= self::MUESTRAS_MINIMAS) {
            return (int) ceil($medido['promedio']);
        }

        return $etapa->diasEsperados();
    }

    /**
     * Dónde está el contenedor. Atraso = días de más esperando la siguiente etapa.
     *
     * @return array{etapa: ?string, etapa_label: ?string, siguiente: ?string, siguiente_label: ?string, dias_en_etapa: ?int, esperados: ?int, dias_atraso: int, llegada_estimada: ?string, terminado: bool}
     */
    public function resumen(Importacion $imp): array
    {
        $fechas = $this->fechas($imp);
        $actual = collect(array_keys($fechas))
            ->map(fn ($v) => EtapaContenedor::from($v))
            ->sortBy(fn ($e) => $e->posicion())
            ->last();

        if (! $actual) {
            return [
                'etapa' => null, 'etapa_label' => null, 'siguiente' => null, 'siguiente_label' => null,
                'dias_en_etapa' => null, 'esperados' => null, 'dias_atraso' => 0,
                'llegada_estimada' => null, 'terminado' => false,
            ];
        }

        $casos = EtapaContenedor::cases();
        $siguiente = $casos[$actual->posicion() + 1] ?? null;
        $desde = $fechas[$actual->value];
        $dias = (int) $desde->diffInDays($this->hoy());
        $esperados = $siguiente ? $this->diasEsperados($siguiente) : null;

        return [
            'etapa' => $actual->value,
            'etapa_label' => $actual->label(),
            'siguiente' => $siguiente?->value,
            'siguiente_label' => $siguiente?->label(),
            'dias_en_etapa' => $siguiente ? $dias : null,
            'esperados' => $esperados,
            'dias_atraso' => $esperados !== null ? max(0, $dias - $esperados) : 0,
            'llegada_estimada' => $siguiente ? $this->llegadaEstimada($actual, $desde)?->toDateString() : null,
            'terminado' => $siguiente === null,
        ];
    }

    /** Todo lo que necesita la pantalla del contenedor. */
    public function paraPantalla(Importacion $imp): array
    {
        $imp->loadMissing(['ordenes.proveedor:id,nombre_completo', 'ordenes.items.empresaDestino:id,razon_social,nombre_comercial', 'declaraciones', 'inspecciones.autor:id,name']);

        $filas = ImportacionEtapa::with('autor:id,name')->where('importacion_id', $imp->id)->get()->keyBy(fn ($f) => $f->etapa->value);
        $fechas = $this->fechas($imp);
        $resumen = $this->resumen($imp);
        $promedios = $this->promedios();

        $etapas = [];
        foreach (EtapaContenedor::cases() as $etapa) {
            $fecha = $fechas[$etapa->value] ?? null;
            $previa = $etapa->anterior() ? ($fechas[$etapa->anterior()->value] ?? null) : null;
            $etapas[] = [
                'etapa' => $etapa->value,
                'label' => $etapa->label(),
                'fecha' => $fecha?->toDateString(),
                'dias' => $fecha && $previa ? (int) $previa->diffInDays($fecha) : null,
                'esperados' => $this->diasEsperados($etapa),
                'promedio' => $promedios[$etapa->value]['promedio'] ?? null,
                'muestras' => $promedios[$etapa->value]['muestras'] ?? 0,
                'notas' => $filas[$etapa->value]->notas ?? null,
                'registrado_por' => $filas[$etapa->value]?->autor?->name ?? null,
                'quitable' => isset($filas[$etapa->value]),
            ];
        }

        $ordenes = $imp->ordenes->map(function ($o) {
            $porDestino = $o->items->groupBy(fn ($i) => $i->empresaDestino?->nombreCorto() ?? \App\Models\EmpresaConfig::current()->nombreCorto());

            return [
                'id' => $o->id,
                'numero' => $o->numero,
                'proveedor' => $o->proveedor?->nombre_completo,
                'moneda' => $o->moneda,
                'subtotal' => (float) $o->subtotal,
                'cantidad' => (float) $o->items->sum('cantidad'),
                'recibido' => (float) $o->items->sum('cantidad_recibida'),
                'recepciones' => RecepcionCompra::where('orden_id', $o->id)->where('estado', 'confirmada')
                    ->get(['id', 'numero', 'total_recibido'])
                    ->map(fn ($r) => ['id' => $r->id, 'numero' => $r->numero, 'total' => (float) $r->total_recibido])->all(),
                'cajas' => (int) $o->items->sum('cajas'),
                'volumen_m3' => (float) $o->pivot->volumen_m3,
                'peso_kg' => (float) $o->pivot->peso_kg,
                'destinos' => $porDestino->map(fn ($items, $nombre) => [
                    'nombre' => $nombre,
                    'cajas' => (int) $items->sum('cajas'),
                ])->values()->all(),
            ];
        })->values();

        return [
            'resumen' => $resumen,
            'etapas' => $etapas,
            'ordenes' => $ordenes,
            'volumen_total' => round($ordenes->sum('volumen_m3'), 2),
            'capacidad_m3' => PreliquidarOrden::DEFAULTS['capacidad_m3'],
            'ordenes_disponibles' => $this->ordenesDisponibles(),
            'declaraciones' => $imp->declaraciones->map(fn ($d) => [
                'id' => $d->id,
                'numero' => $d->numero,
                'selectividad' => $d->selectividad,
                'fecha_cita' => $d->fecha_cita?->toDateString(),
                'fecha_levante' => $d->fecha_levante?->toDateString(),
                'notas' => $d->notas,
            ])->values()->all(),
            'inspecciones' => $imp->inspecciones->map(fn ($i) => [
                'id' => $i->id,
                'tipo' => $i->tipo,
                'fecha' => $i->fecha?->toDateString(),
                'notas' => $i->notas,
                'resuelta_en' => $i->resuelta_en?->toDateString(),
                'registrado_por' => $i->autor?->name,
            ])->values()->all(),
        ];
    }

    /** Si Jorge ya recibió, el contenedor llegó: nadie debería marcarlo dos veces. */
    public function llegoABodega(OrdenCompra $orden): void
    {
        $imp = Importacion::whereHas('ordenes', fn ($q) => $q->whereKey($orden->id))->first();
        if (! $imp || ImportacionEtapa::where('importacion_id', $imp->id)->where('etapa', EtapaContenedor::EnBodega->value)->exists()) {
            return;
        }

        try {
            $this->marcarEtapa($imp, EtapaContenedor::EnBodega, $this->hoy(), 'Recibido en bodega.', auth()->id());
            app(\App\Modules\Marketing\Services\TableroLanzamiento::class)->avisarLlegada($imp);
        } catch (InvalidArgumentException) {
            // Faltan declaraciones sin levante: la recepción vale igual, y que el
            // contenedor siga abierto es justo lo que debe ver Liz.
        }
    }

    /**
     * Le avisa a quien recibe el día, las cajas y el peso, una sola vez por
     * contenedor, para que prepare personal y espacio.
     *
     * @return int cuántos contenedores se avisaron
     */
    public function avisarLlegadas(): int
    {
        $abiertos = Importacion::query()
            ->whereNotIn('estado', [EstadoImportacion::Cerrada->value])
            ->whereNull('aviso_llegada_en')
            ->whereHas('etapas')
            ->whereDoesntHave('etapas', fn ($q) => $q->where('etapa', EtapaContenedor::EnBodega->value))
            ->with(['etapas', 'ordenes.items'])
            ->get();

        $avisados = 0;
        $usuarios = null;

        foreach ($abiertos as $imp) {
            $llegada = $this->resumen($imp)['llegada_estimada'];
            $enCamino = $imp->etapas->first(fn ($f) => $f->etapa === EtapaContenedor::CaminoBodega);
            $cerca = $llegada && Carbon::parse($llegada)->lte($this->hoy()->addDays(self::AVISAR_LLEGADA_ANTES));
            if (! $enCamino && ! $cerca) {
                continue;
            }

            $items = $imp->ordenes->flatMap->items;
            $cajas = (int) $items->sum('cajas');
            $kilos = (float) $imp->ordenes->sum(fn ($o) => (float) $o->pivot->peso_kg);
            $metros = (float) $imp->ordenes->sum(fn ($o) => (float) $o->pivot->volumen_m3);
            // La franja horaria no se puede calcular: la escribe Liz en la nota de la etapa.
            $nota = $enCamino?->notas ? " {$enCamino->notas}" : '';

            $usuarios ??= User::query()->get()->filter(fn (User $u) => Permisos::puede($u, 'compras_recepcion'))->values();
            $this->notificar(
                $usuarios,
                $imp,
                'contenedor_por_llegar',
                "Contenedor {$this->nombre($imp)} llega alrededor del ".($llegada ? Carbon::parse($llegada)->format('d/m') : 'próximo día'),
                sprintf('%s cajas, %s kg, %s m³.%s', number_format($cajas, 0, ',', '.'), number_format($kilos, 0, ',', '.'), number_format($metros, 1, ',', '.'), $nota),
            );

            $imp->aviso_llegada_en = $this->hoy();
            $imp->saveQuietly();
            $avisados++;
        }

        return $avisados;
    }

    /**
     * Avisa a la campana de los contenedores atrasados. No repite el aviso cada
     * mañana: el que se acostumbra a una alerta diaria deja de leerla.
     *
     * @return int cuántos contenedores se avisaron
     */
    public function avisarAtrasos(): int
    {
        $abiertos = Importacion::query()
            ->whereNotIn('estado', [EstadoImportacion::Cerrada->value])
            ->whereHas('etapas')
            ->with('etapas')
            ->get();

        $avisados = 0;
        $usuarios = null;

        foreach ($abiertos as $imp) {
            $r = $this->resumen($imp);
            if ($r['dias_atraso'] <= 0) {
                continue;
            }

            $fila = $imp->etapas->first(fn ($f) => $f->etapa->value === $r['etapa']);
            if (! $fila) {
                continue;
            }
            if ($fila->ultima_alerta_en && $fila->ultima_alerta_en->diffInDays($this->hoy()) < self::REPETIR_AVISO_CADA) {
                continue;
            }

            $usuarios ??= $this->quienesVenImportaciones();
            $this->notificar(
                $usuarios,
                $imp,
                'contenedor_atrasado',
                "Contenedor {$this->nombre($imp)} atrasado {$r['dias_atraso']} día(s)",
                "Lleva {$r['dias_en_etapa']} días en «{$r['etapa_label']}» y lo normal son {$r['esperados']} para «{$r['siguiente_label']}».",
            );

            $fila->ultima_alerta_en = $this->hoy();
            $fila->saveQuietly();
            $avisados++;
        }

        return $avisados;
    }

    /**
     * Cambia los datos del contenedor (ya validados). Liquidado no se toca, y la
     * tasa tampoco si ya hay mercancía recibida: lo recibido está en el libro a
     * esa tasa y cambiarla descuadraría el 1465.
     */
    public function actualizarDatos(Importacion $imp, array $datos): void
    {
        if (in_array($imp->estado, [EstadoImportacion::Liquidada, EstadoImportacion::Cerrada], true)) {
            throw new InvalidArgumentException('El contenedor ya está liquidado: sus datos no se pueden cambiar.');
        }

        $tasa = $datos['tasa_cambio_liquidacion'] ?? null;
        if ($tasa !== null && $tasa !== ''
            && (float) $tasa !== (float) $imp->tasa_cambio_liquidacion
            && RecepcionCompra::where('estado', 'confirmada')->whereIn('orden_id', $imp->ordenes()->pluck('compras_ordenes.id'))->exists()) {
            throw new InvalidArgumentException('Ya hay mercancía recibida con esta tasa de cambio: no se puede cambiar.');
        }

        $imp->update($datos);
    }

    /** Crea o corrige una declaración. «Automática» es que la DIAN da el levante sin más trámite. */
    public function guardarDeclaracion(Importacion $imp, array $datos, ?ImportacionDeclaracion $declaracion, ?int $userId): ImportacionDeclaracion
    {
        if ($declaracion && $declaracion->importacion_id !== $imp->id) {
            throw new InvalidArgumentException('La declaración no es de este contenedor.');
        }

        if (($datos['selectividad'] ?? null) === 'automatica' && empty($datos['fecha_levante'])) {
            $datos['fecha_levante'] = $this->hoy()->toDateString();
        }

        if ($declaracion) {
            $declaracion->update($datos);

            return $declaracion;
        }

        return $imp->declaraciones()->create([...$datos, 'registrado_por' => $userId]);
    }

    public function quitarDeclaracion(Importacion $imp, ImportacionDeclaracion $declaracion): void
    {
        if ($declaracion->importacion_id !== $imp->id) {
            throw new InvalidArgumentException('La declaración no es de este contenedor.');
        }
        $declaracion->delete();
    }

    /** Registra la novedad y le avisa a todo Compras. */
    public function registrarInspeccion(Importacion $imp, array $datos, ?int $userId): ImportacionInspeccion
    {
        $inspeccion = $imp->inspecciones()->create([...$datos, 'registrado_por' => $userId]);
        $this->avisarInspeccion($imp, ImportacionInspeccion::TIPOS[$datos['tipo']], $datos['notas'] ?? null);

        return $inspeccion;
    }

    public function resolverInspeccion(Importacion $imp, ImportacionInspeccion $inspeccion): void
    {
        if ($inspeccion->importacion_id !== $imp->id) {
            throw new InvalidArgumentException('La novedad no es de este contenedor.');
        }
        $inspeccion->update(['resuelta_en' => $this->hoy()->toDateString()]);
    }

    /** Una inspección le cambia los planes a todos: se avisa apenas se marca. */
    public function avisarInspeccion(Importacion $imp, string $etiqueta, ?string $notas): void
    {
        $this->notificar(
            $this->quienesVenImportaciones(),
            $imp,
            'contenedor_inspeccion',
            "{$etiqueta}: contenedor {$this->nombre($imp)}",
            (string) $notas,
        );
    }

    /** @return Collection<int, array{id: int, numero: string, proveedor: ?string, moneda: string}> */
    private function ordenesDisponibles(): Collection
    {
        return OrdenCompra::query()
            ->where('tipo', 'importacion')
            ->whereIn('estado', ['aprobada', 'parcial', 'recibida'])
            ->whereNotIn('id', fn ($q) => $q->select('orden_id')->from('compras_importacion_ordenes'))
            ->with('proveedor:id,nombre_completo')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'proveedor' => $o->proveedor?->nombre_completo,
                'moneda' => $o->moneda,
            ])
            ->values();
    }

    /**
     * Fecha de cada etapa alcanzada, en orden. Si un contenedor viejo trae el
     * zarpe o la llegada en sus columnas pero no tiene etapa marcada, cuentan.
     *
     * @return array<string, Carbon>
     */
    private function fechas(Importacion $imp): array
    {
        $filas = $imp->relationLoaded('etapas')
            ? $imp->etapas
            : ImportacionEtapa::where('importacion_id', $imp->id)->get();

        $fechas = [];
        foreach ($filas as $fila) {
            $fechas[$fila->etapa->value] = $fila->fecha->copy()->startOfDay();
        }
        foreach (EtapaContenedor::cases() as $etapa) {
            $campo = $etapa->campoFecha();
            if ($campo && ! isset($fechas[$etapa->value]) && $imp->{$campo}) {
                $fechas[$etapa->value] = $imp->{$campo}->copy()->startOfDay();
            }
        }
        uksort($fechas, fn ($a, $b) => EtapaContenedor::from($a)->posicion() <=> EtapaContenedor::from($b)->posicion());

        return $fechas;
    }

    /** Suma lo que falta. Sin plazo conocido en algún tramo, no inventa una fecha. */
    private function llegadaEstimada(EtapaContenedor $actual, Carbon $desde): ?Carbon
    {
        $fecha = $desde->copy();
        foreach (EtapaContenedor::cases() as $etapa) {
            if ($etapa->posicion() <= $actual->posicion()) {
                continue;
            }
            $dias = $this->diasEsperados($etapa);
            if ($dias === null) {
                return null;
            }
            $fecha->addDays($dias);
            // Lo que ya debió pasar y no ha pasado pasa hoy, como pronto: un
            // contenedor atrasado no puede estimar una llegada en el pasado.
            if ($fecha->lt($this->hoy())) {
                $fecha = $this->hoy();
            }
        }

        return $fecha;
    }

    /** @return Collection<int, User> */
    private function quienesVenImportaciones(): Collection
    {
        return User::query()->get()->filter(fn (User $u) => Permisos::puede($u, 'compras_importacion'))->values();
    }

    /** @param Collection<int, User> $usuarios */
    private function notificar(Collection $usuarios, Importacion $imp, string $tipo, string $titulo, string $mensaje): void
    {
        foreach ($usuarios as $u) {
            NotificacionErp::crear([
                'user_id' => $u->id,
                'tipo' => $tipo,
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'color' => 'warning',
                'icono' => 'heroicon-o-exclamation-triangle',
                'url' => "/app/compras/importacion/{$imp->id}",
            ]);
        }
    }

    private function nombre(Importacion $imp): string
    {
        return $imp->contenedor ?: $imp->numero;
    }

    private function exigirAbierta(Importacion $imp): void
    {
        if (in_array($imp->estado, [EstadoImportacion::Liquidada, EstadoImportacion::Cerrada], true)) {
            throw new InvalidArgumentException('El contenedor ya está liquidado: sus órdenes no se pueden cambiar.');
        }
    }

    private function hoy(): Carbon
    {
        return now('America/Bogota')->startOfDay();
    }
}
