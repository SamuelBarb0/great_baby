<script setup>
import { reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { Calculator, ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

/*
 * Calculadora rápida de Alejandro: precio del proveedor + unidades por caja +
 * CBM (o medidas de la caja) → costo puesto en Colombia. Usa el mismo
 * servicio que la pre-liquidación de la OC, con los parámetros de la última
 * orden de importación, así que el número coincide con el de la orden.
 */
const props = defineProps({
    parametros: { type: Object, required: true },
    tasa: { type: Number, required: true },
});

const { money } = useMoney();

const form = reactive({
    precio: null,
    unidades_por_caja: null,
    cbm_caja: null,
    caja_largo_cm: null,
    caja_ancho_cm: null,
    caja_alto_cm: null,
    tasa_cambio: props.tasa,
    ...props.parametros,
});
const verParametros = ref(false);
const resultado = ref(null);
const error = ref('');
const calculando = ref(false);

const calcular = async () => {
    calculando.value = true;
    error.value = '';
    try {
        // Como en la OC: el CBM escrito manda; si está vacío, salen de las medidas.
        const payload = { ...form };
        for (const k of ['cbm_caja', 'caja_largo_cm', 'caja_ancho_cm', 'caja_alto_cm']) {
            if (!(Number(payload[k]) > 0)) delete payload[k];
        }
        const { data } = await axios.post('/app/compras/calculadora', payload);
        resultado.value = data;
    } catch (err) {
        resultado.value = null;
        const errores = err.response?.data?.errors;
        error.value = errores ? Object.values(errores).flat()[0] : 'No se pudo calcular.';
    } finally {
        calculando.value = false;
    }
};

const filas = [
    ['costo_china', 'Costo China'],
    ['costo_china_comision', 'Costo China con comisión'],
    ['flete', 'Flete'],
    ['arancel', 'Arancel'],
    ['iva', 'IVA'],
    ['gasto_colombia', 'Gasto Colombia'],
];
const parametrosVisibles = [
    ['comision_pct', 'Comisión %', '0.01'],
    ['flete_m3', 'Flete por m³', '1'],
    ['gasto_colombia_m3', 'Gasto Colombia por m³', '1'],
    ['arancel_pct', 'Arancel %', '0.01'],
    ['iva_pct', 'IVA %', '0.01'],
    ['divisor_precio_1', 'Divisor precio 1', '0.01'],
    ['divisor_precio_2', 'Divisor precio 2', '0.01'],
];
</script>

<template>
    <Head title="Calculadora de importación"/>
    <AppLayout>
        <div class="max-w-3xl mx-auto space-y-4">
            <Link href="/app/compras" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <Calculator class="h-6 w-6 text-brand-600"/>
                Calculadora de importación
            </h1>

            <form class="card p-5 space-y-3" @submit.prevent="calcular">
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs font-semibold">Precio por unidad (RMB) <span class="text-red-500">*</span></label>
                        <input type="number" step="0.0001" min="0" v-model.number="form.precio" class="input w-full" required/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Unidades por caja <span class="text-red-500">*</span></label>
                        <input type="number" step="1" min="1" v-model.number="form.unidades_por_caja" class="input w-full" required/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">Tasa (COP por RMB)</label>
                        <input type="number" step="0.01" min="0" v-model.number="form.tasa_cambio" class="input w-full"/>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">CBM de la caja</label>
                        <input type="number" step="0.000001" min="0" v-model.number="form.cbm_caja" class="input w-full" placeholder="o las medidas →"/>
                    </div>
                    <div class="col-span-1 md:col-span-2">
                        <label class="text-xs font-semibold">Medidas de la caja (cm)</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.1" min="0" v-model.number="form.caja_largo_cm" class="input w-full" placeholder="Largo"/>
                            <input type="number" step="0.1" min="0" v-model.number="form.caja_ancho_cm" class="input w-full" placeholder="Ancho"/>
                            <input type="number" step="0.1" min="0" v-model.number="form.caja_alto_cm" class="input w-full" placeholder="Alto"/>
                        </div>
                    </div>
                </div>

                <button type="button" @click="verParametros = !verParametros" class="btn-ghost text-xs">
                    {{ verParametros ? 'Ocultar' : 'Ver' }} parámetros (vienen de la última orden de importación)
                </button>
                <div v-if="verParametros" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div v-for="[clave, etiqueta, paso] in parametrosVisibles" :key="clave">
                        <label class="text-xs font-semibold">{{ etiqueta }}</label>
                        <input type="number" :step="paso" v-model.number="form[clave]" class="input w-full"/>
                    </div>
                </div>

                <div v-if="error" class="p-2 rounded bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-sm text-red-700 dark:text-red-300">{{ error }}</div>

                <div class="flex justify-end">
                    <button type="submit" :disabled="calculando" class="btn-primary">
                        <Calculator class="h-4 w-4"/> {{ calculando ? 'Calculando…' : 'Calcular' }}
                    </button>
                </div>
            </form>

            <div v-if="resultado" class="card p-5">
                <div class="text-xs text-surface-500 mb-2">CBM de la caja: {{ resultado.cbm_caja }} m³ · por unidad, en COP</div>
                <table class="w-full text-sm">
                    <tbody class="divide-y">
                        <tr v-for="[clave, etiqueta] in filas" :key="clave">
                            <td class="py-1.5 text-surface-600 dark:text-surface-300">{{ etiqueta }}</td>
                            <td class="py-1.5 text-right">{{ money(resultado.unidad[clave]) }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 font-bold">Costo puesto en Colombia</td>
                            <td class="py-2 text-right text-xl font-bold text-brand-600">{{ money(resultado.unidad.costo) }}</td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Precio (costo / {{ form.divisor_precio_1 }})</td>
                            <td class="py-1.5 text-right font-semibold">{{ money(resultado.unidad.precio_1) }}</td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Precio (anterior / {{ form.divisor_precio_2 }})</td>
                            <td class="py-1.5 text-right font-semibold">{{ money(resultado.unidad.precio_2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
