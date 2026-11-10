<?php

namespace Database\Seeders;

use App\Models\Contacto;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Deja el ERP con datos en TODOS los estados, para poder recorrerlo entero.
 *
 * Una pantalla vacía no se puede probar: el filtro «rechazadas» sin ninguna
 * rechazada se ve igual de bien roto que bien hecho, y el tramo de mora 61-90
 * sin facturas no dice si la consulta está mal escrita. Hasta ahora había que
 * ir creando a mano el caso de cada prueba, y eso hace que se prueben siempre
 * los mismos tres caminos: los que alguien se acordó de armar.
 *
 * Acá se arma uno de cada cosa, a propósito, incluidos los casos que nadie
 * crea por gusto: el pedido rechazado, la factura anulada, la garantía negada,
 * la orden de compra cerrada, el período de prueba ya vencido.
 *
 * TODO LO QUE CREA LLEVA EL PREFIJO «QA-».
 *
 * Es la única forma de poder borrarlo después sin llevarse por delante los
 * datos de verdad, y de que nadie confunda una factura de prueba con una que
 * hay que cobrar. Correrlo dos veces no duplica: primero limpia lo suyo.
 *
 * NO toca nada de SIIGO, ni crea movimientos de inventario que no correspondan
 * a un conteo: inventar existencias haría que el kardex mienta.
 */
class QaTodosLosEstadosSeeder extends Seeder
{
    public const PREFIJO = 'QA-';

    private Contacto $cliente;

    private Contacto $clienteMoroso;

    private Contacto $proveedor;

    private User $responsable;

    /** Un granular y un agregado de verdad: las líneas tienen que apuntar a algo. */
    private ?int $varianteId = null;

    private ?int $productoAgregadoId = null;

    public function run(): void
    {
        $this->command?->warn('Limpiando lo que dejó la corrida anterior…');
        $this->limpiar();

        $this->terceros();
        $this->catalogo();

        $this->command?->info('Creando datos en todos los estados:');

        $this->pedidos();
        $this->facturasYMora();
        $this->cotizaciones();
        $this->garantias();
        $this->compras();
        $this->gastos();
        $this->asientos();
        $this->comisionesYMetas();
        $this->solicitudesDeCredito();
        $this->guias();

        $this->command?->newLine();
        $this->command?->info('Listo. Todo lo creado empieza por «'.self::PREFIJO.'».');
    }

    /**
     * Borra lo de la corrida anterior, y SÓLO eso.
     *
     * El orden importa: primero los hijos, porque las llaves foráneas no
     * perdonan. Y se borra por el prefijo, nunca por rango de fechas ni por
     * «lo último que se creó», que son las dos formas de llevarse datos
     * ajenos por delante.
     */
    private function limpiar(): void
    {
        $p = self::PREFIJO;

        $pedidos = DB::table('pedidos_cliente')->where('numero', 'like', $p.'%')->pluck('id');
        DB::table('pedidos_cliente_items')->whereIn('pedido_id', $pedidos)->delete();

        $facturas = DB::table('facturas_venta')->where('numero', 'like', $p.'%')->pluck('id');
        DB::table('factura_venta_items')->whereIn('factura_id', $facturas)->delete();
        DB::table('pagos_venta')->whereIn('factura_id', $facturas)->delete();

        $cotis = DB::table('cotizaciones')->where('numero', 'like', $p.'%')->pluck('id');
        DB::table('cotizacion_items')->whereIn('cotizacion_id', $cotis)->delete();

        DB::table('cotizaciones')->whereIn('id', $cotis)->delete();
        DB::table('facturas_venta')->whereIn('id', $facturas)->delete();
        DB::table('pedidos_cliente')->whereIn('id', $pedidos)->delete();

        DB::table('garantia_tickets')->where('numero', 'like', $p.'%')->delete();
        DB::table('compras_ordenes')->where('numero', 'like', $p.'%')->delete();
        DB::table('gastos_operativos')->where('numero', 'like', $p.'%')->delete();
        DB::table('asientos_manuales')->where('glosa', 'like', $p.'%')->delete();
        DB::table('solicitudes_credito')->where('motivo_retencion', 'like', $p.'%')->delete();
    }

