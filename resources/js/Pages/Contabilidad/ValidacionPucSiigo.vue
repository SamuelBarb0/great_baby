<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle, XCircle, AlertTriangle, Settings, Database, Cloud } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import { pestanasSiigo } from '@/composables/usePestanasContables';
/*
 * CONT-C5 · Diagnóstico de mapeo PUC → SIIGO.
 * Muestra qué cuentas PUC están siendo usadas por documentos pendientes de
 * sincronizar y si cada una tiene auxiliar transaccional resolvible para
 * SIIGO (vía setting override, cuenta propia, o descendiente del plan local).
 */
const props = defineProps({
    resumen: { type: Object, required: true },
    resultados: { type: Array, required: true },
    faltantes: { type: Array, required: true },
    settings: { type: Object, required: true },
    // Lo que SIIGO respondio de verdad. El controlador ya lo mandaba y esta
    // pantalla nunca lo pintaba: la lista exacta de lo que hay que arreglar
    // quedaba invisible.
    rechazadas_siigo: { type: Array, default: () => [] },
    terceros_rechazados: { type: Array, default: () => [] },
});

const viaLabel = {
    setting: 'Setting override',
    propia: 'La propia cuenta es transaccional',
    descendiente: 'Primera descendiente activa',
    none: 'Sin resolver',
};
const viaCls = {
    setting: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    propia: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    descendiente: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    none: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
};
</script>

