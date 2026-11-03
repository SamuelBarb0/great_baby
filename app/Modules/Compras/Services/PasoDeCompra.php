<?php

namespace App\Modules\Compras\Services;

use App\Auth\Permisos;
use App\Models\User;
use App\Modules\Compras\Actions\CrearProductosDeOrden;
use App\Modules\Compras\Enums\EstadoImportacion;
use App\Modules\Compras\Enums\EtapaContenedor;
use App\Modules\Compras\Models\Importacion;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\RecepcionCompra;

/**
 * Qué sigue con una importación, dicho para quien nunca la ha hecho.
 *
 * El proceso dura meses y pasa por varias personas, así que cada pantalla responde
 * lo mismo: en qué paso va, qué hay que hacer ahora, quién lo hace y con qué botón.
 */
class PasoDeCompra
{
    public const PASOS = ['Orden', 'Contenedor', 'Viaje', 'Recibir', 'Costos'];

    public function __construct(private SeguimientoContenedor $seguimiento) {}

    public function deOrden(OrdenCompra $orden, ?User $u): ?array
    {
        $estado = is_object($orden->estado) ? $orden->estado->value : $orden->estado;
        if (! $orden->esImportacion() || in_array($estado, ['anulada', 'cerrada'], true)) {
            return null;
        }

        if (in_array($estado, ['borrador', 'enviada'], true)) {
            return $this->porAprobar($orden, $u);
        }

        $importacion = $this->contenedorDe($orden);
        if (! $importacion) {
            return $this->sinContenedor($orden, $u);
        }

        return $this->desdeAfuera($this->deContenedor($importacion, $u), $importacion);
    }

    public function deContenedor(Importacion $imp, ?User $u): array
    {
        $imp->loadMissing(['ordenes', 'gastos']);
        $edita = Permisos::puede($u, 'compras_importacion');

        if (in_array($imp->estado, [EstadoImportacion::Liquidada, EstadoImportacion::Cerrada], true)) {
            return $this->paso(6, '¡Listo! Este contenedor quedó cerrado.',
                'El costo real ya está en el inventario. Lo que falta pagarle a China se ve en Pagos al exterior.',
                null,
                Permisos::puede($u, 'compras_pagos_exterior') ? [$this->enlace('Ver pagos al exterior', '/app/compras/pagos-exterior')] : []);
        }

        if ($imp->ordenes->isEmpty()) {
            return $this->paso(2, 'Agregue la orden que viaja en este contenedor.',
                'Sin una orden, el sistema no sabe qué mercancía trae.',
                'Importaciones',
                $edita ? [$this->accion('Elegir la orden', 'agregar-orden')] : []);
        }

        $recibidas = RecepcionCompra::whereIn('orden_id', $imp->ordenes->pluck('id'))
            ->where('estado', 'confirmada')->pluck('orden_id')->all();
        $porRecibir = $imp->ordenes->first(fn ($o) => ! in_array($o->id, $recibidas, true));

        return $porRecibir
            ? $this->enCamino($imp, $porRecibir, $u, $edita)
            : $this->porLiquidar($imp, $edita);
    }

    private function porAprobar(OrdenCompra $orden, ?User $u): array
    {
        $aprueba = Permisos::puede($u, 'compras_oc') || ($u?->esAracely() ?? false);
        $porCrear = $orden->items
            ->filter(fn ($i) => ! $i->producto_id && ! $i->variante_id && CrearProductosDeOrden::referenciaDe($i))
            ->count();

        if ($porCrear > 0) {
            return $this->paso(1, "Cree los {$porCrear} productos que faltan y luego apruebe la orden.",
                'Lo que no tiene producto no entra al inventario cuando llega.',
                'Compras',
                [
                    $this->accion('Crear los productos', 'ir-productos'),
                    ...($aprueba ? [$this->accion('Aprobar así', 'aprobar', false)] : []),
                ]);
        }

        return $this->paso(1, 'Revise la orden y apruébela.',
            'Mientras no esté aprobada, no se puede poner en un contenedor.',
            'Compras',
            $aprueba ? [$this->accion('Aprobar la orden', 'aprobar')] : []);
    }

    private function sinContenedor(OrdenCompra $orden, ?User $u): array
    {
        return $this->paso(2, 'Mande el Excel a China y ponga la orden en un contenedor.',
            'El contenedor es lo que se sigue hasta que la mercancía llegue a bodega.',
            'Compras',
            array_values(array_filter([
                Permisos::puede($u, 'compras_importacion') ? $this->accion('Poner en un contenedor', 'contenedor') : null,
                Permisos::puede($u, 'compras_oc') || Permisos::puede($u, 'compras_pagos_exterior')
                    ? $this->enlace('Descargar Excel para China', "/app/compras/orden/{$orden->id}/excel", false, true) : null,
            ])));
    }

