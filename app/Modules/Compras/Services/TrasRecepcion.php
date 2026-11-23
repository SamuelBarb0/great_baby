<?php

namespace App\Modules\Compras\Services;

use App\Models\EmpresaConfig;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Compras\Models\RecepcionCompra;
use App\Modules\Dropi\Enums\CategoriaUbicacion;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Inventario\Enums\EstadoTraslado;
use App\Modules\Inventario\Models\Traslado;
use Illuminate\Support\Collection;

/**
 * Lo que sigue cuando Jorge acepta la mercancía de una importación: ubicar lo que
 * no se puede vender, y separar lo de e-commerce para Araceli.
 *
 * Todo entra primero a Great Baby, que es quien paga los impuestos. Lo de
 * e-commerce pasa después por un traslado que Araceli acepta, no por una venta.
 */
class TrasRecepcion
{
    /**
     * Donde queda lo que llegó mal. Nunca en una ubicación de venta.
     *
     * Se le pasa la bodega contra la que se está recibiendo para que la
     * ubicación nueva HEREDE su responsable y su ciudad.
     *
     * Sin eso nacía huérfana: `User::bodegasAsignadasIds()` reparte el alcance
     * por `responsable_user_id`, así que una bodega sin responsable no es de
     * nadie. Jorge no la veía, y `EjecutarTraslado` le respondía 403 «este
     * traslado es de otra bodega» cuando intentaba sacar de ahí lo averiado
     * para devolvérselo al proveedor. La mercancía quedaba contable y
     * físicamente trabada.
     */
    /**
     * Dónde cae lo averiado, SIN mezclar la mercancía de una marca con la de
     * otra.
     *
     * ───────────────────────────────────────────────────────────────────────
     * EL PROBLEMA
     *
     * Quien descarga clasifica línea por línea —«de estas 50, 30 a Great Baby
     * y 20 a Dropi»— y esa decisión se respeta… mientras la línea llegue bien.
     * En cuanto una llega averiada, `ubicacionNoVendible()` devuelve la PRIMERA
     * ubicación de avería por id y el destino elegido se descarta.
     *
     * Resultado: la avería de Dropi y la de Great Baby terminan en el mismo
     * estante, revueltas, y después nadie sabe a quién reclamarle qué. El área
     * lo pidió por escrito el 20-nov-2026: «la mercancía averiada se gestiona
     * de manera independiente para cada marca, manteniendo una separación
     * física».
     *
     * ───────────────────────────────────────────────────────────────────────
     * LA SOLUCIÓN
     *
     * El dato para separarlas YA estaba en la línea y se estaba tirando. La
     * bodega razona con dos ejes: la OFICINA dice de quién es (10 Great Baby,
     * 11 Dropi, 14 Exhibición) y el ALMACÉN dice en qué estado está (01
     * principal, 02 averías leves). Así que la avería de una línea es el par
     * (la oficina de su destino elegido, almacén de averías).
     *
     * Se pasa `esIngreso: false` a propósito. Esa bandera existe para impedir
     * que alguien DECIDA descargar un contenedor entero directo a «averías
     * leves», y por eso el almacén 02 no admite ingreso directo. Pero acá no
     * lo está decidiendo una persona: lo está decidiendo el sistema porque la
     * mercancía llegó rota, que es exactamente para lo que ese almacén existe.
     *
     * Si no se puede resolver el par —falta el catálogo, la línea no eligió
     * destino, la bodega no tiene oficina— se cae al comportamiento de antes.
     * Entre perder la separación por marca y no poder recibir un contenedor
     * que ya está en el piso, se pierde la separación.
     */
    public function ubicacionDeAveria(
        ?InventarioUbicacion $destinoElegido,
        ?InventarioUbicacion $bodegaDeLaRecepcion = null,
    ): InventarioUbicacion {
        $oficina = $this->oficinaDe($destinoElegido) ?? $this->oficinaDe($bodegaDeLaRecepcion);

        $almacenAverias = \App\Modules\Inventario\Models\Almacen::query()
            ->where('codigo', self::ALMACEN_AVERIAS)
            ->first();

        if ($oficina && $almacenAverias) {
            try {
                return app(\App\Modules\Inventario\Support\UbicacionDelPar::class)
                    ->resolver($oficina, $almacenAverias, esIngreso: false);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'No se pudo resolver la avería por oficina; cae a la ubicación de avería general.',
                    ['oficina' => $oficina->codigo, 'error' => $e->getMessage()],
                );
            }
        }

