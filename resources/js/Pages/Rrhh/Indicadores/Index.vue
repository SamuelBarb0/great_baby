<script setup>
/*
 * Los indicadores que Elena le presenta a la Junta Directiva.
 *
 * El orden es el de su propia lista en la reunión: rotación, ausentismo,
 * cobertura de capacitaciones, por qué se retira la gente y cómo estamos
 * creciendo.
 *
 * El ausentismo sale del cuadro de novedades. Hasta la Fase B aparecía marcado
 * como no disponible porque las incapacidades vivían en un Excel por fuera del
 * sistema, y esta pantalla sólo sabía pintar ese caso.
 *
 * Lo que no cambió: un período sin novedades registradas sigue diciendo «no
 * disponible» en vez de 0 %. Un número sin respaldo en una junta directiva es
 * peor que un hueco, porque el inventado se cree.
 */
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { BarChart3, Printer, Download, TrendingUp, TrendingDown, Info } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    indicadores: { type: Object, required: true },
    tipos_retiro: { type: Array, default: () => [] },
});

const desde = ref(props.indicadores.rango.desde);
const hasta = ref(props.indicadores.rango.hasta);
const filtrar = () => router.get('/app/rrhh/indicadores',
    { desde: desde.value, hasta: hasta.value },
    { preserveState: true, replace: true });

const query = () => `?desde=${desde.value}&hasta=${hasta.value}`;

/* El máximo de cada serie, para dibujar las barras sin librería. */
const maximo = (filas, campo) => Math.max(1, ...filas.map((f) => f[campo] ?? 0));
const ancho = (valor, max) => `${Math.round((valor / max) * 100)}%`;
</script>

