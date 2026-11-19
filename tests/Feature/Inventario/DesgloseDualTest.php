<?php

/**
 * C-F8 · Suite de tests del refactor "desglose dual de stock".
 *
 * Cubre las 6 capas del refactor:
 *   1. BD constraints + trigger
 *   2. Auto-populate de producto_id en InventarioMovimiento::creating
 *   3. Observer Producto::created que genera kardex del stock_directo
 *   4. Motor polimórfico StockService
 *   5. Guardarraíl de variantes en producto agregado
 *   6. Acciones (ReservarStock, VerificarAlertasStock) polimórficas
 *
 * NO cubre (queda para R2):
 *   - EjecutarTraslado con items agregados
 *   - PrepararTomaFisica con productos agregados
 *   - RecibirMercancia / LiquidarImportacion agregados
 */

use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Models\InventarioUbicacion;
use App\Modules\Dropi\Models\Producto;
use App\Modules\Dropi\Models\ProductoVariante;
use App\Modules\Inventario\Actions\ReservarStock;
use App\Modules\Inventario\Actions\VerificarAlertasStock;
use App\Modules\Inventario\Models\AlertaStockConfig;
use App\Modules\Inventario\Models\ReservaInventario;
use App\Modules\Inventario\Services\StockService;
use Illuminate\Support\Facades\DB;

