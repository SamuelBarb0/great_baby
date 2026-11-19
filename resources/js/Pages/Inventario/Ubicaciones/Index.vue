<script setup>
import { ref, computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Warehouse, Plus, Pencil, Trash2, Power, PowerOff, Cloud, CloudOff, Upload, FileText, Receipt, RefreshCw, Star, UserPlus, ShieldCheck, AlertTriangle } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import { mensajeDeError } from '@/composables/useMensajeError';
import { useEscClose } from '@/composables/useEscClose';
import axios from 'axios';

const props = defineProps({
    ubicaciones: { type: Array, required: true },
    categorias: { type: Array, required: true },
    resoluciones: { type: Array, default: () => [] },
    warehouses_siigo: { type: Array, default: () => [] },
    resolucion_default_id: { type: Number, default: null },
    candidatos_admin: { type: Array, default: () => [] },
    // Activas sin ciudad: ningún admin de bodega las puede operar.
    sin_sede: { type: Number, default: 0 },
    bodegas: { type: Array, default: () => [] },
    // El jefe de bodega puede decir quién responde por sus estantes.
    puede_asignar_responsable: { type: Boolean, default: false },
    // Null = Gerencia, que puede sobre todas.
    mis_ubicaciones: { type: Array, default: null },
    // Crear/editar/activar/borrar la ubicación, empujarla a SIIGO y traer las
    // resoluciones DIAN son de Gerencia: tocan el enlace con SIIGO, la
    // resolución y las cuentas del PUC. Sin esta bandera la pantalla ofrecía
    // los seis controles a cualquiera que pudiera MIRARLA —al jefe de bodega
    // también— y el servidor los cerraba con 403; dos de ellos, activar y
    // borrar, ni siquiera avisaban: el click no hacía nada.
    puede_editar_la_ubicacion: { type: Boolean, default: false },
});

const modal = ref(null);

/*
 * Escape cierra el modal.
 *
 * El composable ya existía y lo usaban las siete modales de Compras; las de
 * logística no. Quien está con el teclado —contando con una mano— no debería
 * tener que buscar la X con el mouse. `useEscClose` espera un booleano y éste
 * guarda 'nueva' | 'editar' | null, así que se traduce.
 */
useEscClose(computed({
    get: () => modal.value !== null,
    set: (v) => { if (! v) modal.value = null; },
}));
const modalConfirm = ref(null);
const guardando = ref(false);
const flash = ref(null);

/*
 * El error de guardar tiene que verse DENTRO del modal.
 *
 * Iba al banner de arriba de la página, que no tiene `position` ni `z-index`,
 * mientras el modal es `fixed inset-0 z-50`: el mensaje se pintaba literalmente
 * DEBAJO del modal. Y el modal no se cierra cuando falla —a propósito, para no
 * perder lo escrito—, así que la persona veía el botón volver a habilitarse,
 * nada más, y reintentaba lo mismo. Pasaba con cada rechazo del servidor:
 * código repetido, categoría que no vende, responsable sin papel de bodega.
 */
const errorModal = ref(null);
// UBIC-4 · listado reactivo de resoluciones (se refresca sin reload tras sync).
const resolucionesList = ref([...props.resoluciones]);
const sincronizandoResol = ref(false);
// UBIC-6 · default actual (reactivo, se actualiza sin reload).
const defaultResolId = ref(props.resolucion_default_id);
// UBIC-8 · lista reactiva de candidatos admin (crecerá al crear uno nuevo in-situ).
const candidatosAdmin = ref([...props.candidatos_admin]);
/*
 * El jefe de bodega crea a su gente desde acá.
 *
 * Antes sólo se podía crear un AdminBodega, y era de Gerencia. Pedirle a
 * Gerencia que cree al alistador que entró el lunes terminaba en estantes sin
 * responsable durante semanas, que es exactamente por lo que los alistadores
 * tenían la cola vacía.
 */
/*
 * Dos sitios crean gente de bodega: el panel de la cabecera y el botón
 * «Nuevo» de dentro del formulario de la ubicación. Comparten los campos a
 * propósito —es el mismo formulario— pero NO la bandera de abierto: con una
 * sola, abrir uno abría el otro, y el de la cabecera quedaba desplegado
 * detrás del formulario, con las mismas casillas duplicadas en pantalla.
 */
const nuevoAdmin = reactive({ abierto: false, abiertoEnForm: false, name: '', email: '', password: '', rol: 'Alistador', creando: false, passGenerado: null });

const vacio = () => ({
    id: null, codigo: '', nombre: '', categoria: 'venta', activa: true,
    direccion: '', ciudad: '', responsable_user_id: null, notas: '',
    bodega_id: null, pasillo: '', estante: '', nivel: '',
    siigo_id: null,
    // UBIC-6 · pre-selección de resolución default al crear una ubicación nueva.
    siigo_resolution_id: defaultResolId.value || null,
    siigo_resolution_name: '',
    siigo_resolution_prefix: '', cta_inventario: '', cta_costo: '',
});
const form = reactive(vacio());

const abrirNueva = () => { Object.assign(form, vacio()); errorModal.value = null; modal.value = 'nueva'; };
const abrirEditar = (u) => {
    errorModal.value = null;
    Object.assign(form, vacio(), {
        id: u.id, codigo: u.codigo, nombre: u.nombre, categoria: u.categoria,
        activa: u.activa, direccion: u.direccion, ciudad: u.ciudad,
        responsable_user_id: u.responsable_user_id, notas: u.notas,
        bodega_id: u.bodega_id ?? null, pasillo: u.pasillo || '', estante: u.estante || '', nivel: u.nivel || '',
        siigo_id: u.siigo_id, siigo_resolution_id: u.siigo_resolution_id,
        siigo_resolution_name: u.siigo_resolution_name,
        siigo_resolution_prefix: u.siigo_resolution_prefix,
        cta_inventario: u.cta_inventario ?? '',
        cta_costo: u.cta_costo ?? '',
    });
    modal.value = 'editar';
};

const resolucionElegida = computed(() => resolucionesList.value.find(r => r.id === form.siigo_resolution_id));

