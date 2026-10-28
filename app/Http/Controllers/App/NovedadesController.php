<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Modules\Rrhh\Enums\TipoNovedad;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Novedad;
use App\Modules\Rrhh\Support\CuadroDeNovedades;
use App\Support\ExportadorCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fase B · El cuadro de novedades.
 *
 * Cada mes Gestión Humana arma un Excel de seis bloques y se lo manda al
 * contador: quién entró, quién salió, quién se incapacitó, quién pidió
 * permiso, quién faltó y quién hizo horas extra. De ahí sale la nómina.
 *
 * Vivía entero por fuera del sistema. Dos consecuencias que se veían en el
 * código: `IndicadoresRrhh::ausentismo()` devolvía «no disponible» —el
 * indicador que Elena pidió para la Junta no tenía de dónde salir— y los
 * descuentos llegaban digitados desde una calculadora que nadie más vio.
 *
 * Acá los ingresos y los retiros NO se capturan: se derivan de la ficha del
 * empleado. Lo que se registra son las cuatro novedades del día a día.
 */
class NovedadesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh_novedades'), 403);

            return $next($r);
        })];
    }

    public function index(Request $r): Response
    {
        $periodo = CuadroDeNovedades::normalizarPeriodo($r->query('periodo'));
        $cuadro = CuadroDeNovedades::de($periodo);

        return Inertia::render('Rrhh/Novedades/Index', [
            'periodo' => $periodo,
            'periodos' => CuadroDeNovedades::periodosDisponibles(),
            'resumen' => $cuadro->resumen(),
            'ingresos' => $cuadro->ingresos()->map(fn (Empleado $e) => $this->filaIngreso($e))->values(),
            'retiros' => $cuadro->retiros()->map(fn (Empleado $e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'documento' => $e->numero_documento,
                'cargo' => $e->cargo,
                'empresa' => $e->empresa_afiliadora,
                'ciudad' => $e->ciudad,
                'fecha_retiro' => $e->fecha_retiro?->toDateString(),
                'motivo' => $e->motivo_retiro,
                'tipo_retiro' => $e->tipo_retiro,
            ])->values(),
            'bloques' => collect(TipoNovedad::delCuadro())->mapWithKeys(fn (TipoNovedad $t) => [
                $t->value => $cuadro->novedadesDe($t)->map(fn (Novedad $n) => $this->fila($n))->values(),
            ]),
            'catalogo' => TipoNovedad::catalogo(),
            'empleados' => Empleado::where('estado', 'activo')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'numero_documento', 'cargo', 'area', 'salario'])
                ->map(fn (Empleado $e) => [
                    'id' => $e->id,
                    'nombre' => $e->nombre,
                    'documento' => $e->numero_documento,
                    'cargo' => $e->cargo,
                    'salario' => (float) $e->salario,
                ])->values(),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $this->validar($r);

        $n = new Novedad($data + ['registrada_por_id' => $r->user()->id]);
        $n->empleado()->associate(Empleado::findOrFail($data['empleado_id']));

        $this->aplicarDescuento($r, $n);
        $this->guardarSoporte($r, $n);

        $n->save();

        $n->empleado->anotar('novedad', sprintf(
            '%s · %s del %s%s',
            $n->tipo->label(),
            $n->etiquetaDeClase(),
            $n->fecha_inicio->format('d/m/Y'),
            $n->fecha_fin ? ' al '.$n->fecha_fin->format('d/m/Y') : '',
        ));

        return back()->with('success', 'Novedad registrada en el corte de '.$n->periodo.'.');
    }

    public function update(Request $r, int $novedad): RedirectResponse
    {
        $n = Novedad::findOrFail($novedad);
        $data = $this->validar($r);

        $n->fill($data);
        $n->empleado()->associate(Empleado::findOrFail($data['empleado_id']));

        $this->aplicarDescuento($r, $n);
        $this->guardarSoporte($r, $n);

        $n->save();

        return back()->with('success', 'Novedad actualizada.');
    }

    public function destroy(Request $r, int $novedad): RedirectResponse
    {
        $n = Novedad::findOrFail($novedad);

        /*
         * Se borra blando y queda escrito en la bitácora de la persona. Una
         * novedad que desaparece sin rastro del corte que ya se mandó al
         * contador es justo lo que después nadie puede explicar.
         */
        $n->empleado?->anotar('novedad_eliminada', sprintf(
            '%s · %s del %s',
            $n->tipo->label(), $n->etiquetaDeClase(), $n->fecha_inicio->format('d/m/Y'),
        ));

        $n->delete();

        return back()->with('success', 'Novedad eliminada.');
    }

    /**
     * El cuadro del mes, en el orden de los seis bloques.
     *
     * Sale con el mismo corte que la pantalla porque los dos leen
     * `CuadroDeNovedades`. Si el Excel y la pantalla se calcularan por
     * separado, algún mes dirían cosas distintas y nadie sabría cuál creer.
     */
    public function exportar(Request $r): StreamedResponse
    {
        $periodo = CuadroDeNovedades::normalizarPeriodo($r->query('periodo'));
        $cuadro = CuadroDeNovedades::de($periodo);

        $filas = [];

        $filas[] = ['INGRESOS'];
        $filas[] = ['NOMBRE', 'CEDULA', 'FECHA NACIMIENTO', 'GRUPO SANGUÍNEO Y RH', 'DIRECCIÓN',
            'TELEFONO', 'TIPO CONTRATO', 'CARGO/OBJETO', 'AFILIADO POR', 'SALARIO/HONORARIOS',
            'N° CUENTA BANCARIA', 'EPS', 'PENSIONES', 'CESANTIAS', 'CORREO ELECTRÓNICO',
            'NIVEL DE RIESGO EN ARL'];

        foreach ($cuadro->ingresos() as $e) {
            $filas[] = [
                $e->nombre, $e->numero_documento, $e->fecha_nacimiento?->format('d/m/Y'),
                $e->grupo_sanguineo, $e->direccion, $e->telefono, $e->tipo_contrato, $e->cargo,
                $e->empresa_afiliadora, $this->salarioTexto($e), $this->cuentaTexto($e),
                $e->eps, $e->pension, $e->cesantias, $e->email, $e->arl_nivel_riesgo,
            ];
        }

        $filas[] = [];
        $filas[] = ['RETIROS'];
        $filas[] = ['CEDULA', 'NOMBRE', 'CARGO', 'RAZON COMERCIAL/ CIUDAD', 'FECHA DE RETIRO', 'MOTIVO DE RETIRO'];

        foreach ($cuadro->retiros() as $e) {
            $filas[] = [
                $e->numero_documento, $e->nombre, $e->cargo,
                trim(($e->empresa_afiliadora ?? '').' / '.($e->ciudad ?? ''), ' /'),
                $e->fecha_retiro?->format('d/m/Y'), $e->motivo_retiro,
            ];
        }

        foreach (TipoNovedad::delCuadro() as $tipo) {
            $filas[] = [];
            $filas[] = [mb_strtoupper($tipo->label())];
            $filas[] = $this->encabezadoDe($tipo);

            foreach ($cuadro->novedadesDe($tipo) as $n) {
                $filas[] = $this->filaExport($tipo, $n);
            }
        }

        return ExportadorCsv::deFilas("cuadro-novedades-{$periodo}.csv", $filas);
    }

    // ───────────────────────────── internos ─────────────────────────────

    private function validar(Request $r): array
    {
        $tipo = TipoNovedad::tryFrom((string) $r->input('tipo'));

        $data = $r->validate([
            'empleado_id' => ['required', 'integer', 'exists:rrhh_empleados,id'],
            'tipo' => ['required', new \Illuminate\Validation\Rules\Enum(TipoNovedad::class)],
            'clase' => ['required', 'string', 'max:40'],
            'fecha_inicio' => ['required', 'date'],
            // Una novedad que termina antes de empezar es un error de tipeo que
            // después da días negativos en el cuadro.
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_reintegro' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'dias' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'horas' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'nocturna' => ['nullable', 'boolean'],
            'remunerada' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'soporte' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'descuento_manual' => ['nullable', 'boolean'],
        ]);

        // La clase tiene que existir DENTRO del tipo: «CITA_MEDICA» en una
        // incapacidad rompería el agrupado del ausentismo sin avisar.
        if ($tipo && ! array_key_exists($data['clase'], $tipo->clases())) {
            abort(422, "«{$data['clase']}» no es una clase válida de {$tipo->singular()}.");
        }

        $data['nocturna'] = $r->boolean('nocturna');
        $data['remunerada'] = $r->boolean('remunerada', true);

        unset($data['soporte']);

        return $data;
    }

    /**
     * Pone el descuento: el calculado, o el que escribieron a mano.
     *
     * Cuando alguien escribe un valor distinto del sugerido, se marca
     * `descuento_manual` para que un recálculo posterior no lo pise. Sin esa
     * marca, la corrección que Gestión Humana hizo por una razón que el
     * sistema no conoce desaparecería en silencio.
     */
    private function aplicarDescuento(Request $r, Novedad $n): void
    {
        $sugerido = $n->descuentoSugerido()['valor'];

        if ($r->filled('descuento')) {
            $escrito = round((float) $r->input('descuento'), 2);
            $n->descuento = $escrito;
            $n->descuento_manual = abs($escrito - $sugerido) > 0.009;

            return;
        }

        $n->descuento = $sugerido;
        $n->descuento_manual = false;
    }

    private function guardarSoporte(Request $r, Novedad $n): void
    {
        if (! $r->hasFile('soporte')) {
            return;
        }

        $archivo = $r->file('soporte');
        $n->soporte_path = $archivo->store('rrhh/novedades', 'local');
        $n->soporte_nombre = $archivo->getClientOriginalName();
    }

    public function verSoporte(Request $r, int $novedad)
    {
        $n = Novedad::findOrFail($novedad);

        abort_unless($n->soporte_path && Storage::disk('local')->exists($n->soporte_path), 404);

        return Storage::disk('local')->response($n->soporte_path, $n->soporte_nombre);
    }

    private function fila(Novedad $n): array
    {
        $sugerido = $n->descuentoSugerido();

        return [
            'id' => $n->id,
            'empleado_id' => $n->empleado_id,
            'persona' => $n->empleado?->nombre,
            'documento' => $n->empleado?->numero_documento,
            'cargo' => $n->empleado?->cargo,
            'area' => $n->empleado?->area,
            'tipo' => $n->tipo->value,
            'clase' => $n->clase,
            'clase_label' => $n->etiquetaDeClase(),
            'fecha_inicio' => $n->fecha_inicio?->toDateString(),
            'fecha_fin' => $n->fecha_fin?->toDateString(),
            'fecha_reintegro' => $n->fecha_reintegro?->toDateString(),
            'dias' => $n->dias !== null ? (float) $n->dias : null,
            'horas' => $n->horas !== null ? (float) $n->horas : null,
            'nocturna' => (bool) $n->nocturna,
            'remunerada' => (bool) $n->remunerada,
            'descuento' => (float) $n->descuento,
            'descuento_manual' => (bool) $n->descuento_manual,
            // La cuenta al lado del número, para que no haya que creerle al
            // sistema: quien revisa el cuadro ve de dónde salió.
            'descuento_formula' => $sugerido['formula'],
            'descuento_sugerido' => $sugerido['valor'],
            'observaciones' => $n->observaciones,
            'soporte' => $n->soporte_path ? $n->soporte_nombre : null,
            'periodo' => $n->periodo,
        ];
    }

    private function filaIngreso(Empleado $e): array
    {
        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'documento' => $e->numero_documento,
            'fecha_nacimiento' => $e->fecha_nacimiento?->toDateString(),
            'grupo_sanguineo' => $e->grupo_sanguineo,
            'direccion' => $e->direccion,
            'telefono' => $e->telefono,
            'tipo_contrato' => $e->tipo_contrato,
            'cargo' => $e->cargo,
            'empresa' => $e->empresa_afiliadora,
            'salario' => $this->salarioTexto($e),
            'cuenta' => $this->cuentaTexto($e),
            'eps' => $e->eps,
            'pension' => $e->pension,
            'cesantias' => $e->cesantias,
            'email' => $e->email,
            'arl_nivel_riesgo' => $e->arl_nivel_riesgo,
            'fecha_ingreso' => $e->fecha_ingreso?->toDateString(),
            // Lo que le falta a la ficha. Es lo que hace que el Excel llegue
            // con celdas vacías y alguien tenga que perseguirlo por WhatsApp.
            'faltantes' => $e->faltantesDeFicha(),
        ];
    }

    /** «$2.000.000 + $500.000 (SSI)», como lo escribe el Excel. */
    private function salarioTexto(Empleado $e): string
    {
        $base = '$'.number_format((float) $e->salario, 0, ',', '.');

        if ((float) $e->valor_adicional <= 0) {
            return $base;
        }

        return $base.' + $'.number_format((float) $e->valor_adicional, 0, ',', '.')
            .($e->concepto_adicional ? " ({$e->concepto_adicional})" : '');
    }

    private function cuentaTexto(Empleado $e): string
    {
        return trim(implode(' ', array_filter([$e->banco, $e->tipo_cuenta, $e->cuenta_bancaria])));
    }

    private function encabezadoDe(TipoNovedad $tipo): array
    {
        return match ($tipo) {
            TipoNovedad::Incapacidad => ['CEDULA', 'NOMBRE', 'DIA DE INICIO', 'DIA DE TERMINACIÓN',
                'TOTAL DIA', 'DIA REINTEGRO', 'EG-AT', 'OBSERVACIONES', 'DTOS'],
            TipoNovedad::HorasExtra => ['CEDULA', 'NOMBRE', 'ÁREA', 'HORAS DIURNAS', 'HORAS NOCTURNAS'],
            TipoNovedad::Permiso => ['CEDULA', 'NOMBRE', 'DIA DE INICIO', 'DIA DE TERMINACIÓN',
                'TOTAL DIAS U HORA DE AUSENCIA', 'DIA REINTEGRO', 'TIPO DE PERMISO', 'OBSERVACIONES', 'DTOS'],
            TipoNovedad::Ausencia => ['CEDULA', 'NOMBRE', 'DIA DE INICIO', 'DIA DE TERMINACION',
                'TOTAL DIAS DE AUSENCIA', 'DIA REINTEGRO', 'MOTIVO', 'OBSERVACIONES', 'DTOS'],
        };
    }

    private function filaExport(TipoNovedad $tipo, Novedad $n): array
    {
        $doc = $n->empleado?->numero_documento;
        $nombre = $n->empleado?->nombre;

        if ($tipo === TipoNovedad::HorasExtra) {
            return [$doc, $nombre, $n->empleado?->area,
                $n->nocturna ? null : (float) $n->horas,
                $n->nocturna ? (float) $n->horas : null];
        }

        // El Excel escribe «2 horas» en la misma columna donde otros traen «3»
        // días. Se conserva esa forma para que el contador lo lea igual.
        $cantidad = $n->horas !== null && (float) $n->horas > 0
            ? rtrim(rtrim(number_format((float) $n->horas, 2, ',', '.'), '0'), ',').' horas'
            : ($n->dias !== null ? (float) $n->dias : null);

        return [
            $doc, $nombre,
            $n->fecha_inicio?->format('d/m/Y'),
            $n->fecha_fin?->format('d/m/Y'),
            $cantidad,
            $n->fecha_reintegro?->format('d/m/Y'),
            $n->etiquetaDeClase(),
            $n->observaciones,
            (float) $n->descuento,
        ];
    }
}
