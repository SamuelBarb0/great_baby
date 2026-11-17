<script setup>
/**
 * De qué bodega sale cada ciudad.
 *
 * La pantalla se organiza alrededor de lo que de verdad hace daño: que falte
 * el comodín. Sin él, toda ciudad sin regla propia deja el pedido sin bodega,
 * sin mercancía apartada y fuera de la cola de bodega — y nada de eso se ve
 * hasta el día del alistamiento. Por eso lo primero que aparece arriba es ese
 * aviso, con el número de pedidos que ya están así.
 *
 * La lista de «ciudades sin regla» no es decorativa: es de dónde vienen los
 * pedidos de verdad, ordenadas por cuántos clientes hay. Es con lo que se arma
 * el ruteo la primera vez, en vez de adivinar.
 */
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Route, AlertTriangle, Plus, Pencil, Power, Trash2, MapPin } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    reglas: { type: Array, required: true },
    bodegas: { type: Array, required: true },
    hay_comodin: { type: Boolean, default: false },
    ciudades_sin_regla: { type: Array, default: () => [] },
    pedidos_sin_bodega: { type: Number, default: 0 },
    puede_cambiar: { type: Boolean, default: false },
});

const flash = ref(null);
const guardando = ref(false);
const editando = ref(null);

const avisar = (mensaje, tipo = 'ok') => {
    flash.value = { mensaje, tipo };
    setTimeout(() => (flash.value = null), 6000);
};

const nueva = (ciudad = '') => {
    editando.value = {
        id: null,
        ciudad,
        ubicacion_id: props.bodegas[0]?.id ?? null,
        prioridad: ciudad === '*' ? 999 : 100,
        activa: true,
        notas: '',
    };
};

const editar = (r) => {
    editando.value = { ...r };
};

const guardar = () => {
    if (guardando.value || !editando.value) return;
    guardando.value = true;
    router.post('/app/logistica/reglas-ruteo', editando.value, {
        preserveScroll: true,
        onSuccess: (page) => {
            editando.value = null;
            avisar(page.props?.flash?.success || 'Guardado.');
        },
        onError: (e) => avisar(Object.values(e)[0] || 'No se pudo guardar.', 'error'),
        onFinish: () => (guardando.value = false),
    });
};

const alternar = (r) => router.post(`/app/logistica/reglas-ruteo/${r.id}/alternar`, {}, { preserveScroll: true });

/*
 * La confirmación de borrar va EN la pantalla, no en un `confirm()`.
 *
 * El resto del ERP dejó de usar el diálogo del navegador a propósito: no se
 * puede traducir, no se puede explicar qué pasa con lo que ya se ruteó, y
 * dentro de la aplicación de escritorio puede no aparecer —y entonces borra
 * sin preguntar nada.
 */
const porEliminar = ref(null);

const pedirEliminar = (r) => (porEliminar.value = r);

const eliminarYa = () => {
    const r = porEliminar.value;
    if (!r) return;
    porEliminar.value = null;
    router.delete(`/app/logistica/reglas-ruteo/${r.id}`, { preserveScroll: true });
};

const activas = computed(() => props.reglas.filter((r) => r.activa).length);
</script>

