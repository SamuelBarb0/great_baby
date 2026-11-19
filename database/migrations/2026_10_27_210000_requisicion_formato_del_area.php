<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La requisición de personal, como la pide el formato 1 del área.
 *
 * El flujo ya estaba completo —solicitar, aprobar, abrir la vacante— y era el
 * ÚNICO de los doce formatos que no se podía imprimir. Para poder imprimirlo
 * faltaban cuatro casillas que el papel tiene y la pantalla no.
 *
 * ── Lo que SÍ se agrega ─────────────────────────────────────────────────────
 *
 *  · **Ciudad** — Great Baby tiene bodega en Bogotá y en Bucaramanga; la
 *    requisición dice para dónde es, y hoy eso se adivinaba por el área.
 *  · **Razón comercial** — los formatos del área traen tres nombres distintos
 *    y el papel pide cuál es. Mientras Gestión Humana no confirme, queda en
 *    blanco y se escribe a mano.
 *  · **Tipo de selección** — interna o externa. Decide si la vacante se
 *    publica o se busca entre la gente que ya está.
 *  · **Motivo de la selección** — la lista cerrada del papel (reemplazo del
 *    titular, ampliación de planta, nuevo proyecto, reemplazo de vacaciones,
 *    reestructuración, cubrimiento de licencias, otro). Hoy el campo `motivo`
 *    es texto libre: sirve para explicar, no para contar. Con la lista se
 *    puede responder «cuántas de las contrataciones del año fueron ampliación
 *    de planta», que es justo lo que pregunta una junta.
 *  · **Horario** — va en la orden de contratación y hoy se escribe dos veces.
 *
 * ── Lo que NO se agrega, a propósito ────────────────────────────────────────
 *
 * El papel pide **sexo** y **rango de edad**. La Ley 931 de 2004 prohíbe
 * fijar límites de edad en una oferta de empleo, y el sexo como requisito
 * sólo cabe cuando es esencial para el cargo. No se capturan hasta que el
 * área decida —está en la solicitud de insumos—; si mañana se aprueban, serán
 * preferencia interna que nunca sale a la vacante publicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rrhh_requisiciones', function (Blueprint $t) {
            $t->string('ciudad', 80)->nullable()->after('area');
            $t->string('razon_comercial', 120)->nullable()->after('ciudad');
            // interna · externa
            $t->string('tipo_seleccion', 10)->nullable()->after('razon_comercial');
            // La lista cerrada del papel; `motivo` sigue siendo el texto libre.
            $t->string('motivo_seleccion', 30)->nullable()->after('motivo');
            $t->string('motivo_seleccion_otro', 120)->nullable()->after('motivo_seleccion');
            $t->string('horario', 160)->nullable()->after('tipo_contrato');
        });
    }

    public function down(): void
    {
        Schema::table('rrhh_requisiciones', function (Blueprint $t) {
            $t->dropColumn(['ciudad', 'razon_comercial', 'tipo_seleccion',
                'motivo_seleccion', 'motivo_seleccion_otro', 'horario']);
        });
    }
};
