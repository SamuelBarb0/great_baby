<?php

namespace App\Http\Controllers\App;

use App\Auth\Permisos;
use App\Http\Controllers\Controller;
use App\Models\EmpresaConfig;
use App\Models\NotificacionErp;
use App\Models\User;
use App\Modules\Rrhh\Enums\MotivoSalida;
use App\Modules\Rrhh\Enums\TipoExamenMedico;
use App\Modules\Rrhh\Models\DocumentoEmpleado;
use App\Modules\Rrhh\Models\Empleado;
use App\Modules\Rrhh\Models\EntregaCargo;
use App\Modules\Rrhh\Models\EntrevistaRetiro;
use App\Modules\Rrhh\Models\EvaluacionPrueba;
use App\Modules\Rrhh\Models\Induccion;
use App\Modules\Rrhh\Models\Liquidacion;
use App\Modules\Rrhh\Support\IndicadoresRrhh;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F3 + F4 · La ficha del empleado: su historia laboral.
 *
 * Acá vive lo que hoy está repartido entre carpetas y la memoria de Elena:
 * de qué vacante salió, qué papeles tiene, cuándo se le dio la inducción y
 * quién, y —lo más importante— hasta cuándo va su período de prueba.
 *
 * Ese último dato es el que hoy se pasa de largo: el período se vence, nadie
 * decide nada, y a partir de ahí terminar el contrato ya cuesta indemnización.
 */
class EmpleadoController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $r, \Closure $next) {
            abort_unless(Permisos::puede($r->user(), 'rrhh'), 403);