<template>
    <Head title="De qué bodega sale cada ciudad" />

    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-6 space-y-5">

            <header class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Route class="w-6 h-6 text-brand-600" />
                        De qué bodega sale cada ciudad
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Decide de qué sede sale cada pedido y, por lo mismo, en la cola de quién aparece.
                    </p>
                </div>
                <button v-if="puede_cambiar" class="btn-primary text-sm inline-flex items-center gap-1" @click="nueva()">
                    <Plus class="w-4 h-4" /> Nueva regla
                </button>
            </header>

            <div v-if="flash" class="rounded-lg px-4 py-3 text-sm border"
                 :class="flash.tipo === 'error'
                    ? 'bg-red-50 border-red-200 text-red-800'
                    : 'bg-emerald-50 border-emerald-200 text-emerald-800'">
                {{ flash.mensaje }}
            </div>

            <!-- Lo que de verdad hace daño, dicho primero y con número. -->
            <div v-if="!hay_comodin" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                <div class="font-semibold flex items-center gap-2">
                    <AlertTriangle class="w-4 h-4" /> No hay regla comodín
                </div>
                <p class="mt-1">
                    Una ciudad sin regla propia deja el pedido <strong>sin bodega de origen</strong>: no se le aparta
                    mercancía —se le sigue ofreciendo a otros pedidos— y <strong>bodega nunca lo ve en su cola</strong>.
                    <template v-if="pedidos_sin_bodega">
                        Hoy hay <strong>{{ pedidos_sin_bodega }}</strong> pedido(s) así.
                    </template>
                </p>
                <button v-if="puede_cambiar" class="mt-2 text-sm font-semibold underline" @click="nueva('*')">
                    Crear la regla comodín
                </button>
                <!--
                    Quien administra bodegas puede MIRAR esta pantalla pero no
                    cambiarla —el ruteo reparte entre todas las sedes—, así que
                    leía la alarma, veía los números y no tenía ni un botón ni
                    una indicación de a quién pedírselo.
                -->
                <p v-else class="mt-2 text-sm">
                    Esto lo arregla <strong>Gerencia</strong> desde esta misma pantalla. Mientras tanto, a cada
                    pedido se le puede decir de qué bodega sale, uno por uno, con «Sale de» en el pedido.
                </p>
            </div>

            <div v-else-if="pedidos_sin_bodega" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Quedan <strong>{{ pedidos_sin_bodega }}</strong> pedido(s) de antes sin bodega de origen.
                El comodín ya existe, así que los nuevos salen bien; a los viejos hay que asignarles bodega
                desde el pedido.
            </div>

            <!-- ===================== REGLAS ===================== -->
            <section class="bg-white dark:bg-surface-900 rounded-xl border border-surface-200 dark:border-surface-800 overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-100 dark:border-surface-800">
                    <h2 class="font-semibold">Reglas · {{ activas }} activa(s)</h2>
                    <p class="text-xs text-surface-500 mt-0.5">
                        Se evalúan de menor a mayor prioridad. El comodín <code>*</code> va de último y atrapa
                        lo que nadie reclamó.
                    </p>
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-surface-500 bg-surface-50 dark:bg-surface-800/50">
                            <th class="px-4 py-2 w-20">Prior.</th>
                            <th class="px-2 py-2">Ciudad</th>
                            <th class="px-2 py-2">Sale de</th>
                            <th class="px-2 py-2">Notas</th>
                            <th class="px-2 py-2 w-28"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!reglas.length">
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-surface-500">
                                Todavía no hay ninguna regla. Mientras esté así, ningún pedido tiene bodega de origen.
                            </td>
                        </tr>
                        <tr v-for="r in reglas" :key="r.id"
                            class="border-t border-surface-100 dark:border-surface-800"
                            :class="{ 'opacity-50': !r.activa }">
                            <td class="px-4 py-2 font-mono text-surface-500">{{ r.prioridad }}</td>
                            <td class="px-2 py-2">
                                <span v-if="r.es_comodin" class="font-semibold">Cualquier otra ciudad</span>
                                <span v-else class="font-medium">{{ r.ciudad }}</span>
                            </td>
                            <td class="px-2 py-2">
                                {{ r.bodega || '—' }}
                                <span v-if="r.bodega_codigo" class="text-xs text-surface-500">({{ r.bodega_codigo }})</span>
                            </td>
                            <td class="px-2 py-2 text-xs text-surface-500">{{ r.notas || '—' }}</td>
                            <td class="px-2 py-2">
                                <div v-if="puede_cambiar" class="flex items-center gap-2">
                                    <button class="text-surface-400 hover:text-brand-600" title="Editar" @click="editar(r)">
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                    <button class="text-surface-400 hover:text-amber-600" :title="r.activa ? 'Apagar' : 'Encender'" @click="alternar(r)">
                                        <Power class="w-4 h-4" />
                                    </button>
                                    <button class="text-surface-400 hover:text-red-600" title="Eliminar" @click="pedirEliminar(r)">
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- ============== CIUDADES QUE HOY NO TIENEN REGLA ============== -->
            <section v-if="ciudades_sin_regla.length"
                     class="bg-white dark:bg-surface-900 rounded-xl border border-surface-200 dark:border-surface-800 p-4">
                <h2 class="font-semibold flex items-center gap-2">
                    <MapPin class="w-4 h-4 text-surface-400" />
                    Ciudades de sus clientes que todavía no tienen regla
                </h2>
                <p class="text-xs text-surface-500 mt-0.5 mb-3">
                    De aquí vienen los pedidos de verdad. Con comodín salen todas de la bodega por defecto;
                    si alguna debe salir de otra sede, dele su propia regla.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="c in ciudades_sin_regla" :key="c.ciudad"
                            :disabled="!puede_cambiar"
                            class="px-2.5 py-1 rounded-full text-xs border border-surface-200 dark:border-surface-700 disabled:cursor-default disabled:opacity-60"
                            :class="puede_cambiar ? 'hover:border-brand-400' : ''"
                            :title="puede_cambiar ? `Crear la regla de ${c.ciudad}` : 'Sólo Gerencia puede crear reglas'"
                            @click="nueva(c.ciudad)">
                        {{ c.ciudad }}
                        <span class="text-surface-400">· {{ c.clientes }}</span>
                    </button>
                </div>
            </section>
        </div>

        <!-- ===================== CONFIRMAR BORRADO ===================== -->
        <div v-if="porEliminar" class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50"
             @click.self="porEliminar = null">
            <div class="bg-white dark:bg-surface-900 rounded-xl w-full max-w-sm p-5 space-y-3">
                <h3 class="font-semibold">¿Eliminar la regla de «{{ porEliminar.ciudad }}»?</h3>
                <p class="text-sm text-surface-600 dark:text-surface-400">
                    Los pedidos que ya se rutearon <strong>no cambian</strong>: conservan su bodega.
                    Los nuevos de esa ciudad van a caer al comodín, y si no hay comodín, van a nacer
                    sin bodega de origen.
                </p>
                <div class="flex justify-end gap-2 pt-1">
                    <button class="text-sm px-3 py-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800"
                            @click="porEliminar = null">Cancelar</button>
                    <button class="text-sm px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700"
                            @click="eliminarYa">Eliminar</button>
                </div>
            </div>
        </div>

        <!-- ===================== MODAL ===================== -->
        <div v-if="editando" class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50"
             @click.self="editando = null">
            <div class="bg-white dark:bg-surface-900 rounded-xl w-full max-w-md p-5 space-y-3">
                <h3 class="font-semibold">{{ editando.id ? 'Editar regla' : 'Nueva regla' }}</h3>

                <label class="block text-sm">
                    <span class="text-surface-600">Ciudad</span>
                    <input v-model="editando.ciudad" maxlength="120" class="input w-full mt-1"
                           placeholder="Medellín · o * para todas las demás"/>
                    <span class="block text-xs text-surface-500 mt-0.5">
                        Escriba <code>*</code> para que atrape cualquier ciudad sin regla propia.
                    </span>
                </label>

                <label class="block text-sm">
                    <span class="text-surface-600">Sale de la bodega</span>
                    <select v-model="editando.ubicacion_id" class="input w-full mt-1" :disabled="!bodegas.length">
                        <option v-if="!bodegas.length" :value="null">— no hay bodegas para ofrecer —</option>
                        <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.label }}</option>
                    </select>
                    <!-- Un <select> sin una sola opción no se puede ni explicar: hay que
                         decir qué falta. Se ofrecen las sedes propias, activas y vendibles. -->
                    <span v-if="!bodegas.length" class="block text-xs text-red-600 mt-0.5">
                        No hay ninguna bodega propia activa y marcada para venta. Creala o activala en
                        Bodegas antes de rutear.
                    </span>
                </label>

                <label class="block text-sm">
                    <span class="text-surface-600">Prioridad</span>
                    <input v-model.number="editando.prioridad" type="number" min="0" max="9999" class="input w-full mt-1"/>
                    <span class="block text-xs text-surface-500 mt-0.5">
                        Menor gana. El comodín conviene dejarlo en 999 para que quede de último.
                    </span>
                </label>

                <label class="block text-sm">
                    <span class="text-surface-600">Notas</span>
                    <input v-model="editando.notas" maxlength="255" class="input w-full mt-1"/>
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="editando.activa" type="checkbox" class="rounded"/>
                    <span>Activa</span>
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <button class="btn-ghost text-sm" @click="editando = null">Cancelar</button>
                    <button class="btn-primary text-sm" :disabled="guardando" @click="guardar">Guardar</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
