<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Landmark, AlertTriangle, CheckCircle2, FileText, Upload } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasLibro } from '@/composables/usePestanasContables';
import { useMoney } from '@/composables/useMoney';

defineProps({
    conciliaciones: { type: Array, default: () => [] },
});

const { money: fmtCOP } = useMoney();

const form = reactive({
    texto: '',
    banco: 'Bancolombia',
    cuenta_puc: '1110',
    notas: '',
});

const previo = ref(null);
const archivo = ref(null);      // el File que eligió la persona
const arrastrando = ref(false);
const error = ref('');
const revisando = ref(false);
const guardando = ref(false);

/*
 * Revisar no guarda nada.
 *
 * Un extracto mal leído metería cuatrocientas líneas falsas en la
 * contabilidad. Primero se mira, con la comprobación del propio extracto a la
 * vista, y se guarda después.
 */
const revisar = async () => {
    revisando.value = true;
    error.value = '';
    previo.value = null;
    try {
        /*
         * Con archivo va FormData y sin archivo va JSON.
         *
         * No se le pone `Content-Type` al FormData a propósito: el navegador
         * tiene que ponerlo él para meter el `boundary`, y si se lo escribimos
         * nosotros el archivo llega vacío.
         */
        let cuerpo;
        const cabeceras = {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            'Accept': 'application/json',
        };

        if (archivo.value) {
            cuerpo = new FormData();
            cuerpo.append('archivo', archivo.value);
            cuerpo.append('banco', form.banco);
        } else {
            cabeceras['Content-Type'] = 'application/json';
            cuerpo = JSON.stringify({ texto: form.texto, banco: form.banco });
        }

        const r = await fetch('/app/contabilidad/conciliacion-bancaria/previsualizar', {
            method: 'POST',
            headers: cabeceras,
            body: cuerpo,
        });
        const data = await r.json();
        if (! r.ok) {
            error.value = data?.error ?? data?.message ?? 'No se pudo leer el extracto.';
            return;
        }
        previo.value = data;
    } catch (e) {
        error.value = 'No se pudo leer el extracto: ' + e.message;
    } finally {
        revisando.value = false;
    }
};

const guardar = () => {
    guardando.value = true;

    // El servidor vuelve a leer el extracto desde acá: lo que se guarda no es
    // lo que muestra la pantalla, es lo que se lee otra vez del archivo.
    router.post('/app/contabilidad/conciliacion-bancaria',
        { ...form, archivo: archivo.value },
        { forceFormData: !! archivo.value, onFinish: () => { guardando.value = false; } });
};

/** Lo que se eligió con el botón o se soltó encima. */
const tomarArchivo = (f) => {
    if (! f) return;
    archivo.value = f;
    form.texto = '';          // el archivo manda; no dejamos dos fuentes vivas
    previo.value = null;
    error.value = '';
};

const soltar = (e) => {
    arrastrando.value = false;
    tomarArchivo(e.dataTransfer?.files?.[0]);
};

const quitarArchivo = () => {
    archivo.value = null;
    previo.value = null;
};

const pesoArchivo = computed(() => archivo.value
    ? (archivo.value.size / 1024 / 1024).toFixed(2) + ' MB'
    : '');

/** Hay con qué revisar: o un archivo, o texto suficiente. */
const hayQueLeer = computed(() => !! archivo.value || form.texto.length >= 40);

const resumen = computed(() => previo.value?.cruce?.resumen ?? null);
const extracto = computed(() => previo.value?.extracto ?? null);

/** Las que el banco cobró solo y ya traen cuenta sugerida. */
const conCuenta = computed(() =>
    (previo.value?.solo_banco ?? []).filter((l) => l.cuenta_sugerida).length);
</script>

