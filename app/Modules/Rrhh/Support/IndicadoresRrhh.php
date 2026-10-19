<?php

namespace App\Modules\Rrhh\Support;

use App\Modules\Rrhh\Models\Empleado;
use Illuminate\Support\Carbon;

/**
 * F11 · Los indicadores que Elena lleva a la Junta Directiva.
 *
 * Ella los enumeró uno por uno: «cuando yo tenga reunión en junta directiva,
 * yo realmente me voy a acercar es a mostrarle a la junta directiva
 * indicadores. La rotación del personal, el ausentismo, el indicador de la
 * cobertura de capacitaciones, el reporte de por qué la gente se retira, y
 * cómo estamos creciendo —nosotros le decimos arquitectura de personal—».
 *
 * De esos cinco, CUATRO salen de lo que el módulo ya guarda. El ausentismo NO:
 * necesita el registro de incapacidades y permisos, que vive en el módulo de
 * novedades y todavía no existe. Se devuelve marcado como no disponible en vez
 * de un número inventado — un indicador falso en una junta directiva es peor
 * que un indicador que falta.
 *
 * Los cálculos van acá y no en el controlador porque son los MISMOS números
 * que salen por pantalla, por PDF y por Excel. Si se repitieran, un día dirían
 * cosas distintas.
 */
class IndicadoresRrhh
{
    /**
     * Por qué se va la gente. Lista cerrada para poder contar.
     *
     * El motivo en texto libre sirve para leer el caso; sin esta lista el
     * reporte sería un montón de frases distintas que dicen lo mismo y no se
     * pueden sumar.
     */
    public const TIPOS_RETIRO = [
        'renuncia' => 'Renuncia voluntaria',
        'no_supero_prueba' => 'No superó el período de prueba',
        'terminacion_contrato' => 'Terminación del contrato',
        'fin_obra' => 'Terminó la obra o labor',
        'justa_causa' => 'Terminación con justa causa',
        'mutuo_acuerdo' => 'Mutuo acuerdo',
        'otro' => 'Otro',
    ];

    public function __construct(
        private Carbon $desde,
        private Carbon $hasta,
    ) {}

    public static function entre(string $desde, string $hasta): self
    {
        return new self(Carbon::parse($desde)->startOfDay(), Carbon::parse($hasta)->endOfDay());
    }

    public function todo(): array
    {
        return [
            'rango' => [
                'desde' => $this->desde->toDateString(),
                'hasta' => $this->hasta->toDateString(),
            ],
            'rotacion' => $this->rotacion(),
            'estructura' => $this->estructura(),
            'movimiento' => $this->movimientoPorMes(),
            'retiros' => $this->motivosDeRetiro(),
            'induccion' => $this->coberturaDeInduccion(),
            'ausentismo' => $this->ausentismo(),
        ];
    }

    /**
     * Rotación del período: retiros sobre el promedio de personal.
     *
     * El promedio se calcula sobre la plantilla de CADA MES, no sobre
     * «(inicio + final) / 2». La diferencia no es académica: se vio en la
     * primera pantalla con datos reales.
     *
     *   Great Baby empezó a usar el módulo este año, así que al 1 de enero no
     *   había nadie registrado. Con la fórmula de dos puntos, el promedio daba
     *   1,5 personas y la rotación salía **200%** — un número que Elena habría
     *   llevado a la Junta y que no significa nada.
     *
     * El promedio mensual reparte el peso a lo largo del período y aguanta que
     * la empresa arranque de cero o crezca rápido, que es exactamente lo que
     * está pasando acá.
     *
     * Se devuelve también `confiable`: con menos de un par de meses de datos o
     * con casi nadie en plantilla, el porcentaje se mueve demasiado con un
     * solo retiro y conviene decirlo en vez de que alguien decida sobre él.
     */
    public function rotacion(): array
    {
        $alInicio = $this->plantillaAl($this->desde);
        $alFinal = $this->plantillaAl($this->hasta);
        $retiros = $this->retirosDelPeriodo()->count();
        $ingresos = Empleado::whereBetween('fecha_ingreso', [$this->desde, $this->hasta])->count();

        $promedio = $this->plantillaPromedio();

        return [
            'al_inicio' => $alInicio,
            'al_final' => $alFinal,
            'ingresos' => $ingresos,
            'retiros' => $retiros,
            'promedio' => round($promedio, 1),
            // Sin gente no hay rotación que calcular; dividir daría infinito.
            'porcentaje' => $promedio > 0 ? round(($retiros / $promedio) * 100, 1) : 0.0,
            'confiable' => $promedio >= 5,
            'advertencia' => $promedio >= 5 ? null
                : 'Con un promedio de '.round($promedio, 1).' persona(s) en el período, un solo '
                    .'retiro mueve mucho el porcentaje. Mirá el número de retiros antes que el %.',
        ];
    }

    /** La «arquitectura de personal»: cuánta gente hay y dónde. */
    public function estructura(): array
    {
        $activos = Empleado::where('estado', 'activo')->get(['area', 'cargo', 'tipo_contrato']);

        $porClave = fn (string $clave) => $activos
            ->groupBy(fn ($e) => $e->{$clave} ?: 'Sin definir')
            ->map->count()
            ->sortDesc()
            ->map(fn ($n, $k) => ['nombre' => $k, 'cantidad' => $n])
            ->values()->all();

        return [
            'total' => $activos->count(),
            'por_area' => $porClave('area'),
            'por_cargo' => $porClave('cargo'),
            'por_contrato' => $porClave('tipo_contrato'),
        ];
    }