<template>
    <Head title="Indicadores de Gestión Humana" />
    <AppLayout>
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <BarChart3 class="h-6 w-6 text-brand-600" />Indicadores de Gestión Humana
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Del {{ indicadores.rango.desde }} al {{ indicadores.rango.hasta }}.
                    </p>
                </div>
                <a :href="`/app/rrhh/indicadores/pdf${query()}`" class="btn-secondary">
                    <Printer class="h-4 w-4" /> Llevar impreso a la Junta
                </a>
            </div>

            <div class="card p-3 flex items-end gap-2 flex-wrap">
                <div>
                    <label class="text-[10px] uppercase font-semibold text-surface-500 block">Desde</label>
                    <input type="date" v-model="desde" class="input text-sm py-1" />
                </div>
                <div>
                    <label class="text-[10px] uppercase font-semibold text-surface-500 block">Hasta</label>
                    <input type="date" v-model="hasta" class="input text-sm py-1" />
                </div>
                <button @click="filtrar" class="btn-secondary text-sm">Ver</button>
                <p class="text-xs text-surface-400 ml-2">
                    Por defecto, el año corrido: la rotación de un solo mes no dice nada.
                </p>
            </div>

            <!-- 1 · Rotación -->
            <div class="card p-5">
                <h2 class="font-bold text-sm uppercase text-surface-500 mb-3">Rotación del personal</h2>
                <div class="flex items-end gap-6 flex-wrap">
                    <div>
                        <div class="text-5xl font-black"
                            :class="indicadores.rotacion.porcentaje > 20 ? 'text-rose-600' : 'text-brand-600'">
                            {{ indicadores.rotacion.porcentaje }}<span class="text-2xl">%</span>
                        </div>
                        <div class="text-xs text-surface-400">
                            {{ indicadores.rotacion.retiros }} retiro(s) sobre un promedio de
                            {{ indicadores.rotacion.promedio }} persona(s)
                        </div>
                        <!--
                            Con poca gente, un solo retiro mueve el porcentaje
                            muchísimo. Mejor decirlo que dejar que alguien
                            decida sobre un número que no aguanta el peso.
                        -->
                        <p v-if="indicadores.rotacion.advertencia"
                            class="text-xs text-amber-700 bg-amber-500/10 border-l-2 border-amber-500 p-2 mt-2 max-w-sm">
                            {{ indicadores.rotacion.advertencia }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm flex-1 min-w-[16rem]">
                        <div>
                            <div class="text-xs text-surface-400 uppercase">Al inicio</div>
                            <div class="text-xl font-bold">{{ indicadores.rotacion.al_inicio }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-surface-400 uppercase">Ingresos</div>
                            <div class="text-xl font-bold text-emerald-600">+{{ indicadores.rotacion.ingresos }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-surface-400 uppercase">Retiros</div>
                            <div class="text-xl font-bold text-rose-600">−{{ indicadores.rotacion.retiros }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-surface-400 uppercase">Al final</div>
                            <div class="text-xl font-bold">{{ indicadores.rotacion.al_final }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid lg:grid-cols-2 gap-4">
                <!-- 2 · Ausentismo · sale del cuadro de novedades (Fase B) -->
                <div class="card p-5">
                    <h2 class="font-bold text-sm uppercase text-surface-500 mb-2">Ausentismo</h2>

                    <!-- Sin novedades registradas no se pinta 0 %: se dice por qué -->
                    <div v-if="!indicadores.ausentismo.disponible"
                        class="flex items-start gap-2 text-sm text-surface-600">
                        <Info class="h-5 w-5 shrink-0 text-amber-500 mt-0.5" />
                        <p>{{ indicadores.ausentismo.motivo }}</p>
                    </div>

                    <div v-else>
                        <div class="flex items-end gap-4">
                            <div class="text-4xl font-black"
                                :class="indicadores.ausentismo.porcentaje >= 5 ? 'text-rose-600' : 'text-emerald-600'">
                                {{ indicadores.ausentismo.porcentaje }}<span class="text-xl">%</span>
                            </div>
                            <div class="text-sm text-surface-500 pb-1">
                                {{ indicadores.ausentismo.dias_perdidos }} día(s) perdidos
                                sobre {{ indicadores.ausentismo.dias_disponibles }} disponibles
                            </div>
                        </div>

                        <!-- La pregunta que sigue al número: ¿por qué falta la gente? -->
                        <ul class="mt-3 space-y-1 text-sm">
                            <li v-for="(dias, clase) in indicadores.ausentismo.por_clase" :key="clase"
                                class="flex justify-between gap-2">
                                <span class="text-surface-600">{{ clase }}</span>
                                <span class="font-semibold">{{ dias }} día(s)</span>
                            </li>
                        </ul>

                        <p class="text-xs text-surface-400 mt-2">
                            {{ indicadores.ausentismo.novedades }} novedad(es) registradas en el período.
                            Los días se cuentan sobre el mes comercial de 30 días, el mismo con el que se liquida la nómina.
                        </p>
                    </div>
                </div>

                <!-- 3 · Cobertura de inducción -->
                <div class="card p-5">
                    <h2 class="font-bold text-sm uppercase text-surface-500 mb-2">Cobertura de inducción</h2>
                    <div class="flex items-end gap-4">
                        <div class="text-4xl font-black"
                            :class="indicadores.induccion.porcentaje >= 90 ? 'text-emerald-600' : 'text-amber-600'">
                            {{ indicadores.induccion.porcentaje }}<span class="text-xl">%</span>
                        </div>
                        <div class="text-sm text-surface-500 pb-1">
                            {{ indicadores.induccion.con_induccion }} de {{ indicadores.induccion.activos }}
                            · {{ indicadores.induccion.pendientes }} pendiente(s)
                        </div>
                    </div>
                    <p class="text-xs text-surface-400 mt-2">{{ indicadores.induccion.nota }}</p>
                </div>
            </div>

            <!-- 4 · Por qué se retira la gente -->
            <div class="card p-5">
                <div class="flex items-center justify-between gap-2 flex-wrap mb-3">
                    <h2 class="font-bold text-sm uppercase text-surface-500">Por qué se retira la gente</h2>
                    <a v-if="indicadores.retiros.total" :href="`/app/rrhh/indicadores/retiros${query()}`"
                        class="btn-secondary text-xs">
                        <Download class="h-3.5 w-3.5" /> Excel
                    </a>
                </div>

                <div v-if="!indicadores.retiros.total" class="text-sm text-surface-400">
                    Nadie se retiró en este período.
                </div>
                <div v-else class="space-y-4">
                    <div class="space-y-2">
                        <div v-for="t in indicadores.retiros.por_tipo" :key="t.tipo" class="text-sm">
                            <div class="flex justify-between gap-2">
                                <span :class="t.tipo === 'sin_registrar' ? 'text-amber-600' : ''">{{ t.label }}</span>
                                <span class="font-bold">{{ t.cantidad }}</span>
                            </div>
                            <div class="h-2 rounded bg-surface-200 dark:bg-surface-800 mt-1 overflow-hidden">
                                <div class="h-full bg-brand-500"
                                    :style="{ width: ancho(t.cantidad, maximo(indicadores.retiros.por_tipo, 'cantidad')) }"></div>
                            </div>
                        </div>
                    </div>

                    <!--
                        Lo que dijo la gente al irse · Fase F1.

                        Lo de arriba es cómo clasifica la EMPRESA la salida:
                        veinte renuncias se cuentan igual. Esto es qué contestó
                        LA PERSONA, y ahí se separa lo que es mercado de lo que
                        la empresa puede arreglar.
                    -->
                    <div class="pt-3 border-t">
                        <h3 class="text-xs font-bold uppercase text-surface-500 mb-2">
                            Lo que dijeron en la entrevista de retiro
                        </h3>

                        <div v-if="!indicadores.retiros.entrevistas.disponible"
                            class="flex items-start gap-2 text-sm text-surface-600">
                            <Info class="h-4 w-4 shrink-0 text-amber-500 mt-0.5" />
                            <p>{{ indicadores.retiros.entrevistas.motivo }}</p>
                        </div>

                        <div v-else>
                            <div class="flex flex-wrap gap-4 mb-3">
                                <div>
                                    <div class="text-[10px] uppercase text-surface-400">Se podían evitar</div>
                                    <div class="text-2xl font-black"
                                        :class="indicadores.retiros.entrevistas.accionables ? 'text-rose-600' : 'text-emerald-600'">
                                        {{ indicadores.retiros.entrevistas.accionables }}
                                        <span class="text-sm font-normal text-surface-400">
                                            de {{ indicadores.retiros.entrevistas.hechas }}
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase text-surface-400">No nos recomendarían</div>
                                    <div class="text-2xl font-black"
                                        :class="indicadores.retiros.entrevistas.no_recomiendan ? 'text-rose-600' : 'text-emerald-600'">
                                        {{ indicadores.retiros.entrevistas.no_recomiendan }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase text-surface-400">Cobertura</div>
                                    <div class="text-2xl font-black">{{ indicadores.retiros.entrevistas.cobertura }}%</div>
                                    <div class="text-[10px] text-surface-400">de las salidas</div>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div v-for="m in indicadores.retiros.entrevistas.por_motivo" :key="m.motivo" class="text-sm">
                                    <div class="flex justify-between gap-2">
                                        <span>
                                            {{ m.label }}
                                            <span v-if="m.accionable"
                                                class="ml-1 text-[10px] uppercase text-rose-600 font-bold">
                                                se puede atacar
                                            </span>
                                        </span>
                                        <span class="font-bold">{{ m.cantidad }}</span>
                                    </div>
                                    <div class="h-2 rounded bg-surface-200 dark:bg-surface-800 mt-1 overflow-hidden">
                                        <div class="h-full" :class="m.accionable ? 'bg-rose-500' : 'bg-surface-400'"
                                            :style="{ width: ancho(m.cantidad, maximo(indicadores.retiros.entrevistas.por_motivo, 'cantidad')) }"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- En la junta siempre preguntan «¿y quién fue?» -->
                    <div class="overflow-x-auto">
                        <table v-tabla-movil class="w-full text-sm">
                            <thead class="text-xs text-surface-500 uppercase border-b">
                                <tr>
                                    <th class="text-left p-2">Persona</th>
                                    <th class="text-left p-2">Cargo</th>
                                    <th class="text-left p-2">Motivo</th>
                                    <th class="text-left p-2">Detalle</th>
                                    <th class="text-left p-2">Fecha</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(d, i) in indicadores.retiros.detalle" :key="i">
                                    <td class="p-2 font-semibold">{{ d.nombre }}</td>
                                    <td class="p-2 text-xs">{{ d.cargo }} · {{ d.area }}</td>
                                    <td class="p-2 text-xs">{{ d.tipo }}</td>
                                    <td class="p-2 text-xs text-surface-500">{{ d.motivo || '—' }}</td>
                                    <td class="p-2 text-xs">{{ d.fecha }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 5 · Arquitectura de personal -->
            <div class="card p-5">
                <h2 class="font-bold text-sm uppercase text-surface-500 mb-1">Estructura de personal</h2>
                <p class="text-xs text-surface-400 mb-3">
                    {{ indicadores.estructura.total }} persona(s) activa(s) hoy.
                </p>
                <div class="grid sm:grid-cols-3 gap-5">
                    <div v-for="grupo in [
                        { titulo: 'Por área', filas: indicadores.estructura.por_area },
                        { titulo: 'Por cargo', filas: indicadores.estructura.por_cargo },
                        { titulo: 'Por contrato', filas: indicadores.estructura.por_contrato },
                    ]" :key="grupo.titulo">
                        <div class="text-xs font-bold uppercase text-surface-400 mb-2">{{ grupo.titulo }}</div>
                        <div v-for="f in grupo.filas" :key="f.nombre" class="text-sm mb-1.5">
                            <div class="flex justify-between gap-2">
                                <span class="truncate">{{ f.nombre }}</span>
                                <span class="font-bold shrink-0">{{ f.cantidad }}</span>
                            </div>
                            <div class="h-1.5 rounded bg-surface-200 dark:bg-surface-800 mt-0.5 overflow-hidden">
                                <div class="h-full bg-brand-400"
                                    :style="{ width: ancho(f.cantidad, maximo(grupo.filas, 'cantidad')) }"></div>
                            </div>
                        </div>
                        <div v-if="!grupo.filas.length" class="text-xs text-surface-400">Sin datos.</div>
                    </div>
                </div>
            </div>

            <!-- Cómo estamos creciendo -->
            <div class="card p-5">
                <h2 class="font-bold text-sm uppercase text-surface-500 mb-3">Cómo estamos creciendo</h2>
                <div class="overflow-x-auto">
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs text-surface-500 uppercase border-b">
                            <tr>
                                <th class="text-left p-2">Mes</th>
                                <th class="text-right p-2">Ingresos</th>
                                <th class="text-right p-2">Retiros</th>
                                <th class="text-right p-2">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="m in indicadores.movimiento" :key="m.mes">
                                <td class="p-2 capitalize">{{ m.etiqueta }}</td>
                                <td class="p-2 text-right text-emerald-600">{{ m.ingresos || '—' }}</td>
                                <td class="p-2 text-right text-rose-600">{{ m.retiros || '—' }}</td>
                                <td class="p-2 text-right font-bold">
                                    <span v-if="m.saldo > 0" class="text-emerald-600 inline-flex items-center gap-1">
                                        <TrendingUp class="h-3.5 w-3.5" />+{{ m.saldo }}
                                    </span>
                                    <span v-else-if="m.saldo < 0" class="text-rose-600 inline-flex items-center gap-1">
                                        <TrendingDown class="h-3.5 w-3.5" />{{ m.saldo }}
                                    </span>
                                    <span v-else class="text-surface-400">0</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
