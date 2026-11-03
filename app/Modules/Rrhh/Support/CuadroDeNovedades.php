<?php

namespace App\Modules\Rrhh\Support;

use App\Modules\Rrhh\Enums\TipoNovedad;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Novedad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El cuadro de novedades de un mes, los seis bloques armados.
 *
 * Es el Excel que Gestión Humana le manda al contador, pero construido desde
 * lo que el sistema ya sabe en vez de transcrito a mano.
 *
 * Dos de los seis bloques NO se registran en ninguna parte: los ingresos y los
 * retiros del mes son `fecha_ingreso` y `fecha_retiro` del empleado, y se
 * derivan acá. Guardarlos además como novedad sería tener el mismo hecho en
 * dos tablas, que es la forma en que este sistema se ha roto antes: alguien
 * corrige el retiro en la ficha, la novedad se queda con la fecha vieja, y el
 * cuadro de diciembre contradice al indicador de rotación.
 *
 * Los cálculos viven acá y no en el controlador porque la pantalla, la
 * exportación y el indicador de ausentismo tienen que dar el MISMO número.
 */
class CuadroDeNovedades
{
    private Carbon $desde;

    private Carbon $hasta;

    public function __construct(public readonly string $periodo)
    {
        $this->desde = Carbon::createFromFormat('Y-m-d', $periodo.'-01')->startOfMonth();
        $this->hasta = $this->desde->copy()->endOfMonth();
    }

    public static function de(string $periodo): self
    {
        return new self($periodo);
    }

    /** El mes en palabras: «octubre de 2026». */
    public function titulo(): string
    {
        return $this->desde->locale('es')->isoFormat('MMMM [de] YYYY');
    }

    /**
     * Quién entró en el mes.
     *
     * Trae la ficha completa porque el bloque de ingresos del Excel pide
     * dieciséis datos por persona —hasta el grupo sanguíneo— y es el único
     * bloque donde eso importa: al contador le sirve para afiliar.
     */
    public function ingresos(): Collection
    {
        return Empleado::query()
            ->whereNotNull('fecha_ingreso')
            ->whereBetween('fecha_ingreso', [$this->desde, $this->hasta])
            ->orderBy('fecha_ingreso')
            ->get();
    }

    public function retiros(): Collection
    {
        return Empleado::query()
            ->whereNotNull('fecha_retiro')
            ->whereBetween('fecha_retiro', [$this->desde, $this->hasta])
            ->orderBy('fecha_retiro')
            ->get();
    }

    /** Las novedades de un tipo, con su persona. */
    public function novedadesDe(TipoNovedad $tipo): Collection
    {
        return Novedad::with('empleado:id,nombre,numero_documento,cargo,area,salario')
            ->delPeriodo($this->periodo)
            ->deTipo($tipo)
            ->orderBy('fecha_inicio')
            ->get();
    }

    /** Los seis bloques, listos para pintar o exportar. */
    public function bloques(): array
    {
        $bloques = [
            'ingresos' => ['titulo' => 'Ingresos', 'filas' => $this->ingresos()],
            'retiros' => ['titulo' => 'Retiros', 'filas' => $this->retiros()],
        ];

        foreach (TipoNovedad::delCuadro() as $tipo) {
            $bloques[$tipo->value] = [
                'titulo' => $tipo->label(),
                'filas' => $this->novedadesDe($tipo),
            ];
        }

        return $bloques;
    }

    /**
     * Los totales del corte.
     *
     * `dias_perdidos` suma días y fracciones de día: un permiso de dos horas
     * entra como 0,25. Sin eso, el ausentismo contaría cada cita médica como
     * un día entero.
     */
    public function resumen(): array
    {
        $novedades = Novedad::delPeriodo($this->periodo)->get();
        $ausentismo = $novedades->filter(fn (Novedad $n) => $n->tipo->cuentaAusentismo());

        return [
            'periodo' => $this->periodo,
            'titulo' => $this->titulo(),
            'ingresos' => $this->ingresos()->count(),
            'retiros' => $this->retiros()->count(),
            'novedades' => $novedades->count(),
            'dias_perdidos' => round($ausentismo->sum(fn (Novedad $n) => $n->diasEquivalentes()), 2),
            'horas_extra' => round((float) $novedades
                ->where('tipo', TipoNovedad::HorasExtra)
                ->sum(fn (Novedad $n) => (float) $n->horas), 2),
            'descuentos' => round((float) $novedades->sum(fn (Novedad $n) => (float) $n->descuento), 2),
            // Cuánta gente tiene la ficha incompleta: es lo que hace que el
            // Excel llegue con celdas vacías.
            'fichas_incompletas' => $this->ingresos()
                ->filter(fn (Empleado $e) => $e->faltantesDeFicha() !== [])
                ->count(),
        ];
    }

    /**
     * Días hábiles-persona disponibles en el mes, para el ausentismo.
     *
     * Se usa el mes comercial de 30 días por persona activa, que es con el que
     * se liquida la nómina. Mezclar días calendario con días liquidados daría
     * un porcentaje que no coincide con ningún otro número de la empresa.
     */
    public function diasDisponibles(): float
    {
        $diasMes = max(1, (int) setting('rrhh.dias_mes'));

        $activos = Empleado::whereNotNull('fecha_ingreso')
            ->where('fecha_ingreso', '<=', $this->hasta)
            ->where(fn ($q) => $q->whereNull('fecha_retiro')->orWhere('fecha_retiro', '>=', $this->desde))
            ->count();

        return $activos * $diasMes;
    }

    /** Los últimos doce cortes, para el selector de la pantalla. */
    public static function periodosDisponibles(int $cuantos = 18): array
    {
        $out = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < $cuantos; $i++) {
            $out[] = [
                'valor' => $cursor->format('Y-m'),
                'label' => ucfirst($cursor->locale('es')->isoFormat('MMMM [de] YYYY')),
            ];
            $cursor->subMonth();
        }

        return $out;
    }

    /** «2026-10» válido, o el mes corriente. */
    public static function normalizarPeriodo(?string $periodo): string
    {
        return $periodo && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)
            ? $periodo
            : now()->format('Y-m');
    }
}