    /**
     * Cuántos entraron y cuántos salieron, mes a mes.
     *
     * Es el «cuántos ingresaron, cuánto crecimos» de la reunión: el saldo de
     * cada mes dice si la empresa está creciendo o reponiendo.
     */
    public function movimientoPorMes(): array
    {
        $meses = [];
        $cursor = $this->desde->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->hasta)) {
            $fin = $cursor->copy()->endOfMonth();

            $ingresos = Empleado::whereBetween('fecha_ingreso', [$cursor, $fin])->count();
            $retiros = Empleado::whereNotNull('fecha_retiro')
                ->whereBetween('fecha_retiro', [$cursor, $fin])->count();

            $meses[] = [
                'mes' => $cursor->format('Y-m'),
                'etiqueta' => $cursor->locale('es')->isoFormat('MMM YYYY'),
                'ingresos' => $ingresos,
                'retiros' => $retiros,
                'saldo' => $ingresos - $retiros,
            ];

            $cursor->addMonth();
        }

        return $meses;
    }

    /** Por qué se fue la gente, agrupado y contado. */
    public function motivosDeRetiro(): array
    {
        $retiros = $this->retirosDelPeriodo()->get(['nombre', 'cargo', 'area', 'tipo_retiro', 'motivo_retiro', 'fecha_retiro']);

        $porTipo = $retiros
            ->groupBy(fn ($e) => $e->tipo_retiro ?: 'sin_registrar')
            ->map->count()
            ->sortDesc()
            ->map(fn ($n, $tipo) => [
                'tipo' => $tipo,
                'label' => self::TIPOS_RETIRO[$tipo] ?? 'Sin motivo registrado',
                'cantidad' => $n,
            ])->values()->all();

        return [
            'total' => $retiros->count(),
            'por_tipo' => $porTipo,
            // El detalle también, porque en una junta siempre preguntan «¿y
            // quién fue?» y leer el caso concreto es lo que explica el número.
            'detalle' => $retiros->sortByDesc('fecha_retiro')->map(fn ($e) => [
                'nombre' => $e->nombre,
                'cargo' => $e->cargo,
                'area' => $e->area,
                'tipo' => self::TIPOS_RETIRO[$e->tipo_retiro] ?? 'Sin registrar',
                'motivo' => $e->motivo_retiro,
                'fecha' => $e->fecha_retiro?->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * Cobertura de inducción.
     *
     * Elena habló de «cobertura de capacitaciones». Hoy el sistema sólo guarda
     * la INDUCCIÓN, así que se llama por su nombre: las capacitaciones por
     * cargo son otra cosa, y ella misma dijo en la reunión que todavía no las
     * tiene construidas.
     */
    public function coberturaDeInduccion(): array
    {
        $activos = Empleado::where('estado', 'activo')->count();
        $conInduccion = Empleado::where('estado', 'activo')
            ->where('estado_induccion', 'completada')->count();

        return [
            'activos' => $activos,
            'con_induccion' => $conInduccion,
            'pendientes' => $activos - $conInduccion,
            'porcentaje' => $activos > 0 ? round(($conInduccion / $activos) * 100, 1) : 0.0,
            'nota' => 'Cubre la inducción corporativa. Las capacitaciones por cargo todavía no se registran.',
        ];
    }

    /**
     * Ausentismo · NO disponible todavía.
     *
     * Necesita el registro de incapacidades, permisos y ausencias, que vive en
     * el módulo de novedades y aún no existe. Se devuelve marcado como no
     * disponible, con la razón: un número inventado en una junta directiva es
     * peor que un indicador que falta, porque el inventado se cree.
     */
    public function ausentismo(): array
    {
        return [
            'disponible' => false,
            'motivo' => 'Necesita el registro de incapacidades y permisos (módulo de novedades), '
                .'que todavía no está construido. Aquí no se muestra un estimado para no '
                .'llevar un número sin respaldo a la Junta.',
        ];
    }

    // ───────────────────────────── internos ─────────────────────────────

    /**
     * Promedio de personal del período, mes a mes.
     *
     * Se mide al cierre de cada mes y se promedia. Es más estable que mirar
     * sólo el primer y el último día, sobre todo cuando la empresa arranca el
     * período con poca gente registrada.
     */
    private function plantillaPromedio(): float
    {
        $cortes = [];
        $cursor = $this->desde->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->hasta)) {
            $corte = $cursor->copy()->endOfMonth();
            // El último mes se mide hasta donde llega el período, no hasta su
            // fin: si no, se contaría gente que todavía no ha entrado.
            $cortes[] = $this->plantillaAl($corte->greaterThan($this->hasta) ? $this->hasta : $corte);
            $cursor->addMonth();
        }

        return $cortes ? array_sum($cortes) / count($cortes) : 0.0;
    }

    /** Cuánta gente estaba trabajando en una fecha dada. */
    private function plantillaAl(Carbon $fecha): int
    {
        return Empleado::whereNotNull('fecha_ingreso')
            ->where('fecha_ingreso', '<=', $fecha)
            ->where(fn ($q) => $q->whereNull('fecha_retiro')->orWhere('fecha_retiro', '>', $fecha))
            ->count();
    }

    private function retirosDelPeriodo()
    {
        return Empleado::whereNotNull('fecha_retiro')
            ->whereBetween('fecha_retiro', [$this->desde, $this->hasta]);
    }
}
