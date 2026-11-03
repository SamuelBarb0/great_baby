<?php

namespace App\Modules\Rrhh\Support;

use App\Modules\Rrhh\Enums\MotivoSalida;
use App\Modules\Rrhh\Enums\TipoNovedad;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EntrevistaRetiro;
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
 * Los cinco ya salen. Cuatro de ellos siempre estuvieron en lo que el módulo
 * guardaba; el ausentismo quedó en «no disponible» hasta la Fase B, porque las
 * incapacidades y los permisos vivían en un Excel por fuera del sistema. Ahora
 * los toma del cuadro de novedades.
 *
 * Lo que sí se mantuvo: ninguno se estima. Si el período no tiene novedades
 * registradas, el ausentismo vuelve a decir «no disponible» en vez de pintar
 * un 0 % — un indicador falso en una junta directiva es peor que uno que falta,
 * porque al falso le creen.
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
        ] + $this->loQueDijoLaGente();
    }

    /**
     * Lo que dijeron en la entrevista de retiro, que es otra cosa.
     *
     * `tipo_retiro` dice cómo clasifica la EMPRESA la salida: veinte renuncias
     * se cuentan igual. El motivo de la entrevista dice qué contestó LA
     * PERSONA, y ahí se separa lo que es mercado —mejor oferta, mudanza— de lo
     * que la empresa puede arreglar: el jefe, el clima, la carga.
     *
     * Por eso se devuelve también `accionables`: si de diez salidas siete caen
     * en esa mitad, el problema no es el mercado. Ése es el número que
     * cambia una conversación de Junta.
     *
     * `cobertura` dice sobre cuántas salidas se alcanzó a hacer la entrevista.
     * Sin ese dato, un «0 % por clima laboral» puede significar que nadie se
     * quejó o que no se entrevistó a nadie, que es lo contrario.
     */
    private function loQueDijoLaGente(): array
    {
        $ids = $this->retirosDelPeriodo()->pluck('id');

        $entrevistas = EntrevistaRetiro::whereIn('empleado_id', $ids)
            ->whereNotNull('motivo_principal')
            ->get();

        if ($entrevistas->isEmpty()) {
            return ['entrevistas' => [
                'disponible' => false,
                'motivo' => 'Todavía no hay entrevistas de retiro con motivo registrado en este período.',
            ]];
        }

        $porMotivo = $entrevistas
            ->groupBy(fn ($ev) => $ev->motivo_principal->value)
            ->map->count()
            ->sortDesc()
            ->map(fn ($n, $clave) => [
                'motivo' => $clave,
                'label' => MotivoSalida::from($clave)->label(),
                'cantidad' => $n,
                'accionable' => MotivoSalida::from($clave)->esAccionable(),
            ])->values()->all();

        $accionables = $entrevistas->filter(fn ($ev) => $ev->motivo_principal->esAccionable())->count();
        $noRecomiendan = $entrevistas->where('recomendaria', false)->count();

        return ['entrevistas' => [
            'disponible' => true,
            'hechas' => $entrevistas->count(),
            'cobertura' => $ids->count() > 0
                ? round($entrevistas->count() / $ids->count() * 100, 1)
                : 0.0,
            'por_motivo' => $porMotivo,
            'accionables' => $accionables,
            'no_recomiendan' => $noRecomiendan,
        ]];
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
     * Ausentismo del período: días perdidos sobre días disponibles.
     *
     * Hasta la Fase B esto devolvía `disponible => false`, porque no había
     * dónde estuvieran las incapacidades. Ahora salen del cuadro de novedades.
     *
     * Días perdidos cuenta incapacidades, permisos y ausencias; las horas
     * extra no, obviamente. Un permiso de dos horas entra como 0,25 días y no
     * como uno: contar cada cita médica como día completo duplicaba el número.
     *
     * Días disponibles es el mes comercial de 30 días por persona activa, que
     * es con el que se liquida la nómina. Si se usaran días calendario, el
     * porcentaje no cuadraría con ningún otro número de la empresa.
     *
     * Se sigue devolviendo `disponible => false` mientras no haya UNA sola
     * novedad registrada en el período. Un 0,0 % y un «nadie ha registrado
     * nada todavía» se ven igual en una diapositiva y significan lo contrario.
     */
    public function ausentismo(): array
    {
        $perdidos = 0.0;
        $disponibles = 0.0;
        $registradas = 0;
        $porClase = [];

        foreach ($this->mesesDelPeriodo() as $periodo) {
            $cuadro = CuadroDeNovedades::de($periodo);
            $resumen = $cuadro->resumen();

            $perdidos += (float) $resumen['dias_perdidos'];
            $disponibles += $cuadro->diasDisponibles();
            $registradas += (int) $resumen['novedades'];

            foreach (TipoNovedad::cases() as $tipo) {
                if (! $tipo->cuentaAusentismo()) {
                    continue;
                }

                foreach ($cuadro->novedadesDe($tipo) as $n) {
                    $etiqueta = $n->etiquetaDeClase();
                    $porClase[$etiqueta] = round(($porClase[$etiqueta] ?? 0) + $n->diasEquivalentes(), 2);
                }
            }
        }

        if ($registradas === 0) {
            return [
                'disponible' => false,
                'motivo' => 'Todavía no hay novedades registradas en este período. '
                    .'No se muestra 0 % para no confundir «nadie faltó» con «nadie ha registrado nada».',
            ];
        }

        arsort($porClase);

        return [
            'disponible' => true,
            'dias_perdidos' => round($perdidos, 2),
            'dias_disponibles' => round($disponibles, 2),
            'porcentaje' => $disponibles > 0 ? round($perdidos / $disponibles * 100, 2) : 0.0,
            'novedades' => $registradas,
            // Por qué falta la gente, que es la pregunta que sigue al número.
            'por_clase' => $porClase,
        ];
    }

    /**
     * Los meses «YYYY-MM» que toca el período.
     *
     * El ausentismo se lleva por corte de nómina, así que el rango del tablero
     * —que puede ser cualquier par de fechas— se traduce a los meses que
     * atraviesa.
     *
     * @return array<string>
     */
    private function mesesDelPeriodo(): array
    {
        $meses = [];
        $cursor = $this->desde->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->hasta)) {
            $meses[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $meses;
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
