<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowLeft, Search, Plus, Trash2, Send } from 'lucide-vue-next';

const props = defineProps({
    cliente: { type: Object, required: true },
    variantes: { type: Array, required: true },
    credito: { type: Object, required: true },
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

const query = ref('');
const notas = ref('');
const procesando = ref(false);
const err = ref(null);

/**
 * Carrito indexado por una clave que sirve para los dos tipos de línea.
 *
 * Un producto granular se identifica por su variante; uno agregado —colores
 * surtidos— por el producto, y ahí `variante_id` viene nulo. Usando el
 * variante_id como clave, todos los agregados caían en la misma casilla
 * «null» y se pisaban entre ellos.
 */
const claveDe = (v) => (v.variante_id ? `v:${v.variante_id}` : `p:${v.producto_id}`);
const carrito = ref({});

const resultados = computed(() => {
    const q = (query.value || '').trim().toLowerCase();
    if (!q) return [];
    return props.variantes
        .filter((v) =>
            (v.sku || '').toLowerCase().includes(q)
            || (v.nombre || '').toLowerCase().includes(q)
            || (v.ref || '').toLowerCase().includes(q)
            || (v.color || '').toLowerCase().includes(q)
        )
        .slice(0, 30);
});

const agregar = (v) => {
    const k = claveDe(v);
    if (carrito.value[k]) {
        carrito.value[k].cantidad += 1;
    } else {
        carrito.value[k] = {
            clave: k,
            variante_id: v.variante_id ?? null,
            producto_id: v.producto_id ?? null,
            sku: v.sku, nombre: v.nombre, color: v.color, talla: v.talla,
            precio: v.precio, iva_pct: v.iva_pct, cantidad: 1,
            disponible: v.disponible ?? 0,
        };
    }
};
const quitar = (k) => { delete carrito.value[k]; carrito.value = { ...carrito.value }; };

/*
 * Lo que se está prometiendo por encima de lo que hay.
 *
 * El catálogo no mostraba existencias: se podían cargar 999 unidades de algo
 * que tiene 35 y ni la pantalla ni el envío decían nada. El pedido salía, el
 * cliente quedaba avisado, y el faltante aparecía recién en bodega.
 *
 * No se bloquea —Great Baby vende contra contenedor en camino— pero se ve.
 */
const sinStock = computed(() =>
    carritoLista.value.filter((it) => it.cantidad > (it.disponible ?? 0)));

const carritoLista = computed(() => Object.values(carrito.value));
const subtotal = computed(() => carritoLista.value.reduce((a, it) => a + it.precio * it.cantidad, 0));
const iva = computed(() => carritoLista.value.reduce((a, it) => a + (it.precio * it.cantidad) * (it.iva_pct / 100), 0));
const total = computed(() => subtotal.value + iva.value);

// Preview del semáforo con el total actual.
const vaARetener = computed(() => {
    if (props.credito.tiene_mora_critica) return true;
    if (props.credito.facturas_vencidas > 0) return true;
    if (props.credito.cupo > 0 && (props.credito.saldo_cartera + total.value) > props.credito.cupo) return true;
    return false;
});
const motivoRetencion = computed(() => {
    const m = [];
    if (props.credito.tiene_mora_critica) m.push(`mora crítica (${props.credito.dias_mora_max} días)`);
    if (props.credito.facturas_vencidas > 0) m.push(`${props.credito.facturas_vencidas} factura(s) vencida(s)`);
    const excedido = (props.credito.saldo_cartera + total.value) - props.credito.cupo;
    if (props.credito.cupo > 0 && excedido > 0) m.push(`cupo excedido en ${money(excedido)}`);
    return m.join(' · ');
});

const confirmar = () => {
    err.value = null;
    if (!carritoLista.value.length) {
        err.value = 'Agregá al menos un producto.';
        return;
    }
    procesando.value = true;
    router.post(`/app/vendedor/pedido-nuevo/${props.cliente.id}`, {
        items: carritoLista.value.map((it) => ({
            variante_id: it.variante_id,
            producto_id: it.producto_id,
            cantidad: it.cantidad,
        })),
        notas: notas.value || null,
    }, {
        onError: (e) => { err.value = Object.values(e)[0] || 'No se pudo crear.'; },
        onFinish: () => procesando.value = false,
    });
};
</script>

<template>
    <Head :title="`Pedido · ${cliente.nombre}`"/>
    <AppLayout>
        <div class="space-y-4 max-w-6xl mx-auto">
            <Link href="/app/vendedor" class="btn-ghost text-sm w-fit">
                <ArrowLeft class="h-4 w-4"/> Cambiar de cliente
            </Link>

            <!-- Encabezado cliente + semáforo cartera -->
            <div class="card p-4 flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-xs text-surface-500 uppercase font-semibold">Pedido para</div>
                    <h1 class="text-xl font-bold">{{ cliente.nombre }}</h1>
                    <div class="text-sm text-surface-500">
                        NIT {{ cliente.documento }} · {{ cliente.ciudad }} · lista <b>{{ cliente.lista }}</b>
                    </div>
                </div>
                <div class="text-right text-xs">
                    <div class="text-surface-500 uppercase font-semibold mb-1">Semáforo cartera</div>
                    <div class="space-y-0.5">
                        <div>Saldo actual: <b>{{ money(credito.saldo_cartera) }}</b></div>
                        <div v-if="credito.cupo > 0">Cupo: <b>{{ money(credito.cupo) }}</b></div>
                        <div v-if="credito.facturas_vencidas > 0" class="text-amber-700 font-bold">
                            ⚠ {{ credito.facturas_vencidas }} factura(s) vencida(s)
                        </div>
                        <div v-if="credito.tiene_mora_critica" class="text-red-700 font-bold">
                            🛑 Mora crítica · {{ credito.dias_mora_max }} días
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <!-- Buscador + resultados -->
                <div class="lg:col-span-3 card p-4 space-y-3">
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400"/>
                        <input v-model="query" type="text" placeholder="Buscar SKU, nombre, referencia, color…"
                               class="w-full pl-10 pr-4 py-2.5 border border-surface-300 rounded-lg dark:bg-surface-900 focus:border-brand-500 focus:outline-none"
                               autofocus>
                    </div>
                    <div v-if="!query" class="text-sm text-surface-400 italic">
                        Buscá por nombre de producto, SKU, referencia o color para ir agregando.
                    </div>
                    <div v-else-if="!resultados.length" class="text-sm text-surface-500">Sin resultados.</div>
                    <div v-else class="divide-y divide-surface-200 dark:divide-surface-800 max-h-[500px] overflow-y-auto">
                        <div v-for="v in resultados" :key="claveDe(v)"
                             class="flex items-center justify-between gap-2 py-2">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold truncate">{{ v.nombre }}</div>
                                <div class="text-xs text-surface-500">
                                    <span class="font-mono">{{ v.sku }}</span>
                                    <span v-if="v.color || v.talla"> · {{ v.color }} {{ v.talla }}</span>
                                </div>
                            </div>
                            <div class="text-right whitespace-nowrap">
                                <div class="text-sm font-bold">{{ money(v.precio) }}</div>
                                <div :class="['text-[10px] font-semibold',
                                              v.disponible > 0 ? 'text-emerald-600' : 'text-red-600']">
                                    {{ v.disponible > 0 ? `${v.disponible} disponibles` : 'sin stock' }}
                                </div>
                            </div>
                            <button @click="agregar(v)" class="btn-primary text-xs px-2 py-1">
                                <Plus class="h-3 w-3"/> Agregar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Carrito -->
                <div class="lg:col-span-2 card p-4 space-y-3">
                    <div class="font-bold flex items-center gap-2">
                        🛒 Carrito ({{ carritoLista.length }} ítems)
                    </div>
                    <div v-if="!carritoLista.length" class="text-sm text-surface-400 italic text-center py-6">
                        Agregá productos desde el buscador.
                    </div>
                    <div v-else class="space-y-2 max-h-[380px] overflow-y-auto">
                        <div v-for="it in carritoLista" :key="it.clave"
                             :class="['p-2 rounded border flex items-center gap-2',
                                      it.cantidad > (it.disponible ?? 0)
                                        ? 'border-red-400 bg-red-50 dark:bg-red-950/20'
                                        : 'border-surface-200 dark:border-surface-700']">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold truncate">{{ it.nombre }}</div>
                                <div class="text-xs text-surface-500">{{ it.color }} {{ it.talla }} · {{ money(it.precio) }}</div>
                                <div v-if="it.cantidad > (it.disponible ?? 0)" class="text-[10px] font-bold text-red-600">
                                    Sólo hay {{ it.disponible ?? 0 }} · faltan {{ it.cantidad - (it.disponible ?? 0) }}
                                </div>
                            </div>
                            <input v-model.number="it.cantidad" type="number" min="1" max="9999"
                                   class="w-14 text-center border rounded px-1 py-0.5 text-sm">
                            <button @click="quitar(it.clave)" class="text-red-600 hover:text-red-800">
                                <Trash2 class="h-4 w-4"/>
                            </button>
                        </div>
                    </div>

                    <textarea v-model="notas" placeholder="Notas opcionales para el pedido…" rows="2"
                              class="w-full text-sm border rounded p-2 dark:bg-surface-800"></textarea>

                    <div class="border-t border-surface-200 dark:border-surface-700 pt-3 space-y-1 text-sm">
                        <div class="flex justify-between"><span>Subtotal</span><b>{{ money(subtotal) }}</b></div>
                        <div class="flex justify-between text-surface-500"><span>IVA</span><span>{{ money(iva) }}</span></div>
                        <div class="flex justify-between text-lg font-bold border-t pt-1"><span>Total</span><span class="text-brand-600">{{ money(total) }}</span></div>
                    </div>

                    <div v-if="sinStock.length" class="p-2 rounded bg-red-50 border-l-4 border-red-500 text-xs text-red-800">
                        ⚠ <b>No hay stock para {{ sinStock.length }} ítem(s)</b> de este pedido.
                        Se puede enviar igual si la mercancía viene en camino, pero bodega no va a
                        poder alistarlo completo hasta que entre.
                    </div>

                    <div v-if="vaARetener && carritoLista.length" class="p-2 rounded bg-amber-50 border-l-4 border-amber-500 text-xs text-amber-800">
                        ⚠ Este pedido va a quedar <b>RETENIDO por cartera</b>: {{ motivoRetencion }}.
                        Gerencia lo liberará desde "Pedidos B2B".
                    </div>

                    <div v-if="err" class="text-sm text-red-600 bg-red-50 p-2 rounded">{{ err }}</div>

                    <button @click="confirmar" :disabled="procesando || !carritoLista.length"
                            class="btn-primary w-full py-2.5 text-sm">
                        <Send class="h-4 w-4"/>
                        {{ procesando ? 'Enviando…' : `Enviar pedido para ${cliente.nombre}` }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