    /** Un cliente al día, uno con la cartera hecha un desastre, y un proveedor. */
    private function terceros(): void
    {
        $this->cliente = Contacto::updateOrCreate(
            ['numero_documento' => 'QA900111222'],
            [
                'tipo_documento' => 'NIT',
                'razon_social' => 'QA · Pañalera Al Día SAS',
                'nombre_completo' => 'QA · Pañalera Al Día SAS',
                'email' => 'qa.aldia@ejemplo.test',
                'telefono' => '3001112222',
                'ciudad' => 'Bogotá',
                'es_cliente' => true, 'es_cliente_b2b' => true, 'activo' => true,
                'segmento' => 'VIP',
                'lista_precios_id' => DB::table('listas_precios')->value('id'),
            ]
        );

        $this->clienteMoroso = Contacto::updateOrCreate(
            ['numero_documento' => 'QA900333444'],
            [
                'tipo_documento' => 'NIT',
                'razon_social' => 'QA · Distribuidora Morosa SAS',
                'nombre_completo' => 'QA · Distribuidora Morosa SAS',
                'email' => 'qa.morosa@ejemplo.test',
                'telefono' => '3003334444',
                'ciudad' => 'Medellín',
                'es_cliente' => true, 'es_cliente_b2b' => true, 'activo' => true,
                'segmento' => 'Frecuente',
                'lista_precios_id' => DB::table('listas_precios')->value('id'),
            ]
        );

        $this->proveedor = Contacto::updateOrCreate(
            ['numero_documento' => 'QA901555666'],
            [
                'tipo_documento' => 'NIT',
                'razon_social' => 'QA · Importadora de Prueba SAS',
                'nombre_completo' => 'QA · Importadora de Prueba SAS',
                'es_proveedor' => true, 'activo' => true,
            ]
        );

        $this->responsable = User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['Aracely', 'Gerencia']))
            ->first() ?? User::query()->firstOrFail();
    }

    /**
     * Qué productos usan las líneas.
     *
     * No se inventan: `pedidos_cliente_items` tiene un candado que exige
     * variante O producto, y apuntar a uno que no existe dejaría renglones
     * huérfanos que después no se pueden mostrar.
     */
    private function catalogo(): void
    {
        $this->varianteId = DB::table('producto_variantes as v')
            ->join('productos as p', 'p.id', '=', 'v.producto_id')
            ->where('p.activo', true)->where('p.desglose_stock', true)
            ->value('v.id');

        $this->productoAgregadoId = DB::table('productos')
            ->where('activo', true)->where('desglose_stock', false)
            ->value('id');
    }

    /** Los ocho estados por los que pasa un pedido B2B. */
    private function pedidos(): void
    {
        $estados = [
            'borrador' => 'recién armado, el cliente todavía no lo mandó',
            'enviado' => 'esperando que gerencia lo apruebe',
            'retenido' => 'lo frenó el semáforo de cartera',
            'aprobado' => 'listo para que bodega lo aliste',
            'rechazado' => 'gerencia dijo que no',
            'facturado' => 'ya tiene factura, falta que salga',
            'despachado' => 'salió de bodega',
            'anulado' => 'se cayó después de aprobado',
        ];

        $n = 0;

        foreach ($estados as $estado => $porque) {
            $n++;
            $id = DB::table('pedidos_cliente')->insertGetId([
                'numero' => self::PREFIJO.'PB-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'contacto_id' => $estado === 'retenido' ? $this->clienteMoroso->id : $this->cliente->id,
                // La pantalla muestra la lista DEL PEDIDO, no la del cliente:
                // sin esto la columna sale en «—» y parece un defecto.
                'lista_precios_id' => $this->cliente->lista_precios_id,
                'estado' => $estado,
                'subtotal' => 450000, 'iva' => 0, 'total' => 450000,
                'notas_cliente' => 'QA · '.$porque,
                'motivo_retencion' => $estado === 'retenido' ? 'QA · mora crítica · cupo excedido' : null,
                'motivo_rechazo' => $estado === 'rechazado' ? 'QA · el cliente canceló la compra' : null,
                'enviado_at' => $estado === 'borrador' ? null : now()->subDays(6),
                'aprobado_at' => in_array($estado, ['aprobado', 'facturado', 'despachado'], true) ? now()->subDays(4) : null,
                'facturado_at' => in_array($estado, ['facturado', 'despachado'], true) ? now()->subDays(3) : null,
                'despachado_at' => $estado === 'despachado' ? now()->subDays(2) : null,
                'created_at' => now()->subDays(7), 'updated_at' => now(),
            ]);

            DB::table('pedidos_cliente_items')->insert([
                'pedido_id' => $id,
                'variante_id' => $this->varianteId,
                'producto_id' => $this->varianteId ? null : $this->productoAgregadoId,
                'sku_snapshot' => 'QA-SKU-001',
                'descripcion_snapshot' => 'QA · Producto de prueba',
                'cantidad' => 10, 'precio_unitario' => 45000,
                'subtotal' => 450000, 'total' => 450000,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->command?->line('  · pedidos B2B · '.count($estados).' estados');
    }

    /**
     * Facturas en cada estado Y en cada tramo de mora.
     *
     * Los dos ejes importan y son distintos: el estado lo pone quien factura,
     * el tramo lo pone el calendario. Una pantalla de cartera puede tener bien
     * uno y mal el otro.
     */
    private function facturasYMora(): void
    {
        $casos = [
            ['pendiente', 15, 0, 'vence en dos semanas'],
            ['pendiente', -20, 0, 'tramo 1-30 de mora'],
            ['vencida', -45, 0, 'tramo 31-60'],
            ['vencida', -75, 0, 'tramo 61-90'],
            ['vencida', -100, 0, 'tramo 91-120'],
            ['vencida', -150, 0, 'tramo 120+, la que se escala a gerencia'],
            ['abonada', -10, 300000, 'pagó una parte'],
            ['pagada', -5, 850000, 'cancelada completa'],
            ['anulada', -30, 0, 'se emitió por error'],
            ['borrador', 30, 0, 'todavía sin emitir'],
        ];

        $n = 0;

        foreach ($casos as [$estado, $diasVencimiento, $abonado, $porque]) {
            $n++;
            $total = 850000.0;
            $saldo = max(0, $total - $abonado);

            $facturaId = DB::table('facturas_venta')->insertGetId([
                'numero' => self::PREFIJO.'FV-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'contacto_id' => $diasVencimiento < -60 ? $this->clienteMoroso->id : $this->cliente->id,
                'fecha_emision' => now()->subDays(abs($diasVencimiento) + 30)->toDateString(),
                'fecha_vencimiento' => now()->addDays($diasVencimiento)->toDateString(),
                'estado' => $estado,
                'tipo' => 'credito',
                'subtotal' => $total, 'descuento' => 0, 'impuestos' => 0,
                'total' => $total,
                'saldo' => $estado === 'pagada' ? 0 : ($estado === 'anulada' ? 0 : $saldo),
                'observaciones' => 'QA · '.$porque,
                'created_at' => now()->subDays(abs($diasVencimiento) + 30), 'updated_at' => now(),
            ]);

            DB::table('factura_venta_items')->insert([
                'factura_id' => $facturaId,
                'producto_id' => $this->productoAgregadoId,
                'descripcion' => 'QA · Producto facturado',
                'cantidad' => 10, 'precio_unit' => 85000,
                'descuento_pct' => 0, 'impuesto_pct' => 0,
                'subtotal' => $total,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($abonado > 0) {
                DB::table('pagos_venta')->insert([
                    'factura_id' => $facturaId,
                    'contacto_id' => $this->cliente->id,
                    'fecha' => now()->subDays(3)->toDateString(),
                    'monto_recibido' => $abonado, 'monto_aplicado' => $abonado,
                    'medio_pago' => 'transferencia',
                    'referencia' => self::PREFIJO.'PAGO-'.$n,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $this->command?->line('  · facturas · '.count($casos).' entre estados y tramos de mora');
    }

    /** Las cotizaciones, incluida una vencida y una rechazada. */
    private function cotizaciones(): void
    {
        $casos = [
            ['borrador', 10, 'todavía se está armando'],
            ['enviada', 10, 'el cliente la está mirando'],
            ['aceptada', 10, 'dijo que sí, falta convertirla'],
            ['aceptada', 2, 'aceptada y a punto de vencerse'],
            ['rechazada', 10, 'nos ganaron por precio'],
            ['enviada', -3, 'se venció sin respuesta'],
        ];

        $n = 0;

        foreach ($casos as [$estado, $diasVigencia, $porque]) {
            $n++;
            $id = DB::table('cotizaciones')->insertGetId([
                'numero' => self::PREFIJO.'COT-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'contacto_id' => $this->cliente->id,
                'vendedor_id' => $this->responsable->id,
                'lista_precios_id' => $this->cliente->lista_precios_id,
                'fecha' => now()->subDays(5)->toDateString(),
                'vigente_hasta' => now()->addDays($diasVigencia)->toDateString(),
                'estado' => $estado,
                'subtotal' => 225000, 'iva' => 0, 'total' => 225000,
                'notas' => 'QA · '.$porque,
                'motivo_rechazo' => $estado === 'rechazada' ? 'QA · nos ganaron por precio' : null,
                'created_at' => now()->subDays(5), 'updated_at' => now(),
            ]);

            DB::table('cotizacion_items')->insert([
                'cotizacion_id' => $id,
                'variante_id' => $this->varianteId,
                'producto_id' => $this->varianteId ? null : $this->productoAgregadoId,
                'sku_snapshot' => 'QA-SKU-001',
                'descripcion_snapshot' => 'QA · Producto cotizado',
                'cantidad' => 10, 'precio_unitario' => 22500,
                'iva_porcentaje' => 0, 'subtotal' => 225000, 'iva_valor' => 0, 'total' => 225000,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->command?->line('  · cotizaciones · '.count($casos).' estados, con vencida y rechazada');
    }

    /** Garantías en cada decisión, con y sin costo cargado. */
    private function garantias(): void
    {
        $casos = [
            ['abierto', null, 'recién reportada'],
            ['abierto', null, 'con el plazo de concepto YA vencido'],
            ['en_revision', null, 'bodega la está mirando'],
            ['aprobada', 180000, 'se aprobó y ya tiene costo'],
            ['rechazada', null, 'mal uso, no aplica'],
            ['en_reposicion', 95000, 'se está reponiendo'],
            ['cerrada', 240000, 'cerrada, con todo su costo'],
        ];

        $n = 0;

        foreach ($casos as [$estado, $costo, $porque]) {
            $n++;
            $vencido = str_contains($porque, 'YA vencido');

            DB::table('garantia_tickets')->insert([
                'numero' => self::PREFIJO.'GT-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'contacto_id' => $this->cliente->id,
                'cliente_nombre' => $this->cliente->razon_social,
                'cliente_telefono' => '3001112222',
                'variante_id' => $this->varianteId,
                'cantidad' => 1,
                'descripcion_falla' => 'QA · '.$porque,
                'estado' => $estado,
                'plazo_concepto_at' => $vencido ? now()->subDays(3) : now()->addDays(2),
                'decision_at' => in_array($estado, ['aprobada', 'rechazada', 'en_reposicion', 'cerrada'], true) ? now()->subDays(2) : null,
                'decision_por' => in_array($estado, ['aprobada', 'rechazada', 'en_reposicion', 'cerrada'], true) ? $this->responsable->id : null,
                'notas_decision' => $estado === 'rechazada' ? 'QA · la falla es por mal uso' : null,
                'closed_at' => $estado === 'cerrada' ? now()->subDay() : null,
                // La columna no acepta nulo: sin costo cargado es 0, que es lo que
                // la pantalla cuenta como «todavía no se sabe cuánto costó».
                'costo_producto' => $costo ?? 0,
                'costo_cargado_at' => $costo ? now()->subDay() : null,
                'creado_por' => $this->responsable->id,
                'created_at' => now()->subDays(5), 'updated_at' => now(),
            ]);
        }

        $this->command?->line('  · garantías · '.count($casos).' estados, con plazo vencido y costos cargados');
    }

    /** Órdenes de compra en toda su vida. */
    private function compras(): void
    {
        $estados = ['borrador', 'enviada', 'aprobada', 'parcial', 'recibida', 'cerrada', 'anulada'];

        foreach ($estados as $i => $estado) {
            DB::table('compras_ordenes')->insert([
                'numero' => self::PREFIJO.'OC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'proveedor_id' => $this->proveedor->id,
                'estado' => $estado,
                'total' => 1250000 + ($i * 100000),
                'fecha_emision' => now()->subDays(20 - $i)->toDateString(),
                'created_at' => now()->subDays(20 - $i), 'updated_at' => now(),
            ]);
        }

        $this->command?->line('  · órdenes de compra · '.count($estados).' estados');
    }

    private function gastos(): void
    {
        $estados = ['pendiente', 'aprobado', 'rechazado', 'pagado'];

        foreach ($estados as $i => $estado) {
            DB::table('gastos_operativos')->insert([
                'numero' => self::PREFIJO.'G-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'estado' => $estado,
                'fecha' => now()->subDays(10 - $i)->toDateString(),
                'categoria' => 'servicios',
                'descripcion' => 'QA · gasto en estado '.$estado,
                'monto' => 120000 + ($i * 30000),
                'solicita_id' => $this->responsable->id,
                'created_at' => now()->subDays(10 - $i), 'updated_at' => now(),
            ]);
        }

        $this->command?->line('  · gastos · '.count($estados).' estados');
    }

    private function asientos(): void
    {
        $estados = ['borrador', 'cuadrado', 'aprobado', 'sincronizado', 'anulado'];

        foreach ($estados as $i => $estado) {
            DB::table('asientos_manuales')->insert([
                'fecha' => now()->subDays(8 - $i)->toDateString(),
                'glosa' => self::PREFIJO.'asiento en estado '.$estado,
                'estado' => $estado,
                'valor_total' => 500000 + ($i * 100000),
                'user_id' => $this->responsable->id,
                'created_at' => now()->subDays(8 - $i), 'updated_at' => now(),
            ]);
        }

        $this->command?->line('  · asientos manuales · '.count($estados).' estados');
    }

    /** Metas: una cumplida, una corta, y un vendedor sin meta. */
    private function comisionesYMetas(): void
    {
        $vendedores = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'Vendedor'))
            ->limit(2)->get();

        if ($vendedores->isEmpty()) {
            $this->command?->line('  · metas · sin vendedores en el sistema, se omite');

            return;
        }

        foreach ($vendedores as $i => $v) {
            DB::table('metas_vendedor')->updateOrInsert(
                ['user_id' => $v->id, 'anio' => (int) now()->year, 'mes' => (int) now()->month],
                // Una alcanzable y una exigente, para ver el verde y el rojo.
                [
                    'monto' => $i === 0 ? 500000 : 20000000,
                    'creado_por' => $this->responsable->id,
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );

            DB::table('comisiones_calculadas')->updateOrInsert(
                ['vendedor_id' => $v->id, 'anio' => (int) now()->year, 'mes' => (int) now()->month],
                [
                    'estado' => $i === 0 ? 'borrador' : 'aprobado',
                    'total_facturado' => 8500000, 'total_cobrado' => 6200000,
                    'base_comisionable' => 6200000, 'porcentaje_aplicado' => 5,
                    'comision' => 310000, 'bono_meta' => $i === 0 ? 40000 : 160000,
                    'total_a_pagar' => 350000 + ($i * 120000),
                    'calculada_at' => now(),
                    'aprobada_at' => $i === 0 ? null : now()->subDay(),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
        }

        $this->command?->line('  · metas y comisiones · '.$vendedores->count().' vendedor(es), una meta corta y una cumplida');
    }

    private function solicitudesDeCredito(): void
    {
        $estados = ['pendiente', 'aprobada_gerencia', 'rechazada'];

        foreach ($estados as $i => $estado) {
            DB::table('solicitudes_credito')->insert([
                'contacto_id' => $this->clienteMoroso->id,
                'estado' => $estado,
                'monto_pedido' => 1500000 + ($i * 500000),
                'motivo_retencion' => self::PREFIJO.'cupo excedido · solicitud '.$estado,
                'snapshot_credito' => json_encode(['cupo' => 2000000, 'saldo' => 3500000]),
                'created_at' => now()->subDays(5 - $i), 'updated_at' => now(),
            ]);
        }

        $this->command?->line('  · solicitudes de crédito · '.count($estados).' estados');
    }

    /**
     * Guías en cada estado del seguimiento.
     *
     * El estado no se guarda: se calcula con las fechas y las reglas de plazo.
     * Así que lo que se arma acá son las FECHAS que producen cada caso.
     */
    private function guias(): void
    {
        $casos = [
            [null, null, 'facturado y todavía sin salir de bodega'],
            [now()->subDays(1), null, 'salió ayer, en tránsito'],
            [now()->subDays(12), null, 'lleva doce días sin llegar'],
            [now()->subDays(30), null, 'un mes sin entregar: hay que reclamar'],
            [now()->subDays(5), now()->subDays(3), 'entregada a tiempo'],
        ];

        foreach ($casos as $i => [$despachado, $entregado, $porque]) {
            DB::table('pedidos_cliente')->insert([
                'numero' => self::PREFIJO.'GUIA-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'contacto_id' => $this->cliente->id,
                'lista_precios_id' => $this->cliente->lista_precios_id,
                'estado' => $despachado ? 'despachado' : 'facturado',
                'subtotal' => 300000, 'iva' => 0, 'total' => 300000,
                'notas_cliente' => 'QA · '.$porque,
                'transportadora' => 'QA Envíos',
                'guia_transportadora' => self::PREFIJO.'GUIA'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'enviado_at' => now()->subDays(35),
                'aprobado_at' => now()->subDays(34),
                'facturado_at' => now()->subDays(33),
                'despachado_at' => $despachado,
                'entregado_at' => $entregado,
                'entregado_por_id' => $entregado ? $this->responsable->id : null,
                'entrega_nota' => $entregado ? 'QA · recibió la encargada de la tienda' : null,
                'created_at' => now()->subDays(35), 'updated_at' => now(),
            ]);
        }

        $this->command?->line('  · guías · '.count($casos).' estados del seguimiento');
    }
}
