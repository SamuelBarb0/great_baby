<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, ArrowLeft, Send, CheckCircle, XCircle, ShoppingCart, Clock } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({ cotizacion: { type: Object, required: true } });

const { money: fmtCOP } = useMoney();

const procesando = ref(false);
const modalRechazo = ref(false);
const motivo = ref('');

const COLOR = {
    borrador: 'bg-surface-200 text-surface-700 dark:bg-surface-800 dark:text-surface-300',
    enviada: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    aceptada: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    rechazada: 'bg-red-500/15 text-red-700 dark:text-red-300',
    convertida: 'bg-brand-500/15 text-brand-700 dark:text-brand-300',
    vencida: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
};

const accion = (url, datos = {}) => {
    procesando.value = true;
    router.post(url, datos, {
        preserveScroll: true,
        onFinish: () => { procesando.value = false; modalRechazo.value = false; },
    });
};

const base = `/app/cotizaciones/${props.cotizacion.id}`;
</script>

<template>
    <Head :title="`${cotizacion.numero} · Cotización`"/>
    <AppLayout>
        <div class="space-y-4">
            <div>
                <Link href="/app/cotizaciones" class="text-xs text-brand-600 hover:underline flex items-center gap-1 mb-1">
                    <ArrowLeft class="h-3 w-3"/> Volver a cotizaciones
                </Link>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <FileText class="h-6 w-6 text-brand-600"/>
                        <span class="font-mono">{{ cotizacion.numero }}</span>
                    </h1>
                    <span class="text-[10px] uppercase px-2 py-0.5 rounded font-bold" :class="COLOR[cotizacion.estado]">
                        {{ cotizacion.estado }}
                    </span>
                </div>
            </div>

            <div v-if="$page.props.flash?.success"
                 class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.errors?.cotizacion"
                 class="p-3 rounded-lg bg-red-500/15 border-l-4 border-red-500 text-red-700 text-sm">
                {{ $page.props.errors.cotizacion }}
            </div>

            <!-- La vigencia es la mitad del sentido de una cotización. -->
            <div v-if="cotizacion.estado !== 'convertida'" class="p-3 rounded-lg text-sm flex gap-2"
                 :class="cotizacion.vigente
                    ? 'bg-surface-100 dark:bg-surface-900/40 text-surface-700 dark:text-surface-300'
                    : 'bg-amber-500/15 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200'">
                <Clock class="h-5 w-5 shrink-0"/>
                <div v-if="cotizacion.vigente">
                    Vence el <strong>{{ cotizacion.vigente_hasta }}</strong>
                    <span v-if="cotizacion.dias_para_vencer === 0"> · <strong>es hoy</strong></span>
                    <span v-else-if="cotizacion.dias_para_vencer <= 3">
                        · quedan {{ cotizacion.dias_para_vencer }} días
                    </span>
                    . Hasta esa fecha se respetan estos precios aunque la lista cambie.
                </div>
                <div v-else>
                    <strong>Venció el {{ cotizacion.vigente_hasta }}.</strong>
                    Ya no se puede convertir: hay que hacer una nueva con los precios de hoy.
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2">Cliente</div>
                    <Link v-if="cotizacion.cliente.id" :href="`/app/contactos/${cotizacion.cliente.id}`"
                          class="font-medium hover:text-brand-600">{{ cotizacion.cliente.nombre }}</Link>
                    <div class="text-xs text-surface-500 mt-1">
                        {{ cotizacion.cliente.documento }}<span v-if="cotizacion.cliente.ciudad"> · {{ cotizacion.cliente.ciudad }}</span>
                    </div>
                    <div class="text-xs text-surface-500 mt-2">
                        Vendedor: {{ cotizacion.vendedor || '—' }} · Fecha: {{ cotizacion.fecha }}
                    </div>
                </div>

                <div class="card p-4 lg:col-span-2">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2">Qué se hace con esta cotización</div>

                    <div v-if="cotizacion.pedido" class="text-sm">
                        Ya se convirtió en el pedido
                        <Link :href="`/app/pedidos-b2b/${cotizacion.pedido.id}`"
                              class="font-mono text-brand-600 hover:underline">{{ cotizacion.pedido.numero }}</Link>,
                        con los precios que se cotizaron.

                        <!-- Sólo cuando quien convirtió no es el vendedor: ahí
                             el dato dice que alguien más cerró esa venta, y
                             que el pedido igual quedó de quien la trabajó. -->
                        <p v-if="cotizacion.convertida_por" class="text-xs text-surface-500 mt-1">
                            La cerró {{ cotizacion.convertida_por }}, pero el pedido quedó
                            a nombre de {{ cotizacion.vendedor }}.
                        </p>
                    </div>

                    <div v-else class="flex flex-wrap gap-2">
                        <button v-if="cotizacion.estado_guardado === 'borrador'"
                                @click="accion(`${base}/enviar`)" :disabled="procesando"
                                class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium flex items-center gap-1 disabled:opacity-50">
                            <Send class="h-4 w-4"/> Marcar como enviada
                        </button>

                        <button v-if="['borrador', 'enviada'].includes(cotizacion.estado_guardado)"
                                @click="accion(`${base}/responder`, { respuesta: 'aceptada' })" :disabled="procesando"
                                class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium flex items-center gap-1 disabled:opacity-50">
                            <CheckCircle class="h-4 w-4"/> El cliente aceptó
                        </button>

                        <button v-if="['borrador', 'enviada', 'aceptada'].includes(cotizacion.estado_guardado)"
                                @click="modalRechazo = true" :disabled="procesando"
                                class="px-3 py-2 rounded-lg border border-red-500 text-red-600 text-sm font-medium flex items-center gap-1 disabled:opacity-50">
                            <XCircle class="h-4 w-4"/> El cliente la rechazó
                        </button>

                        <!--
                            El botón se deshabilita y DICE por qué. Un botón
                            muerto sin explicación obliga a adivinar si falta
                            que el cliente acepte o si ya se venció.
                        -->
                        <button @click="accion(`${base}/convertir`)"
                                :disabled="procesando || !! cotizacion.por_que_no"
                                :title="cotizacion.por_que_no || 'Crea el pedido con estos mismos precios'"
                                class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium flex items-center gap-1 disabled:opacity-50">
                            <ShoppingCart class="h-4 w-4"/> Convertir en pedido
                        </button>
                    </div>

                    <p v-if="cotizacion.por_que_no && ! cotizacion.pedido" class="text-xs text-surface-500 mt-2">
                        {{ cotizacion.por_que_no }}
                    </p>

                    <p v-if="cotizacion.motivo_rechazo" class="text-xs text-red-600 mt-2">
                        Motivo del rechazo: {{ cotizacion.motivo_rechazo }}
                    </p>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[620px] text-sm">
                        <thead>
                            <tr class="text-surface-500 text-xs uppercase border-b border-surface-200 dark:border-surface-800">
                                <th class="text-left py-2 px-3">Producto</th>
                                <th class="text-right">Cant.</th>
                                <th class="text-right">Precio</th>
                                <th class="text-right">IVA</th>
                                <th class="text-right px-3">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in cotizacion.items" :key="i.id"
                                class="border-b border-surface-100 dark:border-surface-800/60">
                                <td class="py-2 px-3">
                                    {{ i.descripcion }}
                                    <span class="block text-[10px] text-surface-500 font-mono">{{ i.sku }}</span>
                                </td>
                                <td class="text-right tabular-nums">{{ i.cantidad }}</td>
                                <td class="text-right tabular-nums">{{ fmtCOP(i.precio) }}</td>
                                <td class="text-right tabular-nums text-surface-500 text-xs">{{ i.iva_pct }}%</td>
                                <td class="text-right tabular-nums font-semibold px-3">{{ fmtCOP(i.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-surface-200 dark:border-surface-800">
                                <td colspan="4" class="text-right py-2 text-xs text-surface-500">Subtotal</td>
                                <td class="text-right tabular-nums px-3">{{ fmtCOP(cotizacion.subtotal) }}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-right py-1 text-xs text-surface-500">IVA</td>
                                <td class="text-right tabular-nums px-3">{{ fmtCOP(cotizacion.iva) }}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-right py-2 text-sm font-bold">Total</td>
                                <td class="text-right tabular-nums font-black text-lg px-3">{{ fmtCOP(cotizacion.total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div v-if="cotizacion.notas" class="card p-4">
                <div class="text-xs uppercase font-bold text-brand-600 mb-2">Notas</div>
                <p class="text-sm whitespace-pre-wrap">{{ cotizacion.notas }}</p>
            </div>
        </div>

        <!-- Por qué se perdió: sin esto, al mes no hay forma de saber si se
             pierde por precio, por plazo o por tiempo de entrega. -->
        <div v-if="modalRechazo" @click.self="modalRechazo = false"
             class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="card p-6 max-w-md w-full">
                <h3 class="text-lg font-bold mb-3">¿Por qué la rechazó?</h3>
                <p class="text-xs text-surface-500 mb-3">
                    Es el dato que al mes dice si se pierde por precio, por plazo o por entrega.
                </p>
                <input v-model="motivo" maxlength="255" placeholder="Precio · plazo de entrega · condiciones de pago…"
                       class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-800 bg-transparent text-sm"/>
                <div class="flex gap-2 justify-end mt-4">
                    <button @click="modalRechazo = false" class="px-3 py-2 text-sm text-surface-500">Cancelar</button>
                    <button @click="accion(`${base}/responder`, { respuesta: 'rechazada', motivo_rechazo: motivo })"
                            :disabled="procesando || ! motivo.trim()"
                            class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium disabled:opacity-50">
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
