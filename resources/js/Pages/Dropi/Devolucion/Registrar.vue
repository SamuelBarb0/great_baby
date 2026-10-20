<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Undo2, Search, Package, AlertCircle, ScanLine, Minus, Plus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { usePedidoBadge } from '@/composables/usePedidoBadge';
import { useSonido } from '@/composables/useSonido';

const props = defineProps({
    guiaBuscar: { type: String, default: '' },
    pedido: { type: Object, default: null },
    destinos: { type: Array, default: () => [] },
});

const guia = ref(props.guiaBuscar);
const procesando = ref(false);
const form = reactive({
    destino_inventario: props.destinos[0]?.value ?? 'reingreso',
    notas: '',
});
const buscar = () => {
    const q = (guia.value ?? '').trim();
    if (!q) return; // U10 · no dispara con input vacío
    router.get('/app/dropi/devolucion/registrar', { guia: q }, { preserveState: true, preserveScroll: true });
};

// 5.4 · Re-escaneo: lo que vuelve a bodega es lo que se escanea, no lo que
// decía el pedido. Cada lectura de la pistola suma una unidad a su producto.
const { beep } = useSonido();
const recibidos = reactive({});
const lectura = ref('');
const ultimo = ref(null); // { tipo: 'ok'|'warn'|'error', texto }
const inputEscaneo = ref(null);

const iniciarConteo = () => {
    Object.keys(recibidos).forEach((k) => delete recibidos[k]);
    (props.pedido?.por_escanear ?? []).forEach((r) => { recibidos[r.clave] = 0; });
    ultimo.value = null;
};
watch(() => props.pedido?.id, () => { iniciarConteo(); nextTick(() => inputEscaneo.value?.focus()); }, { immediate: true });

const esperadas = computed(() => (props.pedido?.por_escanear ?? []).reduce((s, r) => s + r.esperadas, 0));
const escaneadas = computed(() => Object.values(recibidos).reduce((s, n) => s + n, 0));
const completo = computed(() => esperadas.value > 0 && escaneadas.value === esperadas.value);

const escanear = () => {
    const codigo = (lectura.value ?? '').trim().toUpperCase();
    lectura.value = '';
    if (!codigo || !props.pedido) return;
    const renglon = props.pedido.por_escanear.find((r) => r.codigos.includes(codigo));
    if (!renglon) {
        ultimo.value = { tipo: 'error', texto: `${codigo} no es de este pedido` };
        beep('error');
        return;
    }
    if (recibidos[renglon.clave] >= renglon.esperadas) {
        ultimo.value = { tipo: 'warn', texto: `${renglon.referencia}: ya están las ${renglon.esperadas} que se despacharon` };
        beep('warn');
        return;
    }
    recibidos[renglon.clave]++;
    ultimo.value = { tipo: 'ok', texto: `✓ ${renglon.referencia} (${recibidos[renglon.clave]}/${renglon.esperadas})` };
    beep('ok');
};
// Por si una etiqueta no lee: ajuste a mano (queda igual en la devolución).
const ajustar = (r, delta) => {
    recibidos[r.clave] = Math.max(0, Math.min(r.esperadas, recibidos[r.clave] + delta));
};

const reingresa = computed(() => !['baja_total', 'no_llego_fisicamente'].includes(form.destino_inventario));

