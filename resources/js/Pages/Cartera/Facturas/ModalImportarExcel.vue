<script setup>
/*
 * F2b · Subir facturas de venta desde el Excel del cliente.
 *
 * Dos pasos, y el primero no toca la base: se sube el archivo, se MUESTRA lo
 * que se crearía, y recién entonces aparece el botón que crea. Importar 300
 * facturas de un clic sin verlas es la clase de cosa que se descubre tres
 * semanas después, cuando el balance no cuadra y nadie se acuerda del archivo.
 *
 * Una fila es un ÍTEM. Las filas que comparten la columna «factura» se agrupan
 * en una sola factura con varios ítems.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Upload, Download, X, FileSpreadsheet, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import { useMoney } from '@/composables/useMoney';

const emit = defineEmits(['cerrar']);
const { money } = useMoney();

const archivo = ref(null);
const nombreArchivo = ref('');
const previa = ref(null);
const analizando = ref(false);
const importando = ref(false);
const errorServidor = ref('');
const verSoloMalas = ref(false);

const elegirArchivo = (e) => {
    const f = e.target.files?.[0] ?? null;
    archivo.value = f;
    nombreArchivo.value = f?.name ?? '';
    previa.value = null;
    errorServidor.value = '';
    if (f) analizar();
};

const analizar = async () => {
    if (! archivo.value || analizando.value) return;
    analizando.value = true;
    errorServidor.value = '';
    try {
        const fd = new FormData();
        fd.append('archivo', archivo.value);
        const { data } = await window.axios.post('/app/facturas/importar-excel/previa', fd);
        previa.value = data;
    } catch (e) {
        errorServidor.value = e?.response?.data?.message
            ?? 'No se pudo leer el archivo. Tiene que ser .csv o .xlsx y pesar menos de 8 MB.';
    } finally {
        analizando.value = false;
    }
};

const importar = () => {
    if (! archivo.value || importando.value || ! (previa.value?.resumen?.listas > 0)) return;
    importando.value = true;
    router.post('/app/facturas/importar-excel', { archivo: archivo.value }, {
        forceFormData: true,
        onFinish: () => { importando.value = false; },
        onSuccess: () => emit('cerrar'),
        onError: (err) => { errorServidor.value = Object.values(err)[0] ?? 'No se pudo importar.'; },
    });
};

const visibles = computed(() => {
    const todas = previa.value?.facturas ?? [];
    return verSoloMalas.value ? todas.filter((f) => f.errores.length) : todas;
});

const hayListas = computed(() => (previa.value?.resumen?.listas ?? 0) > 0);
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 overflow-y-auto"
         @click.self="emit('cerrar')">
        <div class="card p-5 w-full max-w-5xl space-y-4 my-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="font-bold text-lg">Subir facturas desde Excel</h3>
                    <p class="text-xs text-surface-500 mt-0.5">
                        Cada fila es un ítem. Las filas con la misma columna «factura» se agrupan
                        en una sola factura. Nada se crea hasta que confirmés.
                    </p>
                </div>
                <button @click="emit('cerrar')" class="text-surface-400 hover:text-surface-700" aria-label="Cerrar">
                    <X class="h-5 w-5"/>
                </button>
            </div>

            <!-- Paso 1 · el archivo -->
            <div class="flex flex-wrap items-center gap-3">
                <a href="/app/facturas/plantilla-excel"
                   class="btn-secondary text-sm inline-flex items-center gap-1">
                    <Download class="h-4 w-4"/> Descargar plantilla
                </a>
                <label class="btn-primary text-sm inline-flex items-center gap-1 cursor-pointer">
                    <Upload class="h-4 w-4"/>
                    {{ nombreArchivo || 'Elegir archivo' }}
                    <input type="file" accept=".csv,.txt,.xlsx" class="hidden" @change="elegirArchivo"/>
                </label>
                <span v-if="analizando" class="text-sm text-surface-500">Leyendo el archivo…</span>
            </div>

            <p v-if="errorServidor" class="text-sm text-red-600 font-semibold">{{ errorServidor }}</p>

            <!-- Errores que impiden leer el archivo -->
            <div v-if="previa && previa.errores.length && ! previa.facturas.length"
                 class="text-sm bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 p-3 space-y-1">
                <div v-for="(e, k) in previa.errores" :key="k">{{ e }}</div>
            </div>

            <!-- Paso 2 · lo que se crearía -->
            <template v-if="previa && previa.facturas.length">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="card p-3">
                        <div class="text-xs text-surface-500">Facturas en el archivo</div>
                        <div class="text-xl font-bold">{{ previa.resumen.facturas }}</div>
                    </div>
                    <div class="card p-3">
                        <div class="text-xs text-surface-500">Listas para crear</div>
                        <div class="text-xl font-bold text-emerald-600">{{ previa.resumen.listas }}</div>
                    </div>
                    <div class="card p-3">
                        <div class="text-xs text-surface-500">Con errores</div>
                        <div class="text-xl font-bold" :class="previa.resumen.con_error ? 'text-red-600' : ''">
                            {{ previa.resumen.con_error }}
                        </div>
                    </div>
                    <div class="card p-3">
                        <div class="text-xs text-surface-500">Valor de las listas</div>
                        <div class="text-xl font-bold tabular-nums">{{ money(previa.resumen.total_listas) }}</div>
                    </div>
                </div>

                <div v-if="previa.errores.length"
                     class="text-xs bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 p-3 space-y-1">
                    <div v-for="(e, k) in previa.errores" :key="k">{{ e }}</div>
                </div>

                <label v-if="previa.resumen.con_error" class="flex items-center gap-2 text-sm">
                    <input type="checkbox" v-model="verSoloMalas"/>
                    Mostrar sólo las que tienen errores
                </label>

                <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900 text-surface-500 text-xs uppercase sticky top-0">
                            <tr>
                                <th class="w-8 px-2 py-2"></th>
                                <th class="text-left px-2">Factura</th>
                                <th class="text-left">Cliente</th>
                                <th class="text-right">Emisión</th>
                                <th class="text-center">Destino</th>
                                <th class="text-center">Línea</th>
                                <th class="text-center">Ítems</th>
                                <th class="text-right px-2">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="f in visibles" :key="f.clave">
                                <tr class="border-t border-surface-100 dark:border-surface-900"
                                    :class="f.errores.length ? 'bg-red-50/60 dark:bg-red-900/10' : ''">
                                    <td class="px-2 py-2 text-center">
                                        <AlertTriangle v-if="f.errores.length" class="h-4 w-4 text-red-600 inline"/>
                                        <CheckCircle2 v-else class="h-4 w-4 text-emerald-600 inline"/>
                                    </td>
                                    <td class="px-2 font-mono font-semibold">{{ f.clave }}</td>
                                    <td>{{ f.cliente }}</td>
                                    <td class="text-right text-surface-500">{{ f.fecha_emision || '—' }}</td>
                                    <td class="text-center text-xs font-bold">
                                        {{ f.destino_label }}
                                        <div v-if="f.monto_fiscal" class="text-[10px] font-normal text-surface-500">
                                            declara {{ money(f.monto_fiscal) }}
                                        </div>
                                    </td>
                                    <td class="text-center text-xs">{{ f.linea_label }}</td>
                                    <td class="text-center tabular-nums">{{ f.cantidad_items }}</td>
                                    <td class="text-right px-2 font-bold tabular-nums">{{ money(f.total) }}</td>
                                </tr>
                                <tr v-if="f.errores.length || f.avisos.length"
                                    :class="f.errores.length ? 'bg-red-50/60 dark:bg-red-900/10' : ''">
                                    <td></td>
                                    <td colspan="7" class="px-2 pb-2 text-xs space-y-0.5">
                                        <div v-for="(e, k) in f.errores" :key="'e' + k" class="text-red-700 dark:text-red-300">
                                            · {{ e }}
                                        </div>
                                        <div v-for="(a, k) in f.avisos" :key="'a' + k" class="text-amber-700 dark:text-amber-300">
                                            · {{ a }}
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p v-if="previa.resumen.con_error" class="text-xs text-surface-500">
                    Las facturas con errores se saltan ENTERAS, nunca a medias. Corregí el archivo
                    y volvé a subirlo: lo que ya se creó no se duplica porque cada factura recibe
                    su propio consecutivo.
                </p>
            </template>

            <div v-else-if="! previa && ! analizando" class="text-center py-10 text-surface-500">
                <FileSpreadsheet class="h-10 w-10 mx-auto opacity-40"/>
                <p class="text-sm mt-2">Descargá la plantilla, llenala y subila acá.</p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-1">
                <button @click="emit('cerrar')" class="btn-secondary text-sm">Cancelar</button>
                <button @click="importar" :disabled="importando || ! hayListas"
                        class="btn-primary text-sm disabled:opacity-40">
                    {{ importando ? 'Creando…' : `Crear ${previa?.resumen?.listas ?? 0} factura(s)` }}
                </button>
            </div>
        </div>
    </div>
</template>
