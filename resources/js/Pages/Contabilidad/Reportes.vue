<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { BarChart3, FileText, Clock, Cloud, CloudOff, Download } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasReportes } from '@/composables/usePestanasContables';
const props = defineProps({
    reportes: { type: Array, required: true },
    reportes_siigo: { type: Array, default: () => [] },
    siigo_estado: { type: Object, default: null },
});

// El badge SIIGO ya no es un sí/no fijo: muestra cuántos de los documentos que
// alimentan el reporte llegaron de verdad a SIIGO.
const etiquetaSiigo = (s) => s.pct === null ? 'SIIGO · sin datos' : `SIIGO ${s.pct}%`;

const colorSiigo = (s) => {
    if (s.pct === null) return 'bg-surface-200 dark:bg-surface-800 text-surface-600 dark:text-surface-400';
    if (s.pct === 100) return 'bg-emerald-100 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300';
    if (s.pct >= 50) return 'bg-amber-100 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300';
    return 'bg-red-100 dark:bg-red-950/40 text-red-700 dark:text-red-300';
};

const tituloSiigo = (s) => s.pct === null
    ? 'Todavía no hay documentos de esta fuente para comparar.'
    : `${s.en_siigo} de ${s.total} documentos están en SIIGO · ${s.pendientes} sin enviar.`;

// Agrupar por familia.
const familias = computed(() => {
    const g = {};
    props.reportes.forEach((r) => {
        const fam = r.familia || 'Otros';
        (g[fam] ??= []).push(r);
    });
    return g;
});

const totalListos = computed(() => props.reportes.filter(r => r.listo).length);

const generando = ref(false);
const pedirBalance = () => {
    generando.value = true;
    router.post('/app/contabilidad/siigo/balance-prueba', {}, {
        preserveScroll: true,
        onFinish: () => { generando.value = false; },
    });
};
</script>

