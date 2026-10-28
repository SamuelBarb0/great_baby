<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasSiigo } from '@/composables/usePestanasContables';
import { ArrowLeft, AlertTriangle, Landmark, CalendarClock } from 'lucide-vue-next';

const props = defineProps({
    filas: Array,
    total: Number,
    error: String,
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);

const hoy = new Date().toISOString().slice(0, 10);
const vencida = (f) => f && f < hoy;
</script>

<template>
    <Head title="Cuentas por pagar · SIIGO"/>
    <AppLayout>
        <div class="space-y-5 max-w-6xl mx-auto">
            <PestanasSeccion :pestanas="pestanasSiigo"/>


            <Link href="/app/contabilidad/reportes" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4"/> Volver a reportes
            </Link>

            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Landmark class="h-6 w-6 text-brand-600"/>
                    Cuentas por pagar
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Estos saldos los reporta <b>SIIGO</b>, no el ERP. Sirven para contrastar
                    contra lo que muestra el módulo de compras.
                </p>
            </div>

            <div v-if="error" class="card p-4 bg-red-50 border-l-4 border-red-500 text-red-900 flex items-start gap-2">
                <AlertTriangle class="h-5 w-5 shrink-0 mt-0.5"/>
                <div>
                    <div class="font-semibold">No se pudo consultar SIIGO</div>
                    <div class="text-sm">{{ error }}</div>
                </div>
            </div>

            <template v-else>
                <!--
                    El mismo aviso que ya trae la pantalla de conciliación. Sin él,
                    acá se lee «SALDO TOTAL POR PAGAR SEGÚN SIIGO $386.974.114» en
                    letra grande y cualquiera entiende que la empresa debe eso.
                    En el sandbox de SIIGO la cuenta es compartida y esos saldos son
                    de otras empresas: el 22-oct-2026 los 25 documentos que trajo
                    eran de proveedores ajenos a Great Baby.
                -->
                <div class="card p-3 bg-amber-50 border-l-4 border-amber-500 text-amber-900 flex items-start gap-2">
                    <AlertTriangle class="h-4 w-4 shrink-0 mt-0.5"/>
                    <p class="text-xs">
                        Estos saldos vienen tal cual de SIIGO. <b>En el ambiente de pruebas la cuenta
                        es compartida con otros usuarios</b>, así que acá pueden aparecer documentos
                        y proveedores que no son de Great Baby. En producción sólo saldrán los suyos.
                    </p>
                </div>

                <div class="card p-4">
                    <div class="text-xs uppercase font-semibold text-surface-500">Saldo total por pagar según SIIGO</div>
                    <div class="text-3xl font-black text-brand-700 mt-1">{{ money(total) }}</div>
                    <div class="text-xs text-surface-500 mt-1">{{ filas.length }} documentos</div>
                </div>

                <div class="card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table v-tabla-movil class="w-full text-sm">
                            <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900 border-b">
                                <tr>
                                    <th class="p-3 text-left">Documento</th>
                                    <th class="p-3 text-left">Proveedor</th>
                                    <th class="p-3 text-left">NIT</th>
                                    <th class="p-3 text-center">Cuota</th>
                                    <th class="p-3 text-left">Vence</th>
                                    <th class="p-3 text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                                <tr v-if="!filas.length">
                                    <td colspan="6" class="text-center py-10 text-surface-400">
                                        SIIGO no reporta cuentas por pagar pendientes.
                                    </td>
                                </tr>
                                <tr v-for="(f, i) in filas" :key="i" class="hover:bg-brand-50/40 dark:hover:bg-brand-950/20">
                                    <td class="p-3 font-mono text-xs font-bold">{{ f.prefijo }}-{{ f.consecutivo }}</td>
                                    <td class="p-3">{{ f.proveedor || '—' }}</td>
                                    <td class="p-3 text-xs text-surface-600">{{ f.nit }}</td>
                                    <td class="p-3 text-center text-xs">{{ f.cuota }}</td>
                                    <td class="p-3 text-xs">
                                        <span :class="vencida(f.vence) ? 'text-red-600 font-bold inline-flex items-center gap-1' : ''">
                                            <CalendarClock v-if="vencida(f.vence)" class="h-3 w-3"/>
                                            {{ f.vence || '—' }}
                                        </span>
                                    </td>
                                    <td class="p-3 text-right font-bold">{{ money(f.saldo) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

        </div>
    </AppLayout>
</template>
