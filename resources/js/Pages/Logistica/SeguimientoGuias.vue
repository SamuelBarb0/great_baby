<script setup>
/**
 * Dónde está cada guía despachada.
 *
 * El pedido B2B moría en «despachado» y nadie volvía a mirarlo: si la
 * mercancía no llegaba, el primero en enterarse era el cliente llamando.
 *
 * Abre filtrada por lo que pide atención —tardías y no entregadas— porque esa
 * es la razón de entrar. Lo demás está a un clic.
 */
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Truck, ExternalLink, PackageCheck } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useEscClose } from '@/composables/useEscClose';

const props = defineProps({
    guias: { type: Array, required: true },
    conteo: { type: Object, required: true },
    filtro: { type: String, default: 'atencion' },
    plazos: { type: Object, required: true },
});

const money = (n) => new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP', maximumFractionDigits: 0,
}).format(n || 0);

const filtrar = (estado) => router.get('/app/logistica/guias', { estado },
    { preserveScroll: true, preserveState: true });

const TONOS = {
    red: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    emerald: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    sky: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
    surface: 'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
};

// Marcar entregado · la nota es obligatoria: sin saber quién recibió, una
// guía «entregada» no sirve para reclamarle nada a nadie.
const entregando = ref(null);
useEscClose(entregando);
const nota = ref('');
const fecha = ref('');
const guardando = ref(false);

const abrirEntrega = (g) => {
    entregando.value = g;
    nota.value = '';
    fecha.value = '';
};

const confirmarEntrega = () => {
    if (!entregando.value || nota.value.trim().length < 3) return;
    guardando.value = true;
    router.post(`/app/logistica/guias/${entregando.value.id}/entregar`,
        { nota: nota.value, fecha: fecha.value || null },
        {
            preserveScroll: true,
            onFinish: () => { guardando.value = false; entregando.value = null; },
        });
};
</script>