/**
 * C-F8 · Estos tests requieren MariaDB/MySQL como conexión porque validan:
 *   - Trigger `trg_bloqueo_toggle_desglose_stock` (SIGNAL SQLSTATE, no en SQLite)
 *   - CHECK constraint `chk_invmov_sujeto` (SQLite soporte parcial)
 *   - Funciones NOW() en migraciones del proyecto
 *
 * El phpunit.xml default usa `sqlite :memory:`. Para correr esta suite con MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=greatbaby_test php artisan test tests/Feature/Inventario/DesgloseDualTest.php
 *
 * Alternativa fast-feedback: `php artisan desglose-dual:smoke-test` — comando
 * artisan que corre las validaciones críticas en menos de 2 segundos sobre la
 * BD real de dev sin instalación de infraestructura de tests.
 *
 * Pest.php global aplica RefreshDatabase → estos tests skipean si SQLite.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('DesgloseDualTest requiere MariaDB/MySQL (triggers + CHECK constraints). Usa `artisan desglose-dual:smoke-test` como alternativa.');
    }
    $this->bodega = InventarioUbicacion::firstOrCreate(
        ['codigo' => 'TEST-BOD'],
        ['nombre' => 'Bodega test', 'activa' => true, 'categoria' => 'venta'],
    );
});

// ═══════════════════════════════════════════════════════════════
// 1 · Migraciones + CHECK constraints + Trigger de bloqueo
// ═══════════════════════════════════════════════════════════════

describe('BD constraints', function () {
    it('rechaza insertar mov con variante_id Y producto_id NULL', function () {
        $this->expectException(Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/chk_invmov_sujeto/i');

        InventarioMovimiento::create([
            'variante_id' => null,
            'producto_id' => null,
            'ubicacion_id' => $this->bodega->id,
            'tipo' => 'test_null_null',
            'cantidad' => 1,
        ]);
    });

    it('trigger BD bloquea cambio desglose_stock si producto tiene movimientos', function () {
        $p = Producto::create([
            'referencia' => 'TRG-'.uniqid(),
            'nombre' => 'Con movs',
            'desglose_stock' => false,
            'stock_directo' => 10, // observer crea el mov automático
        ]);
        expect($p->movimientos()->exists())->toBeTrue();

        expect(fn () => $p->update(['desglose_stock' => true]))
            ->toThrow(Illuminate\Database\QueryException::class, 'No se puede cambiar desglose_stock');
    });

    it('permite cambio desglose_stock si producto NO tiene movimientos', function () {
        $p = Producto::create([
            'referencia' => 'FREE-'.uniqid(),
            'nombre' => 'Sin movs',
            'desglose_stock' => true,
            'stock_directo' => 0,
        ]);
        expect($p->movimientos()->exists())->toBeFalse();

        $p->update(['desglose_stock' => false]);
        expect($p->fresh()->desglose_stock)->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════════
// 2 · Auto-populate producto_id en InventarioMovimiento::creating
// ═══════════════════════════════════════════════════════════════

describe('InventarioMovimiento auto-populate producto_id', function () {
    it('popula producto_id desde variante cuando se omite', function () {
        $p = Producto::create(['referencia' => 'AUTO-'.uniqid(), 'nombre' => 'x', 'desglose_stock' => true]);
        $v = ProductoVariante::create(['producto_id' => $p->id, 'color_codigo' => 'A']);

        $mov = InventarioMovimiento::create([
            'variante_id' => $v->id,
            // producto_id NO se pasa
            'ubicacion_id' => $this->bodega->id,
            'tipo' => 'auto_populate_test',
            'cantidad' => 5,
        ]);

        expect($mov->producto_id)->toBe($p->id);
    });

    it('no toca producto_id cuando ya viene set (mov agregado)', function () {
        $p = Producto::create(['referencia' => 'PID-'.uniqid(), 'nombre' => 'x', 'desglose_stock' => false, 'stock_directo' => 0]);

        $mov = InventarioMovimiento::create([
            'producto_id' => $p->id,
            'variante_id' => null,
            'ubicacion_id' => $this->bodega->id,
            'tipo' => 'agregado_directo',
            'cantidad' => 3,
        ]);

        expect($mov->producto_id)->toBe($p->id)
            ->and($mov->variante_id)->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════════
// 3 · Observer Producto::created inserta kardex del stock_directo
// ═══════════════════════════════════════════════════════════════

describe('Observer Producto::created inserta kardex inicial', function () {
    it('crea mov de stock inicial si desglose=false + stock_directo>0', function () {
        $p = Producto::create([
            'referencia' => 'OBS-'.uniqid(),
            'nombre' => 'con stock inicial',
            'desglose_stock' => false,
            'stock_directo' => 42,
        ]);

        $svc = app(StockService::class);
        expect($svc->saldoFisicoProducto($p->id))->toBe(42);
    });

    it('NO crea mov cuando desglose=true (aunque stock_directo>0)', function () {
        $p = Producto::create([
            'referencia' => 'NOBS-'.uniqid(),
            'nombre' => 'granular',
            'desglose_stock' => true,
            'stock_directo' => 99, // se ignora en granular
        ]);

        expect($p->movimientos()->count())->toBe(0);
    });

    it('NO duplica mov en re-save', function () {
        $p = Producto::create([
            'referencia' => 'IDEM-'.uniqid(),
            'nombre' => 'x',
            'desglose_stock' => false,
            'stock_directo' => 15,
        ]);

        // Simular un observer llamado dos veces (bug potencial).
        $p->save();

        expect($p->movimientos()->count())->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════════
// 4 · StockService polimórfico
// ═══════════════════════════════════════════════════════════════

describe('StockService polimórfico', function () {
    it('saldoFisicoSujeto con variante suma solo esa variante', function () {
        $p = Producto::create(['referencia' => 'SVC-V-'.uniqid(), 'nombre' => 'x', 'desglose_stock' => true]);
        $v = ProductoVariante::create(['producto_id' => $p->id]);
        InventarioMovimiento::create([
            'variante_id' => $v->id, 'ubicacion_id' => $this->bodega->id,
            'tipo' => 'test', 'cantidad' => 20,
        ]);

        expect(app(StockService::class)->saldoFisicoSujeto($v, $this->bodega->id))->toBe(20);
    });

    it('saldoFisicoSujeto con producto agregado suma solo movs sin variante', function () {
        $p = Producto::create([
            'referencia' => 'SVC-P-'.uniqid(), 'nombre' => 'x',
            'desglose_stock' => false, 'stock_directo' => 30, // observer crea mov de 30
        ]);

        expect(app(StockService::class)->saldoFisicoSujeto($p))->toBe(30);
    });

    it('saldoFisicoSujeto con producto GRANULAR lanza DomainException', function () {
        $p = Producto::create(['referencia' => 'GUARD-'.uniqid(), 'nombre' => 'x', 'desglose_stock' => true]);

        expect(fn () => app(StockService::class)->saldoFisicoSujeto($p))
            ->toThrow(DomainException::class, 'desglose_stock=true');
    });

    it('Producto::stockEn() agrega variantes cuando granular', function () {
        $p = Producto::create(['referencia' => 'AGG-'.uniqid(), 'nombre' => 'x', 'desglose_stock' => true]);
        $v1 = ProductoVariante::create(['producto_id' => $p->id, 'color_codigo' => 'R']);
        $v2 = ProductoVariante::create(['producto_id' => $p->id, 'color_codigo' => 'A']);
        InventarioMovimiento::create(['variante_id' => $v1->id, 'ubicacion_id' => $this->bodega->id, 'tipo' => 't', 'cantidad' => 10]);
        InventarioMovimiento::create(['variante_id' => $v2->id, 'ubicacion_id' => $this->bodega->id, 'tipo' => 't', 'cantidad' => 15]);

        expect($p->stockEn($this->bodega->id))->toBe(25);
    });
});

// ═══════════════════════════════════════════════════════════════
// 5 · Guardarraíl de variantes en producto agregado
// ═══════════════════════════════════════════════════════════════

describe('Guardarraíl variantes', function () {
    it('bloquea crear variante en producto agregado', function () {
        $p = Producto::create([
            'referencia' => 'BAR-'.uniqid(), 'nombre' => 'x',
            'desglose_stock' => false, 'stock_directo' => 0,
        ]);

        expect(fn () => ProductoVariante::create(['producto_id' => $p->id, 'color_codigo' => 'R']))
            ->toThrow(DomainException::class, 'está en modo AGREGADO');
    });

    it('permite crear variante si producto es granular', function () {
        $p = Producto::create(['referencia' => 'OK-'.uniqid(), 'nombre' => 'x', 'desglose_stock' => true]);

        $v = ProductoVariante::create(['producto_id' => $p->id, 'color_codigo' => 'R']);
        expect($v->id)->toBeGreaterThan(0);
    });
});

// ═══════════════════════════════════════════════════════════════
// 6 · ReservarStock polimórfico
// ═══════════════════════════════════════════════════════════════

describe('ReservarStock polimórfico', function () {
    it('reserva por variante con handle() legacy', function () {
        $p = Producto::create(['referencia' => 'RES-V-'.uniqid(), 'nombre' => 'x', 'desglose_stock' => true]);
        $v = ProductoVariante::create(['producto_id' => $p->id]);
        InventarioMovimiento::create(['variante_id' => $v->id, 'ubicacion_id' => $this->bodega->id, 'tipo' => 't', 'cantidad' => 50]);

        $origen = InventarioMovimiento::first(); // cualquier modelo como origen
        $reserva = ReservarStock::run($v->id, $this->bodega->id, 10, $origen);

        /*
         * La reserva de una variante TAMBIÉN guarda su producto.
         *
         * Antes este test exigía que `producto_id` quedara nulo, y ese era el
         * defecto: la disponibilidad masiva del portal suma kardex y reservas
         * con el MISMO filtro por `producto_id`, así que encontraba el físico y
         * no encontraba lo reservado. El portal ofrecía mercancía que ya estaba
         * apartada para otro pedido.
         *
         * A quién pertenece la reserva lo sigue decidiendo `variante_id`: los
         * candados y la búsqueda de reserva existente filtran por ahí. Esto es
         * el mismo dato denormalizado, igual que lo hace el kardex.
         */
        expect($reserva->variante_id)->toBe($v->id)
            ->and($reserva->producto_id)->toBe($p->id)
            ->and($reserva->cantidad)->toBe(10);
    });

    it('reserva por producto agregado con handleSujeto()', function () {
        $p = Producto::create([
            'referencia' => 'RES-P-'.uniqid(), 'nombre' => 'x',
            'desglose_stock' => false, 'stock_directo' => 100,
        ]);
        $origen = InventarioMovimiento::first();

        $reserva = app(ReservarStock::class)->handleSujeto($p, $this->bodega->id, 25, $origen);

        expect($reserva->producto_id)->toBe($p->id)
            ->and($reserva->variante_id)->toBeNull()
            ->and($reserva->cantidad)->toBe(25);
    });

    it('rechaza reservar por producto granular (guardarraíl)', function () {
        $p = Producto::create(['referencia' => 'REG-'.uniqid(), 'nombre' => 'x', 'desglose_stock' => true]);
        // Hace falta un movimiento que sirva de origen: sin él
        // `InventarioMovimiento::first()` devuelve null y `handleSujeto()`
        // reventaba por el tipo del parámetro antes de llegar al guardarraíl,
        // que es justo lo que esta prueba quiere verificar.
        $v = ProductoVariante::create(['producto_id' => $p->id]);
        InventarioMovimiento::create([
            'variante_id' => $v->id, 'ubicacion_id' => $this->bodega->id,
            'tipo' => 't', 'cantidad' => 1,
        ]);
        $origen = InventarioMovimiento::first();

        expect(fn () => app(ReservarStock::class)->handleSujeto($p, $this->bodega->id, 5, $origen))
            ->toThrow(InvalidArgumentException::class, 'desglose_stock=true');
    });

    it('rechaza si stock insuficiente en producto agregado', function () {
        $p = Producto::create([
            'referencia' => 'INS-'.uniqid(), 'nombre' => 'x',
            'desglose_stock' => false, 'stock_directo' => 5,
        ]);
        $origen = InventarioMovimiento::first();

        expect(fn () => app(ReservarStock::class)->handleSujeto($p, $this->bodega->id, 10, $origen))
            ->toThrow(InvalidArgumentException::class, 'Stock insuficiente');
    });
});

// ═══════════════════════════════════════════════════════════════
// 7 · VerificarAlertasStock con config agregada
// ═══════════════════════════════════════════════════════════════

describe('VerificarAlertasStock', function () {
    it('dispara alerta minimo cuando config agregada baja del umbral', function () {
        $p = Producto::create([
            'referencia' => 'ALE-'.uniqid(), 'nombre' => 'x',
            'desglose_stock' => false, 'stock_directo' => 3, // saldo = 3
        ]);
        AlertaStockConfig::create([
            'producto_id' => $p->id,
            'variante_id' => null,
            'ubicacion_id' => $this->bodega->id,
            'stock_minimo' => 10, // 3 < 10 → debe disparar
            'notificar_email' => false,
            'notificar_whatsapp' => false,
            'activa' => true,
        ]);

        $r = VerificarAlertasStock::run();

        expect($r['disparadas'])->toBeGreaterThan(0);
    });
});