// UBIC-6 · marca/desmarca una resolución como default para auto-asignación.
const marcarDefault = async (resol) => {
    try {
        const nuevaId = defaultResolId.value === resol.id ? null : resol.id;
        const { data } = await axios.post('/app/inventario/ubicaciones/resolucion-default', {
            siigo_resolution_id: nuevaId,
        });
        if (data.ok) {
            defaultResolId.value = nuevaId;
            mostrarFlash('success', data.mensaje);
        }
    } catch (e) {
        mostrarFlash('error', mensajeDeError(e, 'No pude marcar la resolucion por defecto'));
    }
};

// UBIC-4 · trae las resoluciones DIAN de SIIGO sin recargar la página.
const sincronizarResoluciones = async () => {
    sincronizandoResol.value = true;
    try {
        const { data } = await axios.post('/app/inventario/ubicaciones/sync-resoluciones');
        if (data.ok) {
            resolucionesList.value = data.resoluciones;
            mostrarFlash('success', data.mensaje);
        } else {
            mostrarFlash('error', data.mensaje || 'No se pudieron traer las resoluciones.');
        }
    } catch (e) {
        mostrarFlash('error', mensajeDeError(e, 'No pude traer las resoluciones de SIIGO'));
    } finally { sincronizandoResol.value = false; }
};

const guardar = async () => {
    guardando.value = true;
    try {
        // Si la resolución fue elegida de la lista, cacheamos nombre+prefix.
        if (resolucionElegida.value) {
            form.siigo_resolution_name = resolucionElegida.value.nombre;
            form.siigo_resolution_prefix = resolucionElegida.value.prefix;
        }
        const { data } = await axios.post('/app/inventario/ubicaciones', form);
        if (data.ok) {
            modal.value = null;
            mostrarFlash('success', data.mensaje);
            // `bodegas` tambien: es la lista del desplegable «Dónde está».
            // Sin refrescarla, la bodega que acabas de crear no aparecía ahí
            // y había que recargar la página a mano para colgarle una posición
            // —que es justo lo siguiente que uno hace al montar una bodega—.
            // Tambien `sin_sede`, que es el contador del aviso de arriba.
            router.reload({ only: ['ubicaciones', 'candidatos_admin', 'bodegas', 'sin_sede'] });
        }
    } catch (e) {
        errorModal.value = mensajeDeError(e, 'No pude guardar la ubicación');
    } finally { guardando.value = false; }
};

/*
 * Activar y borrar tienen que DECIR cuando no se pudo.
 *
 * Las dos salían sin `try/catch`, así que un rechazo del servidor —que es de
 * Gerencia, no del jefe de bodega— se convertía en una promesa rota que nadie
 * atrapa: el modal se cerraba, la fila quedaba igual y la persona volvía a
 * pulsar. Esconder los botones cuando no se puede evita el camino normal, pero
 * la raíz es ésta: una llamada que falla sin avisar miente igual que un dato
 * equivocado.
 */
const toggleActiva = async (u) => {
    try {
        const { data } = await axios.post(`/app/inventario/ubicaciones/${u.id}/toggle`);
        if (data.ok) {
            mostrarFlash('success', data.mensaje || `${u.codigo} ${u.activa ? 'desactivada' : 'activada'}.`);
            router.reload({ only: ['ubicaciones', 'sin_sede'] });
        }
    } catch (e) {
        mostrarFlash('error', mensajeDeError(e, 'No pude cambiar el estado de la ubicación'));
    }
};

const confirmarEliminar = (u) => {
    modalConfirm.value = {
        titulo: `¿Eliminar ubicación ${u.codigo}?`,
        mensaje: u.movimientos_count > 0
            ? `Tiene ${u.movimientos_count} movimientos históricos · se marcará como INACTIVA en lugar de borrarse (preserva el kardex).`
            : 'La ubicación se eliminará definitivamente.',
        color: 'rose', textoConfirmar: u.movimientos_count > 0 ? 'Desactivar' : 'Eliminar',
        onConfirmar: async () => {
            modalConfirm.value = null;
            try {
                const { data } = await axios.delete(`/app/inventario/ubicaciones/${u.id}`);
                mostrarFlash('success', data.mensaje);
                router.reload({ only: ['ubicaciones', 'bodegas', 'sin_sede'] });
            } catch (e) {
                mostrarFlash('error', mensajeDeError(e, 'No pude eliminar la ubicación'));
            }
        },
    };
};

/*
 * Cuántas veces se rechazó el select de cada fila.
 *
 * Es la `key` del control: al subirla, Vue descarta el <select> y lo vuelve a
 * crear leyendo `u.responsable_user_id`, que es el valor que de verdad quedó
 * guardado. Sin esto el control se quedaba mostrando a la persona que el
 * servidor acababa de rechazar.
 */
/*
 * El buscador de la tabla.
 *
 * La pantalla se diseñó para una veintena de ubicaciones. Con el levantamiento
 * del área son 435 posiciones en una sola bodega —17 racks por sección, nivel
 * y lado— y encontrar «el rack 12, sección 4, nivel 3 derecha» recorriendo
 * cuatrocientas noventa filas no es buscar: es rendirse.
 *
 * Filtra por código, nombre y posición, sin tildes y sin mayúsculas, porque la
 * bodega escribe «seccion» tan seguido como «sección».
 */
const buscar = ref('');

const sinTildes = (t) => String(t ?? '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const ubicacionesFiltradas = computed(() => {
    const q = sinTildes(buscar.value).trim();
    if (!q) return props.ubicaciones;

    const partes = q.split(/\s+/);

    return props.ubicaciones.filter((u) => {
        const heno = sinTildes([u.codigo, u.nombre, u.posicion, u.bodega_nombre].join(' '));
        return partes.every((p) => heno.includes(p));
    });
});

const reintentosDelSelect = ref({});

/** Vuelve a pedir sólo los datos del encabezado, sin recargar la página. */
const refrescarContadores = () => router.reload({
    only: ['sin_sede', 'candidatos_admin', 'bodegas'],
    preserveScroll: true,
    preserveState: true,
});

const mostrarFlash = (type, message) => {
    flash.value = { type, message };
    setTimeout(() => { flash.value = null; }, 5000);
};

