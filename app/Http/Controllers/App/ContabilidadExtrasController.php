<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Modules\Cartera\Models\MovimientoContable;
use App\Modules\Contabilidad\Models\AsientoManual;
use App\Modules\Contabilidad\Support\OrigenContable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FIL-D · Contabilidad Vue: Panel + Reporte detalle + landing 9 reportes.
 *
 * Re-audit M5 · reescrito para atacar patrones raíz:
 *   PATRÓN A · withTrashed opcional en TODAS las agregaciones
 *   PATRÓN C · porOrigen suma DEBE+HABER (antes solo debe → subestimaba pagos/NC)
 *   PATRÓN D · reportes hub sin duplicados + retenciones con 3 cuentas
 *   PATRÓN F · validate desde/hasta + esContable helper
 *   PATRÓN H · KPI 1 sola query (antes 3 full-scans)
 */
class ContabilidadExtrasController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless($r->user()?->esContable(), 403);
            return $next($r);
        })];
    }

    /**
     * CONT-C8 · Export CSV de un reporte contable. Gate esRoot (Aracely/Gerencia)
     *   porque descarga el libro contable completo del rango. El Contador puede
     *   ver los reportes en pantalla pero NO exfiltrar.
     *
     *   Params: reporte=mayor|balance|libro_diario (default: mayor)
     *           desde/hasta (defaults: mes actual)
     */
    public function exportarCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user()?->esRoot(), 403,
            'Solo Aracely/Gerencia puede exportar reportes contables.');
        $data = $request->validate([
            'reporte' => ['nullable', 'in:mayor,balance,libro_diario'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);
        $reporte = $data['reporte'] ?? 'mayor';
        $desde = $data['desde'] ?? now('America/Bogota')->startOfMonth()->toDateString();
        $hasta = $data['hasta'] ?? now('America/Bogota')->toDateString();

        \Illuminate\Support\Facades\Log::channel(config('logging.channels.audit') ? 'audit' : 'stack')
            ->info('contabilidad.export.csv', [
                'user_id' => $request->user()?->id,
                'reporte' => $reporte,
                'rango' => [$desde, $hasta],
            ]);

        $filename = "contabilidad-{$reporte}-{$desde}-{$hasta}.csv";
        return response()->streamDownload(function () use ($reporte, $desde, $hasta) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Fecha', 'Cuenta', 'Debe', 'Haber', 'Descripcion', 'Origen', 'OrigenID']);
            MovimientoContable::query()
                ->whereBetween('fecha', [$desde, $hasta])
                ->orderBy('fecha')->orderBy('id')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $m) {
                        fputcsv($out, [
                            $m->fecha?->toDateString(),
                            $m->cuenta_puc,
                            number_format((float) $m->debe, 2, '.', ''),
                            number_format((float) $m->haber, 2, '.', ''),
                            mb_substr((string) $m->descripcion, 0, 200),
                            OrigenContable::etiqueta($m->origen_type),
                            $m->origen_id,
                        ]);
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function panel(Request $request): Response
    {
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'incluir_anulados' => ['nullable', 'boolean'],
        ]);

        // Re-audit M5 UX-C4 · defaults unificados con Index (`hasta = hoy`).
        // Antes Panel usaba `endOfMonth` y en el mismo período los totales
        // divergían entre pantallas.
        $desde = $data['desde'] ?? now('America/Bogota')->startOfMonth()->toDateString();
        $hasta = $data['hasta'] ?? now('America/Bogota')->toDateString();
        $incluirAnulados = (bool) ($data['incluir_anulados'] ?? false);

        // Re-audit R4 SEG-M1 · bitácora consulta con anulados.
        if ($incluirAnulados) {
            \Illuminate\Support\Facades\Log::channel(config('logging.channels.audit') ? 'audit' : 'stack')
                ->info('contabilidad.panel.incluir_anulados', [
                    'user_id' => $request->user()?->id, 'rango' => [$desde, $hasta],
                ]);
        }

        // KPI 1-query.
        $kpiRow = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(debe),0) d, COALESCE(SUM(haber),0) h, COUNT(*) n')
            ->first();
        $totalDebe = (float) $kpiRow->d;
        $totalHaber = (float) $kpiRow->h;

        $topCuentas = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('cuenta_puc, SUM(debe) as debe, SUM(haber) as haber, COUNT(*) as movs')
            ->groupBy('cuenta_puc')->orderByRaw('SUM(debe) + SUM(haber) DESC')->limit(10)->get();

        // Re-audit M5 R4 DATOS-A1 · REGRESIÓN oculta corregida.
        //   Con `withTrashed=true` un reverso (D:1305=100 / H:1105=100) sumado al
        //   original (D:1105=100 / H:1305=100) inflaba `SUM(debe)` a 200 aunque
        //   el monto operacional real es 100. El "ratio 1.00" solo se cumplía
        //   sin toggle. Fix: agregación monetaria EXCLUYE anulados aunque el
        //   toggle esté activo (los reversos aparecen en tablas de detalle pero
        //   NUNCA en agregados por origen — semánticamente son ruido contable).
        //   Se mantiene withTrashed en la query base para `docs` distintos, pero
        //   `volumen` usa CASE para netear.
        $porOrigen = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->whereBetween('fecha', [$desde, $hasta])
            ->whereNotNull('origen_type')
            ->whereNotNull('origen_id')
            ->selectRaw('origen_type, COUNT(DISTINCT origen_id) as docs, SUM(CASE WHEN deleted_at IS NULL THEN COALESCE(debe,0) ELSE 0 END) as volumen')
            ->groupBy('origen_type')->orderByDesc('volumen')->get();

        // CONT-C6 · Semáforo SIIGO del panel.
        $siigoAprobados = AsientoManual::where('estado', 'aprobado')->count();
        $siigoEnSiigo = AsientoManual::where('estado', 'aprobado')->whereNotNull('siigo_journal_id')->count();
        $siigoPct = $siigoAprobados > 0 ? round(($siigoEnSiigo / $siigoAprobados) * 100, 1) : 100;
        $siigoSemaforo = $siigoPct >= 95 ? 'verde' : ($siigoPct >= 80 ? 'amarillo' : 'rojo');

        return Inertia::render('Contabilidad/Panel', [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta, 'incluir_anulados' => $incluirAnulados],
            'kpis' => [
                'total_debe' => $totalDebe,
                'total_haber' => $totalHaber,
                'balance' => round($totalDebe - $totalHaber, 2),
                'unbalanced' => abs($totalDebe - $totalHaber) > 0.01,
                'movimientos' => (int) $kpiRow->n,
                'siigo_aprobados' => $siigoAprobados,
                'siigo_en_siigo' => $siigoEnSiigo,
                'siigo_pct' => $siigoPct,
                'siigo_semaforo' => $siigoSemaforo,
            ],
            'topCuentas' => $topCuentas->map(fn ($r) => [
                'cuenta' => $r->cuenta_puc,
                'debe' => (float) $r->debe, 'haber' => (float) $r->haber,
                'movs' => (int) $r->movs,
            ]),
            'porOrigen' => $porOrigen->map(fn ($r) => [
                'origen' => OrigenContable::etiqueta($r->origen_type),
                'clave' => OrigenContable::clave($r->origen_type),
                'docs' => (int) $r->docs,
                'volumen' => (float) $r->volumen,
            ]),
        ]);
    }

    public function reporteDetalle(Request $request): Response
    {
        // Re-audit M5 SEG-A2 · whitelist explícita del tipo + guard id > 0.
        // Re-audit R2 PATRÓN α · toggle propagado también acá.
        // La whitelist sale de OrigenContable: cualquier origen que el libro
        // conozca se puede abrir, no sólo facturas, pagos y notas crédito. Antes
        // un asiento de wallet Dropi o de una carga de inventario no tenía cómo
        // rastrearse hasta su documento.
        $data = $request->validate([
            'tipo' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(OrigenContable::TIPOS))],
            'id' => ['nullable', 'integer', 'min:1'],
            'incluir_anulados' => ['nullable', 'boolean'],
        ]);

        $tipo = $data['tipo'] ?? 'factura';
        $id = (int) ($data['id'] ?? 0);
        $incluirAnulados = (bool) ($data['incluir_anulados'] ?? false);

        $tipoClass = OrigenContable::clase($tipo);

        $meta = null;
        $movs = [];
        $anuladosCount = 0;
        if ($tipoClass && $id > 0) {
            // Info del documento origen (siempre withTrashed — el documento puede
            // estar anulado pero queremos mostrar el header + badge "anulado").
            $usaSoftDeletes = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($tipoClass), true);
            $origen = ($usaSoftDeletes ? $tipoClass::query()->withTrashed() : $tipoClass::query())->find($id);
            if ($origen && ! OrigenContable::vaASiigo($tipo)) {
                // Contabilidad interna (cargas, wallet, devoluciones Dropi…):
                // no hay documento SIIGO que mostrar, sí el porqué.
                $meta = OrigenContable::resumen($tipo, $origen);
            } elseif ($origen) {
                // Sprint 3 · F.3 · badge SIIGO del documento origen (factura/pago/NC).
                //   factura → siigo_id + cufe (factura electrónica DIAN)
                //   pago    → siigo_id (voucher SIIGO)
                //   NC      → siigo_id
                $siigoId = $origen->siigo_id ?? null;
                $siigoNumero = $origen->numero_siigo ?? $origen->siigo_number ?? null;
                $syncAt = $origen->siigo_sync_at ?? $origen->emitida_at ?? null;
                $cufe = $origen->cufe ?? null;

                $meta = OrigenContable::resumen($tipo, $origen) + [
                    // F.3 · info SIIGO del origen
                    'siigo_id' => $siigoId,
                    'siigo_numero' => $siigoNumero,
                    'siigo_sync_hace' => $syncAt instanceof \Carbon\Carbon ? $syncAt->diffForHumans() : null,
                    'cufe' => $cufe,
                    'es_electronica' => (bool) ($origen->es_electronica ?? false),
                    'stamp_status' => $origen->stamp_status ?? null,
                ];
            }

            // Re-audit R2 FUNC-C2 + R4 DATOS-M1 · una sola query fusiona:
            //   - agg de d/h (respetando toggle: vivos vs vivos+anulados)
            //   - conteo de anulados asociados (siempre, para banner UI)
            // Antes eran 2 roundtrips separados y potencial divergencia entre ellos.
            $baseMovs = MovimientoContable::query()
                ->when($incluirAnulados, fn ($q) => $q->withTrashed())
                ->where('origen_type', $tipoClass)->where('origen_id', $id);

            // Query fusionada con withTrashed forzado + CASE por deleted_at.
            $agg = MovimientoContable::query()->withTrashed()
                ->where('origen_type', $tipoClass)->where('origen_id', $id)
                ->selectRaw(
                    $incluirAnulados
                        ? 'COALESCE(SUM(debe),0) d, COALESCE(SUM(haber),0) h, SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) anulados'
                        : 'COALESCE(SUM(CASE WHEN deleted_at IS NULL THEN debe ELSE 0 END),0) d, COALESCE(SUM(CASE WHEN deleted_at IS NULL THEN haber ELSE 0 END),0) h, SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) anulados'
                )
                ->first();

            $movs = (clone $baseMovs)
                ->orderBy('id')->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'cuenta' => $m->cuenta_puc,
                    'debe' => (float) $m->debe, 'haber' => (float) $m->haber,
                    'descripcion' => $m->descripcion,
                    'fecha' => $m->fecha instanceof \Carbon\Carbon ? $m->fecha->toDateString() : $m->fecha,
                    'anulado' => $m->deleted_at !== null,
                ])->all();

            $anuladosCount = (int) ($agg->anulados ?? 0);
            $totalD = (float) $agg->d;
            $totalH = (float) $agg->h;
        } else {
            $totalD = 0.0; $totalH = 0.0;
        }

        return Inertia::render('Contabilidad/ReporteDetalle', [
            'tipo' => $tipo, 'id' => $id, 'incluir_anulados' => $incluirAnulados,
            'tipos' => OrigenContable::opciones(),
            'meta' => $meta,
            'movimientos' => $movs,
            'anulados_count' => $anuladosCount,
            'totales' => ['debe' => $totalD, 'haber' => $totalH, 'diff' => round($totalD - $totalH, 2)],
        ]);
    }

    /**
     * Saldos acumulados por cuenta PUC, con el nombre que les da el catálogo.
     *
     * `$desde = null` significa «desde que existe el libro», que es lo que
     * necesita un balance general: el activo de hoy es todo lo acumulado, no
     * lo del mes. El estado de resultados sí pide un rango, porque la utilidad
     * es de un periodo.
     *
     * @return \Illuminate\Support\Collection<int, array{codigo:string, nombre:string, debe:float, haber:float}>
     */
    private function saldosPorCuenta(?string $desde, string $hasta, bool $incluirAnulados): \Illuminate\Support\Collection
    {
        $filas = MovimientoContable::query()
            ->when($incluirAnulados, fn ($q) => $q->withTrashed())
            ->when($desde, fn ($q) => $q->where('fecha', '>=', $desde))
            ->where('fecha', '<=', $hasta)
            ->selectRaw('cuenta_puc, SUM(debe) AS debe, SUM(haber) AS haber')
            ->groupBy('cuenta_puc')
            ->get();

        $nombres = \App\Modules\Contabilidad\Models\PlanCuenta::query()
            ->whereIn('codigo', $filas->pluck('cuenta_puc'))
            ->pluck('nombre', 'codigo');

        return $filas->map(fn ($f) => [
            'codigo' => (string) $f->cuenta_puc,
            'nombre' => $nombres[$f->cuenta_puc] ?? 'Cuenta fuera del plan',
            'debe' => (float) $f->debe,
            'haber' => (float) $f->haber,
        ]);
    }

    /**
     * Agrupa las cuentas de una clase PUC y les da el signo que les toca.
     *
     * El saldo de una cuenta de activo o de gasto es `debe - haber`; el de una
     * de pasivo, patrimonio o ingreso es `haber - debe`. Si se presentan todas
     * con la misma resta, el pasivo sale en negativo y el informe no se puede
     * leer.
     */
    private function agruparClase(\Illuminate\Support\Collection $saldos, string $clase, string $naturaleza): array
    {
        $cuentas = $saldos
            ->filter(fn ($c) => str_starts_with($c['codigo'], $clase))
            ->map(function ($c) use ($naturaleza) {
                $c['saldo'] = $naturaleza === 'debito'
                    ? round($c['debe'] - $c['haber'], 2)
                    : round($c['haber'] - $c['debe'], 2);
                return $c;
            })
            ->filter(fn ($c) => abs($c['saldo']) > 0.009)
            ->sortBy('codigo')
            ->values();

        return [
            'cuentas' => $cuentas->all(),
            'total' => round($cuentas->sum('saldo'), 2),
        ];
    }

    /**
     * Balance general · Activo = Pasivo + Patrimonio.
     *
     * La utilidad del ejercicio NO está en una cuenta: sale de restar clases
     * 5/6/7 a la clase 4 y se suma al patrimonio. Sin eso la ecuación nunca
     * cuadra y el informe parece roto cuando en realidad está incompleto.
     */
    public function balanceGeneral(Request $r): Response
    {
        $data = $r->validate([
            'hasta' => ['nullable', 'date'],
            'incluir_anulados' => ['nullable', 'boolean'],
        ]);
        $hasta = $data['hasta'] ?? now('America/Bogota')->toDateString();
        $incluirAnulados = (bool) ($data['incluir_anulados'] ?? false);

        $saldos = $this->saldosPorCuenta(null, $hasta, $incluirAnulados);

        $activo = $this->agruparClase($saldos, '1', 'debito');
        $pasivo = $this->agruparClase($saldos, '2', 'credito');
        $patrimonio = $this->agruparClase($saldos, '3', 'credito');

        // Resultado acumulado: ingresos menos costos y gastos.
        $ingresos = $this->agruparClase($saldos, '4', 'credito')['total'];
        $gastos = $this->agruparClase($saldos, '5', 'debito')['total'];
        $costoVentas = $this->agruparClase($saldos, '6', 'debito')['total'];
        $costosProd = $this->agruparClase($saldos, '7', 'debito')['total'];
        $resultado = round($ingresos - $gastos - $costoVentas - $costosProd, 2);

        $totalPatrimonio = round($patrimonio['total'] + $resultado, 2);
        $descuadre = round($activo['total'] - $pasivo['total'] - $totalPatrimonio, 2);

        return Inertia::render('Contabilidad/BalanceGeneral', [
            'filtros' => ['hasta' => $hasta, 'incluir_anulados' => $incluirAnulados],
            'activo' => $activo,
            'pasivo' => $pasivo,
            'patrimonio' => $patrimonio,
            'resultado_ejercicio' => $resultado,
            'total_patrimonio' => $totalPatrimonio,
            'total_pasivo_patrimonio' => round($pasivo['total'] + $totalPatrimonio, 2),
            'descuadre' => $descuadre,
            'cuadra' => abs($descuadre) <= 0.01,
            'sin_datos' => $saldos->isEmpty(),
        ]);
    }

    /**
     * Estado de resultados · de ingresos a utilidad neta.
     *
     * Se separan costo de ventas (clase 6) de los gastos de operación (clase 5)
     * porque la utilidad BRUTA —la que dice si el negocio compra y vende bien—
     * sólo descuenta el costo. Mezclarlas esconde si el problema está en el
     * margen del producto o en la estructura de la empresa.
     */
    public function estadoResultados(Request $r): Response
    {
        $data = $r->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'incluir_anulados' => ['nullable', 'boolean'],
        ]);
        $desde = $data['desde'] ?? now('America/Bogota')->startOfMonth()->toDateString();
        $hasta = $data['hasta'] ?? now('America/Bogota')->toDateString();
        $incluirAnulados = (bool) ($data['incluir_anulados'] ?? false);

        $saldos = $this->saldosPorCuenta($desde, $hasta, $incluirAnulados);

        $ingresos = $this->agruparClase($saldos, '4', 'credito');
        $costoVentas = $this->agruparClase($saldos, '6', 'debito');
        $gastos = $this->agruparClase($saldos, '5', 'debito');
        $costosProd = $this->agruparClase($saldos, '7', 'debito');

        $utilidadBruta = round($ingresos['total'] - $costoVentas['total'] - $costosProd['total'], 2);
        $utilidadNeta = round($utilidadBruta - $gastos['total'], 2);
        $margen = fn (float $v) => $ingresos['total'] > 0
            ? round($v / $ingresos['total'] * 100, 2)
            : null;

        return Inertia::render('Contabilidad/EstadoResultados', [
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'incluir_anulados' => $incluirAnulados],
            'ingresos' => $ingresos,
            'costo_ventas' => $costoVentas,
            'costos_produccion' => $costosProd,
            'gastos' => $gastos,
            'utilidad_bruta' => $utilidadBruta,
            'utilidad_neta' => $utilidadNeta,
            'margen_bruto' => $margen($utilidadBruta),
            'margen_neto' => $margen($utilidadNeta),
            'sin_datos' => $saldos->isEmpty(),
        ]);
    }

    public function reportes(): Response
    {
        // Cada reporte declara de qué tabla sale y con qué columna se sabe si
        // ese documento llegó a SIIGO. Antes todos traían `siigo_ok => true`
        // escrito a mano: la etiqueta salía verde aunque no se hubiera enviado
        // ni un documento, que es justo lo que hay que poder detectar.
        $cob = fn (string $tabla, string $col, ?callable $filtro = null) => $this->cobertura($tabla, $col, $filtro);

        $deMovimientos = $cob('movimientos_contables', 'siigo_journal_id');
        $deFacturas = $cob('facturas_venta', 'siigo_id', fn ($q) => $q->where('es_electronica', true));

        return Inertia::render('Contabilidad/Reportes', [
            'reportes' => [
                ['nombre' => 'Balance de comprobación', 'desc' => 'Sumas y saldos por cuenta PUC', 'href' => '/app/contabilidad', 'listo' => true, 'familia' => 'Estados', 'siigo' => $deMovimientos, 'export' => 'Excel'],
                ['nombre' => 'Panel contable',          'desc' => 'KPIs, top cuentas y por origen', 'href' => '/app/contabilidad/panel', 'listo' => true, 'familia' => 'Estados', 'siigo' => $deMovimientos, 'export' => null],
                ['nombre' => 'Libro diario',            'desc' => 'Todos los asientos cronológicos del mes', 'href' => '/app/cartera/movimientos', 'listo' => true, 'familia' => 'Auxiliares', 'siigo' => $deMovimientos, 'export' => 'Excel'],
                ['nombre' => 'Movimientos por cuenta',  'desc' => 'Filtra por prefijo PUC (ej. 1305 clientes)', 'href' => '/app/cartera/movimientos?cuenta=1305', 'listo' => true, 'familia' => 'Auxiliares', 'siigo' => $deMovimientos, 'export' => 'Excel'],
                ['nombre' => 'Facturas emitidas',       'desc' => 'Ventas del periodo', 'href' => '/app/facturas', 'listo' => true, 'familia' => 'Auxiliares', 'siigo' => $deFacturas, 'export' => 'PDF+Excel'],
                ['nombre' => 'Pagos recibidos',         'desc' => 'Ingresos del periodo', 'href' => '/app/pagos', 'listo' => true, 'familia' => 'Auxiliares', 'siigo' => $cob('pagos_venta', 'siigo_id'), 'export' => 'Excel'],
                ['nombre' => 'Compras del periodo',     'desc' => 'OCs recibidas', 'href' => '/app/compras/reporte', 'listo' => true, 'familia' => 'Auxiliares', 'siigo' => $cob('compras_recepciones', 'siigo_id'), 'export' => 'Excel'],
                ['nombre' => 'Retenciones (RETEFTE + RETEIVA + RETEICA)', 'desc' => 'Base para declaración DIAN · cuentas 2365/2367/2368', 'href' => '/app/cartera/movimientos?cuentas=2365,2367,2368', 'listo' => true, 'familia' => 'Impuestos', 'siigo' => $deMovimientos, 'export' => 'Excel'],
                ['nombre' => 'Balance general',         'desc' => 'Activo = Pasivo + Patrimonio, con la utilidad del ejercicio', 'href' => '/app/contabilidad/balance-general', 'listo' => true, 'familia' => 'Estados', 'siigo' => $deMovimientos, 'export' => null],
                ['nombre' => 'Estado de resultados',    'desc' => 'De ingresos a utilidad neta, con margen bruto y neto', 'href' => '/app/contabilidad/estado-resultados', 'listo' => true, 'familia' => 'Estados', 'siigo' => $deMovimientos, 'export' => null],
            ],
            // Reportes que NO salen del ERP: los genera la contabilidad de SIIGO.
            'reportes_siigo' => [
                ['nombre' => 'Balance de prueba (oficial SIIGO)', 'desc' => 'Excel generado por SIIGO con el balance real del año', 'accion' => '/app/contabilidad/siigo/balance-prueba'],
                ['nombre' => 'Cuentas por pagar (SIIGO)', 'desc' => 'Saldos vigentes con proveedores según SIIGO', 'accion' => '/app/contabilidad/siigo/cuentas-por-pagar'],
            ],
            // Sprint 3 · F.4 · info de sync SIIGO al hub.
            'siigo_estado' => [
                'push_activo' => \App\Modules\Siigo\Models\SiigoConfig::pushAutoActivo(),
                'ambiente' => optional(\App\Modules\Siigo\Models\SiigoConfig::query()->first())->ambiente ?? 'sandbox',
                'ultima_sync_productos' => optional(\App\Modules\Siigo\Models\SiigoConfig::query()->first()?->sync_productos_at)->diffForHumans(),
            ],
        ]);
    }

    /**
     * Balance de prueba oficial · lo genera SIIGO y devuelve un Excel.
     * No lo construye el ERP: es la contabilidad real contra la que comparar.
     */
    public function siigoBalancePrueba(Request $r, \App\Modules\Siigo\Services\SiigoReportesService $svc)
    {
        $datos = $r->validate([
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'mes_inicio' => ['nullable', 'integer', 'min:1', 'max:13'],
            'mes_fin' => ['nullable', 'integer', 'min:1', 'max:13'],
        ]);

        try {
            $rep = $svc->balanceDePrueba(
                (int) ($datos['anio'] ?? now('America/Bogota')->year),
                (int) ($datos['mes_inicio'] ?? 1),
                (int) ($datos['mes_fin'] ?? 13),
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'SIIGO no pudo generar el balance: '.$e->getMessage());
        }

        return back()->with('success', 'Balance generado en SIIGO.')
            ->with('siigo_reporte_url', $rep['url']);
    }

    /**
     * Conciliación real de facturas ERP ↔ SIIGO.
     * A diferencia de "pendientes de SIIGO" (que mira sólo el ERP), ésta le
     * pregunta a SIIGO y detecta importes distintos y facturas emitidas por
     * fuera del sistema.
     */
    public function conciliacionFacturas(Request $r, \App\Modules\Siigo\Services\ConciliadorFacturasSiigo $svc): Response
    {
        $datos = $r->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $desde = isset($datos['desde'])
            ? \Illuminate\Support\Carbon::parse($datos['desde'])
            : now('America/Bogota')->subDays(30);
        $hasta = isset($datos['hasta'])
            ? \Illuminate\Support\Carbon::parse($datos['hasta'])
            : now('America/Bogota');

        try {
            $res = $svc->conciliar($desde, $hasta);
            $error = null;
        } catch (\Throwable $e) {
            $res = null;
            $error = $e->getMessage();
        }

        return Inertia::render('Contabilidad/ConciliacionSiigo', [
            'resultado' => $res,
            'error' => $error,
            'filtros' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
        ]);
    }

    /** Cuentas por pagar vigentes según SIIGO (no según el ERP). */
    public function siigoCuentasPorPagar(\App\Modules\Siigo\Services\SiigoReportesService $svc): Response
    {
        try {
            $filas = $svc->cuentasPorPagar();
            $error = null;
        } catch (\Throwable $e) {
            $filas = [];
            $error = $e->getMessage();
        }

        return Inertia::render('Contabilidad/CuentasPorPagarSiigo', [
            'filas' => $filas,
            'total' => array_sum(array_column($filas, 'saldo')),
            'error' => $error,
        ]);
    }

    /**
     * Cuántos documentos de la fuente del reporte llegaron realmente a SIIGO.
     *
     * Devuelve `['total' => n, 'en_siigo' => n, 'pendientes' => n, 'pct' => 0-100]`
     * para que la pantalla muestre un dato medido en vez de un semáforo fijo.
     * Sin filas todavía, `pct` es null: "no hay nada que comparar" no es lo
     * mismo que "todo sincronizado".
     *
     * @param  ?callable(\Illuminate\Database\Query\Builder): mixed  $filtro
     * @return array{total:int, en_siigo:int, pendientes:int, pct:?int}
     */
    private function cobertura(string $tabla, string $columna, ?callable $filtro = null): array
    {
        $base = fn () => tap(\DB::table($tabla), fn ($q) => $filtro && $filtro($q));

        $total = (clone $base())->count();
        $enSiigo = (clone $base())->whereNotNull($columna)->where($columna, '!=', '')->count();

        return [
            'total' => $total,
            'en_siigo' => $enSiigo,
            'pendientes' => max(0, $total - $enSiigo),
            'pct' => $total > 0 ? (int) round($enSiigo * 100 / $total) : null,
        ];
    }

}