<template>
    <Head title="Seguimiento de guías" />
    <AppLayout>
        <div class="p-4 md:p-6 space-y-4">
            <div>
                <h1 class="text-xl font-bold flex items-center gap-2">
                    <Truck class="h-5 w-5 text-brand-600" /> Seguimiento de guías
                </h1>
                <p class="text-sm text-surface-500">
                    Dónde está lo que ya salió de bodega. Una entrega normal toma
                    {{ plazos.transito }} días; pasados {{ plazos.sin_entregar }}
                    hay que reclamarle a la transportadora.
                </p>
            </div>

            <!-- Contadores · son de TODO, no de lo filtrado, para poder comparar -->
            <div class="flex flex-wrap gap-2">
                <button @click="filtrar('atencion')"
                    :class="['px-3 py-2 rounded-lg text-sm font-semibold border',
                        filtro === 'atencion' ? 'bg-brand-600 text-white border-brand-600'
                        : 'border-surface-200 dark:border-surface-700']">
                    Piden atención
                    <span class="ml-1 opacity-80">
                        {{ (conteo.no_entregada || 0) + (conteo.tardia || 0) }}
                    </span>
                </button>
                <button v-for="(n, estado) in conteo" :key="estado" @click="filtrar(estado)"
                    :class="['px-3 py-2 rounded-lg text-sm border',
                        filtro === estado ? 'ring-2 ring-brand-500' : '',
                        'border-surface-200 dark:border-surface-700']">
                    <span class="font-semibold">{{ n }}</span>
                    <span class="text-xs text-surface-500 ml-1">{{ estado.replace('_', ' ') }}</span>
                </button>
                <button @click="filtrar('todos')"
                    :class="['px-3 py-2 rounded-lg text-sm border',
                        filtro === 'todos' ? 'ring-2 ring-brand-500' : '',
                        'border-surface-200 dark:border-surface-700']">
                    Todos
                </button>
            </div>

            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-surface-500 border-b border-surface-200 dark:border-surface-800">
                        <tr>
                            <th class="text-left p-3">Pedido</th>
                            <th class="text-left p-3">Cliente</th>
                            <th class="text-left p-3">Transportadora</th>
                            <th class="text-left p-3">Despachado</th>
                            <th class="text-right p-3">Días</th>
                            <th class="text-left p-3">Estado</th>
                            <th class="text-right p-3">Total</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="g in guias" :key="g.id"
                            class="border-b border-surface-100 dark:border-surface-800/60">
                            <td class="p-3 font-mono text-xs">
                                <a :href="`/app/pedidos-b2b/${g.id}`" class="text-brand-600 hover:underline">{{ g.numero }}</a>
                            </td>
                            <td class="p-3">
                                {{ g.cliente }}
                                <div v-if="g.ciudad" class="text-xs text-surface-500">{{ g.ciudad }}</div>
                            </td>
                            <td class="p-3">
                                <div>{{ g.transportadora || '—' }}</div>
                                <div v-if="g.guia" class="text-xs font-mono text-surface-500">{{ g.guia }}</div>
                            </td>
                            <td class="p-3 text-xs">{{ g.despachado || '—' }}</td>
                            <td class="p-3 text-right font-semibold">{{ g.dias ?? '—' }}</td>
                            <td class="p-3">
                                <span :class="['px-2 py-0.5 rounded text-xs font-bold', TONOS[g.color]]">
                                    {{ g.estado_label }}
                                </span>
                                <!--
                                    «Falta resto» va APARTE del estado, no como
                                    un estado más.

                                    Son dos ejes: la guía que ya salió va a
                                    tiempo, tardía o no entregada, y ADEMÁS puede
                                    faltar mercancía. Si fuera un estado, un
                                    parcial despachado hace treinta días dejaría
                                    de ser «no entregada» y se caería de «Piden
                                    atención» — se apagaría la alarma justo sobre
                                    la caja que hay que reclamar.
                                -->
                                <span v-if="g.resto_pendiente"
                                      class="ml-1 px-2 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                                      title="De este pedido falta mercancía por despachar">
                                    falta resto
                                </span>
                                <div v-if="g.entregado" class="text-xs text-surface-500 mt-0.5">
                                    {{ g.entregado }} · {{ g.entrega_nota }}
                                </div>
                            </td>
                            <td class="p-3 text-right">{{ money(g.total) }}</td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <a v-if="g.rastreo" :href="g.rastreo" target="_blank" rel="noopener"
                                    class="btn-ghost p-1.5 inline-flex" title="Rastrear en la transportadora">
                                    <ExternalLink class="h-4 w-4" />
                                </a>
                                <button v-if="g.despachado && !g.entregado" @click="abrirEntrega(g)"
                                    class="btn-primary text-xs px-2 py-1 inline-flex items-center gap-1">
                                    <PackageCheck class="h-3.5 w-3.5" /> Llegó
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!guias.length">
                            <td colspan="8" class="p-8 text-center text-surface-500">
                                Nada acá. Si entraste por «Piden atención», es una buena noticia.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!--
            Modal propio y no AppPromptModal: ahí sólo cabe un texto, y acá la
            FECHA importa tanto como la nota. Registrar hoy una entrega de hace
            tres días dañaría justo el número que esta pantalla existe para
            mostrar.
        -->
        <div v-if="entregando" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50"
            @click.self="entregando = null">
          <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl max-w-md w-full m-4 p-5 space-y-4">
            <h2 class="font-bold text-lg">Marcar {{ entregando.numero }} como entregado</h2>
            <div class="space-y-3">
                <div>
                    <label class="text-xs font-semibold">¿Quién recibió? *</label>
                    <input v-model="nota" class="input w-full" maxlength="300"
                        placeholder="Nombre de quien firmó, o por qué no se pudo entregar" />
                    <p class="text-[11px] text-surface-500 mt-1">
                        Sin esto, una guía «entregada» no sirve para reclamar nada después.
                    </p>
                </div>
                <div>
                    <label class="text-xs font-semibold">Fecha de entrega</label>
                    <input v-model="fecha" type="date" class="input w-full" />
                    <p class="text-[11px] text-surface-500 mt-1">Si se deja vacía, queda la de hoy.</p>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button @click="entregando = null" class="btn-ghost">Cancelar</button>
                    <button @click="confirmarEntrega" :disabled="guardando || nota.trim().length < 3"
                        class="btn-primary disabled:opacity-40">
                        {{ guardando ? 'Guardando…' : 'Marcar entregado' }}
                    </button>
                </div>
            </div>
          </div>
        </div>
    </AppLayout>
</template>
