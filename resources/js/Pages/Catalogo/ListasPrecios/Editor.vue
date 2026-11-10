<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Tags, Search, Wand2, Save, ArrowLeft, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    lista: { type: Object, required: true },
    items: { type: Array, required: true },
    paginacion: { type: Object, required: true },
    filtros: { type: Object, default: () => ({ q: '', solo: null }) },
    otras_listas: { type: Array, default: () => [] },
    resumen: { type: Object, default: () => ({ vendibles: 0, tarifados: 0 }) },
});

const { money: fmtCOP } = useMoney();

// Lo que el usuario está escribiendo, por clave «tipo-id». Arranca con lo que
// ya está guardado para que se vea la lista como está hoy.
const borrador = reactive({});
const sembrar = () => {
    Object.keys(borrador).forEach((k) => delete borrador[k]);
    props.items.forEach((i) => { borrador[`${i.tipo}-${i.id}`] = i.precio ?? ''; });
};
sembrar();
watch(() => props.items, sembrar);

const q = ref(props.filtros.q ?? '');
const solo = ref(props.filtros.solo ?? '');
const guardando = ref(false);
const avisoDerivar = ref('');

const clave = (i) => `${i.tipo}-${i.id}`;

/** Cuántas casillas cambiaron respecto de lo guardado. */
const cambiados = computed(() => props.items.filter((i) => {
    const actual = borrador[clave(i)];
    const guardado = i.precio ?? '';
    return String(actual ?? '') !== String(guardado);
}).length);

/*
 * Un precio por debajo del mínimo de la variante no se bloquea: se marca.
 *
 * El mínimo es una referencia comercial, no una ley —hay liquidaciones y hay
 * cierres de temporada—, pero venderlo sin darse cuenta es otra cosa.
 */
const bajoMinimo = (i) => {
    const v = parseFloat(borrador[clave(i)]);
    return i.precio_min && v > 0 && v < i.precio_min;
};

const margen = (i) => {
    const v = parseFloat(borrador[clave(i)]);
    if (! i.costo || ! v || v <= 0) return null;
    return Math.round(((v - i.costo) / v) * 100);
};

const buscar = () => {
    router.get(`/app/catalogo/listas-precios/${props.lista.id}`,
        { q: q.value, solo: solo.value || undefined },
        { preserveState: true, preserveScroll: true });
};

const irA = (p) => {
    router.get(`/app/catalogo/listas-precios/${props.lista.id}`,
        { q: q.value, solo: solo.value || undefined, page: p },
        { preserveState: true, preserveScroll: true });
};

// ---- derivar -------------------------------------------------------------

const deriva = reactive({
    abierto: false,
    base: 'lista',
    lista_origen_id: null,
    porcentaje: 0,
    solo_vacios: true,
    redondeo: 100,
    calculando: false,
});

/*
 * Derivar NO guarda: calcula y rellena las casillas.
 *
 * Un porcentaje mal tecleado acá cambia el precio de todo el catálogo. Que
 * alguien lo vea antes de confirmar.
 */
const derivar = async () => {
    deriva.calculando = true;
    avisoDerivar.value = '';
    try {
        const r = await fetch(`/app/catalogo/listas-precios/${props.lista.id}/previsualizar`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                base: deriva.base,
                lista_origen_id: deriva.lista_origen_id,
                porcentaje: deriva.porcentaje,
                solo_vacios: deriva.solo_vacios,
                redondeo: deriva.redondeo,
                // Va lo que hay EN LA CASILLA, no lo guardado: si no, «sólo los
                // que no tienen precio» pisa lo que acabás de teclear a mano.
                ids: props.items.map((i) => ({
                    tipo: i.tipo,
                    id: i.id,
                    actual: Number(borrador[`${i.tipo}-${i.id}`]) || 0,
                })),
            }),
        });
        const data = await r.json();
        if (! r.ok) {
            avisoDerivar.value = data?.message ?? 'No se pudo calcular.';
            return;
        }
        data.precios.forEach((p) => { borrador[`${p.tipo}-${p.id}`] = p.precio; });
        avisoDerivar.value = data.mensaje;
    } catch (e) {
        avisoDerivar.value = 'No se pudo calcular: ' + e.message;
    } finally {
        deriva.calculando = false;
    }
};