// UBIC-9 · empuja/reenvía la ubicación a SIIGO como warehouse.
const enviandoSiigo = ref({});
const enviarASiigo = async (u) => {
    // FIX-S0 · early-return si ya estamos enviando (doble-click lo disparaba
    // antes aunque el :disabled estaba puesto, por el micro-delay de reactividad).
    if (enviandoSiigo.value[u.id]) return;
    enviandoSiigo.value[u.id] = true;
    try {
        // Ya no se crea nada allá: SIIGO no deja crear bodegas por API
        // (`POST /v1/warehouses` responde 404; el catálogo es de sólo lectura).
        // El endpoint ahora responde qué hacer, y no hay id que esperar, así
        // que tampoco hay que pollear.
        const { data } = await axios.post(`/app/inventario/ubicaciones/${u.id}/enviar-siigo`);
        mostrarFlash(data.ok ? 'success' : 'error', data.mensaje);
    } catch (e) {
        mostrarFlash('error', mensajeDeError(e, 'No pude consultar el enlace con SIIGO'));
    } finally {
        enviandoSiigo.value[u.id] = false;
    }
};


// FIX-S0 · Modal persistente con password generado (antes se perdía con el flash).
const credencialGenerada = ref(null);
const copiarCredencial = async () => {
    if (!credencialGenerada.value) return;
    try {
        await navigator.clipboard.writeText(
            `${credencialGenerada.value.email}  ·  ${credencialGenerada.value.password}`
        );
        mostrarFlash('success', 'Credenciales copiadas al portapapeles.');
    } catch { mostrarFlash('error', 'No pude copiar · copialas a mano.'); }
};

// UBIC-8 · crea un nuevo Admin Bodega sin salir del modal, lo deja seleccionado.
/*
 * Sobre qué ubicaciones puede decidir quien está mirando.
 *
 * Gerencia sobre todas (`mis_ubicaciones` llega en null); el jefe de bodega
 * sólo sobre las suyas, para que no reparta las de otra sede.
 */
const puedeAsignar = (u) =>
    props.puede_asignar_responsable
    && (props.mis_ubicaciones === null || props.mis_ubicaciones.includes(u.id));

const asignarResponsable = async (u, valor) => {
    try {
        const { data } = await axios.post('/app/inventario/ubicaciones/responsable', {
            ubicacion_id: u.id,
            responsable_user_id: valor ? Number(valor) : null,
        });
        u.responsable_user_id = data.responsable?.id ?? null;
        u.responsable_nombre = data.responsable?.name ?? null;
        /*
         * `mostrarFlash` y no `flash.value = texto`.
         *
         * El banner lee `flash.type` y `flash.message`. Con un string adentro,
         * `type` es undefined —así que nunca es 'success' y pinta el estilo de
         * error— y `message` es undefined, que se imprime vacío: una caja ROJA
         * SIN UNA LETRA, idéntica en el camino que funcionó y en el que falló.
         * Era la única confirmación de todo este flujo.
         */
        mostrarFlash('success', data.mensaje);
        /*
         * Y se recalculan los contadores del encabezado.
         *
         * Estas operaciones van por axios, no por Inertia, así que las props
         * no se vuelven a pedir nunca: el aviso de «N ubicaciones activas sin
         * bodega» y la lista de candidatos se quedaban con el número del
         * arranque. Nombrar a tres responsables seguidos dejaba el aviso
         * diciendo lo mismo que antes de empezar.
         */
        refrescarContadores();
    } catch (e) {
        /*
         * Y el select vuelve a lo que de verdad quedó guardado.
         *
         * `:value` es de una vía: si el servidor rechaza a la persona, el
         * valor del modelo no cambia, Vue no vuelve a pintar el control y la
         * fila se queda MOSTRANDO asignado a alguien que el servidor negó.
         * Forzar el re-pintado con la misma clave no sirve, así que se
         * reasigna el id y el `key` del select lo sigue.
         */
        u.responsable_user_id = u.responsable_user_id ?? null;
        reintentosDelSelect.value[u.id] = (reintentosDelSelect.value[u.id] ?? 0) + 1;
        mostrarFlash('error', mensajeDeError(e));
    }
};

const crearAdminBodega = async () => {
    if (!nuevoAdmin.name || !nuevoAdmin.email) {
        mostrarFlash('error', 'Nombre y email son obligatorios.');
        return;
    }
    nuevoAdmin.creando = true;
    try {
        const { data } = await axios.post('/app/inventario/ubicaciones/admin-bodega', {
            name: nuevoAdmin.name,
            email: nuevoAdmin.email,
            password: nuevoAdmin.password || null,
            rol: nuevoAdmin.rol,
        });
        if (data.ok) {
            candidatosAdmin.value.push(data.usuario);
            form.responsable_user_id = data.usuario.id;
            // FIX-S0 · si se generó password, mostramos un modal persistente
            // con "copiar" en vez de flash de 5s (antes se perdía).
            if (data.password_generado) {
                credencialGenerada.value = {
                    nombre: data.usuario.name,
                    email: data.usuario.email,
                    password: data.password_generado,
                };
            } else {
                mostrarFlash('success', data.mensaje);
            }
            nuevoAdmin.name = ''; nuevoAdmin.email = ''; nuevoAdmin.password = '';
            nuevoAdmin.abierto = false;
            nuevoAdmin.abiertoEnForm = false;
        }
    } catch (e) {
        const err = e.response?.data;
        const msg = err?.errors ? Object.values(err.errors).flat().join(' · ') : mensajeDeError(e, 'No pude crear el admin de bodega');
        mostrarFlash('error', 'Error: ' + msg);
    } finally { nuevoAdmin.creando = false; }
};
</script>

