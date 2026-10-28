<script setup>
/*
 * La bandeja de tesorería.
 *
 * Tres flujos del módulo terminan con la firma de tesorería, y los botones
 * vivían en pantallas que tesorería no puede abrir. El control existía en el
 * código y en la práctica lo apretaba otro «por» ella.
 *
 * Acá sólo está su mano: cuánto hay que pagar, a quién y por qué concepto.
 * No el expediente de nadie.
 */
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Banknote, Wallet, ClipboardCheck, Check } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    nominas: { type: Array, default: () => [] },
    liquidaciones: { type: Array, default: () => [] },
    entregas: { type: Array, default: () => [] },
});

const procesando = ref(false);
const errores = ref({});

const money = (v) => '$' + Number(v || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

const modalPago = ref(null);   // { url, titulo, detalle }
const pago = reactive({ fecha_pago: new Date().toISOString().slice(0, 10) });

const abrirPago = (url, titulo, detalle) => {
    errores.value = {};
    pago.fecha_pago = new Date().toISOString().slice(0, 10);
    modalPago.value = { url, titulo, detalle };
};

const accion = (url, datos = {}) => {
    if (procesando.value) return;
    procesando.value = true;
    errores.value = {};
    router.post(url, datos, {
        preserveScroll: true,
        onError: (e) => { errores.value = e; },
        onFinish: () => { procesando.value = false; modalPago.value = null; },
    });
};

const confirmarPago = () => accion(modalPago.value.url, { ...pago });

const nadaQueHacer = computed(() =>
    !props.nominas.length && !props.liquidaciones.length && !props.entregas.length);
</script>

<template>
    <Head title="Tesorería · Gestión Humana" />
    <AppLayout>
        <div class="space-y-5 max-w-5xl">
            <div>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <Banknote class="h-6 w-6 text-brand-600" />Pendientes de tesorería
                </h1>
                <p class="text-sm text-surface-500 mt-1">
                    Lo que espera su firma: nóminas y liquidaciones aprobadas, y los paz y salvo
                    de quienes se van.
                </p>
            </div>

            <div v-if="nadaQueHacer" class="card p-8 text-center text-sm text-surface-500">
                Nada pendiente. Cuando gerencia apruebe una nómina o una liquidación, aparece acá.
            </div>

            <!-- Nóminas aprobadas -->
            <div v-if="nominas.length" class="card p-4">
                <h2 class="text-xs font-bold uppercase text-surface-500 flex items-center gap-1 mb-3">
                    <Wallet class="h-4 w-4" /> Nóminas aprobadas, sin pagar
                </h2>
                <div v-for="n in nominas" :key="n.id"
                    class="flex items-center justify-between gap-3 py-2 border-b last:border-0 flex-wrap">
                    <div>
                        <div class="font-semibold">{{ n.titulo }}</div>
                        <div class="text-xs text-surface-500">
                            {{ n.personas }} {{ n.personas === 1 ? 'persona' : 'personas' }} ·
                            aprobada {{ n.aprobada }} por {{ n.aprobada_por }}
                        </div>
                        <!--
                            Los aportes salen aparte: van a la PILA, no a la
                            dispersión, y es plata distinta.
                        -->
                        <div class="text-xs text-surface-400">
                            Aportes a la PILA: {{ money(n.aportes) }}
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <div class="text-xs uppercase text-surface-500">A consignar</div>
                            <div class="font-bold">{{ money(n.neto) }}</div>
                        </div>
                        <button @click="abrirPago(`/app/rrhh/nomina/${n.id}/pagar`, n.titulo, money(n.neto))"
                            :disabled="procesando" class="btn-primary text-sm">
                            <Check class="h-4 w-4" /> Marcar pagada
                        </button>
                    </div>
                </div>
            </div>

            <!-- Liquidaciones aprobadas -->
            <div v-if="liquidaciones.length" class="card p-4">
                <h2 class="text-xs font-bold uppercase text-surface-500 flex items-center gap-1 mb-3">
                    <Wallet class="h-4 w-4" /> Liquidaciones aprobadas, sin pagar
                </h2>
                <div v-for="l in liquidaciones" :key="l.empleado_id"
                    class="flex items-center justify-between gap-3 py-2 border-b last:border-0 flex-wrap">
                    <div>
                        <div class="font-semibold">{{ l.nombre }}</div>
                        <div class="text-xs text-surface-500">
                            C.C. {{ l.documento || '—' }} · retiro {{ l.retiro }} ·
                            aprobada {{ l.aprobada }} por {{ l.aprobada_por }}
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <div class="text-xs uppercase text-surface-500">Neto</div>
                            <div class="font-bold">{{ money(l.neto) }}</div>
                        </div>
                        <a :href="`/app/rrhh/empleados/${l.empleado_id}/liquidacion/pdf`" target="_blank"
                            class="btn-secondary text-sm">Ver</a>
                        <button @click="abrirPago(`/app/rrhh/empleados/${l.empleado_id}/liquidacion/pagar`, l.nombre, money(l.neto))"
                            :disabled="procesando" class="btn-primary text-sm">
                            <Check class="h-4 w-4" /> Marcar pagada
                        </button>
                    </div>
                </div>
            </div>

            <!-- Paz y salvo -->
            <div v-if="entregas.length" class="card p-4">
                <h2 class="text-xs font-bold uppercase text-surface-500 flex items-center gap-1 mb-3">
                    <ClipboardCheck class="h-4 w-4" /> Paz y salvo por firmar
                </h2>
                <div v-for="x in entregas" :key="x.empleado_id" class="py-3 border-b last:border-0">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <div class="font-semibold">{{ x.nombre }}</div>
                            <div class="text-xs text-surface-500">
                                C.C. {{ x.documento || '—' }}<span v-if="x.retiro"> · retiro {{ x.retiro }}</span>
                            </div>
                        </div>
                        <button @click="accion(`/app/rrhh/empleados/${x.empleado_id}/entrega-cargo/tesoreria/confirmar`)"
                            :disabled="procesando" class="btn-primary text-sm">
                            <Check class="h-4 w-4" /> Dar conformidad
                        </button>
                    </div>

                    <!-- Sobre qué está firmando -->
                    <div v-if="x.conceptos.length" class="mt-2 text-xs">
                        <div v-for="(c, i) in x.conceptos" :key="i"
                            class="flex justify-between gap-3 py-0.5 border-b last:border-0">
                            <span>{{ c.nombre }}</span>
                            <span class="text-surface-500">{{ c.detalle }}</span>
                        </div>
                    </div>
                    <p v-else class="text-xs text-amber-700 mt-1">
                        Sin conceptos de paz y salvo escritos. Hay que registrarlos antes de firmar:
                        un paz y salvo vacío no afirma nada.
                    </p>

                    <p v-if="x.observaciones" class="text-xs text-surface-500 mt-1">{{ x.observaciones }}</p>
                    <p v-if="x.faltan.length" class="text-xs text-surface-400 mt-1">
                        También falta: {{ x.faltan.filter(f => f !== 'Tesorería').join(', ') || '—' }}
                    </p>
                </div>
                <p v-if="errores.tesoreria" class="text-xs text-rose-600 mt-2">{{ errores.tesoreria }}</p>
            </div>
        </div>

        <!-- Confirmar un pago -->
        <div v-if="modalPago" @click.self="modalPago = null"
            class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full space-y-3">
                <h3 class="text-lg font-bold">Marcar como pagada</h3>
                <p class="text-sm">
                    {{ modalPago.titulo }} · <strong>{{ modalPago.detalle }}</strong>
                </p>
                <p class="text-xs text-surface-500">
                    Con qué fecha salió la consignación. Después de esto no se vuelve a tocar.
                </p>
                <div>
                    <label class="text-xs uppercase text-surface-500">Fecha de pago</label>
                    <input v-model="pago.fecha_pago" type="date" class="input w-full mt-1" />
                    <p v-if="errores.fecha_pago" class="text-xs text-rose-600 mt-1">{{ errores.fecha_pago }}</p>
                </div>
                <div class="flex justify-end gap-2">
                    <button @click="modalPago = null" class="btn-secondary">Cancelar</button>
                    <button @click="confirmarPago" :disabled="procesando" class="btn-primary">
                        Confirmar pago
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
