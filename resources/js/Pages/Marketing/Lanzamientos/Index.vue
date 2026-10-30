<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { Rocket, Plus, AlertTriangle, Clock, CheckCircle2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Tablero de lanzamiento · un tablero por contenedor, con su avance (tarjetas
 * finalizadas de 10). Los contenedores que existían antes del tablero se
 * pueden sumar con «Crear lanzamiento».
 */
defineProps({
    contenedores: { type: Array, required: true },
});

const crear = (c) => router.post('/app/marketing/lanzamientos', { importacion_id: c.importacion_id });
const porcentaje = (l) => l.total ? Math.round(l.finalizadas / l.total * 100) : 0;
</script>

<template>
    <Head title="Lanzamientos"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2"><Rocket class="h-6 w-6 text-brand-600"/>Lanzamientos</h1>
                <p class="text-sm text-surface-500 mt-1">Un tablero por contenedor, desde su llegada hasta el lanzamiento de sus productos.</p>
            </div>

            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">{{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">{{ $page.props.flash.error }}</div>

            <div v-if="!contenedores.length" class="card p-6 text-center text-surface-500">
                Todavía no hay contenedores. Cuando Compras cree uno, su tablero aparece aquí.
            </div>

            <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div v-for="c in contenedores" :key="c.importacion_id" class="card p-4 space-y-3">
                    <div>
                        <div class="font-bold">{{ c.numero }}</div>
                        <div class="text-sm text-surface-500">
                            {{ c.contenedor || 'Sin número de contenedor' }}<span v-if="c.eta"> · ETA {{ c.eta }}</span>
                        </div>
                    </div>

                    <template v-if="c.lanzamiento">
                        <div>
                            <div class="flex justify-between text-xs text-surface-500 mb-1">
                                <span>{{ c.lanzamiento.finalizadas }} de {{ c.lanzamiento.total }} tarjetas</span>
                                <span v-if="c.lanzamiento.finalizado_en" class="text-emerald-700 font-semibold flex items-center gap-1">
                                    <CheckCircle2 class="h-3.5 w-3.5"/>Lanzado {{ c.lanzamiento.finalizado_en }}
                                </span>
                            </div>
                            <div class="h-2 rounded-full bg-surface-200 overflow-hidden">
                                <div class="h-full bg-emerald-500" :style="{ width: porcentaje(c.lanzamiento) + '%' }"></div>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span v-if="c.lanzamiento.bloqueadas" class="px-2 py-0.5 rounded-full bg-red-500/15 text-red-700 flex items-center gap-1">
                                <AlertTriangle class="h-3 w-3"/>{{ c.lanzamiento.bloqueadas }} bloqueada(s)
                            </span>
                            <span v-if="c.lanzamiento.por_aprobar" class="px-2 py-0.5 rounded-full bg-violet-500/15 text-violet-700 flex items-center gap-1">
                                <Clock class="h-3 w-3"/>{{ c.lanzamiento.por_aprobar }} por aprobar
                            </span>
                        </div>
                        <Link :href="`/app/marketing/lanzamientos/${c.lanzamiento.id}`" class="btn-primary w-full justify-center text-sm">Abrir tablero</Link>
                    </template>

                    <button v-else @click="crear(c)" class="btn-ghost border border-brand-300 w-full justify-center text-sm">
                        <Plus class="h-4 w-4"/>Crear lanzamiento
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