const guardar = () => {
    if (!props.pedido) return;
    // Re-audit DR-β UX (UX-C4) · "no llegó físicamente" requiere nota explícita
    //   (fraude/faltante fantasma es material — no aceptamos click a ciegas).
    if (form.destino_inventario === 'no_llego_fisicamente' && (form.notas ?? '').trim().length < 10) {
        alert('Para "no llegó físicamente" es obligatoria una nota (≥10 caracteres) que explique cómo se detectó.');
        return;
    }
    if (reingresa.value && esperadas.value > 0 && escaneadas.value === 0) {
        alert('No has escaneado ninguna unidad. Escanea lo que llegó o, si no llegó nada, elige «NO llegó físicamente».');
        return;
    }
    if (reingresa.value && escaneadas.value < esperadas.value
        && !confirm(`Faltan ${esperadas.value - escaneadas.value} unidad(es) por escanear. Solo vuelve a bodega lo escaneado y el faltante queda anotado. ¿Registrar así?`)) {
        return;
    }
    procesando.value = true;
    router.post('/app/dropi/devolucion/registrar', {
        pedido_id: props.pedido.id,
        destino_inventario: form.destino_inventario,
        notas: form.notas,
        recibidos: { ...recibidos },
    }, { onFinish: () => { procesando.value = false; } });
};

// Re-audit DR-β UX · destacar visualmente el destino "fantasma".
const esDestinoFantasma = (v) => v === 'no_llego_fisicamente';
const { money } = useMoney();
const { badge } = usePedidoBadge();
</script>