        return $this->ubicacionNoVendible($bodegaDeLaRecepcion);
    }

    /** El almacén donde vive lo averiado, por código del catálogo del área. */
    private const ALMACEN_AVERIAS = '02';

    /**
     * La oficina de una ubicación.
     *
     * Un rack no lleva el par: lo hereda de la bodega de la que cuelga
     * (`UbicacionDelPar::consultaDelPar` lo dice explícito). Así que si viene
     * una posición, se sube a su sede antes de preguntar.
     */
    private function oficinaDe(?InventarioUbicacion $u): ?\App\Modules\Inventario\Models\Oficina
    {
        if (! $u) {
            return null;
        }

        $conPar = $u->oficina_id
            ? $u
            : ($u->bodega_id ? InventarioUbicacion::find($u->bodega_id) : null);

        return $conPar?->oficina_id
            ? \App\Modules\Inventario\Models\Oficina::find($conPar->oficina_id)
            : null;
    }

    public function ubicacionNoVendible(?InventarioUbicacion $bodega = null): InventarioUbicacion
    {
        $existente = InventarioUbicacion::query()
            ->where('categoria', CategoriaUbicacion::AveriaReparar->value)
            ->where('activa', true)
            ->orderBy('id')
            ->first();

        if ($existente) {
            return $existente;
        }

        // La sede de la que cuelga: si recibimos contra una posición, su
        // bodega; si contra una bodega, ella misma.
        $sede = $bodega?->bodega_id
            ? InventarioUbicacion::find($bodega->bodega_id)
            : $bodega;

        return InventarioUbicacion::create([
            'codigo' => 'AVR-RECEPCION',
            'nombre' => 'Avería · Recibida de proveedor',
            'categoria' => CategoriaUbicacion::AveriaReparar,
            'activa' => true,
            'bodega_id' => $sede?->id,
            'responsable_user_id' => $sede?->responsable_user_id,
            'ciudad' => $sede?->ciudad,
        ]);
    }

    /**
     * Avisa a Araceli de lo suyo y le deja el traslado listo. Sin ubicación de
     * e-commerce configurada avisa igual: la mercancía ya llegó y tiene que saberlo.
     */
    public function separarEcommerce(RecepcionCompra $recepcion): ?Traslado
    {
        $principal = EmpresaConfig::current()->id;

        // Todo lo que llegó bien y es de ella. Sirve para AVISARLE, se haya
        // repartido o no: la mercancía llegó y tiene que saberlo.
        $deElla = $recepcion->items
            ->filter(fn ($i) => ($i->clasificacion ?? 'apto') === 'apto')
            ->filter(fn ($i) => $i->ordenItem?->empresa_destino_id && (int) $i->ordenItem->empresa_destino_id !== (int) $principal);

        if ($deElla->isEmpty()) {
            return null;
        }

        /*
         * Pero sólo se TRASLADA lo que quedó en la bodega de la recepción.
         *
         * Desde que quien descarga puede mandar la mercancía directo al
         * destino —«estas 20 son de Dropi, van a su bodega»—, esas unidades
         * nunca pasaron por la principal. Sacarlas de ahí con un traslado
         * dejaría la principal en negativo y el stock duplicado del otro lado:
         * el saldo diría que hay 40 donde hay 20.
         */
        /*
         * Lo que se queda acá es lo que NO cambió de bodega — no lo que no
         * tiene ubicación.
         *
         * Antes bastaba con `ubicacion_id === null`, porque la única razón
         * para llenar ese campo era mandar la mercancía a otra bodega. Desde
         * que quien descarga puede anotar el estante —que es justo lo que el
         * área pidió— una línea que se queda en la principal también viene con
         * ubicación: la del rack. Mirando sólo si es null, esas unidades
         * dejaban de contarse como «hay que trasladarlas», el traslado no se
         * creaba, y a Aracely se le decía que su mercancía ya estaba en su
         * bodega cuando seguía en la de Great Baby, arriba de un estante.
         *
         * La pregunta correcta es a qué SEDE pertenece la ubicación elegida.
         */
        $sedeDeLaRecepcion = (int) $recepcion->bodega_id;

        $aTrasladar = $deElla->filter(
            fn ($i) => $this->sedeDe($i->ubicacion_id) === null
                || $this->sedeDe($i->ubicacion_id) === $sedeDeLaRecepcion
        );

        /*
         * De lo que SÍ se repartió, hay que distinguir dos cosas muy
         * distintas, y antes se contaban juntas.
         *
         * «De quién es la mercancía» y «a qué bodega la mandaron» son dos
         * ejes independientes: quien descarga puede marcar que 20 unidades de
         * Aracely entran a la bodega de Great Baby —y a veces es lo correcto,
         * porque ahí hay espacio—. Dando por hecho que todo lo repartido fue
         * a la bodega de ella, el aviso decía «entró directo a su bodega, no
         * hace falta aceptar ningún traslado» de unas cajas que estaban en la
         * bodega de otra empresa. Aracely no salía a buscarlas, y ese stock
         * quedaba contra su empresa en una bodega que no es suya.
         */
        $empresaDeLaBodega = $this->empresaPorBodega();

        $repartidas = $deElla->diff($aTrasladar);

        /*
         * La empresa se pregunta por la SEDE, no por el estante.
         *
         * `empresaPorBodega()` mapea las ubicaciones que tienen oficina, y la
         * oficina vive en la fila de la sede: un rack no la tiene. Preguntando
         * por el id del estante, el mapa devolvía null siempre y toda línea
         * bajada a una posición se reportaba como «quedó en una bodega que no
         * es suya», aunque el estante fuera de su propia bodega.
         */
        $empresaDe = fn ($i) => $empresaDeLaBodega[$this->sedeDe($i->ubicacion_id)] ?? null;

        // Suya de verdad: la oficina de esa bodega es de la misma empresa a la
        // que la línea está destinada. Si la bodega no tiene oficina mapeada
        // todavía, no se sabe, y no se afirma que sea suya.
        $enSuBodega = $repartidas->filter(fn ($i) => $empresaDe($i)
            === (int) $i->ordenItem->empresa_destino_id);

        $enBodegaAjena = $repartidas->reject(fn ($i) => $empresaDe($i)
            === (int) $i->ordenItem->empresa_destino_id);

        $traslado = $aTrasladar->isNotEmpty()
            ? $this->crearTraslado($recepcion, $aTrasladar)
            : null;

        $unidades = rtrim(rtrim(number_format((float) $deElla->sum('cantidad_recibida'), 3, ',', '.'), '0'), ',');

        $ajenas = $enBodegaAjena->isNotEmpty()
            ? ' Ojo: '.rtrim(rtrim(number_format((float) $enBodegaAjena->sum('cantidad_recibida'), 3, ',', '.'), '0'), ',')
                .' unidades suyas quedaron en '
                .($enBodegaAjena->map(fn ($i) => $i->ubicacion?->nombre)->filter()->unique()->implode(', ') ?: 'otra bodega')
                .': pedí el traslado si las querés en la tuya.'
            : '';

        /*
         * La pregunta que manda es si quedó algo SIN TRASLADAR, no si el
         * traslado existe.
         *
         * `$traslado` en null significa dos cosas muy distintas: que no hacía
         * falta ninguno, o que hacía falta y NO SE PUDO —falta la ubicación de
         * e-commerce en Reglas, que viene en cero de fábrica, o las líneas no
         * tienen producto—. Mirando sólo si es null, una recepción repartida a
         * medias le decía a Aracely «entró directo a su bodega, no hace falta
         * aceptar ningún traslado» mientras la otra mitad se quedaba en la
         * bodega de Great Baby, sin traslado y sin que nadie se enterara.
         */
        $faltoTraslado = $aTrasladar->isNotEmpty() && $traslado === null;

        $pendiente = $faltoTraslado
            ? ' Quedaron '.rtrim(rtrim(number_format((float) $aTrasladar->sum('cantidad_recibida'), 3, ',', '.'), '0'), ',')
                .' unidades en '.($recepcion->bodega?->nombre ?: 'la bodega de la recepción')
                .' SIN traslado: falta configurar la ubicación de e-commerce en Reglas, '
                .'o crear el producto de esas líneas.'
            : '';

        $mensaje = match (true) {
            $traslado !== null => "Recepción {$recepcion->numero}. Traslado {$traslado->numero} listo para aceptar.".$ajenas,
            // Hacía falta trasladar y no se pudo: es lo primero que hay que decir.
            $faltoTraslado => "Recepción {$recepcion->numero}.".$pendiente.$ajenas,
            // Se repartió al recibir: no hay nada que trasladar y eso es lo
            // correcto. Decirlo evita que alguien salga a buscar un traslado
            // que no existe.
            $enSuBodega->isNotEmpty() => "Recepción {$recepcion->numero}. Entró directo a su bodega al descargar, "
                .'no hace falta aceptar ningún traslado.'.$ajenas,
            $enBodegaAjena->isNotEmpty() => "Recepción {$recepcion->numero}.".$ajenas,
            default => "Recepción {$recepcion->numero}. No se creó el traslado: falta configurar la ubicación "
                .'de e-commerce en Reglas, o crear el producto de estas líneas.',
        };

        foreach ($this->aracelis() as $u) {
            NotificacionErp::crear([
                'user_id' => $u->id,
                'tipo' => 'recepcion_ecommerce',
                'titulo' => "Llegó mercancía de e-commerce: {$unidades} unidades",
                'mensaje' => $mensaje,
                'color' => 'info',
                'icono' => 'heroicon-o-truck',
                'url' => $traslado ? "/app/inventario/traslados/{$traslado->id}" : "/app/compras/recepcion/{$recepcion->id}",
            ]);
        }

        return $traslado;
    }

    /**
     * De qué empresa es cada bodega, por el eje de oficinas.
     *
     * La oficina del catálogo dice de quién es la mercancía y apunta a una
     * `empresa_config`. Es la única forma de saber si la bodega a la que
     * mandaron unas cajas es de la misma empresa a la que esas cajas están
     * destinadas. Devuelve [bodega_id => empresa_id], y las bodegas sin
     * oficina mapeada simplemente no están: de ésas no se sabe.
     */
    /**
     * A qué sede pertenece una ubicación: ella misma si es bodega, su madre
     * si es un estante.
     *
     * Se memoiza porque se pregunta una vez por renglón de la recepción y un
     * contenedor trae decenas.
     */
    private array $sedes = [];

    private function sedeDe(?int $ubicacionId): ?int
    {
        if (! $ubicacionId) {
            return null;
        }

        if (! array_key_exists($ubicacionId, $this->sedes)) {
            $u = InventarioUbicacion::query()->find($ubicacionId, ['id', 'bodega_id']);

            $this->sedes[$ubicacionId] = $u
                ? (int) ($u->bodega_id ?: $u->id)
                : null;
        }

        return $this->sedes[$ubicacionId];
    }

    private function empresaPorBodega(): array
    {
        $empresaDeOficina = \App\Modules\Inventario\Models\Oficina::query()
            ->whereNotNull('empresa_config_id')
            ->pluck('empresa_config_id', 'id');

        $salida = [];

        foreach (InventarioUbicacion::query()->whereNotNull('oficina_id')->get(['id', 'oficina_id']) as $b) {
            if (isset($empresaDeOficina[$b->oficina_id])) {
                $salida[(int) $b->id] = (int) $empresaDeOficina[$b->oficina_id];
            }
        }

        return $salida;
    }

    /**
     * El traslado sale DE DONDE ESTÁ la mercancía, no de la sede.
     *
     * ───────────────────────────────────────────────────────────────────────
     * POR QUÉ HAY QUE AGRUPAR
     *
     * Antes, a esta lista sólo llegaban las líneas que se habían quedado en
     * la bodega de la recepción sin ubicar, así que poner la sede como origen
     * era correcto.
     *
     * Desde que quien descarga puede anotar el estante, una línea que se
     * queda en esta misma bodega TAMBIÉN trae ubicación: la del rack. Esas
     * hay que trasladarlas igual —son de Aracely y están en la bodega de
     * Great Baby— pero su saldo vive en el rack, no en la fila de la sede.
     *
     * Sacándolas de la sede, el traslado nace y después revienta al enviarlo
     * con «stock insuficiente en origen» sobre mercancía que está ahí, a tres
     * metros. Y el que lo recibe no tiene cómo saber por qué.
     *
     * Así que se agrupa por la ubicación real y se crea UN traslado POR
     * ORIGEN. Lo normal es que sea uno solo; con el contenedor repartido en
     * dos racks, son dos, y cada uno cuadra.
     * ───────────────────────────────────────────────────────────────────────
     *
     * @return Traslado|null  el primero, para el aviso; el resto quedan creados igual
     */
    private function crearTraslado(RecepcionCompra $recepcion, Collection $items): ?Traslado
    {
        // Una línea sin producto creado no tiene qué trasladar en el kardex.
        $items = $items->filter(fn ($i) => $i->variante_id || $i->producto_id);
        $destino = (int) setting('compras.ubicacion_ecommerce', 0);

        if ($items->isEmpty()) {
            return null;
        }

        if ($destino <= 0 || ! InventarioUbicacion::whereKey($destino)->exists()) {
            return null;
        }

        // Cada grupo es un origen real: el estante donde quedó, o la sede si
        // la línea no se ubicó en ninguno.
        $porOrigen = $items->groupBy(fn ($i) => (int) ($i->ubicacion_id ?: $recepcion->bodega_id));

        $primero = null;

        foreach ($porOrigen as $origenId => $delOrigen) {
            // Trasladar a donde ya está no mueve nada y deja un documento
            // muerto en la lista de Aracely.
            if ((int) $origenId === $destino) {
                continue;
            }

            $traslado = Traslado::create([
                'numero' => Traslado::siguienteNumero(),
                'origen_id' => $origenId,
                'destino_id' => $destino,
                'motivo' => 'Importación e-commerce',
                'observaciones' => "Recepción {$recepcion->numero}, orden {$recepcion->orden?->numero}. "
                    .'Se traslada al costo de la liquidación, sin factura de venta.'
                    .($porOrigen->count() > 1 ? ' Parte de la recepción: la mercancía quedó repartida en varias posiciones.' : ''),
                'solicitado_por' => auth()->id(),
                'fecha_solicitud' => now('America/Bogota')->toDateString(),
                'estado' => EstadoTraslado::Borrador,
            ]);

            foreach ($delOrigen as $i) {
                $traslado->items()->create([
                    'variante_id' => $i->variante_id,
                    'producto_id' => $i->producto_id,
                    'cantidad_solicitada' => $i->cantidad_recibida,
                ]);
            }

            $primero ??= $traslado;
        }

        return $primero;
    }

    /** @return Collection<int, User> */
    private function aracelis(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Aracely', 'Gerencia']))->get();
    }
}
