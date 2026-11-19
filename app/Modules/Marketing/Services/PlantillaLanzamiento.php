<?php

namespace App\Modules\Marketing\Services;

/**
 * Las 10 tarjetas que se crean cuando Compras confirma un contenedor, tal como
 * las definió Great Baby en «TABLERO GREAT BABY.pdf» (30-oct-2026): área
 * encargada, checklist y entregable. Si el proceso cambia, se cambia aquí; los
 * lanzamientos ya creados conservan las tarjetas con que nacieron.
 */
final class PlantillaLanzamiento
{
    /**
     * @return list<array{clave: string, titulo: string, area: string, objetivo: string, entregable: string, requiere_aprobacion: bool, items: list<string|array{0: string, 1: string}>}>
     */
    public static function tarjetas(): array
    {
        return [
            [
                'clave' => 'recepcion', 'titulo' => 'Recepción del Contenedor', 'area' => 'compras',
                'objetivo' => 'Dar inicio oficial al lanzamiento.',
                'entregable' => 'Contenedor confirmado.',
                'requiere_aprobacion' => false,
                'items' => [
                    'Confirmar la compra.', 'Confirmar fecha estimada de llegada.', 'Adjuntar Packing List.',
                    'Confirmar los productos que llegan.', 'Informar novedades.', 'Notificar a Araceli.', 'Notificar a Anny.',
                ],
            ],
            [
                'clave' => 'seleccion', 'titulo' => 'Selección de Productos', 'area' => 'dropshipping',
                'objetivo' => 'Seleccionar los productos que harán parte del lanzamiento.',
                'entregable' => 'Listado oficial de productos seleccionados.',
                'requiere_aprobacion' => false,
                'items' => [
                    'Revisar el Packing List.', 'Identificar productos prioritarios.', 'Definir orden de lanzamiento.',
                    'Validar disponibilidad.', 'Compartir listado con Marketing.',
                ],
            ],
            [
                'clave' => 'brief', 'titulo' => 'Brief Comercial', 'area' => 'dropshipping',
                'objetivo' => 'Crear la estrategia comercial para los productos seleccionados.',
                'entregable' => 'Brief Comercial finalizado.',
                'requiere_aprobacion' => false,
                'items' => [
                    'Definir público objetivo.', 'Definir beneficios.', 'Definir problema que resuelve.',
                    'Identificar diferenciales.', 'Buscar referencias.', 'Definir objetivo del lanzamiento.',
                    'Compartir el Brief con Marketing.',
                ],
            ],
            [
                'clave' => 'propuesta', 'titulo' => 'Propuesta Creativa', 'area' => 'marketing',
                'objetivo' => 'Diseñar la estrategia creativa antes de producir contenido.',
                'entregable' => 'Propuesta Creativa.',
                'requiere_aprobacion' => false,
                'items' => [
                    'Revisar Brief.', 'Realizar Benchmarking.', 'Buscar referencias.', 'Definir Hooks.',
                    'Definir Ángulos de venta.', 'Elaborar Guiones.', 'Preparar la propuesta creativa.',
                ],
            ],
            [
                // La única que el PDF obliga a pasar por aprobación: el concepto
                // se aprueba antes de producir.
                'clave' => 'validacion', 'titulo' => 'Validación Comercial', 'area' => 'marketing_dropshipping',
                'objetivo' => 'Aprobar el concepto creativo antes de iniciar la producción.',
                'entregable' => 'Concepto creativo aprobado.',
                'requiere_aprobacion' => true,
                'items' => [
                    'Presentar la propuesta.', 'Revisarla conjuntamente.', 'Realizar ajustes (si aplica).', 'Aprobar el concepto.',
                ],
            ],
            [
                'clave' => 'muestras', 'titulo' => 'Entrega de Muestras', 'area' => 'bodega',
                'objetivo' => 'Entregar los productos físicos al área de Marketing.',
                'entregable' => 'Productos entregados.',
                'requiere_aprobacion' => false,
                'items' => [
                    'Separar las muestras.', 'Confirmar disponibilidad.', 'Entregar los productos.', 'Informar a Marketing.',
                ],
            ],
            [
                'clave' => 'produccion', 'titulo' => 'Producción de Contenido', 'area' => 'marketing',
                'objetivo' => 'Crear el contenido para el lanzamiento.',
                'entregable' => 'Contenido final.',
                'requiere_aprobacion' => false,
                'items' => [
                    'Grabar el contenido.', 'Editar los videos.', 'Diseñar las piezas necesarias.',
                    'Organizar el material.', 'Compartir el contenido con Araceli.',
                ],
            ],
            [
                'clave' => 'dropi', 'titulo' => 'Configuración en Dropi', 'area' => 'dropshipping',
                'objetivo' => 'Configurar los productos para su comercialización.',
                'entregable' => 'Productos listos en Dropi.',
                'requiere_aprobacion' => false,
                'items' => [
                    'Crear los productos.', 'Configurar precios.', 'Configurar variantes.', 'Elaborar descripciones.',
                    'Cargar fotografías.', 'Cargar videos.', 'Validar enlaces.',
                ],
            ],
            [
                'clave' => 'publicacion', 'titulo' => 'Publicación', 'area' => 'marketing_dropshipping',
                'objetivo' => 'Ejecutar el lanzamiento del producto.',
                'entregable' => 'Lanzamiento realizado.',
                'requiere_aprobacion' => false,
                'items' => [
                    ['Publicar en redes sociales.', 'marketing'],
                    ['Compartir el contenido en la comunidad de WhatsApp.', 'marketing'],
                    ['Publicar el producto en Dropi.', 'dropshipping'],
                    ['Compartir el enlace.', 'dropshipping'],
                    ['Comunicar el lanzamiento.', 'dropshipping'],
                ],
            ],
            [
                'clave' => 'seguimiento', 'titulo' => 'Seguimiento', 'area' => 'marketing_dropshipping',
                'objetivo' => 'Evaluar el resultado del lanzamiento.',
                'entregable' => 'Retroalimentación del lanzamiento.',
                'requiere_aprobacion' => false,
                'items' => [
                    'Revisar el desempeño del contenido.', 'Recibir retroalimentación de los dropshippers.',
                    'Registrar oportunidades de mejora.', 'Documentar aprendizajes.',
                ],
            ],
        ];
    }

    /** Objetivo y entregable de una tarjeta, para mostrarlos en su ventana. */
    public static function de(string $clave): ?array
    {
        foreach (self::tarjetas() as $t) {
            if ($t['clave'] === $clave) {
                return $t;
            }
        }

        return null;
    }
}
