<script setup>
import { reactive, computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Minus, ShoppingCart, AlertTriangle } from 'lucide-vue-next';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const props = defineProps({
    producto: { type: Object, required: true },
    variantes: { type: Array, required: true },
    /*
     * El nombre va en snake_case porque así lo manda el controlador.
     *
     * Estaba declarado `bloqueaSinStock` y llega `bloquea_sin_stock`. Vue no
     * convierte snake_case a camelCase —eso sólo pasa con kebab-case en las
     * plantillas—, así que el prop se quedaba siempre en su default `false` y
     * el tope de cantidad NUNCA se activaba, por más que Gerencia prendiera la
     * regla en pantalla. Un candado que no cierra es peor que no tenerlo:
     * figura como puesto.
     *
     * Cuando está apagado, el catálogo informa cuánto hay pero deja pedir más:
     * el inventario todavía no es confiable y bloquear por datos malos es peor.
     */
    bloquea_sin_stock: { type: Boolean, default: false },
});

const cantidades = reactive({});
props.variantes.forEach(v => { cantidades[v.id] = 0; });

// C-F-QA4 · cantidad separada para producto agregado (colores surtidos).
const cantidadAgregada = ref(0);

// Fix COD4 BAJA re-audit · v-model.number sobre input vacío/texto pega NaN.
//   Sin este guard, NaN propaga por totalItems y rompe el checkout con
//   `cantidad: null` en sessionStorage. Cuadramos siempre a entero >=0.
const setCantidadAgregada = (val) => {
    const n = Number.parseInt(val, 10);
    // El tope ya no es 9999 sino lo que de verdad hay en las bodegas de venta.
    const tope = topeDe(props.producto.disponible);
    cantidadAgregada.value = Number.isFinite(n) && n > 0 ? Math.min(n, tope) : 0;
};

// Fix R4 CRÍTICO re-audit · sólo permite comprar agregado si hay precio real.
const puedeComprarAgregado = computed(() =>
    !props.producto.es_agregado || (props.producto.precio_agregado != null && props.producto.precio_agregado > 0)
);

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

/*
 * El «+» no pasa de lo que hay.
 *
 * Es el «tope de pedido por producto» del contrato. Antes el cliente podía
 * pedir 500 de algo de lo que quedaban 3, y nadie se enteraba hasta el
 * alistamiento. El tope sale del disponible real de las bodegas de venta,
 * descontando lo que ya está apartado por otros pedidos.
 */
const topeDe = (disponible) => {
    if (!props.bloquea_sin_stock) return 9999;
    return Number.isFinite(disponible) ? disponible : 0;
};

const inc = (v) => {
    if (v.precio <= 0) return;
    cantidades[v.id] = Math.min(topeDe(v.disponible), (cantidades[v.id] || 0) + 1);
};
const dec = (v) => { cantidades[v.id] = Math.max(0, (cantidades[v.id] || 0) - 1); };

const totalItems = computed(() => {
    const gran = Object.values(cantidades).reduce((s, c) => s + (Number.isFinite(c) ? c : 0), 0);
    return gran + (Number.isFinite(cantidadAgregada.value) ? cantidadAgregada.value : 0);
});

