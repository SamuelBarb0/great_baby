<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { CreditCard, MessageCircle, ShieldAlert, Check, Clock } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasCobranza } from '@/composables/usePestanasContables';
const props = defineProps({
    tab: { type: String, default: 'condiciones' },
    condiciones: { type: Array, required: true },
    cobranzas: { type: Array, required: true },
    excepciones: { type: Array, required: true },
});

const tabAct = ref(props.tab);
const { money: fmtCOP } = useMoney();

const badgeEstado = (e) => {
    const map = {
        aprobada: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        rechazada: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
        pendiente: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        escalada: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        enviado: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        fallido: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    };
    return map[e] || 'bg-slate-100 text-slate-700';
};
</script>

<template>
    <Head title="Crédito y cobranza"/>
    <AppLayout>
        <div class="space-y-4">
            <PestanasSeccion :pestanas="pestanasCobranza"/>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <CreditCard class="h-6 w-6 text-brand-600"/>
                    Crédito y cobranza
                </h1>
                <p class="text-sm text-surface-500 mt-1">Condiciones de crédito por cliente, bitácora de gestión de cobros y workflow de excepciones.</p>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-2 border-b border-surface-200 dark:border-surface-800">
                <button @click="tabAct = 'condiciones'" :class="['px-4 py-2 text-sm font-semibold border-b-2 flex items-center gap-2', tabAct === 'condiciones' ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500']">
                    <CreditCard class="h-4 w-4"/> Condiciones ({{ condiciones.length }})
                </button>
                <button @click="tabAct = 'cobranzas'" :class="['px-4 py-2 text-sm font-semibold border-b-2 flex items-center gap-2', tabAct === 'cobranzas' ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500']">
                    <MessageCircle class="h-4 w-4"/> Cobranzas enviadas ({{ cobranzas.length }})
                </button>
                <button @click="tabAct = 'excepciones'" :class="['px-4 py-2 text-sm font-semibold border-b-2 flex items-center gap-2', tabAct === 'excepciones' ? 'border-brand-500 text-brand-600' : 'border-transparent text-surface-500']">
                    <ShieldAlert class="h-4 w-4"/> Excepciones ({{ excepciones.length }})
                </button>
            </div>

            <!-- Condiciones -->
            <div v-if="tabAct === 'condiciones'" class="card overflow-hidden">
                <div v-if="! condiciones.length" class="text-center py-12 text-surface-500">
                    <CreditCard class="h-10 w-10 mx-auto opacity-40"/>
                    <div class="text-sm mt-2">Sin condiciones registradas.</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[800px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900">
                            <tr class="text-surface-500 text-xs uppercase">
                                <th class="text-left px-4 py-2">Cliente</th>
                                <th class="text-right">Cupo</th>
                                <th class="text-right">Plazo</th>
                                <th class="text-right">Dto PP</th>
                                <th class="text-center">Flete</th>
                                <th class="text-center">Estado</th>
                                <th class="text-left">Vigencia</th>
                                <th class="text-left">Aprobó</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in condiciones" :key="c.id" class="border-t border-surface-100 dark:border-surface-900">
                                <td class="px-4 py-2">
                                    <Link :href="'/app/contactos/' + c.contacto_id" class="font-medium text-brand-600 hover:underline">
                                        {{ c.contacto }}
                                    </Link>
                                </td>
                                <td class="text-right font-mono font-bold">{{ fmtCOP(c.cupo) }}</td>
                                <td class="text-right">{{ c.plazo_dias }}d</td>
                                <td class="text-right">
                                    <span v-if="c.descuento_pronto_pago_pct > 0">{{ c.descuento_pronto_pago_pct }}% en {{ c.plazo_pronto_pago_dias }}d</span>
                                    <span v-else class="text-surface-400">—</span>
                                </td>
                                <td class="text-center">
                                    <Check v-if="c.flete_asumido_gb" class="h-4 w-4 text-emerald-600 inline"/>
                                    <span v-else class="text-surface-400">—</span>
                                </td>
                                <td class="text-center">
                                    <span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold', c.activa ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-slate-100 text-slate-700']">
                                        {{ c.activa ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="text-xs text-surface-500">
                                    <div>{{ c.vigente_desde || '—' }}</div>
                                    <div v-if="c.vigente_hasta">→ {{ c.vigente_hasta }}</div>
                                </td>
                                <td class="text-xs">{{ c.aprobada_por || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cobranzas -->
            <div v-if="tabAct === 'cobranzas'" class="card overflow-hidden">
                <div v-if="! cobranzas.length" class="text-center py-12 text-surface-500">
                    <MessageCircle class="h-10 w-10 mx-auto opacity-40"/>
                    <div class="text-sm mt-2">Sin cobranzas enviadas.</div>
                    <div class="text-xs mt-1">El schedule diario 9:00 AM las genera automáticamente.</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[800px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900">
                            <tr class="text-surface-500 text-xs uppercase">
                                <th class="text-left px-4 py-2">Enviado</th>
                                <th class="text-left">Cliente</th>
                                <th class="text-left">Factura</th>
                                <th class="text-left">Canal</th>
                                <th class="text-left">Tramo</th>
                                <th class="text-center">Estado</th>
                                <th class="text-left">Gestor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in cobranzas" :key="c.id" class="border-t border-surface-100 dark:border-surface-900">
                                <td class="px-4 py-2 text-xs text-surface-500">{{ c.enviado_hace }}</td>
                                <td>{{ c.contacto }}</td>
                                <td>
                                    <Link v-if="c.factura_id" :href="'/app/facturas/' + c.factura_id" class="font-mono text-brand-600 hover:underline">{{ c.factura_numero }}</Link>
                                </td>
                                <td class="capitalize"><span class="uppercase text-xs font-semibold">{{ c.canal }}</span></td>
                                <td class="text-xs">{{ c.tramo }}</td>
                                <td class="text-center"><span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold', badgeEstado(c.estado)]">{{ c.estado }}</span></td>
                                <td class="text-xs">{{ c.gestor || 'Cron' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Excepciones -->
            <div v-if="tabAct === 'excepciones'" class="card overflow-hidden">
                <div v-if="! excepciones.length" class="text-center py-12 text-surface-500">
                    <ShieldAlert class="h-10 w-10 mx-auto opacity-40"/>
                    <div class="text-sm mt-2">Sin solicitudes de excepción de crédito.</div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table v-tabla-movil class="w-full min-w-[900px] text-sm">
                        <thead class="bg-surface-50 dark:bg-surface-900">
                            <tr class="text-surface-500 text-xs uppercase">
                                <th class="text-left px-4 py-2">Creada</th>
                                <th class="text-left">Cliente</th>
                                <th class="text-right">Monto pedido</th>
                                <th class="text-left">Motivo</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Nivel</th>
                                <th class="text-left">Solicitó</th>
                                <th class="text-left">Resolvió</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in excepciones" :key="s.id" class="border-t border-surface-100 dark:border-surface-900">
                                <td class="px-4 py-2 text-xs text-surface-500">{{ s.creada }}</td>
                                <td>{{ s.contacto }}</td>
                                <td class="text-right font-bold font-mono">{{ fmtCOP(s.monto_pedido) }}</td>
                                <td class="text-xs text-surface-600 dark:text-surface-400">{{ s.motivo_retencion }}</td>
                                <td class="text-center"><span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold', badgeEstado(s.estado)]">{{ s.estado }}</span></td>
                                <td class="text-center text-xs">{{ s.nivel_actual }}</td>
                                <td class="text-xs">{{ s.solicitante || '—' }}</td>
                                <td class="text-xs">
                                    <span v-if="s.resolutor">{{ s.resolutor }}</span>
                                    <span v-else class="text-amber-600 flex items-center gap-1"><Clock class="h-3 w-3"/> pendiente</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
