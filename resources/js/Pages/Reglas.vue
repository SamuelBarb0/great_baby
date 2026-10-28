<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Save, Sliders, RotateCcw, Search, AlertTriangle } from 'lucide-vue-next';
import { useEventListener } from '@vueuse/core';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const props = defineProps({
    grupos: { type: Object, required: true },
    gruposLabels: { type: Object, required: true },
    // clave de regla → familia de document-type de SIIGO (NC, ND, CC, …)
    familiasDocumento: { type: Object, default: () => ({}) },
});

/**
 * Comprobantes reales de la cuenta de SIIGO, agrupados por familia.
 *
 * Los tipos de documento se piden una vez al entrar. Hasta que lleguen, el
 * desplegable sale con la opción «sin elegir» y, si ya había un id guardado,
 * con ese id: así la casilla nunca miente sobre lo que hay configurado.
 */
const documentos = ref({});
const docsError = ref('');

onMounted(async () => {
    if (! Object.keys(props.familiasDocumento).length) return;
    try {
        const r = await fetch('/app/siigo/document-types', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (! r.ok) throw new Error('HTTP ' + r.status);
        documentos.value = (await r.json()).grupos || {};
    } catch (e) {
        docsError.value = 'No pude traer los comprobantes de SIIGO. Los tipos de documento quedan como están.';
        console.error('[reglas] document-types', e);
    }
});

/** ¿El id guardado figura entre los comprobantes que devolvió SIIGO? */
const estaEnLista = (r) => {
    const familia = props.familiasDocumento[r.clave];
    return (documentos.value[familia] || []).some((d) => Number(d.id) === Number(r.valor));
};

// Confirmaciones con el modal propio: el confirm() nativo queda bloqueado
// dentro del iframe de la app de escritorio y en celular ignora el diseno.
const modalConfirm = ref(null);

/**
 * Las reglas de tipo `json` llegan ya decodificadas del servidor.
 *
 * Puestas tal cual en una casilla se verían como «[object Object]» y guardar
 * escribiría ese texto encima del valor real. Se pasan a texto JSON para que
 * la casilla muestre exactamente lo que hay; el backend acepta la cadena.
 */
const aTexto = (grupos) => {
    const copia = JSON.parse(JSON.stringify(grupos));
    for (const grupo in copia) {
        for (const r of copia[grupo]) {
            if (r.tipo === 'json' && typeof r.valor !== 'string') {
                r.valor = JSON.stringify(r.valor ?? {}, null, 0);
            }
        }
    }
    return copia;
};

// Estado local editable.
const originales = ref(aTexto(props.grupos));
const editable = reactive(aTexto(props.grupos));
const guardando = ref(false);
const busqueda = ref('');

// Solo re-hidratamos las CLAVES que efectivamente se enviaron a guardar.
// Si Aracely edita otras reglas mientras el POST está en vuelo, esas no se pisan.
const clavesEnvio = ref(new Set());  // populated en guardar() antes de POST
watch(() => props.grupos, (nuevos) => {
    if (! clavesEnvio.value.size && modificadas.value.length) return; // refresh externo con edits → no pisar

    // Merge selectivo: solo reglas cuya clave estaba en el POST recién guardado.
    const limpios = aTexto(nuevos);
    Object.keys(limpios).forEach(grupo => {
        if (! editable[grupo]) editable[grupo] = [];
        limpios[grupo].forEach((nueva, i) => {
            const enEdit = editable[grupo][i];
            if (clavesEnvio.value.has(nueva.clave) || ! enEdit) {
                editable[grupo][i] = JSON.parse(JSON.stringify(nueva));
            }
        });
    });
    originales.value = JSON.parse(JSON.stringify(limpios));
    clavesEnvio.value = new Set(); // limpiar tras aplicar
}, { deep: true });

const modificadas = computed(() => {
    const cambios = [];
    for (const grupo in editable) {
        for (const i in editable[grupo]) {
            const nuevo = editable[grupo][i];
            const viejo = originales.value[grupo]?.[i];
            if (! viejo) continue;
            if (String(nuevo.valor) !== String(viejo.valor)) {
                cambios.push({ clave: nuevo.clave, valor: nuevo.valor });
            }
        }
    }
    return cambios;
});

// Guard beforeunload — evita perder cambios al cerrar/navegar externamente.
useEventListener(typeof window !== 'undefined' ? window : null, 'beforeunload', (e) => {
    if (modificadas.value.length) {
        e.preventDefault();
        e.returnValue = '';
    }
});
// Guard navegación Inertia — Aracely click en otro item del sidebar.
//
// Acá iba un confirm() nativo porque Inertia necesita la respuesta en el acto,
// pero queda bloqueado dentro del iframe de la app de escritorio: la
// navegación pasaba de largo y los cambios se perdían sin aviso. Ahora se
// cancela siempre el viaje, se pregunta con el modal y, si dice que sí, se
// repite el mismo destino con la guarda desactivada.
let offBefore = null;
let saliendoSinGuardar = false;
onMounted(() => {
    offBefore = router.on('before', (event) => {
        if (saliendoSinGuardar) {
            saliendoSinGuardar = false;
            return;
        }
        if (! modificadas.value.length) return;
        if (event.detail.visit.url.pathname === '/app/reglas') return;

        const visita = event.detail.visit;
        const cuantos = modificadas.value.length;
        event.preventDefault();
        modalConfirm.value = {
            titulo: `Tenés ${cuantos} cambio${cuantos === 1 ? '' : 's'} sin guardar`,
            mensaje: 'Si salís ahora se descartan.',
            color: 'amber',
            textoConfirmar: 'Descartar y salir',
            onConfirmar: () => {
                modalConfirm.value = null;
                saliendoSinGuardar = true;
                router.visit(visita.url, {
                    method: visita.method,
                    data: visita.data,
                    replace: visita.replace,
                    preserveState: visita.preserveState,
                    preserveScroll: visita.preserveScroll,
                });
            },
        };
    });
});
onBeforeUnmount(() => { offBefore?.(); });

// Validación cliente antes de enviar. Retorna array de errores por clave.
const validar = () => {
    const errs = [];
    for (const c of modificadas.value) {
        // Buscar tipo en editable
        let tipo = null;
        for (const g in editable) {
            const found = editable[g].find(r => r.clave === c.clave);
            if (found) { tipo = found.tipo; break; }
        }
        if (tipo === 'int' && (c.valor === '' || c.valor === null || isNaN(Number(c.valor)) || !Number.isInteger(Number(c.valor)))) {
            errs.push(`${c.clave}: debe ser entero`);
        }
        if (tipo === 'float' && (c.valor === '' || c.valor === null || isNaN(Number(c.valor)))) {
            errs.push(`${c.clave}: debe ser número`);
        }
        if ((tipo === 'int' || tipo === 'float') && Number(c.valor) < 0) {
            errs.push(`${c.clave}: no puede ser negativo`);
        }
    }
    return errs;
};

const erroresCliente = ref([]);

const guardar = () => {
    if (guardando.value || ! modificadas.value.length) return;
    const errs = validar();
    if (errs.length) {
        erroresCliente.value = errs;
        return;
    }
    erroresCliente.value = [];
    guardando.value = true;
    // Snapshot: sólo estas claves se re-hidratan desde el server.
    // Reglas editadas en vuelo (mientras el POST viaja) NO se pisan.
    const payload = modificadas.value;
    clavesEnvio.value = new Set(payload.map(p => p.clave));
    router.post('/app/reglas', { reglas: payload }, {
        preserveScroll: true,
        onFinish: () => { guardando.value = false; },
        onError: (errors) => {
            clavesEnvio.value = new Set();
            erroresCliente.value = Object.values(errors).flat().map(String);
        },
    });
};

const resetearTodo = () => {
    modalConfirm.value = {
        titulo: '¿Descartar todos los cambios sin guardar?',
        mensaje: 'Las reglas vuelven a como estaban la última vez que se guardó.',
        color: 'amber',
        textoConfirmar: 'Descartar',
        onConfirmar: () => {
            modalConfirm.value = null;
            Object.assign(editable, JSON.parse(JSON.stringify(originales.value)));
            erroresCliente.value = [];
        },
    };
};

const labelGrupo = (g) => props.gruposLabels[g] || g;

/*
 * Selector de área.
 *
 * El buscador solo no alcanzaba: con 94 reglas y una caja vacía hay que
 * ADIVINAR qué escribir, y quien entra por primera vez no sabe qué se puede
 * configurar. Las reglas ya venían agrupadas por área; lo que faltaba era
 * poder elegir una y ver nada más esa.
 *
 * El buscador se queda para cuando ya se sabe la palabra.
 */
const grupoSel = ref('');

const areas = computed(() =>
    Object.keys(editable)
        .map((g) => ({ clave: g, label: labelGrupo(g), cuantas: editable[g].length }))
        .sort((a, b) => a.label.localeCompare(b.label, 'es')),
);

// Filtro: primero el área elegida, después el texto (clave, etiqueta o descripción).
const gruposFiltrados = computed(() => {
    const q = busqueda.value.trim().toLowerCase();

    const base = grupoSel.value && editable[grupoSel.value]
        ? { [grupoSel.value]: editable[grupoSel.value] }
        : editable;

    if (! q) return base;

    const out = {};
    for (const g in base) {
        const filas = base[g].filter(r =>
            r.clave.toLowerCase().includes(q)
            || r.etiqueta.toLowerCase().includes(q)
            || (r.descripcion || '').toLowerCase().includes(q)
        );
        if (filas.length) out[g] = filas;
    }
    return out;
});

const mostradas = computed(() =>
    Object.values(gruposFiltrados.value).reduce((s, g) => s + g.length, 0),
);

const hayResultados = computed(() => Object.keys(gruposFiltrados.value).length > 0);
const totalReglas = computed(() =>
    Object.values(editable).reduce((sum, g) => sum + g.length, 0)
);
</script>

<template>
    <Head title="Reglas de Negocio"/>
    <AppLayout>
        <div class="space-y-6 max-w-6xl">
            <!-- Encabezado -->
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2 text-surface-900 dark:text-surface-100">
                        <Sliders class="h-6 w-6 text-brand-600"/>
                        Reglas de Negocio
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Ajustá umbrales y parámetros de todo el ERP.
                        Los cambios impactan de inmediato en todo el sistema.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="modificadas.length"
                        @click="resetearTodo"
                        class="btn-secondary text-sm"
                        :disabled="guardando"
                    >
                        <RotateCcw class="h-4 w-4"/> Descartar
                    </button>
                    <button
                        @click="guardar"
                        :disabled="guardando || !modificadas.length"
                        class="btn-primary disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <Save class="h-4 w-4"/>
                        <span v-if="guardando">Guardando…</span>
                        <span v-else-if="modificadas.length">Guardar ({{ modificadas.length }})</span>
                        <span v-else>Sin cambios</span>
                    </button>
                </div>
            </div>

            <!--
                Elegir área + buscar.

                El selector va primero porque es el que responde «¿qué puedo
                configurar acá?». El buscador sirve cuando ya se sabe la
                palabra, y antes era lo único que había.
            -->
            <div class="flex gap-2 flex-wrap items-start">
                <select v-model="grupoSel" class="input w-full sm:w-72" aria-label="Área de la regla">
                    <option value="">Todas las áreas ({{ totalReglas }})</option>
                    <option v-for="a in areas" :key="a.clave" :value="a.clave">
                        {{ a.label }} ({{ a.cuantas }})
                    </option>
                </select>

                <div class="relative flex-1 min-w-[16rem]">
                    <Search class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400" aria-hidden="true"/>
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="…o buscar por nombre, clave o descripción"
                        aria-label="Buscar regla"
                        class="input w-full pl-10"
                    />
                </div>
            </div>

            <div v-if="busqueda || grupoSel" class="text-xs text-surface-500 -mt-2">
                {{ hayResultados ? `${mostradas} de ${totalReglas} reglas` : 'Sin resultados' }}
                <button v-if="grupoSel || busqueda" @click="grupoSel = ''; busqueda = ''"
                    class="underline ml-1">ver todas</button>
            </div>

            <!-- Errores validación cliente -->
            <div v-if="erroresCliente.length" class="p-3 rounded-lg bg-red-500/10 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">
                <div class="font-bold flex items-center gap-2"><AlertTriangle class="h-4 w-4"/> No se pudo guardar:</div>
                <ul class="list-disc list-inside mt-1 space-y-0.5">
                    <li v-for="err in erroresCliente" :key="err">{{ err }}</li>
                </ul>
            </div>

            <!-- Flash success/warning -->
            <div v-if="$page.props.flash?.success" class="p-3 rounded-lg bg-emerald-500/15 border-l-4 border-emerald-500 text-emerald-700 dark:text-emerald-300">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.warning" class="p-3 rounded-lg bg-amber-500/15 border-l-4 border-amber-500 text-amber-700 dark:text-amber-300 text-sm">
                {{ $page.props.flash.warning }}
            </div>

            <!-- Empty state -->
            <div v-if="!totalReglas" class="card p-12 text-center border-2 border-dashed">
                <div class="text-5xl">⚙️</div>
                <div class="text-lg text-surface-500 mt-3">No hay reglas configuradas.</div>
                <div class="text-xs text-surface-500 mt-1">Contactá al equipo técnico — este panel debería tener reglas sembradas.</div>
            </div>

            <!--
                Si no se pudieron traer los comprobantes de SIIGO hay que
                decirlo: si no, los desplegables salen vacíos y parece que la
                cuenta no tuviera tipos de documento configurados.
            -->
            <div v-if="docsError"
                 class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                {{ docsError }}
            </div>

            <!-- Grupos -->
            <div v-for="(reglas, grupo) in gruposFiltrados" :key="grupo" class="card p-5">
                <h2 class="text-lg font-bold mb-1">{{ labelGrupo(grupo) }}</h2>
                <div class="text-xs text-surface-500 mb-4 uppercase tracking-wider">{{ reglas.length }} parámetros</div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div v-for="r in reglas" :key="r.clave" class="p-3 rounded-lg border border-surface-200 dark:border-surface-800 bg-surface-50 dark:bg-surface-900">
                        <!-- Bool: label envuelve el checkbox y su leyenda -->
                        <template v-if="r.tipo === 'bool'">
                            <div class="text-sm font-semibold text-surface-800 dark:text-surface-200">{{ r.etiqueta }}</div>
                            <div v-if="r.descripcion" class="text-xs text-surface-500 mt-0.5 mb-2">{{ r.descripcion }}</div>
                            <div v-else class="mb-2"></div>
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="r.valor" class="h-5 w-5 rounded border-surface-300 text-brand-600 focus:ring-brand-500"/>
                                <span class="text-sm">{{ r.valor ? 'Activado' : 'Desactivado' }}</span>
                            </label>
                        </template>

                        <!-- Numérico o string: label estándar -->
                        <template v-else>
                            <label :for="'regla-' + r.clave" class="block text-sm font-semibold text-surface-800 dark:text-surface-200">
                                {{ r.etiqueta }}
                            </label>
                            <div v-if="r.descripcion" class="text-xs text-surface-500 mt-0.5 mb-2">{{ r.descripcion }}</div>
                            <div v-else class="mb-2"></div>

                            <!--
                                Tipos de documento de SIIGO: desplegable con los
                                comprobantes reales de la cuenta. Antes era una
                                casilla numérica y había que adivinar un id entre
                                59 notas crédito que no se ven desde acá.
                            -->
                            <select
                                v-if="familiasDocumento[r.clave]"
                                :id="'regla-' + r.clave"
                                v-model.number="r.valor"
                                class="input w-full">
                                <option :value="0">— sin elegir —</option>
                                <option v-for="d in (documentos[familiasDocumento[r.clave]] || [])"
                                        :key="d.id" :value="d.id">{{ d.label }}</option>
                                <!--
                                    Si el valor guardado no está en la lista
                                    (comprobante borrado o inactivo en SIIGO) se
                                    agrega igual, para que la casilla muestre lo
                                    que de verdad hay y guardar no lo borre.
                                -->
                                <option v-if="r.valor && ! estaEnLista(r)" :value="r.valor">
                                    {{ r.valor }} · no figura en SIIGO
                                </option>
                            </select>

                            <input
                                v-else-if="r.tipo === 'int' || r.tipo === 'float'"
                                :id="'regla-' + r.clave"
                                type="number"
                                :step="r.tipo === 'float' ? '0.01' : '1'"
                                min="0"
                                v-model.number="r.valor"
                                class="input w-full font-mono tabular-nums"
                            />
                            <!-- JSON: campo ancho, porque un mapa no entra en una línea. -->
                            <textarea
                                v-else-if="r.tipo === 'json'"
                                :id="'regla-' + r.clave"
                                v-model="r.valor"
                                rows="2"
                                spellcheck="false"
                                class="input w-full font-mono text-xs"
                            ></textarea>

                            <input
                                v-else
                                :id="'regla-' + r.clave"
                                type="text"
                                v-model="r.valor"
                                class="input w-full"
                            />
                        </template>

                        <div class="mt-1 text-xs text-surface-400 font-mono">{{ r.clave }}</div>
                    </div>
                </div>
            </div>

            <div class="text-xs text-surface-500 text-center py-4">
                💡 Los cambios se propagan a todo el ERP (workers incluidos) en cuanto guardás.
            </div>
        </div>
        <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
    </AppLayout>
</template>
