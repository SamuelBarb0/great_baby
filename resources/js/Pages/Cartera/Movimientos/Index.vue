<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useMoney } from '@/composables/useMoney';
import { BookOpen } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    movimientos: { type: Object, required: true },
    filtros: { type: Object, required: true },
    // H8 · totales calculados en el backend sobre TODO el rango filtrado,
    // no sólo la página actual. Sin esto la conciliación mensual era imposible.
    totales: { type: Object, default: () => ({ suma_debe: 0, suma_haber: 0, diferencia: 0, total_filas: 0 }) },
});

const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);
const cuenta = ref(props.filtros.cuenta || '');
const filtrar = () => router.get('/app/cartera/movimientos', { desde: desde.value, hasta: hasta.value, cuenta: cuenta.value || null }, { preserveState: true });

const { money } = useMoney();

// H8 · usar totales de backend (todo el rango), no de la página.
const totalDebe = computed(() => props.totales.suma_debe);
const totalHaber = computed(() => props.totales.suma_haber);
const diferencia = computed(() => props.totales.diferencia);
</script>

<template>
    <Head title="Movimientos contables"/>
    <AppLayout>
        <div class="space-y-4">
            <h1 class="text-2xl font-bold flex items-center gap-2"><BookOpen class="h-6 w-6 text-brand-600"/>Movimientos contables</h1>

            <div class="card p-3 flex items-center gap-2 flex-wrap">
                <div><label class="text-xs">Desde</label><input type="date" v-model="desde" class="input"/></div>
                <div><label class="text-xs">Hasta</label><input type="date" v-model="hasta" class="input"/></div>
                <div class="flex-1"><label class="text-xs">Cuenta PUC (prefijo)</label><input v-model="cuenta" placeholder="1305, 4135, 2408..." class="input w-full font-mono"/></div>
                <button @click="filtrar" class="btn-primary self-end">Filtrar</button>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="card p-3"><div class="text-xs text-surface-500">Total débito (rango)</div><div class="text-xl font-bold">{{ money(totalDebe) }}</div></div>
                <div class="card p-3"><div class="text-xs text-surface-500">Total crédito (rango)</div><div class="text-xl font-bold">{{ money(totalHaber) }}</div></div>
                <div class="card p-3" :class="Math.abs(diferencia) > 0.01 ? 'ring-2 ring-red-500' : ''">
                    <div class="text-xs text-surface-500">Diferencia</div>
                    <div class="text-xl font-bold" :class="Math.abs(diferencia) > 0.01 ? 'text-red-600' : 'text-emerald-600'">{{ money(diferencia) }}</div>
                </div>
                <div class="card p-3">
                    <div class="text-xs text-surface-500">Filas en el rango</div>
                    <div class="text-xl font-bold">{{ totales.total_filas.toLocaleString('es-CO') }}</div>
                    <div class="text-[10px] text-surface-400 mt-0.5">mostrando {{ movimientos.data.length }} de esta página</div>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table v-tabla-movil data-vacia="No hubo movimiento contable en ese rango de fechas." class="w-full text-sm">
                    <thead class="text-xs text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-2">Fecha</th>
                            <th class="text-left p-2">Cuenta</th>
                            <th class="text-right p-2">Débito</th>
                            <th class="text-right p-2">Crédito</th>
                            <th class="text-left p-2">Descripción</th>
                            <th class="text-left p-2">Origen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="m in movimientos.data" :key="m.id" class="hover:bg-surface-50">
                            <td class="p-2 text-xs">{{ m.fecha }}</td>
                            <td class="p-2 font-mono font-bold">{{ m.cuenta }}</td>
                            <td class="p-2 text-right" :class="m.debe > 0 ? 'text-emerald-600 font-bold' : 'text-surface-300'">{{ m.debe > 0 ? money(m.debe) : '—' }}</td>
                            <td class="p-2 text-right" :class="m.haber > 0 ? 'text-red-600 font-bold' : 'text-surface-300'">{{ m.haber > 0 ? money(m.haber) : '—' }}</td>
                            <td class="p-2 text-xs">{{ m.descripcion }}</td>
                            <td class="p-2 text-xs">
                                <a v-if="m.origen_url" :href="m.origen_url" class="text-brand-600 hover:underline" title="Ver el asiento completo y su documento">{{ m.origen }}</a>
                                <span v-else>{{ m.origen }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