    private function enCamino(Importacion $imp, OrdenCompra $orden, ?User $u, bool $edita): array
    {
        $resumen = $this->seguimiento->resumen($imp);
        $actual = $resumen['etapa'] ? EtapaContenedor::from($resumen['etapa']) : null;
        $recibe = Permisos::puede($u, 'compras_recepcion');
        $url = "/app/compras/recepcion/nueva?oc={$orden->id}";

        if ($actual && $actual->posicion() >= EtapaContenedor::CaminoBodega->posicion()) {
            return $this->paso(4, 'La mercancía ya va para bodega: cuente lo que llegó.',
                'Nada entra al inventario hasta que bodega lo acepte.',
                'Bodega',
                $recibe ? [$this->enlace("Recibir {$orden->numero}", $url)] : []);
        }

        // Con una declaración sin levante el sistema no deja avanzar: decirlo antes.
        $sinLevante = $imp->declaraciones()->whereNull('fecha_levante')->count();
        if ($sinLevante > 0 && $resumen['siguiente'] === EtapaContenedor::Levante->value) {
            return $this->paso(3, "Falta el levante de {$sinLevante} declaración(es).",
                'Cuando la aduana lo dé, escriba la fecha en cada declaración. Sin eso el contenedor no puede seguir.',
                'Importaciones',
                $edita ? [$this->accion('Ir a las declaraciones', 'aduana')] : []);
        }

        $siguiente = $resumen['siguiente_label'] ?? EtapaContenedor::cases()[0]->label();

        return $this->paso(3,
            $actual ? "Va en «{$actual->label()}». Cuando pase lo siguiente, márquelo: «{$siguiente}»." : "Marque cómo va el contenedor. Empieza por «{$siguiente}».",
            'Con estas fechas el sistema avisa si se atrasa y calcula cuándo pagarle a China.',
            'Importaciones',
            array_values(array_filter([
                $edita ? $this->accion("Marcar «{$siguiente}»", 'marcar') : null,
                $recibe ? $this->enlace('Ya llegó a bodega: recibir', $url, false) : null,
            ])));
    }

    private function porLiquidar(Importacion $imp, bool $edita): array
    {
        if ($imp->gastos->isEmpty()) {
            return $this->paso(5, 'Bodega ya contó. Ahora agregue los gastos del contenedor.',
                'Flete, aduana, bodegaje… Se reparten entre los productos para saber cuánto costó cada uno de verdad.',
                'Contabilidad',
                $edita ? [$this->accion('Agregar un gasto', 'agregar-gasto'), $this->accion('No hay gastos: liquidar', 'liquidar', false)] : []);
        }

        return $this->paso(5, '¿Ya están todos los gastos? Liquide el contenedor.',
            'Liquidar reparte los gastos y deja el costo final de cada producto. Las cantidades no cambian.',
            'Contabilidad',
            $edita ? [$this->accion('Liquidar el contenedor', 'liquidar'), $this->accion('Agregar otro gasto', 'agregar-gasto', false)] : []);
    }

    /** Afuera del contenedor sus botones llevan a él, en la parte donde se hace. */
    private function desdeAfuera(array $paso, Importacion $imp): array
    {
        $pestanas = ['marcar' => 'seguimiento', 'agregar-orden' => 'seguimiento', 'agregar-gasto' => 'costos', 'liquidar' => 'costos'];
        $acciones = collect($paso['acciones'])->reject(fn ($a) => $a['tipo'] === 'accion' && ! $a['principal'])->map(
            fn ($a) => $a['tipo'] === 'accion'
                ? $this->enlace('Ir al contenedor', "/app/compras/importacion/{$imp->id}?tab=".($pestanas[$a['accion']] ?? 'seguimiento'))
                : $a
        );

        if ($acciones->doesntContain('etiqueta', 'Ir al contenedor')) {
            $acciones->push($this->enlace('Ir al contenedor', "/app/compras/importacion/{$imp->id}", $acciones->isEmpty()));
        }

        return [...$paso, 'acciones' => $acciones->values()->all()];
    }

    private function contenedorDe(OrdenCompra $orden): ?Importacion
    {
        return $orden->belongsToMany(Importacion::class, 'compras_importacion_ordenes', 'orden_id', 'importacion_id')->first();
    }

    private function paso(int $numero, string $titulo, string $explica, ?string $quien, array $acciones): array
    {
        return ['numero' => $numero, 'pasos' => self::PASOS, 'titulo' => $titulo, 'explica' => $explica, 'quien' => $quien, 'acciones' => $acciones];
    }

    private function accion(string $etiqueta, string $accion, bool $principal = true): array
    {
        return ['tipo' => 'accion', 'etiqueta' => $etiqueta, 'accion' => $accion, 'principal' => $principal];
    }

    private function enlace(string $etiqueta, string $href, bool $principal = true, bool $descarga = false): array
    {
        return ['tipo' => $descarga ? 'descarga' : 'enlace', 'etiqueta' => $etiqueta, 'href' => $href, 'principal' => $principal];
    }
}
