<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\EmpresaConfig;
use App\Modules\Rrhh\Enums\TipoExamenMedico;
use App\Modules\Rrhh\Models\Contratacion;
use App\Modules\Rrhh\Models\Empleado;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Fase D2 · La carta del examen médico ocupacional (SST-F-001).
 *
 * El ERP guardaba la FECHA y el RESULTADO del examen, pero no tenía con qué
 * PEDIRLO: la carta que la persona lleva a la IPS se escribía por fuera, a
 * mano, sobre el Word del área. Era el sistema sabiendo que el examen se hizo
 * sin haber participado en pedirlo.
 *
 * La misma carta sirve para los cinco exámenes de la Resolución 2346 de 2007,
 * y dos de ellos ya hacían falta: el periódico mientras la persona trabaje y
 * el de egreso al salir. Sin el de egreso la empresa queda sin con qué
 * demostrar en qué condiciones se fue alguien.
 *
 * Por eso hay dos puertas: la contratación (ingreso) y la ficha del empleado
 * (los otros cuatro). Son dos momentos distintos con dos permisos distintos.
 */
class OrdenExamenMedicoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

            return $next($r);
        })];
    }

    /** La de ingreso, desde la contratación en curso. */
    public function paraContratacion(Request $r, int $contratacion)
    {
        $x = Contratacion::with('candidato:id,nombre,documento', 'vacante:id,titulo')
            ->findOrFail($contratacion);

        $x->anotar('emo_solicitado', 'Carta de examen médico de ingreso generada por '.$r->user()->name);

        return $this->carta($r, TipoExamenMedico::Ingreso, [
            'nombre' => $x->candidato?->nombre,
            'documento' => $x->candidato?->documento,
            'cargo' => $x->vacante?->titulo,
        ]);
    }

    /**
     * Los otros cuatro, desde la ficha de alguien que ya trabaja acá.
     *
     * El tipo se pide por querystring y se valida contra el enum: una carta
     * que dice «de ingreso» para alguien que se está yendo manda a la IPS a
     * aplicar la batería equivocada.
     */
    public function paraEmpleado(Request $r, int $empleado)
    {
        $e = Empleado::findOrFail($empleado);

        $tipo = TipoExamenMedico::tryFrom((string) $r->query('tipo'));

        abort_unless($tipo && in_array($tipo, TipoExamenMedico::deEmpleado(), true), 422,
            'Tipo de examen no válido para alguien que ya está contratado.');

        $e->anotar('emo_solicitado', "Carta de {$tipo->enLaCarta()} generada por ".$r->user()->name);

        return $this->carta($r, $tipo, [
            'nombre' => $e->nombre,
            'documento' => $e->numero_documento,
            'cargo' => $e->cargo,
        ]);
    }

    /**
     * El impreso.
     *
     * Los datos de la IPS salen de las reglas porque es un proveedor: el día
     * que se cambie de IPS la carta sale con la nueva sin esperar a nadie.
     */
    private function carta(Request $r, TipoExamenMedico $tipo, array $persona)
    {
        return Pdf::loadView('rrhh.pdf.orden-examen-medico', [
            'tipo' => $tipo,
            'persona' => $persona,
            'empresa' => EmpresaConfig::current(),
            'ips' => [
                'nombre' => setting('rrhh.ips_emo_nombre'),
                'direccion' => setting('rrhh.ips_emo_direccion'),
                'horario' => setting('rrhh.ips_emo_horario'),
                'instrucciones' => setting('rrhh.ips_emo_instrucciones'),
            ],
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            // Se mira antes de imprimirla y entregarla.
            ->stream('examen-medico-'.str($persona['nombre'] ?? 'colaborador')->slug().'.pdf');
    }
}
