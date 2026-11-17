<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\Induccion;
use App\Modules\Rrhh\Models\Novedad;
use App\Modules\Rrhh\Models\Requisicion;
use App\Modules\Rrhh\Models\Vacante;
use App\Modules\Dropi\Models\InventarioUbicacion;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración para recorrer Gestión Humana rol por rol.
 *
 * El módulo quedó construido pero con tres personas activas: media pantalla
 * sale vacía y no se puede ver si funciona. Esto llena los huecos que hacen
 * falta para que cada rol tenga algo que mirar.
 *
 * ── Qué es dato de demostración y qué no ────────────────────────────────────
 *
 * Los niveles de riesgo ARL, las afiliaciones y los responsables de bodega
 * son datos que el ÁREA tiene que confirmar —están pedidos en la solicitud de
 * insumos—. Acá se ponen valores verosímiles para poder recorrer las
 * pantallas, NO como si fueran los de verdad. Por eso van en un sembrador
 * aparte, marcado como demo, y no en el de producción.
 *
 * Es idempotente: se puede correr las veces que haga falta.
 */
class DemoGestionHumanaSeeder extends Seeder
{
    public function run(): void
    {
        $this->completarFichas();
        $this->asignarBodegas();
        $this->gentePorTodoElCiclo();
        $this->novedadesDelMes();
    }

    /**
     * Completa lo que falta en las fichas existentes.
     *
     * Nivel de riesgo, afiliaciones y datos bancarios: sin eso la nómina
     * calcula la ARL con el nivel más bajo y el cuadro del contador sale
     * incompleto.
     */
    private function completarFichas(): void
    {
        $riesgoPorArea = [
            'Logística' => 'III',   // bodega, manipulación de carga
            'Contabilidad' => 'I',  // oficina
            'Comercial' => 'II',
        ];

        foreach (Empleado::all() as $e) {
            $e->forceFill(array_filter([
                'arl_nivel_riesgo' => $e->arl_nivel_riesgo ?: ($riesgoPorArea[$e->area] ?? 'I'),
                'eps' => $e->eps ?: 'Nueva EPS',
                'pension' => $e->pension ?: 'Porvenir',
                'cesantias' => $e->cesantias ?: 'Protección',
                'caja' => $e->caja ?: 'Cafam',
                'arl' => $e->arl ?: 'Positiva',
                'banco' => $e->banco ?: 'Bancolombia',
                'tipo_cuenta' => $e->tipo_cuenta ?: 'ahorros',
                'cuenta_bancaria' => $e->cuenta_bancaria ?: '91'.str_pad((string) $e->id, 8, '0', STR_PAD_LEFT),
                'fecha_nacimiento' => $e->fecha_nacimiento ?: now()->subYears(28 + $e->id),
                'grupo_sanguineo' => $e->grupo_sanguineo ?: 'O+',
                'ciudad' => $e->ciudad ?: 'Bogotá',
                'direccion' => $e->direccion ?: 'Calle 100 # 15-20',
                'horario' => $e->horario ?: 'Lunes a viernes 7:30 a.m. a 5:30 p.m.',
                'periodicidad_pago' => $e->periodicidad_pago ?: 'mensual',
            ]))->save();
        }

        $this->command?->line('  fichas completadas: '.Empleado::count());
    }

    /**
     * Le pone responsable a las bodegas.
     *
     * Sin esto la cola de alistamiento le sale VACÍA al bodeguero: el filtro
     * es fail-closed y sin bodegas asignadas no ve nada.
     */
    private function asignarBodegas(): void
    {
        $bodeguero = User::where('email', 'admin.bodega.bog@greatbaby.com')->first();
        $alistador = User::where('email', 'alistador.bog@greatbaby.com')->first();

        if (! $bodeguero) {
            return;
        }

        /*
         * Sólo las bodegas de venta, y sólo las propias.
         *
         * Antes repartía TODA ubicación sin responsable en round-robin, lo que
         * dejaba al alistador a cargo de avería, de bajas y de garantía —las
         * tres cosas que justamente no debe tocar— y encima de las 43 filas
         * importadas del catálogo compartido de SIIGO, que son de otras
         * empresas. Con el alcance de lectura por responsable, eso le habría
         * mostrado media base de datos.
         */
        $propias = InventarioUbicacion::query()
            ->whereNull('responsable_user_id')
            ->where('activa', true)
            ->where('categoria', 'venta')
            ->where('codigo', 'not like', 'SIIGO-%')
            ->orderBy('id')
            ->get();

        foreach ($propias as $i => $u) {
            // El alistador queda con una sola; el resto son del jefe de bodega.
            $u->update(['responsable_user_id' => ($i === 0 && $alistador)
                ? $alistador->id
                : $bodeguero->id]);
        }

        $this->command?->line('  ubicaciones con responsable: '.InventarioUbicacion::whereNotNull('responsable_user_id')->count());
    }