const guardar = () => {
    guardando.value = true;
    router.put(`/app/catalogo/listas-precios/${props.lista.id}`, {
        precios: props.items.map((i) => ({
            tipo: i.tipo,
            id: i.id,
            precio: borrador[clave(i)] === '' ? null : borrador[clave(i)],
        })),
    }, {
        preserveScroll: true,
        onFinish: () => { guardando.value = false; },
    });
};
</script>

<template>
    <Head :title="`${lista.nombre} · Listas de precios`"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <Link href="/app/catalogo/listas-precios"
                      class="text-xs text-brand-600 hover:underline flex items-center gap-1 mb-1">
                    <ArrowLeft class="h-3 w-3"/> Volver a listas
                </Link>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Tags class="h-6 w-6 text-brand-600"/> {{ lista.nombre }}
                    <span class="text-xs font-normal text-surface-500">· {{ lista.canal }} · {{ lista.origen }}</span>
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    <strong>{{ resumen.tarifados }}</strong> de {{ resumen.vendibles }} productos tienen precio
                    en esta lista. Lo que quede en blanco no se le puede vender a un cliente que la tenga asignada.
                </p>
            </div>

            <!--
                `->with('success'|'warning')` llega como `flash.success` /
                `flash.warning`, no como `flash.message`: esa otra llave la
                llena la convención `->with('flash', [...])` y leerla acá
                dejaría el banner mudo.
            -->
            <div v-if="$page.props.flash?.warning || $page.props.flash?.success" class="p-3 rounded-lg text-sm"
                 :class="$page.props.flash?.warning
                    ? 'bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200'
                    : 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700'">
                {{ $page.props.flash?.warning || $page.props.flash?.success }}
            </div>

            <!-- Buscar y filtrar -->
            <div class="card p-3 flex flex-wrap gap-2 items-center">
                <div class="flex-1 min-w-[220px] relative">
                    <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                    <input v-model="q" @keyup.enter="buscar" type="search"
                           placeholder="Referencia o nombre del producto…"
                           class="w-full pl-9 pr-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm"/>
                </div>
                <select v-model="solo" @change="buscar"
                        class="px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm">
                    <option value="">Todos</option>
                    <option value="sin_precio">Sólo los que no tienen precio</option>
                    <option value="con_precio">Sólo los que ya tienen</option>
                </select>
                <button @click="buscar" class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium">
                    Buscar
                </button>
                <button @click="deriva.abierto = ! deriva.abierto"
                        class="px-3 py-2 rounded-lg border border-brand-600 text-brand-600 text-sm font-medium flex items-center gap-1">
                    <Wand2 class="h-4 w-4"/> Calcular precios
                </button>
            </div>

            <!-- Derivar -->
            <div v-if="deriva.abierto" class="card p-4 space-y-3">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600">
                    Calcular los precios de esta página
                </div>
                <p class="text-xs text-surface-500">
                    Rellena las casillas con el resultado. <strong>No guarda nada</strong>:
                    revisá los números y después dale «Guardar precios».
                </p>

                <div class="flex flex-wrap gap-3 items-end">
                    <label class="text-sm">
                        <span class="block text-xs text-surface-500 mb-1">A partir de</span>
                        <select v-model="deriva.base"
                                class="px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm">
                            <option value="lista">Otra lista</option>
                            <option value="costo">El costo de proveedor</option>
                        </select>
                    </label>

                    <label v-if="deriva.base === 'lista'" class="text-sm">
                        <span class="block text-xs text-surface-500 mb-1">Lista de origen</span>
                        <select v-model="deriva.lista_origen_id"
                                class="px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm">
                            <option :value="null">Elegí una…</option>
                            <option v-for="o in otras_listas" :key="o.id" :value="o.id">
                                {{ o.nombre }} ({{ o.tarifados }} con precio)
                            </option>
                        </select>
                    </label>

                    <label class="text-sm">
                        <span class="block text-xs text-surface-500 mb-1">
                            {{ deriva.base === 'lista' ? 'Ajuste %' : 'Margen %' }}
                        </span>
                        <input v-model.number="deriva.porcentaje" type="number" step="0.5"
                               class="w-28 px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm tabular-nums"/>
                    </label>

                    <label class="text-sm">
                        <span class="block text-xs text-surface-500 mb-1">Redondear a</span>
                        <select v-model.number="deriva.redondeo"
                                class="px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm">
                            <option :value="1">Al peso</option>
                            <option :value="50">$50</option>
                            <option :value="100">$100</option>
                            <option :value="1000">$1.000</option>
                        </select>
                    </label>

                    <label class="text-sm flex items-center gap-2 pb-2">
                        <input v-model="deriva.solo_vacios" type="checkbox" class="rounded"/>
                        <span class="text-xs">Sólo los que no tienen precio</span>
                    </label>

                    <button @click="derivar" :disabled="deriva.calculando || (deriva.base === 'lista' && ! deriva.lista_origen_id)"
                            class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium disabled:opacity-50">
                        {{ deriva.calculando ? 'Calculando…' : 'Calcular' }}
                    </button>
                </div>

                <div v-if="avisoDerivar" class="text-xs p-2 rounded bg-brand-500/10 text-brand-700 dark:text-brand-300">
                    {{ avisoDerivar }}
                </div>
            </div>

            <!-- La tabla -->
            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2 px-3">Producto</th>
                                <th class="text-left">Detalle</th>
                                <th class="text-right">Costo</th>
                                <th class="text-right px-3">Precio en esta lista</th>
                                <th class="text-right">Margen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="! items.length">
                                <td colspan="5" class="text-center py-8 text-surface-500">
                                    No hay productos que coincidan.
                                </td>
                            </tr>
                            <tr v-for="i in items" :key="clave(i)"
                                class="border-b border-surface-100 dark:border-surface-800/60">
                                <td class="py-1.5 px-3">
                                    <div class="font-medium">{{ i.nombre }}</div>
                                    <div class="text-[11px] text-surface-500 font-mono">{{ i.ref }}</div>
                                </td>
                                <td class="text-surface-500 text-xs">{{ i.detalle }}</td>
                                <td class="text-right tabular-nums text-surface-500 text-xs">
                                    {{ i.costo > 0 ? fmtCOP(i.costo) : '—' }}
                                </td>
                                <td class="px-3">
                                    <div class="flex justify-end">
                                        <input v-model="borrador[clave(i)]" type="number" step="1" min="0"
                                               placeholder="sin precio"
                                               class="w-36 px-2 py-1 text-right tabular-nums rounded border bg-transparent text-sm"
                                               :class="bajoMinimo(i)
                                                    ? 'border-red-500 text-red-600'
                                                    : (borrador[clave(i)] === '' || borrador[clave(i)] === null)
                                                        ? 'border-amber-400'
                                                        : 'border-surface-200 dark:border-surface-800'"/>
                                    </div>
                                    <div v-if="bajoMinimo(i)" class="text-[10px] text-red-600 text-right mt-0.5 flex items-center justify-end gap-1">
                                        <AlertTriangle class="h-3 w-3"/> bajo el mínimo ({{ fmtCOP(i.precio_min) }})
                                    </div>
                                </td>
                                <td class="text-right tabular-nums text-xs"
                                    :class="margen(i) !== null && margen(i) < 0 ? 'text-red-600 font-bold' : 'text-surface-500'">
                                    {{ margen(i) !== null ? margen(i) + '%' : '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="p-3 border-t border-surface-100 dark:border-surface-800 flex flex-wrap gap-3 items-center justify-between">
                    <div class="text-xs text-surface-500">
                        {{ paginacion.total }} productos · página {{ paginacion.pagina }} de {{ paginacion.paginas }}
                        <span v-if="cambiados > 0" class="ml-2 text-brand-600 font-semibold">
                            · {{ cambiados }} sin guardar
                        </span>
                    </div>
                    <div class="flex gap-2 items-center">
                        <button v-if="paginacion.pagina > 1" @click="irA(paginacion.pagina - 1)"
                                class="px-3 py-1.5 rounded border border-surface-200 dark:border-surface-800 text-sm">
                            « Anterior
                        </button>
                        <button v-if="paginacion.pagina < paginacion.paginas" @click="irA(paginacion.pagina + 1)"
                                class="px-3 py-1.5 rounded border border-surface-200 dark:border-surface-800 text-sm">
                            Siguiente »
                        </button>
                        <button @click="guardar" :disabled="guardando"
                                class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium flex items-center gap-1 disabled:opacity-50">
                            <Save class="h-4 w-4"/> {{ guardando ? 'Guardando…' : 'Guardar precios' }}
                        </button>
                    </div>
                </div>
            </div>

            <!--
                Se guarda de a página. Decirlo acá evita la sorpresa de cambiar
                tres páginas, guardar y descubrir que sólo quedó la última.
            -->
            <p class="text-xs text-surface-500">
                Se guarda la página que estás viendo. Si pasás a la siguiente sin guardar,
                lo que escribiste en ésta se pierde.
            </p>
        </div>
    </AppLayout>
</template>
