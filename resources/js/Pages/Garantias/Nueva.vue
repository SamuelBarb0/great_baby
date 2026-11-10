<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ShieldCheck, Upload, Save, Search, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const form = reactive({
    cliente_nombre: '', cliente_telefono: '', pedido_guia: '',
    // El sujeto del reclamo: una variante, o el producto entero cuando se
    // maneja agregado. Antes el formulario declaraba `variante_id` y no tenía
    // ningún campo para elegirla, así que mandaba siempre vacío.
    variante_id: null, producto_id: null,
    cantidad: 1, descripcion_falla: '', fotos: [],
});
const procesando = ref(false);
const errorMsg = ref('');
const onFotos = (e) => { form.fotos = Array.from(e.target.files).slice(0, 5); };

/*
 * El buscador del producto, con las variantes y los productos de «colores
 * surtidos» en la misma lista: quien atiende el reclamo no tiene por qué saber
 * cómo se maneja el stock de ese producto.
 */
const busqueda = ref('');
const resultados = ref([]);
const elegido = ref(null);
let debTimer;

const buscar = () => {
    clearTimeout(debTimer);
    elegido.value = null;
    form.variante_id = null;
    form.producto_id = null;
    if (busqueda.value.trim().length < 2) { resultados.value = []; return; }
    debTimer = setTimeout(async () => {
        const r = await fetch(`/app/garantias/buscar-producto?q=${encodeURIComponent(busqueda.value.trim())}`);
        resultados.value = r.ok ? await r.json() : [];
    }, 200);
};

const seleccionar = (p) => {
    form.variante_id = p.variante_id;
    form.producto_id = p.producto_id;
    elegido.value = p;
    busqueda.value = p.label;
    resultados.value = [];
};

const limpiarProducto = () => {
    elegido.value = null;
    form.variante_id = null;
    form.producto_id = null;
    busqueda.value = '';
    resultados.value = [];
};

const guardar = () => {
    if (procesando.value) return;
    procesando.value = true;
    errorMsg.value = '';
    router.post('/app/garantias', form, {
        forceFormData: true,
        onError: (e) => { errorMsg.value = Object.values(e).flat()[0] || 'Error'; },
        onFinish: () => procesando.value = false,
    });
};
</script>

<template>
    <Head title="Nueva garantía"/>
    <AppLayout>
        <div class="max-w-3xl mx-auto space-y-4">
            <Link href="/app/garantias" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>
            <h1 class="text-2xl font-bold flex items-center gap-2"><ShieldCheck class="h-6 w-6 text-brand-600"/>Nueva garantía</h1>

            <div v-if="errorMsg" class="p-3 rounded-lg bg-red-50 border-l-4 border-red-500 text-red-700 text-sm">{{ errorMsg }}</div>

            <div class="card p-5 space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div><label class="text-xs font-semibold">Cliente</label><input v-model="form.cliente_nombre" class="input w-full" required autofocus/></div>
                    <div><label class="text-xs font-semibold">Teléfono</label><input v-model="form.cliente_telefono" class="input w-full" placeholder="300..."/></div>
                    <div>
                        <label class="text-xs font-semibold">Guía o factura de la venta original</label>
                        <input v-model="form.pedido_guia" class="input w-full font-mono"
                            placeholder="Guía de Dropi (4200000…) o factura (FV-261103-0001)"/>
                        <!--
                            El mismo campo acepta las dos cosas: quien atiende
                            el reclamo no tiene por qué saber de qué canal vino
                            la venta, y el cliente menos.
                        -->
                        <p class="text-[11px] text-surface-500 mt-1">
                            Con este dato el sistema comprueba que el producto y la cantidad
                            sí se le vendieron a ese cliente. Se puede dejar vacío si el
                            cliente no lo tiene, pero entonces no hay contra qué validar.
                        </p>
                    </div>
                    <div><label class="text-xs font-semibold">Cantidad</label><input type="number" min="1" v-model.number="form.cantidad" class="input w-full"/></div>
                </div>
                <!--
                    Qué producto falló. No existía este campo: el formulario
                    mandaba la variante vacía y el alta se caía, así que por
                    esta pantalla no se podía abrir ninguna garantía. Los
                    productos de «colores surtidos» no tienen variantes y
                    entran por su referencia.
                -->
                <div>
                    <label class="text-xs font-semibold">Producto que falló</label>
                    <div class="relative">
                        <div class="relative">
                            <input v-model="busqueda" @input="buscar" class="input w-full pr-8" autocomplete="off"
                                placeholder="Buscar por nombre, referencia o código de barras"/>
                            <button v-if="busqueda" type="button" @click="limpiarProducto"
                                class="absolute right-2 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600">
                                <X class="h-4 w-4"/>
                            </button>
                            <Search v-else class="absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 text-surface-400"/>
                        </div>
                        <ul v-if="resultados.length" class="absolute z-10 mt-1 w-full max-h-60 overflow-auto border rounded-lg bg-white shadow-lg">
                            <li v-for="r in resultados" :key="`${r.variante_id ?? 'p'}-${r.producto_id ?? 'v'}-${r.sku}`"
                                @click="seleccionar(r)" class="p-2 text-xs cursor-pointer hover:bg-brand-50 border-b last:border-0">
                                <div class="font-semibold">{{ r.producto }}</div>
                                <div class="text-surface-500">{{ r.detalle }} · <span class="font-mono">{{ r.sku }}</span></div>
                            </li>
                        </ul>
                    </div>
                    <p v-if="elegido" class="text-[11px] text-emerald-600 mt-1">
                        {{ elegido.producto }} · <span class="font-mono">{{ elegido.sku }}</span>
                        <span v-if="elegido.producto_id"> · sin desglose por color ni talla</span>
                    </p>
                    <p v-else class="text-[11px] text-surface-500 mt-1">
                        Si el producto se maneja por color y talla, elegí la variante exacta.
                    </p>
                </div>
                <div>
                    <label class="text-xs font-semibold">Descripción de la falla</label>
                    <textarea v-model="form.descripcion_falla" rows="4" class="input w-full" minlength="10" required placeholder="Describe la falla reportada por el cliente..."></textarea>
                </div>
                <div>
                    <label class="text-xs font-semibold">Fotos de evidencia (máx 5, 5MB c/u)</label>
                    <input type="file" accept="image/*" multiple @change="onFotos" class="input w-full"/>
                    <p v-if="form.fotos.length" class="text-xs text-surface-500 mt-1">{{ form.fotos.length }} archivos seleccionados</p>
                </div>
            </div>

            <button @click="guardar" :disabled="procesando" class="btn-primary w-full">
                <Save class="h-4 w-4"/> {{ procesando ? 'Guardando…' : 'Crear ticket de garantía' }}
            </button>
        </div>
    </AppLayout>
</template>