<template>
    <Head title="Reportes contables · SIIGO"/>
    <AppLayout>
        <div class="space-y-6 max-w-5xl">
            <PestanasSeccion :pestanas="pestanasReportes"/>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <BarChart3 class="h-6 w-6 text-brand-600"/>
                    Reportes contables
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    {{ totalListos }} de {{ reportes.length }} reportes listos · todos con cuentas PUC Great Baby.
                </p>
            </div>

            <!-- Sprint 3 · F.4 · estado SIIGO global visible en el hub -->
            <div v-if="siigo_estado" class="card p-4 flex items-center justify-between gap-4 flex-wrap"
                 :class="siigo_estado.push_activo ? 'border-l-4 border-emerald-500 bg-emerald-50/40 dark:bg-emerald-950/20' : 'border-l-4 border-amber-500 bg-amber-50/40 dark:bg-amber-950/20'">
                <div class="flex items-center gap-3">
                    <Cloud v-if="siigo_estado.push_activo" class="h-6 w-6 text-emerald-600"/>
                    <CloudOff v-else class="h-6 w-6 text-amber-600"/>
                    <div>
                        <div class="font-bold" :class="siigo_estado.push_activo ? 'text-emerald-700' : 'text-amber-700'">
                            {{ siigo_estado.push_activo ? 'Sync SIIGO activo' : 'Sync SIIGO pausado' }}
                            <span class="text-xs uppercase font-normal ml-2 px-2 py-0.5 rounded bg-surface-200 dark:bg-surface-800">{{ siigo_estado.ambiente }}</span>
                        </div>
                        <div class="text-xs text-surface-500">
                            Última sync productos: {{ siigo_estado.ultima_sync_productos || 'nunca' }}
                        </div>
                    </div>
                </div>
                <Link href="/app/siigo" class="btn-ghost text-sm">Panel SIIGO →</Link>
            </div>

            <div v-for="(items, fam) in familias" :key="fam" class="space-y-2">
                <h2 class="text-xs uppercase tracking-widest font-bold text-surface-500">{{ fam }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <template v-for="(r) in items" :key="(r.href || r.nombre)">
                        <Link v-if="r.listo && r.href" :href="r.href"
                            class="card p-5 hover:shadow-lg hover:-translate-y-0.5 transition-all relative">
                            <FileText class="h-8 w-8 text-brand-600 mb-2"/>
                            <div class="font-bold">{{ r.nombre }}</div>
                            <p class="text-xs text-surface-500 mt-1">{{ r.desc }}</p>
                            <!-- F.4 · badge de export y compat SIIGO -->
                            <div class="mt-3 flex items-center gap-2 flex-wrap">
                                <span v-if="r.export" class="inline-flex items-center gap-1 text-[10px] font-semibold bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded">
                                    <Download class="h-3 w-3"/> {{ r.export }}
                                </span>
                                <span v-if="r.siigo" :class="['inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded', colorSiigo(r.siigo)]"
                                      :title="tituloSiigo(r.siigo)">
                                    <Cloud class="h-3 w-3"/> {{ etiquetaSiigo(r.siigo) }}
                                </span>
                            </div>
                        </Link>
                        <div v-else class="card p-5 opacity-50 cursor-not-allowed"
                             role="button" aria-disabled="true"
                             title="Aún en desarrollo — no depende de permisos.">
                            <Clock class="h-8 w-8 text-surface-400 mb-2"/>
                            <div class="font-bold text-surface-500">{{ r.nombre }}</div>
                            <p class="text-xs text-surface-500 mt-1">{{ r.desc }}</p>
                            <span class="mt-2 inline-block text-[10px] font-bold uppercase bg-surface-200 dark:bg-surface-800 text-surface-600 dark:text-surface-400 px-2 py-0.5 rounded">Próximamente</span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Reportes que genera SIIGO, no el ERP -->
            <div v-if="reportes_siigo.length">
                <h2 class="text-sm font-bold uppercase text-surface-500 mb-3 flex items-center gap-2">
                    <Cloud class="h-4 w-4 text-brand-600"/>
                    Directo de SIIGO
                </h2>
                <p class="text-xs text-surface-500 mb-3">
                    Estos no los arma el ERP: los entrega la contabilidad de SIIGO. Son la
                    referencia para contrastar los reportes de arriba.
                </p>

                <div v-if="$page.props.flash?.siigo_reporte_url"
                     class="card p-4 mb-3 bg-emerald-50 border-l-4 border-emerald-500">
                    <div class="font-semibold text-emerald-900 text-sm">SIIGO generó el balance</div>
                    <a :href="$page.props.flash.siigo_reporte_url" target="_blank" rel="noopener"
                       class="text-sm text-brand-700 underline break-all">Descargar el Excel</a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="card p-5">
                        <Cloud class="h-8 w-8 text-brand-600 mb-2"/>
                        <div class="font-bold">Balance de prueba (oficial SIIGO)</div>
                        <p class="text-xs text-surface-500 mt-1">
                            SIIGO arma el Excel con el balance real del año y devuelve el enlace.
                        </p>
                        <button @click="pedirBalance" :disabled="generando"
                                class="btn-primary mt-3 text-sm"
                                :class="{ 'opacity-50 cursor-not-allowed': generando }">
                            <Download class="h-4 w-4"/>
                            {{ generando ? 'Generando en SIIGO…' : 'Generar balance' }}
                        </button>
                    </div>

                    <Link href="/app/contabilidad/siigo/cuentas-por-pagar"
                          class="card p-5 hover:shadow-lg hover:-translate-y-0.5 transition-all">
                        <Cloud class="h-8 w-8 text-brand-600 mb-2"/>
                        <div class="font-bold">Cuentas por pagar (SIIGO)</div>
                        <p class="text-xs text-surface-500 mt-1">
                            Saldos vigentes con proveedores según SIIGO, con vencimiento y documento.
                        </p>
                    </Link>

                    <Link href="/app/contabilidad/siigo/conciliacion"
                          class="card p-5 hover:shadow-lg hover:-translate-y-0.5 transition-all md:col-span-2">
                        <Cloud class="h-8 w-8 text-brand-600 mb-2"/>
                        <div class="font-bold">Conciliación de facturas ERP ↔ SIIGO</div>
                        <p class="text-xs text-surface-500 mt-1">
                            Compara factura por factura contra SIIGO: detecta importes distintos,
                            facturas que no llegaron y facturas emitidas por fuera del ERP.
                        </p>
                    </Link>
                </div>
            </div>

            <!-- Info footer sobre formato SIIGO -->
            <div class="card p-4 text-xs text-surface-500">
                <div class="font-semibold text-surface-700 dark:text-surface-300 mb-2">Cómo leer el badge SIIGO</div>
                <ul class="space-y-1 list-disc list-inside">
                    <li>El porcentaje es real: cuántos de los documentos que alimentan ese reporte tienen ya su identificador de SIIGO. Pasá el mouse por encima para ver el detalle.</li>
                    <li><b>SIIGO 100%</b> significa que todo lo que ves en el reporte está también en SIIGO. Menos de 100% significa que hay documentos sin enviar y las cifras pueden no coincidir.</li>
                    <li>Para ver qué quedó pendiente, entrá a <Link href="/app/contabilidad/pendientes-siigo" class="text-brand-600 underline">pendientes de SIIGO</Link> o al <Link href="/app/siigo" class="text-brand-600 underline">panel SIIGO</Link>.</li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