            return $next($r);
        })];
    }

    public function show(Request $r, int $empleado): Response
    {
        $e = Empleado::with([
            'vacante:id,titulo,requisicion_id',
            'contratacion:id,empleado_id,eps,arl,pension,caja,examen_resultado,contrato_firmado_at',
            'novedades.registradaPor:id,name',
            'induccionPor:id,name',
            'documentos.subidoPor:id,name',
            'user:id,email',
        ])->findOrFail($empleado);

        return Inertia::render('Rrhh/Empleados/Show', [
            'empleado' => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'documento' => $e->tipo_documento.' '.$e->numero_documento,
                'email' => $e->email,
                'telefono' => $e->telefono,
                'cargo' => $e->cargo,
                'area' => $e->area,
                'tipo_contrato' => $e->tipo_contrato,
                'salario' => (float) $e->salario,
                'fecha_ingreso' => $e->fecha_ingreso?->toDateString(),
                'estado' => $e->estado,
                'estado_induccion' => $e->estado_induccion,
                'induccion_at' => $e->induccion_at?->format('Y-m-d'),
                'induccion_por' => $e->induccionPor?->name,
                'induccion_temas' => $e->induccion_temas,
                'prueba_hasta' => $e->prueba_hasta?->toDateString(),
                'prueba_sugerida' => $e->pruebaSugerida()?->toDateString(),
                'prueba_dias' => $e->diasDePrueba(),
                'prueba_evaluada_at' => $e->prueba_evaluada_at?->format('Y-m-d'),
                'prueba_resultado' => $e->prueba_resultado,
                'prueba_observacion' => $e->prueba_observacion,
                'user_id' => $e->user_id,
                'acceso_creado_at' => $e->acceso_creado_at?->toDateString(),
                'activacion_pendiente' => (bool) $e->activacion_token,
                /*
                 * Con qué correo ENTRA, que no siempre es el de la ficha.
                 *
                 * `crearAcceso()` respeta el correo que ya tenía la ficha —es
                 * el que va en el cuadro de novedades para el contador— y
                 * guarda el del ingreso en el usuario. Los dos son datos
                 * legítimos y distintos, pero la pantalla sólo mostraba el de
                 * la ficha: quien lo leía le dictaba a la persona un correo
                 * con el que no entra. Se muestra el del portal cuando difiere.
                 */
                'portal_email' => $e->user?->email,
                'tipo_retiro' => $e->tipo_retiro,
                'motivo_retiro' => $e->motivo_retiro,
                'fecha_retiro' => $e->fecha_retiro?->toDateString(),
                'vacante' => $e->vacante ? ['id' => $e->vacante->id, 'titulo' => $e->vacante->titulo] : null,
                'requisicion_id' => $e->vacante?->requisicion_id,
                /*
                 * Las afiliaciones VIGENTES, no las del trámite.
                 *
                 * Se muestra la de la ficha; si todavía no la tiene —gente
                 * registrada antes de la Fase A— se cae a la del trámite de
                 * contratación. Es el valor EFECTIVO: lo que la casilla
                 * muestra es lo que de verdad rige, no lo que está guardado en
                 * esa columna. Si mostrara vacío estando heredada, el primer
                 * guardado las borraría todas.
                 */
                'afiliaciones' => [
                    'EPS' => $e->eps ?: $e->contratacion?->eps,
                    'ARL' => $e->arl ?: $e->contratacion?->arl,
                    'Pensión' => $e->pension ?: $e->contratacion?->pension,
                    'Caja' => $e->caja ?: $e->contratacion?->caja,
                ],
                // Fase A · los datos que pide el cuadro de novedades.
                'ficha' => [
                    'fecha_nacimiento' => $e->fecha_nacimiento?->toDateString(),
                    'grupo_sanguineo' => $e->grupo_sanguineo,
                    'direccion' => $e->direccion,
                    'ciudad' => $e->ciudad,
                    'telefono' => $e->telefono,
                    'email' => $e->email,
                    'empresa_afiliadora' => $e->empresa_afiliadora,
                    'horario' => $e->horario,
                    'periodicidad_pago' => $e->periodicidad_pago,
                    'valor_adicional' => $e->valor_adicional !== null ? (float) $e->valor_adicional : null,
                    'concepto_adicional' => $e->concepto_adicional,
                    'banco' => $e->banco,
                    'tipo_cuenta' => $e->tipo_cuenta,
                    'cuenta_bancaria' => $e->cuenta_bancaria,
                    'eps' => $e->eps ?: $e->contratacion?->eps,
                    'pension' => $e->pension ?: $e->contratacion?->pension,
                    'cesantias' => $e->cesantias,
                    'caja' => $e->caja ?: $e->contratacion?->caja,
                    'arl' => $e->arl ?: $e->contratacion?->arl,
                    'arl_nivel_riesgo' => $e->arl_nivel_riesgo,
                ],
                // Lo que le falta para salir entera en el cuadro del mes.
                'faltantes' => $e->faltantesDeFicha(),
                'devenga_auxilio' => $e->devengaAuxilioTransporte(),
                // Y por qué no, cuando no: el panel muestra el valor
                // EFECTIVO y la razón, no una casilla muda.
                'auxilio_excluido' => (bool) $e->auxilio_excluido,
                'auxilio_excluido_motivo' => $e->auxilio_excluido_motivo,
                'por_que_no_auxilio' => $e->porQueNoDevengaAuxilio(),
            ],
            'documentos' => $e->documentos->map(fn ($d) => [
                'id' => $d->id,
                'tipo_label' => $d->tipo_label,
                'nombre' => $d->nombre,
                'subido_por' => $d->subidoPor?->name,
                'cuando' => $d->created_at?->format('Y-m-d'),
                'vence' => $d->fecha_vencimiento?->toDateString(),
            ])->values(),
            'tipos_documento' => collect(DocumentoEmpleado::TIPOS)
                ->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
            // Los exámenes que se le piden a alguien que ya trabaja acá: el de
            // ingreso ya pasó, así que no se ofrece.
            'tipos_examen' => TipoExamenMedico::catalogo(
                TipoExamenMedico::deEmpleado(),
            ),
            // Fase F1 · la entrevista de retiro.
            'motivos_salida' => MotivoSalida::catalogo(),
            'criterios_retiro' => EntrevistaRetiro::catalogoCriterios(),
            'entrevista_retiro' => $this->entrevistaDeRetiro($e),
            // Fase F2 · entrega de cargo.
            'bloques_entrega' => EntregaCargo::catalogoBloques(),
            'entrega_cargo' => $this->entregaDeCargo($e),
            // F8 · la inducción con su temario.
            'induccion' => $this->induccionDe($e),
            'temario_en_blanco' => Induccion::temarioEnBlanco(),
            // F10 · la liquidación definitiva, si ya se fue.
            'liquidacion' => $this->liquidacionDe($e),
            'puedo_liquidar' => [
                'calcular' => Permisos::puede($r->user(), 'rrhh'),
                'aprobar' => Permisos::puede($r->user(), 'rrhh_nomina_aprobar'),
                'pagar' => Permisos::puede($r->user(), 'contabilidad'),
                'ver' => Permisos::puede($r->user(), 'rrhh_nomina'),
            ],
            // Qué bloques puede firmar QUIEN está mirando: cada área firma el
            // suyo y nada más.
            'puedo_firmar' => collect(EntregaCargo::AREAS)
                ->map(fn ($a) => Permisos::puede($r->user(), $a['permiso'])),
            // Para registrar el retiro desde acá: sin el motivo, el indicador
            // de rotación no puede responder la pregunta de la Junta.
            'tipos_retiro' => collect(IndicadoresRrhh::TIPOS_RETIRO)
                ->map(fn ($l, $k) => ['valor' => $k, 'label' => $l])->values(),
            // Sus novedades, para no tener que ir al cuadro del mes a buscar
            // si esta persona ya se había incapacitado antes.
            /*
             * Las evaluaciones del período de prueba, con su detalle.
             *
             * En plural porque el período se prorroga: la segunda evaluación
             * no reemplaza a la primera, y el historial es justo lo que
             * sustenta la decisión si después alguien la reclama.
             */
            'evaluaciones_prueba' => EvaluacionPrueba::with('jefe:id,name')
                ->where('empleado_id', $e->id)
                ->orderByDesc('fecha_evaluacion')
                ->get()
                ->map(fn ($ev) => [
                    'id' => $ev->id,
                    'fecha' => $ev->fecha_evaluacion?->toDateString(),
                    'jefe' => $ev->jefe?->name,
                    'jefe_cargo' => $ev->jefe_cargo,
                    'resultado' => $ev->resultado,
                    'promedio' => $ev->promedio(),
                    'calificaciones' => $ev->calificaciones(),
                    'resaltar' => $ev->aspectos_resaltar,
                    'mejorar' => $ev->aspectos_mejorar,
                    'motivo' => $ev->motivo,
                    // Lo que el jefe dejó sin calificar, sin contar «si aplica».
                    'sin_calificar' => $ev->sinCalificar(),
                ])->values(),
            'novedades' => $e->novedades->map(fn ($n) => [
                'id' => $n->id,
                'tipo' => $n->tipo->label(),
                'clase' => $n->etiquetaDeClase(),
                'desde' => $n->fecha_inicio?->toDateString(),
                'hasta' => $n->fecha_fin?->toDateString(),
                'dias' => $n->dias !== null ? (float) $n->dias : null,
                'horas' => $n->horas !== null ? (float) $n->horas : null,
                'descuento' => (float) $n->descuento,
                'periodo' => $n->periodo,
            ])->values(),
            'bitacora' => $e->bitacora()->with('usuario:id,name')->get()->map(fn ($b) => [
                'resumen' => $b->resumen,
                'usuario' => $b->usuario?->name ?? 'Sistema',
                'cuando' => $b->created_at?->format('Y-m-d H:i'),
            ])->values(),
        ]);
    }

    /**
     * La inducción con su temario, si ya se registró.
     *
     * Devuelve el temario GUARDADO, no el vigente: si el área agrega un tema
     * en marzo, la inducción de enero no se vuelve incompleta por algo que no
     * existía cuando se dio.
     */
    private function induccionDe(Empleado $e): ?array
    {
        $i = Induccion::with('registradaPor:id,name')
            ->where('empleado_id', $e->id)->first();

        if (! $i) {
            return null;
        }

        return [
            'fecha' => $i->fecha?->toDateString(),
            'responsable_nombre' => $i->responsable_nombre,
            'responsable_cargo' => $i->responsable_cargo,
            'temas' => $i->temario(),
            'cubiertos' => $i->cubiertos(),
            'total' => count($i->temario()),
            'pendientes' => $i->pendientes(),
            'completa' => $i->completa(),
            'firmada_at' => $i->firmada_at?->toDateString(),
            'firmada' => $i->firmada(),
            'observaciones' => $i->observaciones,
            'registrada_por' => $i->registradaPor?->name,
        ];
    }

    /**
     * La liquidación definitiva de quien se fue.
     *
     * Sale de la fila guardada: una vez calculada, lo que vale es lo que
     * quedó escrito, no lo que daría recalcular hoy.
     */
    private function liquidacionDe(Empleado $e): ?array
    {
        $liq = Liquidacion::with([
            'liquidadaPor:id,name', 'aprobadaPor:id,name', 'pagadaPor:id,name',
        ])->where('empleado_id', $e->id)->first();

        if (! $liq) {
            return null;
        }

        return [
            'estado' => $liq->estado,
            'estado_label' => $liq->estadoLabel(),
            'color' => $liq->colorEstado(),
            'se_puede_rehacer' => $liq->sePuedeRehacer(),
            'tiempo_servido' => $liq->tiempoServido(),
            'manos_repetidas' => $liq->hizoVariasManos(),
            'conceptos' => $liq->conceptos(),
            'total_a_favor' => (float) $liq->total_a_favor,
            'total_descuentos' => (float) $liq->total_descuentos,
            'neto' => (float) $liq->neto,
            'vacaciones_tomadas' => (int) $liq->vacaciones_tomadas,
            'cesantias_ya_pagadas' => (float) $liq->cesantias_ya_pagadas,
            'indemnizacion' => (float) $liq->indemnizacion,
            'indemnizacion_concepto' => $liq->indemnizacion_concepto,
            'otros_descuentos' => (float) $liq->otros_descuentos,
            'otros_descuentos_concepto' => $liq->otros_descuentos_concepto,
            'observaciones' => $liq->observaciones,
            'fecha_pago' => $liq->fecha_pago?->toDateString(),
            'liquidada' => $liq->liquidada_at?->format('Y-m-d H:i'),
            'liquidada_por' => $liq->liquidadaPor?->name,
            'aprobada' => $liq->aprobada_at?->format('Y-m-d H:i'),
            'aprobada_por' => $liq->aprobadaPor?->name,
            'pagada' => $liq->pagada_at?->format('Y-m-d H:i'),
            'pagada_por' => $liq->pagadaPor?->name,
        ];
    }

    /**
     * La entrega de cargo, con sus seis listas y sus tres firmas.
     *
     * Las listas vienen agrupadas por bloque para que la pantalla no tenga que
     * repartirlas, y las firmas con quién y cuándo: el valor del formato no es
     * que esté lleno sino que las tres áreas lo hayan dado por bueno.
     */
    private function entregaDeCargo(Empleado $e): ?array
    {
        $entrega = EntregaCargo::with([
            'items', 'recibe:id,name',
            'jefeConfirmoPor:id,name', 'ghConfirmoPor:id,name', 'tesoreriaConfirmoPor:id,name',
        ])->where('empleado_id', $e->id)->first();

        if (! $entrega) {
            return null;
        }

        return [
            'fecha' => $entrega->fecha?->toDateString(),
            'recibe_id' => $entrega->recibe_id,
            'recibe_nombre' => $entrega->recibe_nombre,
            'quien_recibe' => $entrega->quienRecibe(),
            'observaciones' => $entrega->observaciones,
            'items' => $entrega->items->map(fn ($i) => [
                'bloque' => $i->bloque, 'nombre' => $i->nombre, 'detalle' => $i->detalle,
            ])->values(),
            'completa' => $entrega->completa(),
            'faltan_firmas' => $entrega->faltanFirmas(),
            // Se avisa en pantalla lo mismo que se imprime: si una sola
            // persona dio dos de las tres conformidades, no hubo tres
            // revisiones por más que el formato esté lleno.
            'firmas_repetidas' => $entrega->firmoVariosBloques(),
            'firmas' => collect(EntregaCargo::AREAS)
                ->map(fn ($a, $clave) => [
                    'titulo' => $a['titulo'],
                    'cuando' => $entrega->{"{$clave}_confirmo_at"}?->format('Y-m-d H:i'),
                    'quien' => $entrega->{$clave === 'gh' ? 'ghConfirmoPor' : ($clave === 'jefe' ? 'jefeConfirmoPor' : 'tesoreriaConfirmoPor')}?->name,
                ]),
        ];
    }

    /**
     * La entrevista de retiro de esta persona, si ya se hizo.
     *
     * Se devuelve plana para que el formulario la precargue y se pueda
     * corregir: una entrevista se conversa, y es normal volver a abrirla para
     * completar lo que quedó suelto.
     */
    private function entrevistaDeRetiro(Empleado $e): ?array
    {
        $ev = EntrevistaRetiro::with('entrevistador:id,name')
            ->where('empleado_id', $e->id)->first();

        if (! $ev) {
            return null;
        }

        $base = [
            'fecha_entrevista' => $ev->fecha_entrevista?->toDateString(),
            'entrevistador' => $ev->entrevistador?->name,
            'salida' => $ev->salida,
            'motivo_principal' => $ev->motivo_principal?->value,
            'motivo_otro' => $ev->motivo_otro,
            'motivo_en_palabras' => $ev->motivoEnPalabras(),
            'detalle_motivo' => $ev->detalle_motivo,
            'que_valoro' => $ev->que_valoro,
            'que_mejorar' => $ev->que_mejorar,
            'funciones_correspondian' => $ev->funciones_correspondian,
            'funciones_explicacion' => $ev->funciones_explicacion,
            'recomendaria' => $ev->recomendaria,
            'recomendaria_por_que' => $ev->recomendaria_por_que,
            'accesos_cerrados' => $ev->accesos_cerrados,
            'observaciones' => $ev->observaciones,
            'promedio' => $ev->promedio(),
            // Lo que de verdad se mira: dónde calificó 1 o 2 y por qué.
            'puntos_bajos' => $ev->puntosBajos(),
        ];

        foreach (array_keys(EntrevistaRetiro::CRITERIOS) as $c) {
            $base["n_{$c}"] = $ev->{"n_{$c}"};
            $base["c_{$c}"] = $ev->{"c_{$c}"};
        }

        return $base;
    }

    /**
     * Fase E · El formato GH-F-015 de una evaluación de período de prueba.
     *
     * Se imprime una evaluación concreta y no «la del empleado», porque puede
     * haber más de una: si el período se prorroga, la segunda no reemplaza a
     * la primera y el papel de cada una tiene que decir lo que se evaluó ESE
     * día.
     */
    public function evaluacionPruebaPdf(Request $r, int $empleado, int $evaluacion)
    {
        $e = Empleado::findOrFail($empleado);

        $ev = EvaluacionPrueba::with('jefe:id,name')
            ->where('empleado_id', $e->id)
            ->findOrFail($evaluacion);

        return Pdf::loadView('rrhh.pdf.evaluacion-prueba', [
            'e' => $e,
            'ev' => $ev,
            'empresa' => EmpresaConfig::current(),
            'generadoPor' => $r->user()->name,
        ])->setPaper('letter')
            ->stream('evaluacion-prueba-'.str($e->nombre)->slug().'.pdf');
    }

    /**
     * Fase A · Guarda los datos laborales de la ficha.
     *
     * Son los dieciséis que pide el bloque de ingresos del cuadro de
     * novedades. Nueve de ellos no existían en el sistema y se transcribían a
     * mano desde la carpeta física cada mes.
     *
     * Dos cuidados que vienen de haberse quemado antes:
     *
     *   · Se escribe SÓLO lo que el formulario mandó. La pantalla manda todos
     *     los campos siempre —precargados con el valor efectivo—, así que
     *     guardar sin tocar nada no borra lo que no se editó.
     *
     *   · Las afiliaciones que se ven en la pantalla son las EFECTIVAS: la de
     *     la ficha, o la del trámite de contratación mientras nadie la haya
     *     cambiado. Si la casilla mostrara vacío estando heredada, el primer
     *     guardado las borraría todas sin que nadie lo pidiera.
     */
    public function fichaLaboral(Request $r, int $empleado): RedirectResponse
    {
        $e = Empleado::findOrFail($empleado);

        $data = $r->validate([
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'grupo_sanguineo' => ['nullable', 'string', 'max:6'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'ciudad' => ['nullable', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'empresa_afiliadora' => ['nullable', 'string', 'max:120'],
            'horario' => ['nullable', 'string', 'max:160'],
            'periodicidad_pago' => ['nullable', 'in:mensual,quincenal'],
            'valor_adicional' => ['nullable', 'numeric', 'min:0'],
            'concepto_adicional' => ['nullable', 'string', 'max:60'],
            /*
             * El auxilio de transporte no depende sólo del sueldo: tampoco lo
             * devenga quien vive en el lugar de trabajo ni aquel a quien la
             * empresa le pone la ruta. El motivo es obligatorio cuando se
             * excluye —quitarle el auxilio a alguien es quitarle plata todos
             * los meses, y tiene que quedar dicho por qué—.
             */
            'auxilio_excluido' => ['nullable', 'boolean'],
            'auxilio_excluido_motivo' => [
                'nullable', 'string', 'max:200',
                Rule::requiredIf(fn () => $r->boolean('auxilio_excluido')),
            ],
            'banco' => ['nullable', 'string', 'max:80'],
            'tipo_cuenta' => ['nullable', 'in:ahorros,corriente'],
            'cuenta_bancaria' => ['nullable', 'string', 'max:40'],
            'eps' => ['nullable', 'string', 'max:120'],
            'pension' => ['nullable', 'string', 'max:120'],
            'cesantias' => ['nullable', 'string', 'max:120'],
            'caja' => ['nullable', 'string', 'max:120'],
            'arl' => ['nullable', 'string', 'max:120'],
            'arl_nivel_riesgo' => ['nullable', 'in:I,II,III,IV,V'],
        ], [
            // Ver la nota de `EntrevistaCandidatoController`: el `:date` de
            // `before:today` no se traduce desde `attributes`, sale «today».
            'fecha_nacimiento.before' => 'La fecha de nacimiento tiene que ser anterior a hoy.',
        ], ['arl_nivel_riesgo' => 'nivel de riesgo ARL']);

        // `only()` sobre lo que llegó: un campo ausente del formulario no se
        // toca, y uno vacío a propósito sí se limpia.
        $e->update(array_intersect_key($data, $r->all()));

        $faltan = $e->fresh()->faltantesDeFicha();

        $e->anotar('ficha_laboral', 'Datos laborales actualizados por '.$r->user()->name);

        return back()->with('success', $faltan === []
            ? 'Ficha completa. Esta persona ya sale entera en el cuadro de novedades.'
            : 'Ficha guardada. Todavía falta: '.implode(', ', $faltan).'.');
    }

    /**
     * Deja escrita la inducción: cuándo, quién la dio y qué se vio.
     *
     * Antes sólo se podía marcar «completada», sin fecha ni responsable. Para
     * una inducción en seguridad y salud en el trabajo eso es justo lo que hay
     * que poder demostrar.
     */
    public function induccion(Request $r, int $empleado): RedirectResponse
    {
        $data = $r->validate([
            'temas' => ['required', 'string', 'min:10', 'max:2000'],
        ], [], ['temas' => 'temas vistos']);

        $e = Empleado::findOrFail($empleado);

        $e->update([
            'estado_induccion' => 'completada',
            'induccion_at' => now(),
            'induccion_por_id' => $r->user()->id,
            'induccion_temas' => $data['temas'],
        ]);
        $e->anotar('induccion', "Inducción dada por {$r->user()->name}");

        return back()->with('success', 'Inducción registrada.');
    }

    /** Fija hasta cuándo va el período de prueba. */
    public function periodoDePrueba(Request $r, int $empleado): RedirectResponse
    {
        $e = Empleado::findOrFail($empleado);

        $data = $r->validate([
            // No puede terminar antes de que la persona entre: sería una fecha
            // vencida desde el primer día.
            'hasta' => ['required', 'date', 'after:'.($e->fecha_ingreso?->toDateString() ?? 'today')],
        ], [], ['hasta' => 'fecha']);

        $e->update(['prueba_hasta' => $data['hasta']]);
        $e->anotar('prueba', 'Período de prueba hasta '.$data['hasta']);

        return back()->with('success', 'Período de prueba registrado.');
    }

    /**
     * La decisión al final del período de prueba.
     *
     * Hay que tomarla ANTES de que se venza: después, terminar el contrato ya
     * no se puede sin justa causa ni indemnización.
     */
    public function evaluarPrueba(Request $r, int $empleado): RedirectResponse
    {
        $data = $r->validate([
            'resultado' => ['required', 'in:continua,no_continua'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $e = Empleado::with('vacante.requisicion')->findOrFail($empleado);
        abort_if($e->prueba_evaluada_at, 422, 'Este período de prueba ya fue evaluado.');

        /*
         * Primero el jefe, después Gestión Humana.
         *
         * «Ese período de prueba debe estar validado por el jefe del área y por
         * gestión humana» (Elena, 19-oct). El jefe es el que sabe si la persona
         * rinde en el puesto; decidir sin su concepto es decidir a ciegas.
         *
         * La excepción es la gente que entró por fuera del proceso —los que ya
         * estaban antes del módulo—: ahí no hay jefe a quién preguntarle.
         */
        if ($e->jefeDelArea() && ! $e->tieneConceptoDelJefe()) {
            return back()->withErrors([
                'resultado' => 'Falta el concepto del jefe del área. Sin eso no se cierra.',
            ]);
        }

        $observacion = $data['observacion'] ?? null;

        // «No continúa» sin decir por qué deja a la empresa sin con qué
        // sustentar la decisión si alguien la reclama después.
        if ($data['resultado'] === 'no_continua' && blank($observacion)) {
            return back()->withErrors(['observacion' => 'Decí por qué no continúa.']);
        }

        $e->update([
            'prueba_evaluada_at' => now(),
            // Quién decidió. En la historia laboral impresa, esta columna
            // mostraba un guion donde el concepto del jefe sí lleva nombre.
            'prueba_evaluada_por_id' => $r->user()->id,
            'prueba_resultado' => $data['resultado'],
            'prueba_observacion' => $observacion,
            ...($data['resultado'] === 'no_continua'
                ? ['estado' => 'retirado', 'fecha_retiro' => now()->toDateString()]
                : []),
        ]);

        $e->anotar('prueba_evaluada', ($data['resultado'] === 'continua'
            ? 'Superó el período de prueba'
            : "No continúa: {$observacion}")
            .($e->prueba_jefe_resultado ? " · el jefe conceptuó «{$e->prueba_jefe_resultado}»" : ''));

        return back()->with('success', $data['resultado'] === 'continua'
            ? "{$e->nombre} superó el período de prueba."
            : "Quedó registrado el retiro de {$e->nombre}.");
    }

    /*
     * El concepto del jefe del área vive en `MiEquipoController`.
     *
     * No puede estar acá: este controlador exige el permiso `rrhh`, y el líder
     * de área no lo tiene —ahí están los salarios y las hojas de vida de toda
     * la empresa—. Él entra por su propia pantalla y sólo sobre SU gente.
     */

    /**
     * Le crea el acceso al portal a un empleado.
     *
     * Devuelve el enlace de activación en pantalla, no sólo por correo: acá el
     * correo del empleado muchas veces no existe o no lo revisa, y Gestión
     * Humana lo manda por el WhatsApp, que es como se comunican.
     */
    public function crearAcceso(Request $r, int $empleado): RedirectResponse
    {
        $e = Empleado::findOrFail($empleado);

        abort_unless($e->estado === 'activo', 422,
            'Sólo se le crea acceso a alguien que está trabajando.');

        $data = $r->validate([
            // Si ya tiene correo se usa el suyo; si no, se pide acá y queda
            // guardado, porque el login es por correo.
            'email' => ['required', 'email', 'max:150'],
        ], [], ['email' => 'correo']);

        $otro = User::where('email', $data['email'])
            ->when($e->user_id, fn ($q) => $q->where('id', '!=', $e->user_id))->first();

        // Si el correo ya es de otra persona del sistema, enlazarlo le daría
        // a esta persona el espacio de aquella.
        if ($otro && ! Empleado::where('user_id', $otro->id)->where('id', $e->id)->exists()
            && Empleado::where('user_id', $otro->id)->exists()) {
            return back()->withErrors([
                'email' => 'Ese correo ya es de otro empleado del sistema.',
            ]);
        }

        $resultado = PortalColaboradorController::crearAcceso($e, $data['email']);

        return back()->with('success', 'Acceso creado.')
            ->with('enlace_activacion', $resultado['link']);
    }

    public function subirDocumento(Request $r, int $empleado): RedirectResponse
    {
        $data = $r->validate([
            'tipo' => ['required', 'in:'.implode(',', array_keys(DocumentoEmpleado::TIPOS))],
            'nombre' => ['nullable', 'string', 'max:160'],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:8192'],
            'fecha_vencimiento' => ['nullable', 'date'],
        ], [], ['archivo' => 'documento']);

        $e = Empleado::findOrFail($empleado);

        DocumentoEmpleado::create([
            'empleado_id' => $e->id,
            'candidato_id' => $e->candidato_id,
            'tipo' => $data['tipo'],
            'nombre' => $data['nombre'] ?? DocumentoEmpleado::TIPOS[$data['tipo']],
            'archivo_path' => $r->file('archivo')->store("rrhh/documentos/emp-{$e->id}"),
            'subido_por' => $r->user()->id,
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
        ]);

        $e->anotar('documento', 'Documento cargado: '.DocumentoEmpleado::TIPOS[$data['tipo']]);

        return back()->with('success', 'Documento cargado.');
    }

    /**
     * Avisa de los períodos de prueba que están por vencerse.
     *
     * Lo llama el comando programado: es el recordatorio que hoy no existe y
     * por eso el plazo se pasa solo.
     */
    public static function avisarPruebasPorVencer(int $dias = 15): int
    {
        $porVencer = Empleado::pruebaPorVencer($dias)->with('vacante.requisicion')->get();
        if ($porVencer->isEmpty()) {
            return 0;
        }

        $gestionHumana = User::permission(Permisos::permiso('rrhh'))->pluck('id');

        foreach ($porVencer as $e) {
            $faltan = $e->diasDePrueba();
            $titulo = $faltan < 0
                ? "Período de prueba VENCIDO · {$e->nombre}"
                : "Período de prueba vence en {$faltan} día(s) · {$e->nombre}";
            $color = $faltan < 0 ? 'danger' : 'warning';

            foreach ($gestionHumana as $id) {
                NotificacionErp::crear([
                    'user_id' => $id,
                    'tipo' => 'rrhh_prueba',
                    'titulo' => $titulo,
                    'mensaje' => "{$e->cargo} · hasta el {$e->prueba_hasta->toDateString()}. Hay que decidir antes.",
                    'url' => "/app/rrhh/empleados/{$e->id}",
                    'icono' => 'heroicon-o-clock',
                    'color' => $color,
                ]);
            }

            /*
             * Y al jefe del área, que es quien opina PRIMERO.
             *
             * Sin esto, Gestión Humana se enteraba de que el plazo corría pero
             * quien tenía que dar el primer paso no se enteraba nunca, y el
             * período se vencía igual. La pantalla llegó a decir «ya se le
             * pidió» cuando no se le había pedido nada.
             */
            $jefe = $e->jefeDelArea();
            if ($jefe && ! $e->tieneConceptoDelJefe() && ! $gestionHumana->contains($jefe)) {
                NotificacionErp::crear([
                    'user_id' => $jefe,
                    'tipo' => 'rrhh_prueba',
                    'titulo' => $titulo,
                    'mensaje' => "Falta TU concepto sobre {$e->nombre} ({$e->cargo}). Gestión Humana no puede cerrar sin él.",
                    'url' => '/app/rrhh/mi-equipo',
                    'icono' => 'heroicon-o-clock',
                    'color' => $color,
                ]);
            }
        }

        return $porVencer->count();
    }
}