const agregarAlCarrito = () => {
    if (totalItems.value === 0) return;
    // Fix R4 · si el producto es agregado sin precio real, NO permitir compra.
    //   El backend descartaría la línea en silencio; mejor bloquear en UI.
    if (!puedeComprarAgregado.value) return;
    let carrito = [];
    try { carrito = JSON.parse(sessionStorage.getItem('portal.carrito') || '[]'); } catch {}

    // C-F-QA4 · producto agregado (una sola línea por producto)
    if (props.producto.es_agregado && cantidadAgregada.value > 0) {
        const existe = carrito.find(i => i.producto_id === props.producto.id && !i.variante_id);
        if (existe) { existe.cantidad += cantidadAgregada.value; }
        else {
            carrito.push({
                producto_id: props.producto.id,
                variante_id: null,
                cantidad: cantidadAgregada.value,
                precio: props.producto.precio_agregado || 0,
                producto: props.producto.nombre,
                referencia: props.producto.referencia,
                detalle: 'Colores surtidos',
                es_agregado: true,
            });
        }
        cantidadAgregada.value = 0;
    }

    for (const v of props.variantes) {
        const cant = cantidades[v.id] || 0;
        if (cant === 0) continue;
        const existe = carrito.find(i => i.variante_id === v.id);
        if (existe) { existe.cantidad += cant; }
        else {
            carrito.push({
                variante_id: v.id,
                producto_id: null,
                cantidad: cant,
                precio: v.precio,
                producto: props.producto.nombre,
                referencia: props.producto.referencia,
                detalle: [v.color, v.talla].filter(Boolean).join(' · '),
                es_agregado: false,
            });
        }
        cantidades[v.id] = 0;
    }
    sessionStorage.setItem('portal.carrito', JSON.stringify(carrito));
    window.dispatchEvent(new CustomEvent('portal:carrito-actualizado'));
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
</script>

<template>
    <Head :title="producto.nombre"/>
    <PortalLayout>
        <div class="max-w-4xl mx-auto space-y-4">
            <Link href="/portal/catalogo" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver al catálogo
            </Link>

            <div class="card p-6">
                <div class="text-xs font-mono text-surface-500">{{ producto.referencia }}</div>
                <h1 class="text-2xl font-bold mt-1">{{ producto.nombre }}</h1>
                <p v-if="producto.descripcion" class="text-sm text-surface-600 dark:text-surface-400 mt-2">{{ producto.descripcion }}</p>
            </div>

            <!-- C-F-QA4 · AVISO agregado (colores surtidos) -->
            <div v-if="producto.es_agregado && producto.aviso_agregado"
                 class="card p-4 border-l-4 border-amber-500 bg-amber-50 dark:bg-amber-900/20">
                <div class="flex items-start gap-3">
                    <AlertTriangle class="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5"/>
                    <div class="text-sm text-amber-900 dark:text-amber-200">{{ producto.aviso_agregado }}</div>
                </div>
            </div>

            <!-- C-F-QA4 · Bloque de compra para producto AGREGADO -->
            <div v-if="producto.es_agregado" class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs uppercase tracking-widest font-bold text-amber-600">Producto surtido</div>
                    <div v-if="cantidadAgregada > 0" class="text-sm text-surface-600">{{ cantidadAgregada }} unidades</div>
                </div>

                <div class="py-3 flex items-center gap-4">
                    <div class="flex-1">
                        <div class="font-medium">Colores surtidos según disponibilidad</div>
                        <div class="text-xs text-surface-500 font-mono">{{ producto.referencia }}</div>
                    </div>
                    <div class="text-right w-28">
                        <div v-if="puedeComprarAgregado" class="font-bold">{{ money(producto.precio_agregado) }}</div>
                        <div v-else class="text-xs text-amber-700 font-semibold">Cotizar por WhatsApp</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="setCantidadAgregada((cantidadAgregada || 0) - 1)" :disabled="!cantidadAgregada || !puedeComprarAgregado" class="btn-ghost p-1.5 disabled:opacity-30">
                            <Minus class="h-4 w-4"/>
                        </button>
                        <input :value="cantidadAgregada" @input="setCantidadAgregada($event.target.value)" :disabled="!puedeComprarAgregado" type="number" min="0" max="9999" inputmode="numeric"
                            class="input w-16 text-center text-sm disabled:opacity-40 disabled:cursor-not-allowed"/>
                        <button @click="setCantidadAgregada((cantidadAgregada || 0) + 1)" :disabled="!puedeComprarAgregado" class="btn-ghost p-1.5 disabled:opacity-30">
                            <Plus class="h-4 w-4"/>
                        </button>
                    </div>
                </div>

                <div class="pt-4 border-t border-surface-200 dark:border-surface-800 mt-4">
                    <button @click="agregarAlCarrito" :disabled="totalItems === 0 || !puedeComprarAgregado" class="btn-primary w-full disabled:opacity-50 disabled:cursor-not-allowed">
                        <ShoppingCart class="h-4 w-4"/>
                        <span v-if="puedeComprarAgregado">Agregar {{ totalItems || '' }} al carrito</span>
                        <span v-else>Solicitar cotización por WhatsApp</span>
                    </button>
                </div>
            </div>

            <!-- Producto GRANULAR (variantes por color/talla) -->
            <div v-else class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600">Variantes</div>
                    <div v-if="totalItems > 0" class="text-sm text-surface-600">{{ totalItems }} unidades seleccionadas</div>
                </div>

                <div class="divide-y divide-surface-100 dark:divide-surface-800">
                    <div v-for="v in variantes" :key="v.id" class="py-3 flex items-center gap-4">
                        <div class="flex-1">
                            <div class="font-medium">
                                <span v-if="v.color">{{ v.color }}</span>
                                <span v-if="v.color && v.talla" class="text-surface-400"> · </span>
                                <span v-if="v.talla">Talla {{ v.talla }}</span>
                                <span v-if="!v.color && !v.talla" class="text-surface-500">Única</span>
                            </div>
                            <div class="text-xs text-surface-500 font-mono">{{ v.codigo_barras }}</div>
                        </div>
                        <div class="text-right w-28">
                            <div v-if="v.precio > 0" class="font-bold">{{ money(v.precio) }}</div>
                            <div v-else class="text-xs text-amber-600">Sin precio</div>
                            <!-- Lo que hay de esta variante, ya descontado lo apartado. -->
                            <div class="text-[11px] font-semibold" :class="[
                                v.disponible === 0 ? 'text-red-600'
                                : v.disponible <= 10 ? 'text-amber-600' : 'text-surface-500',
                            ]">
                                <template v-if="v.disponible === 0">Agotado</template>
                                <template v-else>{{ v.disponible }} disp.</template>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button @click="dec(v)" :disabled="!cantidades[v.id]" class="btn-ghost p-1.5 disabled:opacity-30">
                                <Minus class="h-4 w-4"/>
                            </button>
                            <input v-model.number="cantidades[v.id]" type="number" min="0" :max="topeDe(v.disponible)"
                                @input="cantidades[v.id] = Math.min(topeDe(v.disponible), Math.max(0, Number.parseInt($event.target.value, 10) || 0))"
                                :disabled="bloquea_sin_stock && v.disponible === 0"
                                class="input w-16 text-center text-sm disabled:opacity-40"/>
                            <button @click="inc(v)" :disabled="v.precio <= 0" class="btn-ghost p-1.5 disabled:opacity-30">
                                <Plus class="h-4 w-4"/>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="pt-4 border-t border-surface-200 dark:border-surface-800 mt-4">
                    <button @click="agregarAlCarrito" :disabled="totalItems === 0" class="btn-primary w-full disabled:opacity-50 disabled:cursor-not-allowed">
                        <ShoppingCart class="h-4 w-4"/>
                        Agregar {{ totalItems || '' }} al carrito
                    </button>
                </div>
            </div>
        </div>
    </PortalLayout>
</template>
