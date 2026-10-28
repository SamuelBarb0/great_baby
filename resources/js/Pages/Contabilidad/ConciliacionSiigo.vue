<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasSiigo } from '@/composables/usePestanasContables';
import { ArrowLeft, AlertTriangle, CheckCircle2, Scale, CloudOff, Cloud, TrendingUp } from 'lucide-vue-next';

const props = defineProps({
    resultado: Object,
    error: String,
    filtros: Object,
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);

const recargar = () => router.get('/app/contabilidad/siigo/conciliacion',
    { desde: desde.value, hasta: hasta.value }, { preserveState: true });
</script>

<template>
    <Head title="Conciliación ERP ↔ SIIGO"/>
    <AppLayout>
        <div class="space-y-5 max-w-6xl mx-auto">
            <PestanasSeccion :pestanas="pestanasSiigo"/>


            <Link href="/app/contabilidad/reportes" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4"/> Volver a reportes
            </Link>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Scale class="h-6 w-6 text-brand-600"/>
                    Conciliación de facturas · ERP ↔ SIIGO
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Esta pantalla le pregunta a SIIGO. A diferencia de «pendientes de SIIGO»,
                    detecta facturas con <b>importes distintos</b> en cada lado y facturas
                    emitidas <b>por fuera del ERP</b>.
                </p>
            </div>

            <!-- Filtro -->
            <div class="card p-4 flex items-end gap-3 flex-wrap">
                <div>
                    <label class="block text-xs font-semibold text-surface-500 mb-1">Desde</label>
                    <input type="date" v-model="desde" class="input"/>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-surface-500 mb-1">Hasta</label>
                    <input type="date" v-model="hasta" class="input"/>
                </div>
                <button @click="recargar" class="btn-primary">Conciliar</button>
            </div>

            <div v-if="error" class="card p-4 bg-red-50 border-l-4 border-red-500 text-red-900 flex items-start gap-2">
                <AlertTriangle class="h-5 w-5 shrink-0 mt-0.5"/>
                <div>
                    <div class="font-semibold">No se pudo consultar SIIGO</div>
                    <div class="text-sm">{{ error }}</div>
                </div>
            </div>

            <template v-else-if="resultado">
                <!-- Resumen -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="card p-4">
                        <div class="text-xs uppercase font-semibold text-surface-500">Cuadran</div>
                        <div class="text-2xl font-black text-emerald-700 flex items-center gap-1">
                            <CheckCircle2 class="h-5 w-5"/> {{ resultado.resumen.coinciden }}
                        </div>
                    </div>
                    <div class="card p-4" :class="{ 'ring-2 ring-red-400': resultado.resumen.descuadradas }">
                        <div class="text-xs uppercase font-semibold text-surface-500">Importes distintos</div>
                        <div class="text-2xl font-black" :class="resultado.resumen.descuadradas ? 'text-red-700' : 'text-surface-400'">
                            {{ resultado.resumen.descuadradas }}
                        </div>
                    </div>
                    <div class="card p-4" :class="{ 'ring-2 ring-amber-400': resultado.resumen.sin_enviar }">
                        <div class="text-xs uppercase font-semibold text-surface-500">Sin enviar</div>
                        <div class="text-2xl font-black" :class="resultado.resumen.sin_enviar ? 'text-amber-700' : 'text-surface-400'">
                            {{ resultado.resumen.sin_enviar }}
                        </div>
                    </div>
                    <div class="card p-4">
                        <div class="text-xs uppercase font-semibold text-surface-500">Sólo en SIIGO</div>
                        <div class="text-2xl font-black text-indigo-700">{{ resultado.resumen.solo_siigo }}</div>
                    </div>
                </div>

                <div class="text-xs text-surface-500">
                    {{ resultado.resumen.erp }} facturas electrónicas en el ERP ·
                    {{ resultado.resumen.siigo }} en SIIGO con nuestra resolución ·
                    periodo {{ resultado.periodo.desde }} a {{ resultado.periodo.hasta }}
                </div>

                <!-- Descuadradas · lo más grave -->
                <div v-if="resultado.descuadradas.length" class="card overflow-hidden border-l-4 border-red-500">
                    <div class="px-4 py-3 border-b bg-red-50">
                        <h3 class="font-bold text-red-900 flex items-center gap-2">
                            <AlertTriangle class="h-5 w-5"/>
                            Importes distintos · {{ resultado.descuadradas.length }}
                        </h3>
                        <p class="text-xs text-red-800 mt-1">
                            La misma factura tiene un total en el ERP y otro en SIIGO. Hay que corregirlo antes de declarar.
                        </p>
                    </div>
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50">
                            <tr>
                                <th class="p-3 text-left">Factura ERP</th>
                                <th class="p-3 text-left">Nº SIIGO</th>
                                <th class="p-3 text-right">Total ERP</th>
                                <th class="p-3 text-right">Total SIIGO</th>
                                <th class="p-3 text-right">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(f, i) in resultado.descuadradas" :key="i">
                                <td class="p-3 font-mono text-xs font-bold">{{ f.numero }}</td>
                                <td class="p-3 font-mono text-xs">{{ f.numero_siigo }}</td>
                                <td class="p-3 text-right">{{ money(f.total_erp) }}</td>
                                <td class="p-3 text-right">{{ money(f.total_siigo) }}</td>
                                <td class="p-3 text-right font-bold text-red-700">{{ money(f.diferencia) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Sin enviar -->
                <div v-if="resultado.sin_enviar.length" class="card overflow-hidden border-l-4 border-amber-500">
                    <div class="px-4 py-3 border-b bg-amber-50">
                        <h3 class="font-bold text-amber-900 flex items-center gap-2">
                            <CloudOff class="h-5 w-5"/>
                            No llegaron a SIIGO · {{ resultado.sin_enviar.length }}
                        </h3>
                    </div>
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50">
                            <tr>
                                <th class="p-3 text-left">Factura</th><th class="p-3 text-left">Fecha</th>
                                <th class="p-3 text-left">Estado</th><th class="p-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(f, i) in resultado.sin_enviar" :key="i">
                                <td class="p-3 font-mono text-xs font-bold">{{ f.numero }}</td>
                                <td class="p-3 text-xs">{{ f.fecha }}</td>
                                <td class="p-3 text-xs">{{ f.estado }}</td>
                                <td class="p-3 text-right">{{ money(f.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Ausentes en SIIGO -->
                <div v-if="resultado.ausentes_en_siigo.length" class="card overflow-hidden border-l-4 border-red-500">
                    <div class="px-4 py-3 border-b bg-red-50">
                        <h3 class="font-bold text-red-900 flex items-center gap-2">
                            <AlertTriangle class="h-5 w-5"/>
                            El ERP las da por emitidas pero SIIGO no las tiene · {{ resultado.ausentes_en_siigo.length }}
                        </h3>
                        <p class="text-xs text-red-800 mt-1">Puede que se hayan anulado desde el portal de SIIGO.</p>
                    </div>
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50">
                            <tr><th class="p-3 text-left">Factura</th><th class="p-3 text-left">Nº SIIGO</th><th class="p-3 text-right">Total</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(f, i) in resultado.ausentes_en_siigo" :key="i">
                                <td class="p-3 font-mono text-xs font-bold">{{ f.numero }}</td>
                                <td class="p-3 font-mono text-xs">{{ f.numero_siigo || '—' }}</td>
                                <td class="p-3 text-right">{{ money(f.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Solo SIIGO -->
                <div v-if="resultado.solo_siigo.length" class="card overflow-hidden">
                    <div class="px-4 py-3 border-b">
                        <h3 class="font-bold flex items-center gap-2">
                            <Cloud class="h-5 w-5 text-indigo-600"/>
                            Emitidas en SIIGO sin pasar por el ERP · {{ resultado.solo_siigo.length }}
                        </h3>
                        <p class="text-xs text-surface-500 mt-1">
                            En producción esto significa que alguien facturó directo en el portal.
                            En el ambiente de pruebas de SIIGO es normal: la resolución se comparte con otros usuarios.
                        </p>
                    </div>
                    <table v-tabla-movil class="w-full text-sm">
                        <thead class="text-xs uppercase text-surface-500 bg-surface-50">
                            <tr>
                                <th class="p-3 text-left">Nº SIIGO</th><th class="p-3 text-left">Fecha</th>
                                <th class="p-3 text-left">Cliente</th><th class="p-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(f, i) in resultado.solo_siigo" :key="i">
                                <td class="p-3 font-mono text-xs font-bold">{{ f.numero_siigo }}</td>
                                <td class="p-3 text-xs">{{ f.fecha }}</td>
                                <td class="p-3 text-xs">{{ f.cliente }}</td>
                                <td class="p-3 text-right">{{ money(f.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!resultado.descuadradas.length && !resultado.sin_enviar.length && !resultado.ausentes_en_siigo.length"
                     class="card p-6 text-center bg-emerald-50 border-l-4 border-emerald-500">
                    <CheckCircle2 class="h-8 w-8 mx-auto mb-2 text-emerald-600"/>
                    <div class="font-bold text-emerald-900">Todo cuadra con SIIGO en este periodo</div>
                    <p class="text-sm text-emerald-800 mt-1">
                        Las {{ resultado.resumen.coinciden }} facturas del ERP están en SIIGO con el mismo importe.
                    </p>
                </div>
            </template>

        </div>
    </AppLayout>
</template>