<template>
    <Head title="Validación mapeo PUC → SIIGO"/>
    <AppLayout>
        <div class="max-w-6xl mx-auto space-y-4">
            <PestanasSeccion :pestanas="pestanasSiigo"/>

            <div class="card p-5">
                <div class="flex items-center gap-3 mb-4">
                    <Database class="h-6 w-6 text-brand-600"/>
                    <div>
                        <h1 class="text-2xl font-bold">Validación mapeo PUC → SIIGO</h1>
                        <p class="text-xs text-surface-500">
                            Antes de pushear un documento a SIIGO, cada cuenta PUC usada debe tener una
                            auxiliar transaccional resolvible. Si falta mapeo, el push falla con
                            <code>account_not_allowed</code>.
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-center">
                    <div class="card p-3 ring-1 ring-surface-200">
                        <div class="text-2xl font-bold">{{ resumen.pucs_en_uso }}</div>
                        <div class="text-[10px] uppercase text-surface-500">PUCs en uso</div>
                    </div>
                    <div class="card p-3 ring-1 ring-emerald-200">
                        <div class="text-2xl font-bold text-emerald-600">{{ resumen.pucs_ok }}</div>
                        <div class="text-[10px] uppercase text-surface-500">Mapeadas OK</div>
                    </div>
                    <div class="card p-3" :class="resumen.pucs_faltantes > 0 ? 'ring-2 ring-red-500' : 'ring-1 ring-surface-200'">
                        <div class="text-2xl font-bold" :class="resumen.pucs_faltantes > 0 ? 'text-red-600' : 'text-emerald-600'">
                            {{ resumen.pucs_faltantes }}
                        </div>
                        <div class="text-[10px] uppercase text-surface-500">Sin mapear</div>
                    </div>
                    <div class="card p-3 ring-1 ring-surface-200">
                        <div class="text-2xl font-bold">{{ resumen.settings_configurados }}<span class="text-sm text-surface-400">/{{ resumen.settings_totales }}</span></div>
                        <div class="text-[10px] uppercase text-surface-500">Settings SIIGO</div>
                    </div>
                    <Link href="/app/empresa/reglas" class="card p-3 ring-1 ring-brand-300 hover:bg-brand-50 flex items-center justify-center gap-2">
                        <Settings class="h-4 w-4"/>
                        <span class="text-sm font-semibold">Configurar</span>
                    </Link>
                </div>
            </div>

            <!-- Alerta cuando hay faltantes -->
            <div v-if="faltantes.length > 0" class="card p-4 border-l-4 border-red-500 bg-red-50/50 dark:bg-red-950/20">
                <div class="flex items-start gap-3">
                    <AlertTriangle class="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5"/>
                    <div class="flex-1">
                        <h2 class="font-bold text-red-800 dark:text-red-200">{{ faltantes.length }} cuenta(s) sin mapear</h2>
                        <p class="text-xs text-surface-600 mt-1">
                            Los documentos pendientes que usan estas cuentas no podrán llegar a SIIGO
                            hasta que la contadora configure el setting sugerido o agregue una cuenta
                            auxiliar transaccional en el plan local.
                        </p>
                        <ul class="mt-3 space-y-1 text-sm">
                            <li v-for="f in faltantes" :key="f.puc" class="flex items-start gap-2">
                                <XCircle class="h-4 w-4 text-red-500 flex-shrink-0 mt-0.5"/>
                                <div class="flex-1">
                                    <span class="font-mono font-bold">{{ f.puc }}</span> ·
                                    <span class="text-surface-600 dark:text-surface-300">{{ f.mensaje }}</span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Tabla detallada de resultados -->
            <div class="card p-0 overflow-hidden">
                <div class="p-3 border-b bg-surface-50 dark:bg-surface-900/40 text-xs uppercase font-bold text-brand-600">
                    Cuentas PUC usadas por documentos pendientes
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="text-[10px] uppercase text-surface-500 border-b">
                        <tr>
                            <th class="text-left p-3">PUC</th>
                            <th class="text-left p-3">Resolución</th>
                            <th class="text-left p-3">PUC final SIIGO</th>
                            <th class="text-left p-3">Vía</th>
                            <th class="text-right p-3">Documentos impactados</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-200 dark:divide-surface-800">
                        <tr v-for="r in resultados" :key="r.puc" class="hover:bg-surface-50 dark:hover:bg-surface-900/40">
                            <td class="p-3 font-mono font-bold">{{ r.puc }}</td>
                            <td class="p-3">
                                <CheckCircle v-if="r.ok" class="h-4 w-4 text-emerald-500 inline"/>
                                <XCircle v-else class="h-4 w-4 text-red-500 inline"/>
                                <span v-if="r.ok" class="text-emerald-700 dark:text-emerald-300 ml-1 text-xs">OK</span>
                                <span v-else class="text-red-700 dark:text-red-300 ml-1 text-xs">{{ r.mensaje }}</span>
                            </td>
                            <td class="p-3 font-mono">{{ r.puc_final || '—' }}</td>
                            <td class="p-3">
                                <span :class="['px-2 py-0.5 rounded text-[10px] font-bold uppercase', viaCls[r.via]]">{{ viaLabel[r.via] }}</span>
                                <div v-if="r.setting_usado" class="text-[10px] text-surface-500 mt-1 font-mono">{{ r.setting_usado }}</div>
                            </td>
                            <td class="p-3 text-right">
                                <span v-if="r.impacto > 0" class="font-bold" :class="r.ok ? 'text-surface-600' : 'text-red-600'">
                                    {{ r.impacto }}
                                </span>
                                <span v-else class="text-surface-400">0</span>
                            </td>
                        </tr>
                        <tr v-if="resultados.length === 0">
                            <td colspan="5" class="p-8 text-center text-surface-500">
                                <Cloud class="h-10 w-10 mx-auto mb-2 text-emerald-500"/>
                                <div class="font-bold">¡Nada pendiente!</div>
                                <div class="text-xs">No hay movimientos contables sin sincronizar a SIIGO.</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Settings SIIGO críticos -->
            <div class="card p-0 overflow-hidden">
                <div class="p-3 border-b bg-surface-50 dark:bg-surface-900/40 text-xs uppercase font-bold text-brand-600 flex items-center justify-between">
                    <span>Settings SIIGO críticos</span>
                    <Link href="/app/empresa/reglas" class="btn-ghost text-xs"><Settings class="h-3 w-3"/> Editar</Link>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <tbody class="divide-y divide-surface-200 dark:divide-surface-800">
                        <tr v-for="(info, clave) in settings" :key="clave" class="hover:bg-surface-50 dark:hover:bg-surface-900/40">
                            <td class="p-3 font-mono text-xs">{{ clave }}</td>
                            <td class="p-3">
                                <span v-if="info.configurado" class="text-emerald-600 flex items-center gap-1 text-xs">
                                    <CheckCircle class="h-3 w-3"/> {{ info.valor }}
                                </span>
                                <span v-else class="text-red-600 flex items-center gap-1 text-xs">
                                    <XCircle class="h-3 w-3"/> sin configurar
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Lo que SIIGO rechazo de verdad, no lo que el validador local supone. -->
            <div v-if="rechazadas_siigo.length" class="card overflow-hidden">
                <div class="px-5 py-3 border-b border-surface-200 dark:border-surface-800">
                    <h2 class="font-bold text-rose-700 dark:text-rose-300">
                        Cuentas que SIIGO rechazo ({{ rechazadas_siigo.length }})
                    </h2>
                    <p class="text-xs text-surface-500 mt-0.5">
                        SIIGO no publica su plan de cuentas, asi que esta es la unica forma de saber que
                        falta mapear. Se corrige en Plan de cuentas, en el campo &laquo;cuenta SIIGO&raquo;.
                    </p>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="bg-surface-50 dark:bg-surface-900/60 text-xs uppercase text-surface-500">
                        <tr>
                            <th class="text-left px-5 py-2">Cuenta</th>
                            <th class="text-left px-5 py-2">Que respondio SIIGO</th>
                            <th class="text-center px-5 py-2">Veces</th>
                            <th class="text-left px-5 py-2">Ultimo intento</th>
                            <th class="text-center px-5 py-2">Mapeada</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in rechazadas_siigo" :key="c.cuenta"
                            class="border-t border-surface-100 dark:border-surface-800">
                            <td class="px-5 py-2 font-mono font-semibold">{{ c.cuenta }}</td>
                            <td class="px-5 py-2 text-surface-600 dark:text-surface-400">{{ c.mensaje }}</td>
                            <td class="px-5 py-2 text-center tabular-nums">{{ c.veces }}</td>
                            <td class="px-5 py-2 text-xs text-surface-500">{{ c.ultimo }}</td>
                            <td class="px-5 py-2 text-center">
                                <CheckCircle v-if="c.mapeada" class="h-4 w-4 text-emerald-600 inline"/>
                                <XCircle v-else class="h-4 w-4 text-red-500 inline"/>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Terceros, que es otro problema: no se mapean, se crean en SIIGO. -->
            <div v-if="terceros_rechazados.length" class="card overflow-hidden">
                <div class="px-5 py-3 border-b border-surface-200 dark:border-surface-800">
                    <h2 class="font-bold text-amber-700 dark:text-amber-300">
                        Terceros que SIIGO no conoce ({{ terceros_rechazados.length }})
                    </h2>
                    <p class="text-xs text-surface-500 mt-0.5">
                        Esto NO se arregla en el plan de cuentas: hay que crear el tercero en SIIGO con esa
                        misma identificacion, o el documento que lo use no va a entrar.
                    </p>
                </div>
                <table v-tabla-movil class="w-full text-sm">
                    <thead class="bg-surface-50 dark:bg-surface-900/60 text-xs uppercase text-surface-500">
                        <tr>
                            <th class="text-left px-5 py-2">Identificacion</th>
                            <th class="text-left px-5 py-2">Nombre en el ERP</th>
                            <th class="text-center px-5 py-2">Veces</th>
                            <th class="text-left px-5 py-2">Ultimo intento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in terceros_rechazados" :key="t.tercero"
                            class="border-t border-surface-100 dark:border-surface-800">
                            <td class="px-5 py-2 font-mono font-semibold">{{ t.tercero }}</td>
                            <td class="px-5 py-2">{{ t.nombre || 'No esta en Contactos' }}</td>
                            <td class="px-5 py-2 text-center tabular-nums">{{ t.veces }}</td>
                            <td class="px-5 py-2 text-xs text-surface-500">{{ t.ultimo }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