<template>
    <Head title="Ubicaciones · Inventario"/>
    <AppLayout>
        <div class="space-y-4 max-w-7xl">
            <!-- Una ubicación tiene que colgar de una bodega para que alguien
                 pueda operarla: el permiso sigue a la bodega, no a la fila
                 suelta. Si el rack no dice a qué bodega pertenece, el
                 responsable de esa bodega recibe 403 al contarlo o al mover
                 mercancía ahí. Era invisible: la pantalla no decía nada y el
                 error aparecía recién al intentar el conteo. -->
            <div v-if="sin_sede > 0"
                 class="card p-4 border-l-4 border-amber-500 bg-amber-50/60 dark:bg-amber-950/30">
                <div class="flex items-start gap-3">
                    <AlertTriangle class="h-6 w-6 text-amber-600 shrink-0"/>
                    <div class="text-sm">
                        <div class="font-bold text-amber-900 dark:text-amber-200">
                            {{ sin_sede }} ubicación(es) activas sin bodega
                        </div>
                        <p class="text-surface-700 dark:text-surface-300 mt-0.5">
                            Nadie que administre una bodega puede contarlas ni mover mercancía en ellas: el permiso
                            sigue a la bodega, y estas no dicen de qué bodega son. Editá cada una, elegí en
                            «¿Dónde está?» la bodega donde está físicamente y, si querés, anotá pasillo, estante y
                            nivel: el responsable de esa bodega queda habilitado al instante.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <Warehouse class="h-6 w-6 text-brand-600"/>Ubicaciones
                    </h1>
                    <p class="text-sm text-surface-500 mt-1">
                        Bodegas, puntos de venta y centros de distribución · con mapeo de resolución DIAN y cuentas PUC por sitio.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="puede_editar_la_ubicacion" @click="sincronizarResoluciones" :disabled="sincronizandoResol"
                            class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-sky-500 text-sky-700 hover:bg-sky-50 disabled:opacity-50"
                            title="Trae de SIIGO las resoluciones DIAN de factura venta activas">
                        <RefreshCw :class="['h-4 w-4', sincronizandoResol && 'animate-spin']"/>
                        {{ sincronizandoResol ? 'Trayendo…' : 'Traer resoluciones de SIIGO' }}
                    </button>
                    <!-- El jefe de bodega crea a su gente sin pasar por Gerencia.
                         Vive acá afuera y no dentro del formulario de la ubicación,
                         que toca SIIGO y el PUC y sí es de Gerencia. -->
                    <button v-if="puede_asignar_responsable"
                            @click="nuevoAdmin.abierto = !nuevoAdmin.abierto"
                            class="text-sm inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-violet-500 text-violet-700 hover:bg-violet-50">
                        <UserPlus class="h-4 w-4"/> Nueva persona de bodega
                    </button>
                    <button v-if="puede_editar_la_ubicacion" @click="abrirNueva" class="btn-primary text-sm inline-flex items-center gap-1.5">
                        <Plus class="h-4 w-4"/> Nueva ubicación
                    </button>
                </div>
            </div>

            <!-- Crear alistador, despachador o jefe de bodega. -->
            <div v-if="nuevoAdmin.abierto" class="card p-4 border-l-4 border-violet-500 space-y-2">
                <!--
                    Si el jefe de bodega no responde por ninguna ubicación, crear
                    gente acá no le sirve de nada: el select de responsable sólo
                    aparece en SUS ubicaciones, y no tiene ninguna. Antes el panel
                    se abría igual, él creaba la persona, y después no encontraba
                    dónde asignarla ni por qué.
                -->
                <div v-if="mis_ubicaciones !== null && !mis_ubicaciones.length"
                     class="rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-xs p-2.5">
                    Ojo: usted todavía no responde por ninguna bodega, así que la persona que cree acá
                    no la va a poder asignar a nada. Pídale a Gerencia que lo deje como responsable de
                    su bodega primero.
                </div>
                <div class="font-semibold text-sm">Nueva persona de bodega</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <input v-model="nuevoAdmin.name" class="input" placeholder="Nombre completo"/>
                    <input v-model="nuevoAdmin.email" type="email" class="input" placeholder="correo@dominio.com"/>
                    <input v-model="nuevoAdmin.password" type="text" class="input" placeholder="Clave (opcional, se genera sola)"/>
                    <select v-model="nuevoAdmin.rol" class="input" aria-label="Qué hace esta persona">
                        <option value="Alistador">Alistador · alista pedidos</option>
                        <option value="Despachador">Despachador · entrega a la transportadora</option>
                        <option value="AdminBodega">Jefe de bodega · administra y mueve inventario</option>
                    </select>
                </div>
                <p class="text-[11px] text-violet-700">
                    <template v-if="nuevoAdmin.rol === 'AdminBodega'">
                        Va a poder mover inventario en las bodegas de las que lo hagan responsable.
                    </template>
                    <template v-else>
                        Va a ver la cola y el stock de las bodegas de las que lo hagan responsable.
                        Mover inventario sigue siendo del jefe de bodega.
                    </template>
                    Después de crearlo, asignalo en la columna «Admin bodega» de su ubicación.
                </p>
                <div v-if="nuevoAdmin.passGenerado" class="text-xs bg-violet-50 border border-violet-200 rounded p-2">
                    Clave generada: <strong class="font-mono">{{ nuevoAdmin.passGenerado }}</strong> ·
                    anotala ahora, no se vuelve a mostrar.
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="nuevoAdmin.abierto = false" class="text-xs px-3 py-1.5 rounded-lg hover:bg-surface-100">Cancelar</button>
                    <button type="button" @click="crearAdminBodega" :disabled="nuevoAdmin.creando"
                            class="text-xs px-3 py-1.5 rounded-lg bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-50">
                        {{ nuevoAdmin.creando ? 'Creando…' : 'Crear' }}
                    </button>
                </div>
            </div>

            <div v-if="flash" role="alert" :class="['card p-3 border-l-4', flash.type==='success' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-rose-500 bg-rose-50 text-rose-700']">
                {{ flash.message }}
            </div>

            <!-- UBIC-6 · Resoluciones sincronizadas + default marcada con estrella.
                 Click en la estrella alterna default. -->
            <div v-if="resolucionesList.length" class="card p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs uppercase font-bold text-brand-600 flex items-center gap-1.5">
                        <Receipt class="h-3.5 w-3.5"/>
                        Resoluciones DIAN sincronizadas · {{ resolucionesList.length }}
                    </div>
                    <div class="text-[11px] text-surface-500">
                        La resolución marcada con <Star class="h-3 w-3 inline text-amber-500 fill-amber-500 mx-0.5"/> se auto-asigna a toda ubicación nueva de categoría venta.
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-for="r in resolucionesList" :key="r.id" :disabled="!puede_editar_la_ubicacion" @click="marcarDefault(r)"
                            :class="[
                                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs',
                                r.id === defaultResolId ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-surface-200',
                                /* Un control apagado no puede seguir invitando a pulsarlo: sin
                                   esto, los chips quedaban muertos para el jefe de bodega pero
                                   con el mismo hover y el mismo «Click para…» de siempre. */
                                puede_editar_la_ubicacion
                                    ? (r.id === defaultResolId ? '' : 'hover:border-amber-300 hover:bg-amber-50/50')
                                    : 'opacity-60 cursor-default'
                            ]"
                            :title="!puede_editar_la_ubicacion
                                ? 'La resolución por defecto la marca Gerencia'
                                : (r.id === defaultResolId ? 'Click para quitar como default' : 'Click para marcar como default')">
                        <Star :class="['h-3.5 w-3.5', r.id === defaultResolId ? 'text-amber-500 fill-amber-500' : 'text-surface-300']"/>
                        <span v-if="r.prefix" class="font-mono bg-sky-100 text-sky-700 px-1 py-0.5 rounded text-[10px]">{{ r.prefix }}</span>
                        {{ r.nombre }}
                    </button>
                </div>
            </div>

            <!--
                El buscador. Con 435 posiciones en una sola bodega, encontrar
                «rack 12 sección 4 nivel 3 derecha» recorriendo la tabla no es
                buscar: es rendirse.
            -->
            <div class="card p-3 flex items-center gap-3 flex-wrap">
                <div class="relative flex-1 min-w-[16rem]">
                    <input v-model="buscar" type="search"
                           placeholder="Buscar por código, nombre o posición · ej: R12-S4  ·  rack 5 derecha"
                           aria-label="Buscar ubicación"
                           class="input w-full pl-3"/>
                </div>
                <div class="text-xs text-surface-500 whitespace-nowrap">
                    <template v-if="buscar.trim()">
                        <strong>{{ ubicacionesFiltradas.length }}</strong> de {{ ubicaciones.length }}
                        <button class="ml-2 underline hover:text-brand-600" @click="buscar = ''">limpiar</button>
                    </template>
                    <template v-else>
                        {{ ubicaciones.length }} ubicación(es)
                    </template>
                </div>
            </div>

            <!-- Tabla principal · FIX-S0 overflow-x-auto para móvil (10 cols) -->
            <div class="card overflow-x-auto">
                <table v-tabla-movil class="w-full text-sm min-w-[900px]">
                    <thead class="text-xs text-surface-500 uppercase border-b bg-surface-50">
                        <tr>
                            <th class="text-left p-2 w-24">Código</th>
                            <th class="text-left p-2">Nombre</th>
                            <th class="text-left p-2 w-32">Categoría</th>
                            <th class="text-left p-2" title="Bodega a la que pertenece y posición física adentro">Dónde está</th>
                            <th class="text-left p-2 w-28" title="Resolución DIAN mapeada en SIIGO">
                                <Receipt class="h-3 w-3 inline"/> Resol.
                            </th>
                            <th class="text-left p-2 w-24">PUC inv.</th>
                            <th class="text-left p-2 w-40" title="Admin bodega (scope restringido)">
                                <ShieldCheck class="h-3 w-3 inline"/> Admin bodega
                            </th>
                            <th class="text-center p-2 w-20">Movs.</th>
                            <th class="text-center p-2 w-20">Activa</th>
                            <th class="text-right p-2 w-28">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="u in ubicacionesFiltradas" :key="u.id"
                            :class="[!u.activa && 'opacity-50 bg-surface-50/50', 'hover:bg-surface-50']">
                            <td class="p-2 font-mono font-bold"
                                :class="u.importada_de_siigo ? 'text-surface-400' : 'text-brand-600'">{{ u.codigo }}</td>
                            <td class="p-2" :class="u.importada_de_siigo && 'text-surface-400'">
                                <!-- Las SIIGO-* las trajo el importador del catálogo:
                                     existen como destino contable, no las opera nadie.
                                     Son 43 contra 21 propias y el sandbox es compartido,
                                     así que varias son de otras empresas. -->
                                <span v-if="u.importada_de_siigo"
                                      class="inline-flex items-center gap-0.5 mr-1 text-[10px] bg-surface-100 text-surface-500 px-1 py-0.5 rounded border border-surface-300"
                                      title="Vino del catálogo de SIIGO · no es una ubicación que opere Great Baby">
                                    <Cloud class="h-2.5 w-2.5"/> de SIIGO
                                </span>
                                <span v-else-if="!u.es_bodega" class="text-surface-300 mr-1 font-mono">└</span>
                                <span v-else class="inline-flex items-center gap-0.5 mr-1 text-[10px] bg-brand-50 text-brand-700 px-1 py-0.5 rounded border border-brand-200"
                                      title="Es una bodega · las posiciones cuelgan de ella">
                                    <Warehouse class="h-2.5 w-2.5"/> Bodega
                                </span>
                                {{ u.nombre }}
                                <span v-if="u.siigo_id" class="inline-flex items-center gap-0.5 ml-1 text-[10px] bg-emerald-50 text-emerald-700 px-1 py-0.5 rounded border border-emerald-200"
                                      :title="`Sincronizado con SIIGO · warehouse #${u.siigo_id}`">
                                    <Cloud class="h-2.5 w-2.5"/> SIIGO · {{ u.siigo_id }}
                                </span>
                                <!-- Decía «Crear en SIIGO» y no creaba nada: ese
                                     catálogo es de sólo lectura allá. Ahora el
                                     botón sólo explica cómo enlazarla. -->
                                <button v-else-if="puede_editar_la_ubicacion" @click="enviarASiigo(u)" :disabled="enviandoSiigo[u.id]"
                                        class="inline-flex items-center gap-0.5 ml-1 text-[10px] bg-surface-100 text-surface-600 hover:bg-surface-200 px-1 py-0.5 rounded border border-surface-300 disabled:opacity-50"
                                        title="Cómo enlazar esta ubicación con una bodega de SIIGO">
                                    <CloudOff class="h-2.5 w-2.5"/>
                                    Sin bodega SIIGO
                                </button>
                            </td>
                            <td class="p-2 text-xs text-surface-600">{{ u.categoria_label }}</td>
                            <td class="p-2 text-xs text-surface-600">
                                <template v-if="u.bodega_nombre">
                                    <div class="font-medium">{{ u.bodega_nombre }}</div>
                                    <div v-if="u.posicion" class="text-surface-500">{{ u.posicion }}</div>
                                    <div v-else class="text-amber-600">sin pasillo/estante/nivel</div>
                                </template>
                                <template v-else-if="u.es_bodega && u.responsable_user_id">
                                    {{ u.ciudad || 'sede' }}
                                </template>
                                <button v-else-if="puede_editar_la_ubicacion" @click="abrirEditar(u)"
                                        class="inline-flex items-center gap-1 text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5 hover:bg-amber-100"
                                        title="Nadie la puede contar ni mover hasta que diga de qué bodega es">
                                    <AlertTriangle class="h-3 w-3"/> Asignar bodega
                                </button>
                                <!-- Mandar a «asignar bodega» a quien no puede abrir el
                                     formulario es mandarlo a una puerta cerrada. -->
                                <span v-else class="inline-flex items-center gap-1 text-amber-700"
                                      title="Nadie la puede contar ni mover hasta que diga de qué bodega es">
                                    <AlertTriangle class="h-3 w-3"/> sin bodega · lo asigna Gerencia
                                </span>
                            </td>
                            <td class="p-2 text-xs">
                                <span v-if="u.siigo_resolution_prefix" class="font-mono bg-sky-50 text-sky-700 px-1.5 py-0.5 rounded">
                                    {{ u.siigo_resolution_prefix }}
                                </span>
                                <span v-else class="text-surface-300">—</span>
                            </td>
                            <td class="p-2 text-xs font-mono text-surface-600">{{ u.cta_inventario || '—' }}</td>
                            <td class="p-2 text-xs">
                                <!-- Decir quién responde por un estante es trabajo de todos
                                     los días del jefe de bodega: se hace acá mismo, en la
                                     fila, sin abrir el formulario completo (que además toca
                                     SIIGO y el PUC y es de Gerencia). -->
                                <select v-if="puedeAsignar(u)"
                                        :key="`resp-${u.id}-${reintentosDelSelect[u.id] || 0}`"
                                        :value="u.responsable_user_id"
                                        @change="asignarResponsable(u, $event.target.value)"
                                        class="input text-xs py-1 w-full max-w-[14rem]"
                                        :aria-label="`Responsable de ${u.nombre}`">
                                    <option :value="''">— sin responsable —</option>
                                    <option v-for="c in candidatosAdmin" :key="c.id" :value="c.id">
                                        {{ c.name }}<template v-if="c.roles.length"> · {{ c.roles[0] }}</template>
                                    </option>
                                </select>
                                <template v-else>
                                    <span v-if="u.responsable_nombre" class="inline-flex items-center gap-1 bg-violet-50 text-violet-700 px-1.5 py-0.5 rounded">
                                        <ShieldCheck class="h-3 w-3"/> {{ u.responsable_nombre }}
                                    </span>
                                    <span v-else class="text-surface-300">— sin responsable —</span>
                                </template>
                            </td>
                            <td class="p-2 text-center text-xs text-surface-500">{{ u.movimientos_count || 0 }}</td>
                            <td class="p-2 text-center">
                                <!--
                                    Un icono de 16 px sin texto ni estado: no se
                                    sabía qué hacía, ni si estaba prendido, ni se
                                    acertaba con el dedo. Ahora dice qué hace y a
                                    qué ubicación, publica su estado, y el botón
                                    mide 36 px aunque el icono siga igual.
                                -->
                                <button v-if="puede_editar_la_ubicacion" @click="toggleActiva(u)"
                                        type="button"
                                        role="switch"
                                        :aria-checked="!!u.activa"
                                        :aria-label="`${u.activa ? 'Desactivar' : 'Activar'} ${u.nombre || u.codigo}`"
                                        :title="u.activa ? 'Activa · clic para desactivar' : 'Inactiva · clic para activar'"
                                        class="inline-flex items-center justify-center h-9 w-9 rounded hover:bg-surface-100 dark:hover:bg-surface-700">
                                    <Power v-if="u.activa" class="h-4 w-4 text-emerald-600"/>
                                    <PowerOff v-else class="h-4 w-4 text-rose-400"/>
                                </button>
                            </td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button v-if="puede_editar_la_ubicacion" @click="abrirEditar(u)" class="text-brand-600 hover:text-brand-700 p-1" title="Editar">
                                    <Pencil class="h-4 w-4"/>
                                </button>
                                <button v-if="puede_editar_la_ubicacion" @click="confirmarEliminar(u)" class="text-red-500 hover:text-red-700 p-1" title="Eliminar">
                                    <Trash2 class="h-4 w-4"/>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!ubicaciones.length">
                            <td colspan="10" class="p-8 text-center text-sm text-surface-500">
                                Aún no hay ubicaciones · creá la primera con el botón de arriba.
                            </td>
                        </tr>
                        <!-- Hay ubicaciones, pero ninguna coincide: es otra cosa muy
                             distinta de no tener ninguna, y hay que decirlo distinto. -->
                        <tr v-else-if="!ubicacionesFiltradas.length">
                            <td colspan="10" class="p-8 text-center text-sm text-surface-500">
                                Ninguna de las {{ ubicaciones.length }} ubicaciones coincide con
                                «{{ buscar }}».
                                <button class="underline hover:text-brand-600" @click="buscar = ''">Ver todas</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Modal crear/editar -->
            <div v-if="modal" class="fixed inset-0 bg-black/50 flex items-start justify-center p-4 z-50 overflow-y-auto"
                 @click.self="modal = null">
                <!--
                    Para un lector de pantalla esto era un trozo más de la
                    página: sin `role` no se anunciaba como diálogo y sin
                    `aria-labelledby` no leía de qué era.
                -->
                <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full my-8"
                     role="dialog"
                     aria-modal="true"
                     aria-labelledby="ubicacion-modal-titulo">
                    <div class="p-5 border-b flex items-center gap-3">
                        <Warehouse class="h-6 w-6 text-brand-600"/>
                        <h2 id="ubicacion-modal-titulo" class="text-lg font-bold text-surface-800">
                            {{ modal === 'nueva' ? 'Nueva ubicación' : `Editar ${form.codigo}` }}
                        </h2>
                    </div>
                    <div class="p-5 space-y-4">
                        <!--
                            El motivo del rechazo, donde la persona está
                            mirando. `role="alert"` para que un lector de
                            pantalla lo anuncie sin que haya que ir a buscarlo.
                        -->
                        <div v-if="errorModal" role="alert"
                             class="rounded-lg border-l-4 border-rose-500 bg-rose-50 text-rose-800 px-3 py-2 text-sm">
                            {{ errorModal }}
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-surface-600">Código *</label>
                                <input v-model="form.codigo" class="input w-full font-mono" placeholder="PRIN"/>
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-xs font-semibold text-surface-600">Nombre *</label>
                                <input v-model="form.nombre" class="input w-full"/>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-surface-600">Categoría *</label>
                                <select v-model="form.categoria" class="input w-full">
                                    <option v-for="c in categorias" :key="c.value" :value="c.value">{{ c.label }}</option>
                                </select>
                            </div>

                            <!-- Jerarquía: o esto ES una bodega, o es una
                                 posición dentro de una. De esto depende quién
                                 puede contar y mover mercancía acá. -->
                            <div class="md:col-span-3 border-t border-surface-200 dark:border-surface-800 pt-3">
                                <label class="text-xs font-semibold text-surface-600">¿Dónde está?</label>
                                <select v-model="form.bodega_id" class="input w-full min-h-11">
                                    <option :value="null">Esto es una bodega (sede principal)</option>
                                    <!-- Las sedes de Great Baby van aparte: el resto son bodegas de
                                         otras empresas que llegan del ambiente compartido de SIIGO. -->
                                    <optgroup label="Sedes de Great Baby">
                                        <option v-for="b in bodegas.filter((x) => x.es_sede)" :key="b.id" :value="b.id"
                                                :disabled="form.id === b.id">
                                            Dentro de {{ b.nombre }}
                                        </option>
                                    </optgroup>
                                    <optgroup label="Otras bodegas (catálogo de SIIGO)">
                                        <option v-for="b in bodegas.filter((x) => ! x.es_sede)" :key="b.id" :value="b.id"
                                                :disabled="form.id === b.id">
                                            Dentro de {{ b.nombre }}
                                        </option>
                                    </optgroup>
                                </select>
                                <p class="text-xs text-surface-500 mt-0.5">
                                    Quien sea responsable de la bodega podrá contar y mover mercancía en todas
                                    las ubicaciones que estén adentro.
                                </p>
                            </div>

                            <div v-if="form.bodega_id" class="md:col-span-3 grid grid-cols-3 gap-3">
                                <div>
                                    <label class="text-xs font-semibold text-surface-600">Pasillo</label>
                                    <input v-model="form.pasillo" class="input w-full min-h-11" maxlength="30" placeholder="4"/>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-surface-600">Estante</label>
                                    <input v-model="form.estante" class="input w-full min-h-11" maxlength="30" placeholder="6"/>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-surface-600">Nivel</label>
                                    <input v-model="form.nivel" class="input w-full min-h-11" maxlength="30" placeholder="3"/>
                                </div>
                                <p class="col-span-3 text-xs text-surface-500 -mt-1">
                                    Para encontrar la mercancía en el piso. Ejemplo: pañales recién nacido en
                                    pasillo 4, estante 6, nivel 3.
                                </p>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-surface-600">Ciudad</label>
                                <input v-model="form.ciudad" class="input w-full"/>
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-xs font-semibold text-surface-600">Dirección</label>
                                <input v-model="form.direccion" class="input w-full"/>
                            </div>
                        </div>

                        <!-- Bloque SIIGO -->
                        <div class="border-t pt-4">
                            <div class="text-xs uppercase font-bold text-brand-600 mb-2 flex items-center gap-1.5">
                                <Cloud class="h-3.5 w-3.5"/> Enlaces SIIGO
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs font-semibold text-surface-600">Bodega SIIGO (warehouse)</label>
                                    <!-- `w.id` ya ES el id de SIIGO: el controlador
                                         manda `['id' => (int) $c->codigo]`, no la fila
                                         local de `siigo_catalogos`. -->
                                    <select v-model="form.siigo_id" class="input w-full">
                                        <option :value="null">— sin mapear —</option>
                                        <option v-for="w in warehouses_siigo" :key="w.id" :value="w.id">
                                            [{{ w.id }}] {{ w.nombre }}
                                        </option>
                                    </select>
                                    <p class="text-[11px] text-surface-500 mt-1">
                                        Las bodegas se crean en SIIGO, no acá: esta lista son las que ya
                                        trajimos de allá. Un rack o una zona de avería no va mapeada.
                                    </p>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-surface-600 flex items-center gap-1">
                                        Resolución DIAN para facturar
                                        <Star v-if="form.siigo_resolution_id === defaultResolId && defaultResolId"
                                              class="h-3 w-3 text-amber-500 fill-amber-500"
                                              title="Es la resolución default · se auto-asigna a nuevas ubicaciones"/>
                                    </label>
                                    <select v-model="form.siigo_resolution_id" class="input w-full">
                                        <option :value="null">— sin resolución (no factura) —</option>
                                        <option v-for="r in resolucionesList" :key="r.id" :value="r.id">
                                            <template v-if="r.id === defaultResolId">★ </template><template v-if="r.prefix">[{{ r.prefix }}] </template>{{ r.nombre }}
                                        </option>
                                    </select>
                                    <p v-if="!resolucionesList.length" class="text-[11px] text-amber-700 mt-1">
                                        No hay resoluciones sincronizadas · usá el botón "Traer resoluciones de SIIGO" arriba.
                                    </p>
                                    <p v-else-if="defaultResolId && modal !== 'editar'" class="text-[11px] text-emerald-700 mt-1">
                                        Default pre-seleccionada · se auto-asigna a toda ubicación nueva de venta sin elegir otra.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Bloque contable -->
                        <div class="border-t pt-4">
                            <div class="text-xs uppercase font-bold text-brand-600 mb-2 flex items-center gap-1.5">
                                <FileText class="h-3.5 w-3.5"/> Cuentas PUC por ubicación
                                <span class="text-[10px] font-normal text-surface-500 ml-1">(dejá vacío para heredar las globales)</span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs font-semibold text-surface-600">Cta. inventario (default 1435)</label>
                                    <input v-model="form.cta_inventario" class="input w-full font-mono" placeholder="1435"/>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-surface-600">Cta. costo (default 6135)</label>
                                    <input v-model="form.cta_costo" class="input w-full font-mono" placeholder="6135"/>
                                </div>
                            </div>
                        </div>

                        <!-- UBIC-8 · Admin bodega · se auto-promueve a rol AdminBodega al guardar -->
                        <div class="border-t pt-4">
                            <div class="text-xs uppercase font-bold text-brand-600 mb-2 flex items-center gap-1.5">
                                <ShieldCheck class="h-3.5 w-3.5"/> Admin de esta bodega
                                <span class="text-[10px] font-normal text-surface-500 ml-1">(solo verá y operará ESTA ubicación)</span>
                            </div>
                            <div class="flex gap-2">
                                <select v-model="form.responsable_user_id" class="input flex-1">
                                    <option :value="null">— sin admin asignado —</option>
                                    <option v-for="c in candidatosAdmin" :key="c.id" :value="c.id">
                                        {{ c.name }} · {{ c.email }}<template v-if="c.roles.length"> · [{{ c.roles.join(', ') }}]</template>
                                    </option>
                                </select>
                                <button type="button" @click="nuevoAdmin.abiertoEnForm = !nuevoAdmin.abiertoEnForm"
                                        class="text-xs inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-violet-500 text-violet-700 hover:bg-violet-50">
                                    <UserPlus class="h-3.5 w-3.5"/> Nuevo
                                </button>
                            </div>
                            <div v-if="nuevoAdmin.abiertoEnForm" class="mt-3 p-3 rounded-lg border border-violet-200 bg-violet-50/50 space-y-2">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                    <input v-model="nuevoAdmin.name" class="input" placeholder="Nombre completo"/>
                                    <input v-model="nuevoAdmin.email" type="email" class="input" placeholder="correo@dominio.com"/>
                                    <input v-model="nuevoAdmin.password" type="text" class="input" placeholder="Password (opcional, se genera)"/>
                                    <select v-model="nuevoAdmin.rol" class="input" aria-label="Qué hace esta persona">
                                        <option value="Alistador">Alistador · alista pedidos</option>
                                        <option value="Despachador">Despachador · entrega a la transportadora</option>
                                        <option value="AdminBodega">Jefe de bodega · administra y mueve inventario</option>
                                    </select>
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="nuevoAdmin.abiertoEnForm = false" class="text-xs px-3 py-1.5 rounded-lg hover:bg-white">Cancelar</button>
                                    <button type="button" @click="crearAdminBodega" :disabled="nuevoAdmin.creando"
                                            class="text-xs px-3 py-1.5 rounded-lg bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-50">
                                        {{ nuevoAdmin.creando ? 'Creando…' : 'Crear + asignar' }}
                                    </button>
                                </div>
                                <p class="text-[11px] text-violet-700">
                                    Se creará como <strong>{{ nuevoAdmin.rol }}</strong> y quedará asignado a esta ubicación al guardar.
                                    <template v-if="nuevoAdmin.rol !== 'AdminBodega'">
                                        Va a ver la cola y el stock de esta bodega; mover inventario sigue siendo del jefe de bodega.
                                    </template>
                                </p>
                            </div>
                        </div>

                        <div class="border-t pt-4">
                            <label class="text-xs font-semibold text-surface-600">Notas</label>
                            <textarea v-model="form.notas" rows="2" class="input w-full"/>
                        </div>
                    </div>
                    <div class="p-5 border-t flex items-center justify-between gap-2">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="form.activa" class="rounded"/> Activa
                        </label>
                        <div class="flex gap-2">
                            <button @click="modal = null" class="text-sm px-4 py-2 rounded-lg hover:bg-surface-100">Cancelar</button>
                            <button @click="guardar" :disabled="guardando" class="btn-primary text-sm inline-flex items-center gap-1.5">
                                {{ guardando ? 'Guardando…' : 'Guardar' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FIX-S0 · Modal persistente con credencial generada del admin bodega -->
            <div v-if="credencialGenerada" class="fixed inset-0 bg-black/60 flex items-center justify-center p-4 z-50">
                <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-5 border-2 border-violet-500">
                    <h3 class="text-lg font-bold text-violet-700 flex items-center gap-2 mb-3">
                        <ShieldCheck class="h-5 w-5"/> Credenciales del Admin Bodega
                    </h3>
                    <p class="text-xs text-surface-600 mb-3">
                        El usuario fue creado. Guardá o envíale estas credenciales ahora:
                        <strong>este password NO se vuelve a mostrar</strong>.
                    </p>
                    <div class="bg-surface-50 rounded-lg p-3 font-mono text-sm space-y-1 mb-4">
                        <div><span class="text-surface-400">Nombre:</span> {{ credencialGenerada.nombre }}</div>
                        <div><span class="text-surface-400">Email:</span> {{ credencialGenerada.email }}</div>
                        <div><span class="text-surface-400">Password:</span> <strong>{{ credencialGenerada.password }}</strong></div>
                    </div>
                    <div class="flex justify-between gap-2">
                        <button @click="copiarCredencial" class="text-sm px-3 py-2 rounded-lg border border-violet-500 text-violet-700 hover:bg-violet-50">
                            Copiar al portapapeles
                        </button>
                        <button @click="credencialGenerada = null" class="btn-primary text-sm">
                            Entendido · ya las guardé
                        </button>
                    </div>
                </div>
            </div>

            <AppConfirmModal :cfg="modalConfirm" @cerrar="modalConfirm = null"/>
        </div>
    </AppLayout>
</template>
