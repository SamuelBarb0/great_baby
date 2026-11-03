<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';

/*
 * «No me cierres hasta que me des un seguimiento»: un pago a China vencido no se
 * puede dejar para luego. El aviso no tiene botón de cerrar ni se va con Esc; se va
 * cuando alguien escribe qué se ha hecho, y vuelve a los pocos días si sigue igual.
 */
const page = usePage();
// En la pantalla de pagos no se muestra: es donde se resuelve, y taparla impediría
// marcar pagada la cuota o cambiar su plan.
const pendientes = computed(() => (page.url.startsWith('/app/compras/pagos-exterior') ? [] : (page.props.pagos_sin_seguimiento ?? [])));
const actual = computed(() => pendientes.value[0] ?? null);
const nota = ref('');
const enviando = ref(false);

watch(() => actual.value?.id, () => { nota.value = ''; });

const money = (n) => (n === null || n === undefined ? '' : '$' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 0 }));
const num = (n) => Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 2 });

const guardar = () => {
    if (enviando.value || nota.value.trim().length < 3) return;
    enviando.value = true;
    router.post(`/app/compras/pagos-exterior/${actual.value.id}/seguimiento`, { nota: nota.value }, {
        preserveScroll: true,
        onFinish: () => { enviando.value = false; },
    });
};
</script>

<template>
    <div v-if="actual" class="fixed inset-0 z-[60] bg-black/60 flex items-center justify-center p-4" role="alertdialog" aria-modal="true" aria-labelledby="aviso-pago-titulo">
        <div class="card p-6 max-w-lg w-full border-t-4 border-red-600">
            <div class="flex items-start gap-3">
                <AlertTriangle class="h-6 w-6 text-red-600 mt-0.5 flex-shrink-0"/>
                <div class="min-w-0 flex-1">
                    <h2 id="aviso-pago-titulo" class="text-lg font-bold">Pago a China vencido hace {{ actual.dias }} día{{ actual.dias === 1 ? '' : 's' }}</h2>
                    <p class="text-sm mt-1"><b>{{ actual.orden }}</b> · {{ actual.proveedor }}</p>
                    <p class="text-sm">{{ actual.concepto }}: <b>{{ num(actual.monto_moneda) }} {{ actual.moneda }}</b> <span class="text-surface-500">({{ money(actual.monto_cop) }})</span></p>
                    <p v-if="pendientes.length > 1" class="text-xs text-surface-500 mt-1">Y {{ pendientes.length - 1 }} más después de este.</p>
                    <label class="block text-xs font-semibold mt-4" for="aviso-pago-nota">¿Qué se ha hecho con este pago?</label>
                    <textarea id="aviso-pago-nota" v-model="nota" rows="3" maxlength="500" class="input w-full" autofocus></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <a href="/app/compras/pagos-exterior" class="btn-ghost text-sm">Ver pagos</a>
                <button @click="guardar" :disabled="nota.trim().length < 3 || enviando" class="btn-primary text-sm disabled:opacity-50">{{ enviando ? 'Guardando…' : 'Registrar seguimiento' }}</button>
            </div>
        </div>
    </div>
</template>
