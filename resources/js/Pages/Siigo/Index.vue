<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import {
    Cloud, CheckCircle, XCircle, AlertCircle, Clock, RefreshCw,
    Power, PowerOff, ExternalLink, Package, ListChecks, Ban, AlertTriangle,
    Receipt, Star, Warehouse, ArrowRight,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const props = defineProps({
    config: { type: Object, required: true },
    kpis: { type: Object, required: true },
    cola: { type: Object, required: true },
    fallidos_recientes: { type: Array, required: true },
    // PROD-15 · productos que fallaron ≥3 veces en los últimos 7 días.
    fallas_permanentes: { type: Array, default: () => [] },
    // INV-A3 · movimientos kardex contables sin asiento SIIGO.
    movs_pendientes_siigo: { type: Object, default: () => ({ total: 0, por_antiguedad: {}, ejemplos: [] }) },
    logs: { type: Array, required: true },
    puede_toggle: { type: Boolean, default: false },
    reconciliar: { type: Object, default: () => ({ estado: 'idle' }) },
    // UBIC-7 · estado del setup Facturación Electrónica para el wizard.
    setup_fe: { type: Object, default: () => ({ estado: 'rojo' }) },
    // Las cuentas PUC configuradas, contrastadas con el plan de cuentas.
    cuentas_puc: { type: Array, default: () => [] },
});

// Sólo se avisa de las que están mal; las que están bien no necesitan espacio.
const cuentasPucConProblema = computed(() => props.cuentas_puc.filter((c) => c.estado !== 'verde'));

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

// F8 · toggle kill-switch (solo Aracely).
const togglando = ref(false);
const togglear = () => {
    if (! props.puede_toggle) return;
    const nuevo = ! props.config.push_auto;
    modalConfirm.value = {
        titulo: nuevo ? '¿Encender el sync automático a SIIGO?' : '¿Apagar el sync automático?',
        mensaje: nuevo
            ? 'Cada cambio de producto se enviará solo.'
            : 'Los productos que edites NO se enviarán hasta que lo reactives. Los jobs que ya están encolados también se pausan.',
        color: nuevo ? 'emerald' : 'amber',
        textoConfirmar: nuevo ? 'Encender' : 'Apagar',
        onConfirmar: () => {
            modalConfirm.value = null;
            togglando.value = true;
            router.post('/app/siigo/kill-switch', { activo: nuevo }, {
                preserveScroll: true,
                onFinish: () => { togglando.value = false; },
            });
        },
    };
};

// F8 · reintentar un fallido.
const reintentando = ref(null);
const reintentar = (logId) => {
    modalConfirm.value = {
        titulo: '¿Reintentar este sync?',
        mensaje: 'Se encola un job manual nuevo.',
        color: 'sky',
        textoConfirmar: 'Reintentar',
        onConfirmar: () => {
            modalConfirm.value = null;
            reintentando.value = logId;
            router.post(`/app/siigo/logs/${logId}/reintentar`, {}, {
                preserveScroll: true,
                onFinish: () => { reintentando.value = null; },
            });
        },
    };
};

// Semáforo global.
//   Lo primero que mira es si SIIGO contesta. Antes se armaba sólo con la
//   casilla "activo" y el kill-switch, así que con la llave vencida decía
//   "Todo funcionando" mientras cada llamada moría con 401.
const semaforo = computed(() => {
    if (! props.config.activo || ! props.config.usuario) {
        return { color: 'gray', txt: 'Sin configurar' };
    }
    if (props.config.ultimo_auth_ok === false) {
        return { color: 'red', txt: 'SIIGO rechaza las credenciales · no sale nada' };
    }
    if (props.config.ultimo_auth_ok === null) {
        return { color: 'amber', txt: 'Conexión sin probar' };
    }
    if (! props.config.push_auto) return { color: 'amber', txt: 'Push automático PAUSADO' };
    if (props.kpis.productos_hoy_fallidos > 0) return { color: 'red', txt: `${props.kpis.productos_hoy_fallidos} sync con error hoy` };
    return { color: 'emerald', txt: 'Todo funcionando' };
});

// Credenciales · sólo gerencia. La access key no viaja al navegador: en blanco
//   se conserva la que ya está guardada (encriptada) en la base.
const credForm = ref({
    usuario: props.config.usuario || '',
    access_key: '',
    partner_id: props.config.partner_id || '',
    ambiente: props.config.ambiente || 'sandbox',
});
const guardandoCred = ref(false);
const probando = ref(false);
const mostrarCredenciales = ref(props.config.ultimo_auth_ok === false || ! props.config.usuario);

const guardarCredenciales = () => {
    if (guardandoCred.value) return;
    guardandoCred.value = true;
    router.post('/app/siigo/credenciales', { ...credForm.value }, {
        preserveScroll: true,
        onSuccess: () => { credForm.value.access_key = ''; },
        onFinish: () => { guardandoCred.value = false; },
    });
};

const probarConexion = () => {
    if (probando.value) return;
    probando.value = true;
    router.post('/app/siigo/probar-conexion', {}, {
        preserveScroll: true,
        onFinish: () => { probando.value = false; },
    });
};

/*
 * Traer de SIIGO los catálogos: grupos de inventario, impuestos y listas.
 *
 * La ruta existía desde siempre —`POST /app/siigo/sincronizar-catalogos`—
 * pero el único botón que la llamaba vivía en el panel Filament, que por
 * diseño es sólo de Dropi. Mientras tanto el formulario de producto mandaba
 * al usuario «al panel SIIGO, a hacer clic en Sincronizar catálogos»: un
 * botón que en ESTA pantalla no existía.
 *
 * Sin esos catálogos `account_group` se resuelve a null por las cuatro vías
 * y SIIGO rechaza cada producto con «The field account_group is required».
 * En producción eso tenía 156 productos sin sincronizar y, detrás de ellos,
 * cada recepción y cada factura que dependiera de uno.
 */
const sincronizandoCat = ref(false);
const resumenCat = ref(null);

const sincronizarCatalogos = async () => {
    if (sincronizandoCat.value) return;
    sincronizandoCat.value = true;
    resumenCat.value = null;
    try {
        const { data } = await window.axios.post('/app/siigo/sincronizar-catalogos', {});
        if (data?.ok) {
            const r = data.resumen || {};
            resumenCat.value = `✓ ${r.account_groups ?? 0} grupos · ${r.taxes ?? 0} impuestos · ${r.price_lists ?? 0} listas`;
            router.reload({ preserveScroll: true });
        } else {
            resumenCat.value = data?.mensaje || 'No se pudo sincronizar.';
        }
    } catch (e) {
        resumenCat.value = e?.response?.data?.mensaje || 'No se pudo sincronizar.';
    } finally {
        sincronizandoCat.value = false;
    }
};

const claseSemaforo = computed(() => ({
    'text-emerald-600 bg-emerald-50 border-emerald-200': semaforo.value.color === 'emerald',
    'text-amber-600 bg-amber-50 border-amber-200': semaforo.value.color === 'amber',
    'text-red-600 bg-red-50 border-red-200': semaforo.value.color === 'red',
    'text-surface-500 bg-surface-50 border-surface-200': semaforo.value.color === 'gray',
}));

const claseEstadoLog = (l) => {
    if (l.estado === 'exitoso' || l.estado === 'reconciliado') return 'text-emerald-600';
    if (l.estado === 'fallido') return 'text-red-600';
    if (l.estado === 'omitido' || l.estado === 'ignorado') return 'text-surface-500';
    return 'text-amber-600';
};
</script>

<template>
    <Head title="SIIGO · Panel de sync"/>
    <AppLayout>
        <div class="space-y-4 max-w-6xl">

            <!-- Conexión con SIIGO · lo primero que hay que ver.
                 Si SIIGO no acepta las credenciales no sale NADA del ERP, y
                 hasta ahora eso pasaba en silencio con el panel en verde. -->
            <div v-if="config.ultimo_auth_ok === false"
                 class="card p-5 border-2 border-rose-400 bg-rose-50/60 dark:bg-rose-950/30">
                <div class="flex items-start gap-3">
                    <XCircle class="h-8 w-8 text-rose-600 shrink-0"/>
                    <div class="flex-1 min-w-0">
                        <h2 class="font-bold text-lg text-rose-800 dark:text-rose-200">SIIGO está rechazando las credenciales</h2>
                        <p class="text-sm text-surface-700 dark:text-surface-300 mt-1">
                            Mientras esto siga así <strong>no sale ni una factura, ni un pago, ni un asiento</strong>.
                            Entrá al portal de SIIGO, copiá el usuario y la <em>access key</em> vigentes y pegalos acá abajo.
                        </p>
                        <p v-if="config.ultimo_auth_error" class="text-xs font-mono mt-2 text-rose-700 dark:text-rose-300">
                            {{ config.ultimo_auth_error }}
                            <span v-if="config.ultimo_auth_at" class="text-surface-500"> · {{ config.ultimo_auth_at }}</span>
                        </p>
                        <button v-if="puede_toggle && !mostrarCredenciales" @click="mostrarCredenciales = true"
                                class="btn-primary mt-3 text-sm">
                            Cambiar credenciales
                        </button>
                    </div>
                </div>
            </div>

            <!-- Credenciales · sólo gerencia -->
            <div v-if="puede_toggle" class="card p-5">
                <button @click="mostrarCredenciales = ! mostrarCredenciales"
                        class="w-full flex items-center justify-between gap-2 text-left min-h-11">
                    <span class="font-semibold flex items-center gap-2">
                        <Cloud class="h-4 w-4 text-brand-600"/> Credenciales de SIIGO
                    </span>
                    <span class="text-xs px-2 py-1 rounded-full"
                          :class="config.ultimo_auth_ok === true ? 'bg-emerald-100 text-emerald-700'
                                 : config.ultimo_auth_ok === false ? 'bg-rose-100 text-rose-700'
                                 : 'bg-amber-100 text-amber-700'">
                        {{ config.ultimo_auth_ok === true ? 'Conectada'
                           : config.ultimo_auth_ok === false ? 'Sin conexión' : 'Sin probar' }}
                    </span>
                </button>

                <div v-if="mostrarCredenciales" class="mt-4 space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Usuario (correo)</label>
                            <input v-model="credForm.usuario" type="email" class="input w-full min-h-11" maxlength="190"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Access key</label>
                            <input v-model="credForm.access_key" type="password" autocomplete="off"
                                   class="input w-full min-h-11" placeholder="Dejala vacía para conservar la actual"/>
                            <p class="text-xs text-surface-500 mt-0.5">
                                Se guarda encriptada y no se vuelve a mostrar.
                                <span v-if="config.tiene_access_key">Hay una guardada.</span>
                            </p>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Partner ID</label>
                            <input v-model="credForm.partner_id" class="input w-full min-h-11" maxlength="120"/>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Ambiente</label>
                            <select v-model="credForm.ambiente" class="input w-full min-h-11">
                                <option value="sandbox">Sandbox (pruebas)</option>
                                <option value="produccion">Producción (facturas reales)</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button @click="guardarCredenciales" :disabled="guardandoCred || !credForm.usuario"
                                class="btn-primary text-sm disabled:opacity-40">
                            {{ guardandoCred ? 'Guardando…' : 'Guardar credenciales' }}
                        </button>
                        <button @click="probarConexion" :disabled="probando"
                                class="btn-ghost text-sm border border-surface-300 dark:border-surface-700 disabled:opacity-40">
                            <RefreshCw class="h-4 w-4" :class="probando && 'animate-spin'"/>
                            {{ probando ? 'Probando…' : 'Probar conexión' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Cola atascada · nadie está procesando los envíos a SIIGO.
                 Sin esto el silencio es invisible: el ERP encola y no sale
                 nada, sin un solo aviso en pantalla. -->
            <div v-if="cola.atascada"
                 class="card p-5 border-2 border-rose-400 bg-rose-50/60 dark:bg-rose-950/30">
                <div class="flex items-start gap-3">
                    <AlertTriangle class="h-8 w-8 text-rose-600 shrink-0"/>
                    <div class="flex-1 min-w-0">
                        <!-- Dos causas, dos destinos: la llave la arregla
                             Aracely acá mismo, el worker es soporte técnico.
                             Antes siempre decía lo segundo. -->
                        <h2 class="font-bold text-lg text-rose-800 dark:text-rose-200">
                            {{ cola.motivo === 'credencial'
                                ? 'La llave de SIIGO no está autenticando'
                                : 'Nadie está enviando los documentos a SIIGO' }}
                        </h2>
                        <p class="text-sm text-surface-700 dark:text-surface-300 mt-1">
                            Hay <strong>{{ cola.total_todas_las_colas }} documento(s) esperando</strong> y el más
                            viejo lleva <strong>{{ cola.espera_minutos }} minutos</strong> en la fila. Mientras esto
                            siga así, lo que factures y cobres queda guardado en el ERP pero <strong>no llega a
                            SIIGO</strong>.
                        </p>
                        <p v-if="cola.motivo === 'credencial'" class="text-sm text-surface-700 dark:text-surface-300 mt-2">
                            SIIGO está rechazando la credencial, así que los envíos
                            <strong>quedan en espera</strong> —no se pierden— y arrancan solos apenas
                            la llave vuelva a servir. Pegá la nueva arriba, en «Credenciales de SIIGO»,
                            y probá la conexión.
                        </p>
                        <p v-else class="text-xs text-surface-600 dark:text-surface-400 mt-2">
                            Es un proceso del servidor que debe estar siempre encendido
                            (<code class="font-mono">queue:work</code>). Avisale a soporte técnico.
                        </p>
                    </div>
                </div>
            </div>

            <!-- UBIC-7 · Wizard Setup Facturación Electrónica -->
            <div v-if="setup_fe.estado !== 'verde'"
                 :class="[
                    'card p-5 border-2',
                    setup_fe.estado === 'rojo' ? 'border-rose-400 bg-rose-50/50' : 'border-amber-400 bg-amber-50/50',
                 ]">
                <div class="flex items-start gap-3 mb-4">
                    <div :class="setup_fe.estado === 'rojo' ? 'text-rose-600' : 'text-amber-600'">
                        <Receipt class="h-8 w-8"/>
                    </div>
                    <div class="flex-1">
                        <h2 class="font-bold text-lg text-surface-800 flex items-center gap-2">
                            Setup Facturación Electrónica
                            <span v-if="setup_fe.estado === 'rojo'" class="text-xs font-normal px-2 py-0.5 rounded-full bg-rose-100 text-rose-700">
                                Falta configurar
                            </span>
                            <span v-else class="text-xs font-normal px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">
                                Casi lista
                            </span>
                        </h2>
                        <p class="text-sm text-surface-600 mt-0.5">
                            Para que las facturas salgan con el prefijo correcto a la DIAN, seguí estos pasos. El único paso que te saca del ERP es la solicitud en MUISCA (trámite legal, 1 vez cada 2 años).
                        </p>
                    </div>
                </div>

                <div class="space-y-2">
                    <!-- Paso 1 · DIAN (único offline) -->
                    <div class="flex items-start gap-3 p-3 rounded-lg border bg-white">
                        <div class="shrink-0 w-7 h-7 rounded-full bg-surface-100 text-surface-700 font-bold text-sm flex items-center justify-center">1</div>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-sm text-surface-800 flex items-center gap-2">
                                Solicitar resolución en la DIAN
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-surface-100 text-surface-500 uppercase">Legal · offline</span>
                            </div>
                            <p class="text-xs text-surface-500 mt-0.5">
                                Entrás a MUISCA con tu firma digital, pedís la resolución de FE para el prefijo que uses (ej: <code class="bg-surface-100 px-1 rounded">FV</code>, <code class="bg-surface-100 px-1 rounded">FE</code>). La DIAN tarda ~3h en aprobar y asociar prefijos.
                            </p>
                        </div>
                        <a href="https://muisca.dian.gov.co/" target="_blank" rel="noopener"
                           class="text-xs inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-sky-400 text-sky-700 hover:bg-sky-50 whitespace-nowrap">
                            Ir a MUISCA <ExternalLink class="h-3 w-3"/>
                        </a>
                    </div>

                    <!-- Paso 2 · SIIGO Servicios Electrónicos · forzar sync DIAN→SIIGO -->
                    <div class="flex items-start gap-3 p-3 rounded-lg border bg-white">
                        <div :class="['shrink-0 w-7 h-7 rounded-full font-bold text-sm flex items-center justify-center',
                            setup_fe.total_resoluciones > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-surface-100 text-surface-700']">
                            <CheckCircle v-if="setup_fe.total_resoluciones > 0" class="h-4 w-4"/>
                            <span v-else>2</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-sm text-surface-800">
                                Forzar sync DIAN → SIIGO
                                <span v-if="setup_fe.total_resoluciones > 0" class="text-emerald-600 text-xs ml-2">
                                    ✓ {{ setup_fe.total_resoluciones }} resolución(es) cargada(s)
                                </span>
                            </div>
                            <p class="text-xs text-surface-500 mt-0.5">
                                Dentro de SIIGO Servicios Electrónicos tenés el botón <strong>"Sincronizar resolución facturación electrónica"</strong>. Ejecutalo después de las 3h del paso 1 (único click fuera del ERP acá).
                            </p>
                        </div>
                        <a href="https://servicioselectronicos.siigo.com/#/dianresolution/false" target="_blank" rel="noopener"
                           class="text-xs inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-sky-400 text-sky-700 hover:bg-sky-50 whitespace-nowrap">
                            Ir a SIIGO SE <ExternalLink class="h-3 w-3"/>
                        </a>
                    </div>

                    <!-- Paso 3 · ERP · marcar default + ubicaciones -->
                    <div class="flex items-start gap-3 p-3 rounded-lg border bg-white">
                        <div :class="['shrink-0 w-7 h-7 rounded-full font-bold text-sm flex items-center justify-center',
                            setup_fe.hay_default && setup_fe.ubicaciones_sin_resolucion.length === 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-surface-100 text-surface-700']">
                            <CheckCircle v-if="setup_fe.hay_default && setup_fe.ubicaciones_sin_resolucion.length === 0" class="h-4 w-4"/>
                            <span v-else>3</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-sm text-surface-800">
                                Marcar default + asignar a ubicaciones
                            </div>
                            <p class="text-xs text-surface-500 mt-0.5">
                                <span v-if="! setup_fe.hay_default && setup_fe.total_resoluciones > 0">
                                    Marcá una resolución con <Star class="h-3 w-3 inline text-amber-500"/> para que las nuevas ubicaciones de venta la hereden.
                                </span>
                                <span v-else-if="setup_fe.hay_default && setup_fe.ubicaciones_sin_resolucion.length > 0">
                                    Default marcada ✓ — {{ setup_fe.ubicaciones_sin_resolucion.length }} ubicación(es) de venta todavía sin mapear:
                                    <span class="font-mono text-amber-700">{{ setup_fe.ubicaciones_sin_resolucion.map(u => u.codigo).join(', ') }}</span>
                                </span>
                                <span v-else-if="setup_fe.total_resoluciones === 0" class="text-surface-400">
                                    Primero completá los pasos 1 y 2.
                                </span>
                                <span v-else>Todo listo.</span>
                            </p>
                        </div>
                        <Link href="/app/inventario/ubicaciones"
                              class="text-xs inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-brand-600 text-white hover:bg-brand-700 whitespace-nowrap">
                            Ir a ubicaciones <ArrowRight class="h-3 w-3"/>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Setup FE listo → mostrar card verde minimalista -->
            <div v-else class="card p-3 border-l-4 border-emerald-500 bg-emerald-50/40 flex items-center gap-3">
                <CheckCircle class="h-5 w-5 text-emerald-600"/>
                <span class="text-sm text-emerald-800">
                    <strong>Facturación Electrónica lista.</strong>
                    {{ setup_fe.total_resoluciones }} resolución(es) · default marcada · {{ setup_fe.ubicaciones_venta_total }} ubicaciones mapeadas.
                </span>
            </div>

            <!-- Cuentas PUC configuradas vs. plan de cuentas real -->
            <div v-if="cuentasPucConProblema.length"
                 class="card p-4 border-l-4 border-amber-400 bg-amber-50/50 space-y-2">
                <div class="flex items-center gap-2 text-sm font-bold text-amber-900">
                    <AlertTriangle class="h-4 w-4"/>
                    Cuentas contables que SIIGO va a rechazar ({{ cuentasPucConProblema.length }})
                </div>
                <p class="text-xs text-amber-800">
                    El ERP arma los asientos con estas cuentas. SIIGO sólo acepta cuentas auxiliares que
                    existan en el plan, así que mientras estén así el asiento sale y vuelve rechazado.
                    Qué código corresponde lo define el contador.
                </p>
                <div class="divide-y divide-amber-200 text-sm">
                    <div v-for="c in cuentasPucConProblema" :key="c.clave"
                         class="flex items-start justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <div class="font-semibold">{{ c.etiqueta }}</div>
                            <div class="text-xs text-surface-600">{{ c.detalle }}</div>
                        </div>
                        <span class="font-mono text-xs px-2 py-0.5 rounded whitespace-nowrap"
                              :class="c.estado === 'rojo' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-900'">
                            {{ c.codigo || 'sin valor' }}
                        </span>
                    </div>
                </div>
                <p class="text-[11px] text-surface-600">
                    Se cambian en <strong>Configuración → Reglas</strong>, claves <code>siigo.cta_*</code>.
                </p>
            </div>

            <!-- Header con semáforo global -->
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Cloud class="h-6 w-6 text-brand-600"/>
                        Sincronización SIIGO
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Estado del cableado ERP ↔ SIIGO · productos, facturas, pagos, asientos.
                    </p>
                    <!-- PROD-14 · acceso al reporte de discrepancias -->
                    <Link href="/app/siigo/discrepancias"
                          class="inline-flex items-center gap-1 mt-2 text-xs text-amber-700 hover:text-amber-900 underline">
                        Ver discrepancias ERP ↔ SIIGO →
                    </Link>
                </div>
                <div :class="['px-4 py-2 border rounded-lg font-semibold text-sm', claseSemaforo]">
                    ● {{ semaforo.txt }}
                </div>
            </div>

            <!-- Kill-switch prominente -->
            <div class="card p-5" v-if="config.activo">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <div :class="config.push_auto ? 'text-emerald-600' : 'text-amber-600'">
                            <Power v-if="config.push_auto" class="h-10 w-10"/>
                            <PowerOff v-else class="h-10 w-10"/>
                        </div>
                        <div>
                            <div class="font-bold text-lg">
                                Push automático · {{ config.push_auto ? 'ENCENDIDO' : 'APAGADO' }}
                            </div>
                            <p class="text-xs text-surface-500 mt-1 max-w-md">
                                <span v-if="config.push_auto">
                                    Cada cambio de producto (crear/editar/desactivar) se envía a SIIGO en menos de 30 s.
                                    El pull sigue corriendo cada 15 min sin importar este switch.
                                </span>
                                <span v-else>
                                    Los cambios se guardan en el ERP pero NO se envían a SIIGO. Los jobs ya encolados tampoco se ejecutan.
                                    El pull sigue corriendo.
                                </span>
                            </p>
                            <p v-if="config.push_auto_updated_at" class="text-[10px] text-surface-400 mt-1">
                                Último cambio: {{ config.push_auto_updated_at }} · fuente: {{ config.push_auto_source === 'ui' ? 'desde este panel' : '.env' }}
                            </p>
                        </div>
                    </div>
                    <button v-if="puede_toggle" @click="togglear" :disabled="togglando"
                            :class="['px-6 py-3 rounded-lg font-bold text-sm transition',
                                     config.push_auto
                                       ? 'bg-amber-600 hover:bg-amber-700 text-white'
                                       : 'bg-emerald-600 hover:bg-emerald-700 text-white',
                                     togglando ? 'opacity-50 cursor-wait' : '']">
                        {{ config.push_auto ? '⏸ Apagar' : '▶ Encender' }}
                    </button>
                    <div v-else class="text-xs text-surface-400 italic">Solo Aracely puede alternar</div>
                </div>
            </div>

            <!-- Warning: SIIGO aún no configurado -->
            <div v-if="!config.activo || !config.usuario" class="card p-5 bg-amber-50 dark:bg-amber-900/20 border-amber-200">
                <div class="flex items-start gap-3">
                    <AlertCircle class="h-6 w-6 text-amber-600 flex-shrink-0 mt-0.5"/>
                    <div>
                        <div class="font-bold text-amber-900 dark:text-amber-100">SIIGO aún no configurado</div>
                        <p class="text-sm text-amber-800 dark:text-amber-200 mt-1">
                            Cuando Aracely tenga las credenciales (usuario + access key + Partner ID), se cargan
                            <button type="button" @click="mostrarCredenciales = true" class="underline font-semibold">
                                acá arriba, en «Credenciales de SIIGO»
                            </button>.
                            El código está listo; solo falta encender la conexión.
                        </p>
                    </div>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 flex items-center gap-1">
                        <CheckCircle class="h-3 w-3"/>Sincronizados HOY
                    </div>
                    <div class="text-2xl font-bold mt-1 text-emerald-600">{{ kpis.productos_hoy_exitosos }}</div>
                </div>
                <div class="card p-4" :class="kpis.productos_hoy_fallidos > 0 ? 'ring-2 ring-red-500' : ''">
                    <div class="text-xs uppercase text-surface-500 flex items-center gap-1">
                        <XCircle class="h-3 w-3"/>Errores HOY
                    </div>
                    <div class="text-2xl font-bold mt-1" :class="kpis.productos_hoy_fallidos > 0 ? 'text-red-600' : 'text-surface-400'">
                        {{ kpis.productos_hoy_fallidos }}
                    </div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 flex items-center gap-1">
                        <Package class="h-3 w-3"/>Sin sincronizar
                    </div>
                    <div class="text-2xl font-bold mt-1 text-amber-600">{{ kpis.productos_sin_siigo_id }}</div>
                    <div class="text-[10px] text-surface-400 mt-0.5">productos activos aún sin siigo_id</div>
                </div>
                <div class="card p-4">
                    <div class="text-xs uppercase text-surface-500 flex items-center gap-1">
                        <ListChecks class="h-3 w-3"/>7 días
                    </div>
                    <div class="text-2xl font-bold mt-1">{{ kpis.total_semana }}</div>
                    <div class="text-[10px] text-surface-400 mt-0.5">total operaciones sync</div>
                </div>
            </div>

            <!-- Cola en vivo + Última sync -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="card p-5">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3 flex items-center gap-2">
                        <Clock class="h-4 w-4"/>Cola de envío ({{ cola.pendientes }} pendientes)
                    </div>
                    <div v-if="!cola.proximos.length" class="text-center py-6 text-surface-500 text-sm">
                        ✅ Cola vacía. Todos los jobs procesados.
                    </div>
                    <div v-else class="space-y-1.5">
                        <div v-for="p in cola.proximos" :key="p.id"
                             class="flex items-center gap-2 py-1.5 px-2 rounded hover:bg-surface-50 dark:hover:bg-surface-800 text-sm">
                            <span class="font-mono text-xs text-surface-500 w-16">#{{ p.producto_id }}</span>
                            <span :class="['inline-block px-2 py-0.5 rounded text-[10px] font-semibold uppercase',
                                            p.accion === 'crear' ? 'bg-blue-100 text-blue-700'
                                          : p.accion === 'actualizar' ? 'bg-purple-100 text-purple-700'
                                          : 'bg-red-100 text-red-700']">
                                {{ p.accion }}
                            </span>
                            <span class="text-xs text-surface-500 ml-auto">{{ p.disponible_en }}</span>
                            <span v-if="p.attempts > 0" class="text-[10px] text-amber-600 font-semibold">
                                {{ p.attempts }} intento{{ p.attempts > 1 ? 's' : '' }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card p-5">
                    <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">
                        Última sincronización
                    </div>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between border-b border-surface-100 pb-2">
                            <dt class="text-surface-500">Productos ↓ (pull)</dt>
                            <dd class="font-mono text-xs">{{ config.sync_productos_at || 'nunca' }}</dd>
                        </div>
                        <div class="flex justify-between border-b border-surface-100 pb-2">
                            <dt class="text-surface-500">Clientes ↓</dt>
                            <dd class="font-mono text-xs">{{ config.sync_clientes_at || 'nunca' }}</dd>
                        </div>
                        <div class="flex justify-between border-b border-surface-100 pb-2">
                            <dt class="text-surface-500">Catálogos ↓</dt>
                            <dd class="font-mono text-xs">{{ config.sync_catalogos_at || 'nunca' }}</dd>
                        </div>
                        <div class="flex justify-between pt-1">
                            <dt class="text-surface-500">Ambiente</dt>
                            <dd class="font-semibold uppercase">{{ config.ambiente || '—' }}</dd>
                        </div>
                    </dl>

                    <button type="button" @click="sincronizarCatalogos" :disabled="sincronizandoCat"
                            class="btn-ghost w-full mt-4 text-xs justify-center disabled:opacity-50">
                        <RefreshCw class="w-3.5 h-3.5" :class="sincronizandoCat ? 'animate-spin' : ''"/>
                        {{ sincronizandoCat ? 'Trayendo catálogos…' : 'Sincronizar catálogos' }}
                    </button>
                    <p class="text-[11px] text-surface-500 mt-2 leading-snug">
                        Trae los grupos de inventario, los impuestos y las listas de precios.
                        Sin ellos SIIGO rechaza cada producto con
                        <span class="font-mono">«The field account_group is required»</span>.
                    </p>
                    <p v-if="resumenCat" class="text-[11px] font-mono mt-2">{{ resumenCat }}</p>
                </div>
            </div>

            <!-- B5 · Última reconciliación (job incremental/full) -->
            <div v-if="reconciliar.estado !== 'idle'" class="card p-5">
                <div class="text-xs uppercase tracking-widest font-bold mb-3 flex items-center gap-2"
                     :class="reconciliar.estado === 'fallido' ? 'text-red-600' : (reconciliar.estado === 'corriendo' ? 'text-amber-600' : 'text-emerald-600')">
                    <RefreshCw class="h-4 w-4" :class="reconciliar.estado === 'corriendo' && 'animate-spin'"/>
                    Última reconciliación · modo {{ reconciliar.modo || '—' }} · estado {{ reconciliar.estado }}
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
                    <div><div class="text-xs text-surface-500">Nuevos</div><div class="font-bold">{{ reconciliar.resumen?.nuevos ?? '—' }}</div></div>
                    <div><div class="text-xs text-surface-500">Actualizados</div><div class="font-bold">{{ reconciliar.resumen?.actualizados ?? '—' }}</div></div>
                    <div><div class="text-xs text-surface-500">Linkeados</div><div class="font-bold">{{ reconciliar.resumen?.linkeados ?? '—' }}</div></div>
                    <div><div class="text-xs text-surface-500">Errores</div><div class="font-bold text-amber-700">{{ reconciliar.resumen?.errores ?? '—' }}</div></div>
                    <div><div class="text-xs text-surface-500">Hace</div><div class="font-bold">{{ reconciliar.hace || '—' }}</div></div>
                </div>
                <p v-if="reconciliar.resumen?.desde" class="mt-2 text-xs text-surface-500">
                    Desde: <code class="text-xs">{{ reconciliar.resumen.desde }}</code>
                </p>
                <p v-if="reconciliar.error" class="mt-2 text-xs text-red-600">{{ reconciliar.error }}</p>
            </div>

            <!-- INV-A3 · Movimientos kardex pendientes de SIIGO -->
            <!-- UBIC-10 · Export saldos iniciales SIIGO -->
            <div class="card p-5 border-2 border-sky-300 bg-sky-50/40">
                <div class="flex items-start gap-4">
                    <div class="text-sky-600 shrink-0">
                        <Warehouse class="h-8 w-8"/>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-bold text-sky-900 mb-1 flex items-center gap-2">
                            Saldos iniciales de inventario → SIIGO
                            <span class="text-[10px] font-normal px-2 py-0.5 rounded-full bg-sky-100 text-sky-700">
                                Formato nativo "Ingresar saldos por Excel"
                            </span>
                        </h3>
                        <p class="text-sm text-surface-600 mb-3">
                            Descargá el inventario valorizado actual con el layout que acepta la pantalla
                            <strong>Saldos iniciales de inventario</strong> de SIIGO. Marcá allá la casilla
                            <em>"Ingresar saldos por Excel"</em> y pegá las columnas <code class="text-xs bg-white border px-1 rounded">A-D</code>.
                            Las instrucciones completas vienen en la hoja <em>"Cómo cargarlo en SIIGO"</em> del archivo.
                        </p>
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="/app/siigo/saldos-iniciales.xlsx"
                               class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-sky-600 text-white hover:bg-sky-700">
                                <Warehouse class="h-4 w-4"/> Descargar Excel · solo bodegas de venta
                            </a>
                            <a href="/app/siigo/saldos-iniciales.xlsx?categorias=venta,garantia,averia_reparar,averia_baja,cuarentena"
                               class="text-xs inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-sky-500 text-sky-700 hover:bg-sky-50">
                                Descargar TODO (incluye avería/cuarentena)
                            </a>
                            <a href="https://siigonube.siigo.com/#/initial-balance-inventory"
                               target="_blank" rel="noopener"
                               class="text-xs inline-flex items-center gap-1 px-3 py-2 rounded-lg border text-surface-700 hover:bg-white">
                                Abrir SIIGO · pantalla de saldos iniciales ↗
                            </a>
                        </div>
                        <p class="text-[11px] text-amber-700 mt-2">
                            ⚠ Solo exporta ubicaciones mapeadas a una warehouse SIIGO (<code>siigo_id ≠ null</code>). Si falta alguna, primero creala en el CRUD de Ubicaciones.
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="movs_pendientes_siigo.total > 0"
                 class="card p-5 border-2 border-amber-400 bg-amber-50/40">
                <div class="flex items-start justify-between gap-3 mb-3">
                    <div class="text-xs uppercase tracking-widest font-bold text-amber-700 flex items-center gap-2">
                        <Warehouse class="h-4 w-4"/>
                        Movimientos kardex sin asiento SIIGO ({{ movs_pendientes_siigo.total }})
                    </div>
                    <span class="text-[10px] text-amber-700/70">traslado · ajuste toma · merma · sobrante</span>
                </div>
                <div class="grid grid-cols-4 gap-2 mb-3 text-center text-xs">
                    <div class="p-2 rounded bg-white border">
                        <div class="font-bold text-amber-700">{{ movs_pendientes_siigo.por_antiguedad.hoy || 0 }}</div>
                        <div class="text-surface-500">últimas 24h</div>
                    </div>
                    <div class="p-2 rounded bg-white border">
                        <div class="font-bold text-amber-700">{{ movs_pendientes_siigo.por_antiguedad.esta_semana || 0 }}</div>
                        <div class="text-surface-500">esta semana</div>
                    </div>
                    <div class="p-2 rounded bg-white border">
                        <div class="font-bold text-amber-700">{{ movs_pendientes_siigo.por_antiguedad.este_mes || 0 }}</div>
                        <div class="text-surface-500">este mes</div>
                    </div>
                    <div class="p-2 rounded bg-white border">
                        <div class="font-bold text-rose-600">{{ movs_pendientes_siigo.por_antiguedad.mas_viejos || 0 }}</div>
                        <div class="text-surface-500">más viejos</div>
                    </div>
                </div>
                <div class="space-y-1">
                    <div v-for="ej in movs_pendientes_siigo.ejemplos" :key="ej.id"
                         class="flex items-center gap-3 py-1.5 px-3 rounded border border-amber-200 bg-white text-sm">
                        <span class="font-mono text-xs text-surface-500">#{{ ej.id }}</span>
                        <span class="font-semibold text-xs uppercase">{{ ej.tipo }}</span>
                        <span class="text-xs text-surface-600">ubicación {{ ej.ubicacion }}</span>
                        <span class="flex-1"></span>
                        <span class="text-xs font-mono">${{ Math.round(ej.valor).toLocaleString() }}</span>
                        <span class="text-[10px] text-surface-500">{{ ej.hace }}</span>
                    </div>
                </div>
            </div>

            <!-- PROD-15 · ALERTA DE FALLA PERMANENTE · productos que fallaron ≥3 veces en 7 días -->
            <div v-if="fallas_permanentes.length"
                 class="card p-5 border-2 border-rose-500 bg-rose-50/40 dark:bg-rose-900/10">
                <div class="flex items-start justify-between gap-3 mb-3">
                    <div class="text-xs uppercase tracking-widest font-bold text-rose-700 flex items-center gap-2">
                        <AlertTriangle class="h-4 w-4 animate-pulse"/>
                        Falla permanente ({{ fallas_permanentes.length }})
                    </div>
                    <span class="text-[10px] text-rose-700/70">
                        ≥3 intentos fallidos en los últimos 7 días
                    </span>
                </div>
                <p class="text-xs text-rose-700/80 mb-3">
                    Estos productos se intentaron sincronizar varias veces y SIIGO los rechazó todas.
                    Normalmente es un campo faltante (categoría, impuesto) o un error en la ficha. Abrí el producto para revisar.
                </p>
                <div class="space-y-1.5">
                    <Link v-for="f in fallas_permanentes" :key="f.producto_id"
                          :href="f.ya_no_existe ? '#' : `/app/catalogo/productos/${f.producto_id}`"
                          class="flex items-center gap-3 py-2 px-3 rounded border border-rose-200 bg-white hover:bg-rose-50 text-sm transition">
                        <AlertTriangle class="h-4 w-4 text-rose-600 flex-shrink-0"/>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-xs">
                                <span class="font-mono text-brand-700">{{ f.referencia }}</span>
                                <span class="ml-2 text-surface-700">{{ f.nombre }}</span>
                                <span v-if="f.ya_no_existe" class="ml-2 text-[10px] uppercase text-surface-400">eliminado</span>
                            </div>
                            <div class="text-[11px] text-surface-500 truncate">{{ f.ultimo_mensaje }}</div>
                        </div>
                        <div class="text-right">
                            <div class="inline-block px-2 py-0.5 rounded bg-rose-600 text-white text-[10px] font-bold">
                                {{ f.intentos }} intentos
                            </div>
                            <div class="text-[10px] text-surface-500 mt-0.5">{{ f.ultima_falla }}</div>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Últimos fallidos con botón reintentar -->
            <div class="card p-5" v-if="fallidos_recientes.length">
                <div class="text-xs uppercase tracking-widest font-bold text-red-600 mb-3 flex items-center gap-2">
                    <Ban class="h-4 w-4"/>Sincronizaciones fallidas recientes ({{ fallidos_recientes.length }})
                </div>
                <div class="space-y-1.5">
                    <div v-for="f in fallidos_recientes" :key="f.id"
                         class="flex items-center gap-3 py-2 px-3 rounded border border-red-100 dark:border-red-900/40 bg-red-50/30 dark:bg-red-900/10 text-sm">
                        <XCircle class="h-4 w-4 text-red-600 flex-shrink-0"/>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-xs">
                                Producto #{{ f.producto_id }} · acción <span class="uppercase">{{ f.accion }}</span>
                                <span v-if="f.http_status" class="ml-2 text-red-600 font-mono">HTTP {{ f.http_status }}</span>
                            </div>
                            <div class="text-[11px] text-surface-500 truncate">{{ f.mensaje }}</div>
                        </div>
                        <span class="text-[10px] text-surface-500">{{ f.hace }}</span>
                        <button @click="reintentar(f.id)" :disabled="reintentando === f.id"
                                class="px-3 py-1 text-xs font-semibold rounded bg-brand-600 hover:bg-brand-700 text-white inline-flex items-center gap-1 transition"
                                :class="reintentando === f.id ? 'opacity-50 cursor-wait' : ''">
                            <RefreshCw :class="['h-3 w-3', reintentando === f.id ? 'animate-spin' : '']"/>
                            Reintentar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Bitácora reciente -->
            <div class="card p-5">
                <div class="text-xs uppercase tracking-widest font-bold text-brand-600 mb-3">
                    Últimas {{ logs.length }} operaciones
                </div>
                <div v-if="!logs.length" class="text-center py-10 text-surface-500 text-sm">
                    Sin sincronizaciones aún. Cuando llegue una acción a la cola aparecerá acá.
                </div>
                <table v-tabla-movil v-else class="w-full text-sm">
                    <thead class="text-[10px] text-surface-500 uppercase border-b">
                        <tr>
                            <th class="text-left p-2">Hora</th>
                            <th class="text-left p-2">Recurso</th>
                            <th class="text-left p-2">Estado</th>
                            <th class="text-right p-2">Nuevos</th>
                            <th class="text-right p-2">Actualizados</th>
                            <th class="text-right p-2">Errores</th>
                            <th class="text-right p-2">Duración</th>
                            <th class="text-left p-2">Mensaje</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="l in logs" :key="l.id" class="hover:bg-surface-50 dark:hover:bg-surface-800">
                            <td class="p-2 text-xs text-surface-500 whitespace-nowrap">{{ l.hace }}</td>
                            <td class="p-2 font-mono text-xs">{{ l.recurso }}</td>
                            <td class="p-2"><span :class="['text-xs font-semibold uppercase', claseEstadoLog(l)]">{{ l.estado }}</span></td>
                            <td class="p-2 text-right">{{ l.nuevos || '—' }}</td>
                            <td class="p-2 text-right">{{ l.actualizados || '—' }}</td>
                            <td class="p-2 text-right" :class="l.errores > 0 ? 'text-red-600 font-bold' : ''">{{ l.errores || '—' }}</td>
                            <td class="p-2 text-right text-xs text-surface-500">{{ l.duracion_ms }}ms</td>
                            <td class="p-2 text-xs text-surface-500 truncate max-w-md" :title="l.mensaje">{{ l.mensaje }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