<template>
    <Head title="Conciliación bancaria · Contabilidad"/>
    <AppLayout>
        <PestanasSeccion :pestanas="pestanasLibro"/>

        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Landmark class="h-6 w-6 text-brand-600"/> Conciliación bancaria
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Pegá el extracto tal como lo entrega el banco. Se cruza contra los pagos del ERP y
                    queda en tres montones: lo que <strong>casó</strong>, lo que <strong>sólo está en el
                    banco</strong> —ahí viven el 4x1000 y la cuota de manejo, que son gastos que faltan
                    asentar— y lo que <strong>sólo está en el ERP</strong>.
                </p>
            </div>

            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg text-sm bg-red-500/15 border-l-4 border-red-500 text-red-700">
                {{ $page.props.flash.error }}
            </div>
            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg text-sm bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700">
                {{ $page.props.flash.success }}
            </div>

            <div class="card p-4 space-y-3">
                <div class="flex flex-wrap gap-3">
                    <label class="text-sm">
                        <span class="block text-xs text-surface-500 mb-1">Banco</span>
                        <input v-model="form.banco" type="text"
                               class="px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm"/>
                    </label>
                    <label class="text-sm">
                        <span class="block text-xs text-surface-500 mb-1">Cuenta PUC del banco</span>
                        <input v-model="form.cuenta_puc" type="text"
                               class="w-32 px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm tabular-nums"/>
                    </label>
                </div>

                <!--
                    Subir el archivo es el camino de todos los días; pegar el
                    texto queda como salida para el banco que no da archivo.
                -->
                <div @dragover.prevent="arrastrando = true"
                     @dragleave.prevent="arrastrando = false"
                     @drop.prevent="soltar"
                     :class="['rounded-xl border-2 border-dashed p-5 text-center transition-colors',
                              arrastrando ? 'border-brand-500 bg-brand-500/10'
                                          : 'border-surface-300 dark:border-surface-700']">

                    <div v-if="! archivo">
                        <Upload class="h-7 w-7 mx-auto mb-2 text-surface-400"/>
                        <p class="text-sm font-medium">Arrastrá acá el extracto del banco</p>
                        <p class="text-xs text-surface-500 mt-1">
                            PDF, Excel o CSV, tal como lo baja del portal. Hasta 20&nbsp;MB.
                        </p>
                        <label class="inline-block mt-3 px-3 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium cursor-pointer">
                            Buscar el archivo
                            <input type="file" class="hidden"
                                   accept=".pdf,.xlsx,.xls,.ods,.csv,.txt"
                                   @change="tomarArchivo($event.target.files[0])"/>
                        </label>
                    </div>

                    <div v-else class="flex items-center justify-center gap-3 text-sm">
                        <FileText class="h-5 w-5 text-brand-600 shrink-0"/>
                        <span class="font-medium break-all">{{ archivo.name }}</span>
                        <span class="text-xs text-surface-500 tabular-nums">{{ pesoArchivo }}</span>
                        <button @click="quitarArchivo"
                                class="text-xs text-red-600 hover:underline shrink-0">Quitar</button>
                    </div>
                </div>

                <!--
                    El PDF escaneado no sirve y conviene decirlo ANTES, no
                    después de que suba veinte megas y espere.
                -->
                <p class="text-xs text-surface-500">
                    Si el PDF es escaneado —una foto de las hojas— no se puede leer:
                    hay que bajarlo del portal del banco.
                </p>

                <details class="text-sm" :open="! archivo && form.texto.length > 0">
                    <summary class="cursor-pointer text-xs text-surface-500 hover:text-brand-600">
                        …o pegar el extracto a mano
                    </summary>
                    <label class="block mt-2">
                        <textarea v-model="form.texto" rows="8" spellcheck="false" :disabled="!! archivo"
                                  placeholder="ESTADO DE CUENTA&#10;DESDE: 2026/08/31 HASTA: 2026/09/30&#10;…&#10;1/09 TRANSFERENCIA DESDE NEQUI 355,000.00 227,920,668.05"
                                  class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-xs font-mono disabled:opacity-40"></textarea>
                    </label>
                </details>

                <div class="flex gap-2 items-center">
                    <button @click="revisar" :disabled="revisando || ! hayQueLeer"
                            class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium disabled:opacity-50">
                        {{ revisando ? 'Leyendo…' : 'Revisar' }}
                    </button>
                    <span class="text-xs text-surface-500">Revisar no guarda nada.</span>
                </div>

                <div v-if="error" class="p-3 rounded-lg text-sm bg-red-500/15 border-l-4 border-red-500 text-red-700">
                    {{ error }}
                </div>
            </div>

            <!-- Lo que se leyó, antes de guardar -->
            <div v-if="previo" class="space-y-3">
                <!--
                    La comprobación del propio extracto va primero: antes de
                    buscar diferencias contra el ERP hay que estar seguro de
                    haber leído bien el banco.
                -->
                <div class="p-3 rounded-lg text-sm flex gap-2"
                     :class="extracto.cuadra
                        ? 'bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-800 dark:text-emerald-200'
                        : 'bg-red-500/15 border-l-4 border-red-500 text-red-700'">
                    <component :is="extracto.cuadra ? CheckCircle2 : AlertTriangle" class="h-5 w-5 shrink-0"/>
                    <div v-if="extracto.cuadra">
                        <strong>El extracto cuadra consigo mismo.</strong>
                        {{ fmtCOP(extracto.saldo_anterior) }} + {{ fmtCOP(extracto.total_abonos) }}
                        − {{ fmtCOP(extracto.total_cargos) }} = {{ fmtCOP(extracto.saldo_actual) }}.
                        Período {{ extracto.desde }} a {{ extracto.hasta }}, cuenta {{ extracto.cuenta }}.
                    </div>
                    <div v-else-if="extracto.verificable">
                        <strong>El extracto NO cuadra consigo mismo:</strong>
                        sobran {{ fmtCOP(Math.abs(extracto.descuadre)) }} entre el saldo actual y el que dan
                        los movimientos. Se perdió alguna línea al leer — revisá que lo hayas pegado completo.
                        <span class="block mt-1">No se va a poder guardar así.</span>
                    </div>
                    <div v-else>
                        <strong>No se pudo comprobar el extracto:</strong>
                        falta el encabezado con «SALDO ANTERIOR» y «SALDO ACTUAL», que es con lo que se
                        verifica la lectura. Se puede guardar igual, pero nadie comprobó que esté completo.
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="card p-4 border-l-4 border-emerald-500">
                        <div class="text-xs uppercase text-surface-500">Conciliado</div>
                        <div class="text-2xl font-black tabular-nums text-emerald-600">{{ resumen.conciliados }}</div>
                        <div class="text-xs text-surface-500">{{ fmtCOP(resumen.monto_conciliado) }}</div>
                    </div>
                    <div class="card p-4 border-l-4 border-amber-500">
                        <div class="text-xs uppercase text-surface-500">Sólo en el banco</div>
                        <div class="text-2xl font-black tabular-nums text-amber-600">{{ resumen.solo_banco }}</div>
                        <div class="text-xs text-surface-500">
                            {{ fmtCOP(resumen.monto_solo_banco) }} ·
                            <strong>{{ conCuenta }}</strong> con cuenta sugerida
                        </div>
                    </div>
                    <div class="card p-4 border-l-4 border-red-500">
                        <div class="text-xs uppercase text-surface-500">Sólo en el ERP</div>
                        <div class="text-2xl font-black tabular-nums text-red-600">{{ resumen.solo_erp }}</div>
                        <div class="text-xs text-surface-500">{{ fmtCOP(resumen.monto_solo_erp) }}</div>
                    </div>
                </div>

                <div class="card p-4">
                    <div class="text-xs uppercase tracking-widest font-bold text-amber-600 mb-2">
                        Lo que el banco cobró y nadie registró · primeras 15
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[620px] text-sm">
                            <thead>
                                <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                    <th class="text-left py-2">Fecha</th>
                                    <th class="text-left">Descripción</th>
                                    <th class="text-right">Valor</th>
                                    <th class="text-left pl-3">Cuenta sugerida</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(l, i) in previo.solo_banco.slice(0, 15)" :key="i"
                                    class="border-b border-surface-100 dark:border-surface-800/60">
                                    <td class="py-1.5 text-xs tabular-nums">{{ l.fecha }}</td>
                                    <td class="text-xs">{{ l.descripcion }}</td>
                                    <td class="text-right tabular-nums"
                                        :class="l.valor < 0 ? 'text-red-600' : 'text-emerald-600'">
                                        {{ fmtCOP(l.valor) }}
                                    </td>
                                    <td class="pl-3 text-xs">
                                        <span v-if="l.cuenta_sugerida" class="font-mono text-brand-600">
                                            {{ l.cuenta_sugerida.cuenta }}
                                        </span>
                                        <span v-else class="text-surface-400">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-surface-500 mt-2">
                        Las cuentas salen del mapa de conceptos del banco, configurable en
                        <Link href="/app/reglas" class="text-brand-600 hover:underline">Reglas</Link>.
                    </p>
                </div>

                <div class="card p-4 space-y-3">
                    <label class="block text-sm">
                        <span class="block text-xs text-surface-500 mb-1">Notas (opcional)</span>
                        <input v-model="form.notas" type="text"
                               class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm"/>
                    </label>
                    <button @click="guardar" :disabled="guardando || (extracto.verificable && ! extracto.cuadra)"
                            class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium disabled:opacity-50">
                        {{ guardando ? 'Guardando…' : 'Guardar conciliación' }}
                    </button>
                </div>
            </div>

            <!-- Las que ya están guardadas -->
            <div class="card overflow-hidden">
                <div class="p-3 border-b border-surface-100 dark:border-surface-800 text-xs uppercase font-bold text-brand-600">
                    Conciliaciones guardadas
                </div>
                <div v-if="! conciliaciones.length" class="text-center py-8 text-surface-500 text-sm">
                    Todavía no hay ninguna.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2 px-3">Período</th>
                                <th class="text-left">Banco</th>
                                <th class="text-right">Saldo extracto</th>
                                <th class="text-right">Líneas</th>
                                <th class="text-right">Conciliadas</th>
                                <th class="text-left pl-3">Quién</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in conciliaciones" :key="c.id"
                                class="border-b border-surface-100 dark:border-surface-800/60 hover:bg-surface-50 dark:hover:bg-surface-900/30">
                                <td class="py-2 px-3">
                                    <Link :href="`/app/contabilidad/conciliacion-bancaria/${c.id}`"
                                          class="font-medium hover:text-brand-600 flex items-center gap-1">
                                        <FileText class="h-3.5 w-3.5"/> {{ c.desde }} → {{ c.hasta }}
                                    </Link>
                                </td>
                                <td class="text-surface-500 text-xs">
                                    {{ c.banco }}<span v-if="c.cuenta"> · {{ c.cuenta }}</span>
                                </td>
                                <td class="text-right tabular-nums">{{ fmtCOP(c.saldo_extracto) }}</td>
                                <td class="text-right tabular-nums text-surface-500">{{ c.lineas }}</td>
                                <td class="text-right tabular-nums font-semibold text-emerald-600">{{ c.conciliadas }}</td>
                                <td class="pl-3 text-xs text-surface-500">{{ c.quien }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