    /**
     * Gente en cada punto del ciclo, para que ninguna pantalla salga vacía.
     *
     * Una en período de prueba sin evaluar, una con inducción a medias, una
     * recién ingresada sin ficha completa. Son los tres estados en los que el
     * módulo tiene algo que decir.
     */
    private function gentePorTodoElCiclo(): void
    {
        $gente = [
            ['nombre' => 'Camila Rodríguez', 'cargo' => 'Auxiliar contable', 'area' => 'Contabilidad',
                'salario' => 1_900_000, 'ingreso' => now()->subDays(20), 'prueba' => now()->addDays(40)],
            ['nombre' => 'Julián Patiño', 'cargo' => 'Alistador', 'area' => 'Logística',
                'salario' => 1_423_500, 'ingreso' => now()->subDays(8), 'prueba' => now()->addDays(52)],
            ['nombre' => 'Natalia Osorio', 'cargo' => 'Asesora comercial', 'area' => 'Comercial',
                'salario' => 1_750_905, 'ingreso' => now()->subMonths(8), 'prueba' => null],
        ];

        foreach ($gente as $g) {
            Empleado::firstOrCreate(
                ['numero_documento' => 'demo'.crc32($g['nombre'])],
                [
                    'nombre' => $g['nombre'], 'tipo_documento' => 'CC',
                    'cargo' => $g['cargo'], 'area' => $g['area'],
                    'tipo_contrato' => 'indefinido', 'salario' => $g['salario'],
                    'fecha_ingreso' => $g['ingreso'], 'prueba_hasta' => $g['prueba'],
                    'estado' => 'activo',
                    'estado_induccion' => $g['prueba'] ? 'pendiente' : 'completada',
                    'eps' => 'Sura', 'pension' => 'Colfondos', 'cesantias' => 'Porvenir',
                    'caja' => 'Compensar', 'arl' => 'Positiva',
                    'arl_nivel_riesgo' => $g['area'] === 'Logística' ? 'III' : 'I',
                    'banco' => 'Davivienda', 'tipo_cuenta' => 'ahorros',
                    'cuenta_bancaria' => '88'.crc32($g['nombre']),
                    'horario' => 'Lunes a viernes 7:30 a.m. a 5:30 p.m.',
                    'periodicidad_pago' => 'mensual', 'ciudad' => 'Bogotá',
                ],
            );
        }

        // Una inducción a medias, para que se vea el aviso de pendientes.
        $julian = Empleado::where('nombre', 'Julián Patiño')->first();

        if ($julian && ! Induccion::where('empleado_id', $julian->id)->exists()) {
            $temas = Induccion::temarioEnBlanco();

            foreach ($temas as $i => $t) {
                $temas[$i]['cubierto'] = $i < 4;
                $temas[$i]['responsable'] = $i < 4 ? 'Elena Gestión Humana' : '';
            }

            Induccion::create([
                'empleado_id' => $julian->id,
                'fecha' => now()->subDays(6),
                'responsable_nombre' => 'Elena Gestión Humana',
                'responsable_cargo' => 'Jefe de Gestión Humana',
                'temas' => $temas,
                'observaciones' => 'Se continúa la semana entrante con los temas de SST.',
            ]);
        }

        $this->command?->line('  empleados: '.Empleado::count());
    }

    /**
     * Novedades del mes en curso, para que el cuadro y la nómina tengan de
     * dónde sacar descuentos y horas extra.
     */
    private function novedadesDelMes(): void
    {
        $periodo = now()->format('Y-m');
        $activos = Empleado::where('estado', 'activo')->take(3)->get();

        if ($activos->count() < 2 || Novedad::where('periodo', $periodo)->count() >= 3) {
            return;
        }

        $elena = User::where('email', 'gestionhumana.demo@greatbaby.com')->first();

        Novedad::create([
            'empleado_id' => $activos[0]->id, 'tipo' => 'incapacidad', 'clase' => 'EG',
            'fecha_inicio' => now()->startOfMonth()->addDays(4),
            'fecha_fin' => now()->startOfMonth()->addDays(6),
            'dias' => 3, 'remunerada' => true, 'periodo' => $periodo,
            'observaciones' => 'Incapacidad por enfermedad general.',
            'registrada_por_id' => $elena?->id,
        ])->recalcularDescuento();

        Novedad::create([
            'empleado_id' => $activos[1]->id, 'tipo' => 'horas_extra', 'clase' => 'ORDINARIA',
            'fecha_inicio' => now()->startOfMonth()->addDays(10),
            'horas' => 6, 'nocturna' => false, 'periodo' => $periodo,
            'observaciones' => 'Cierre de inventario.',
            'registrada_por_id' => $elena?->id,
        ]);

        $this->command?->line('  novedades del período '.$periodo.': '.Novedad::where('periodo', $periodo)->count());
    }
}