<template>
    <Head title="Registrar devolución"/>
    <AppLayout>
        <div class="max-w-3xl mx-auto space-y-4">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <Undo2 class="h-6 w-6 text-brand-600"/>
                Registrar devolución
            </h1>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.warning" class="p-3 rounded-lg bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-sm">
                {{ $page.props.flash.warning }}
            </div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">
                {{ $page.props.flash.error }}
            </div>

            <div class="card p-4">
                <label class="block text-xs font-semibold text-surface-600 mb-1">Guía o Dropi ID del pedido devuelto</label>
                <div class="flex gap-2">
                    <input v-model="guia" @keydown.enter="buscar" class="input flex-1 font-mono"
                        placeholder="Ej: 4200000123456 o DP-12345" autofocus/>
                    <button @click="buscar" class="btn-primary"><Search class="h-4 w-4"/> Buscar</button>
                </div>
            </div>

            <div v-if="guiaBuscar && !pedido" class="card p-6 text-center text-red-600">
                <AlertCircle class="h-8 w-8 mx-auto mb-2"/>
                No se encontró un pedido con esa guía.
            </div>

            <div v-if="pedido" class="card p-5 space-y-4">
                <div v-if="pedido.devolucion_existente" class="p-3 rounded-lg bg-amber-50 border-l-4 border-amber-500 text-amber-800 text-sm">
                    <AlertCircle class="h-4 w-4 inline"/> Este pedido ya tiene una devolución registrada.
                </div>

                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs text-surface-500">Guía</div>
                        <div class="font-mono font-bold text-lg">{{ pedido.guia }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-surface-500">Monto</div>
                        <div class="font-bold text-brand-600">{{ money(pedido.monto) }}</div>
                    </div>
                </div>
                <div class="border-t border-surface-200 dark:border-surface-800 pt-3">
                    <div class="text-sm font-semibold">{{ pedido.cliente }}</div>
                    <div class="text-xs text-surface-500 dark:text-surface-400 flex items-center gap-2 mt-1">
                        {{ pedido.ciudad }} ·
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase" :class="badge(pedido.estado).cls">
                            {{ badge(pedido.estado).label }}
                        </span>
                    </div>
                </div>

                <!-- 5.4 · Re-escaneo de lo que llegó -->
                <div v-if="!pedido.devolucion_existente && pedido.por_escanear.length">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Escanea lo que llegó</div>
                        <div class="text-sm font-bold" :class="completo ? 'text-emerald-600' : 'text-amber-600'">
                            {{ escaneadas }} / {{ esperadas }} unidades
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <ScanLine class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"/>
                            <input ref="inputEscaneo" v-model="lectura" @keydown.enter.prevent="escanear"
                                class="input w-full pl-9 font-mono" placeholder="Código de barras o referencia del producto"
                                autocomplete="off" aria-label="Escanear producto devuelto"/>
                        </div>
                    </div>
                    <p v-if="ultimo" class="mt-2 text-sm font-semibold"
                        :class="{ 'text-emerald-600': ultimo.tipo === 'ok', 'text-amber-600': ultimo.tipo === 'warn', 'text-red-600': ultimo.tipo === 'error' }">
                        {{ ultimo.texto }}
                    </p>
                    <ul class="mt-3 text-sm divide-y divide-surface-100 dark:divide-surface-800">
                        <li v-for="r in pedido.por_escanear" :key="r.clave" class="py-2 flex items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="font-mono text-xs text-surface-500">{{ r.referencia }}</div>
                                <div class="truncate">{{ r.descripcion }}</div>
                            </div>
                            <button type="button" @click="ajustar(r, -1)" class="btn-ghost p-1" :aria-label="`Quitar una unidad de ${r.referencia}`"><Minus class="h-4 w-4"/></button>
                            <div class="w-16 text-center font-bold tabular-nums"
                                :class="recibidos[r.clave] === r.esperadas ? 'text-emerald-600' : 'text-amber-600'">
                                {{ recibidos[r.clave] }}/{{ r.esperadas }}
                            </div>
                            <button type="button" @click="ajustar(r, 1)" class="btn-ghost p-1" :aria-label="`Sumar una unidad de ${r.referencia}`"><Plus class="h-4 w-4"/></button>
                        </li>
                    </ul>
                </div>

                <div v-else-if="!pedido.devolucion_existente">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-2">Ítems del pedido</div>
                    <ul class="text-sm divide-y divide-surface-100">
                        <li v-for="(it, idx) in pedido.items" :key="idx" class="py-2 flex items-center justify-between">
                            <div>
                                <div class="font-mono text-xs text-surface-500">{{ it.sku }}</div>
                                <div>{{ it.descripcion }}</div>
                            </div>
                            <div class="font-bold">{{ it.cantidad }}</div>
                        </li>
                    </ul>
                    <p class="text-xs text-amber-700 mt-2">Ningún ítem está vinculado al catálogo: no hay nada que reingresar al inventario.</p>
                </div>

                <div v-if="!pedido.devolucion_existente" class="border-t border-surface-200 dark:border-surface-800 pt-4 space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-surface-600 dark:text-surface-300 mb-1">Destino inventario</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <label v-for="opt in destinos" :key="opt.value"
                                :class="['cursor-pointer border-2 rounded-lg p-3 text-center text-sm transition min-h-[76px] flex items-center justify-center',
                                    form.destino_inventario === opt.value
                                        ? (esDestinoFantasma(opt.value) ? 'border-red-600 bg-red-50 dark:bg-red-950 text-red-800 dark:text-red-200' : 'border-brand-600 bg-brand-50 dark:bg-brand-950 text-brand-800 dark:text-brand-200')
                                        : (esDestinoFantasma(opt.value) ? 'border-red-300 text-red-700' : 'border-surface-200 dark:border-surface-800')]">
                                <input type="radio" v-model="form.destino_inventario" :value="opt.value" class="hidden"/>
                                <div class="font-semibold">{{ opt.label }}</div>
                            </label>
                        </div>
                        <p v-if="esDestinoFantasma(form.destino_inventario)" class="mt-2 text-xs text-red-700 bg-red-50 dark:bg-red-950 p-2 rounded border-l-4 border-red-500">
                            <b>Mercancía fantasma:</b> Dropi la marcó como devuelta pero NUNCA llegó a bodega. No se genera movimiento de inventario y queda alerta en Discrepancias para investigación. Requiere nota explicando cómo se detectó.
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-surface-600 mb-1">Notas (opcional)</label>
                        <textarea v-model="form.notas" rows="3" maxlength="500" class="input w-full"
                            placeholder="Ej: caja abierta, prenda con manchas, cliente rechazó tallaje"></textarea>
                    </div>
                    <button @click="guardar" :disabled="procesando" class="btn-primary w-full text-lg py-3 disabled:opacity-50">
                        <Package class="h-5 w-5"/>
                        {{ procesando ? 'Registrando…' : 'Registrar devolución' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
