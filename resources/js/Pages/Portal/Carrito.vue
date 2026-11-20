<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ShoppingCart, Trash2, Send, Package } from 'lucide-vue-next';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { useEscClose } from '@/composables/useEscClose';

const items = ref([]);
const notas = ref('');
const enviando = ref(false);
const errorMsg = ref('');
const modalVaciar = ref(false);
useEscClose(modalVaciar);

const cargar = () => {
    try { items.value = JSON.parse(sessionStorage.getItem('portal.carrito') || '[]'); }
    catch { items.value = []; }
};
onMounted(cargar);

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);
const totalItems = computed(() => items.value.reduce((s, i) => s + (i.cantidad || 0), 0));
const totalPrecio = computed(() => items.value.reduce((s, i) => s + (i.cantidad * i.precio || 0), 0));

const guardar = () => {
    sessionStorage.setItem('portal.carrito', JSON.stringify(items.value));
    window.dispatchEvent(new CustomEvent('portal:carrito-actualizado'));
};
const eliminar = (idx) => { items.value.splice(idx, 1); guardar(); };
const actualizar = (idx, cant) => {
    items.value[idx].cantidad = Math.max(1, parseInt(cant) || 1);
    guardar();
};
const vaciar = () => { items.value = []; guardar(); modalVaciar.value = false; };

const confirmar = () => {
    if (enviando.value || items.value.length === 0) return;
    enviando.value = true;
    errorMsg.value = '';
    router.post('/portal/carrito/confirmar', {
        // C-F-QA4 · propagar producto_id para items agregados.
        items: items.value.map(i => ({
            variante_id: i.variante_id || null,
            producto_id: i.producto_id || null,
            cantidad: i.cantidad,
        })),
        notas: notas.value,
    }, {
        preserveState: false,
        onSuccess: () => {
            sessionStorage.removeItem('portal.carrito');
            window.dispatchEvent(new CustomEvent('portal:carrito-actualizado'));
        },
        onError: (e) => { errorMsg.value = Object.values(e)[0] || 'Error al enviar pedido'; },
        onFinish: () => { enviando.value = false; },
    });
};
</script>

<template>
    <Head title="Carrito"/>
    <PortalLayout>
        <div class="max-w-4xl mx-auto space-y-4">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <ShoppingCart class="h-6 w-6 text-brand-600"/>
                Tu carrito
            </h1>

            <div v-if="errorMsg" class="p-3 rounded-lg bg-red-50 border-l-4 border-red-500 text-red-700 text-sm">
                {{ errorMsg }}
            </div>

            <div v-if="!items.length" class="card p-12 text-center text-surface-500">
                <Package class="h-16 w-16 mx-auto text-surface-300 mb-4"/>
                <div class="font-semibold text-lg mb-2">Carrito vacío</div>
                <p class="text-sm mb-4">Aún no has agregado productos.</p>
                <Link href="/portal/catalogo" class="btn-primary inline-flex">Explorar catálogo</Link>
            </div>

            <div v-else class="space-y-3">
                <div class="card p-4">
                    <div class="divide-y divide-surface-100 dark:divide-surface-800">
                        <div v-for="(i, idx) in items" :key="idx" class="py-3 flex items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-mono text-surface-500">{{ i.referencia }}</div>
                                <div class="font-semibold truncate flex items-center gap-2">
                                    {{ i.producto }}
                                    <!-- C-F-QA4 · badge visible para items agregados -->
                                    <span v-if="i.es_agregado" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                        Colores surtidos
                                    </span>
                                </div>
                                <div class="text-xs text-surface-500">{{ i.detalle }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-surface-500">{{ money(i.precio) }} c/u</div>
                                <div class="font-bold">{{ money(i.precio * i.cantidad) }}</div>
                            </div>
                            <input type="number" min="1" max="9999" :value="i.cantidad"
                                @input="e => actualizar(idx, e.target.value)"
                                class="input w-20 text-center"/>
                            <button @click="eliminar(idx)" class="btn-ghost p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">
                                <Trash2 class="h-4 w-4"/>
                            </button>
                        </div>
                    </div>
                    <div class="pt-3 border-t border-surface-200 dark:border-surface-800 mt-3 flex items-center justify-between">
                        <button @click="modalVaciar = true" class="btn-ghost text-xs text-red-600">Vaciar carrito</button>
                        <div class="text-right">
                            <div class="text-xs text-surface-500">Total ({{ totalItems }} uds)</div>
                            <div class="text-2xl font-bold text-brand-600">{{ money(totalPrecio) }}</div>
                        </div>
                    </div>
                </div>

                <div class="card p-4">
                    <label class="block text-xs font-semibold text-surface-600 dark:text-surface-400 mb-1">Notas para el equipo (opcional)</label>
                    <textarea v-model="notas" rows="3" maxlength="1000" placeholder="Instrucciones de despacho, referencia de compra, etc."
                        class="input w-full"></textarea>
                </div>

                <button @click="confirmar" :disabled="enviando" class="btn-primary w-full text-lg py-3 disabled:opacity-50">
                    <Send class="h-5 w-5"/>
                    {{ enviando ? 'Enviando pedido…' : 'Enviar pedido a GREAT BABY' }}
                </button>
                <p class="text-xs text-surface-500 text-center">Al enviar, el equipo revisará stock y confirmará precios antes de facturar.</p>
            </div>
        </div>

        <!-- Modal confirmar vaciar carrito (evita destrucción accidental por tap en tablet) -->
        <div v-if="modalVaciar" @click.self="modalVaciar = false"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-surface-900 rounded-xl shadow-2xl p-6 max-w-sm w-full">
                <h3 class="text-lg font-bold mb-2">Vaciar carrito</h3>
                <p class="text-sm text-surface-600 dark:text-surface-400">
                    Se eliminarán {{ items.length }} producto{{ items.length !== 1 ? 's' : '' }} del carrito. Esta acción no se puede deshacer.
                </p>
                <div class="flex items-center justify-end gap-2 mt-4">
                    <button @click="modalVaciar = false" class="btn-ghost">Cancelar</button>
                    <button @click="vaciar" class="btn-primary bg-red-600 hover:bg-red-700">Vaciar</button>
                </div>
            </div>
        </div>
    </PortalLayout>
</template>
