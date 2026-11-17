<script setup>
/**
 * El catálogo con el que clasifica la bodega.
 *
 * Son dos ejes independientes y la pantalla los muestra así, uno al lado del
 * otro, porque la confusión más común es creer que son lo mismo:
 *
 *   · la OFICINA dice de quién es la mercancía;
 *   · el ALMACÉN dice en qué estado y en qué condición comercial está.
 *
 * Abajo va lo que de verdad evita un daño: decirle a cada bodega que ya existe
 * qué par representa. Si nadie lo hace, el sistema crea una bodega «10-01»
 * nueva al lado de la PRINCIPAL que ya tiene todo el stock, y el inventario
 * queda partido en dos filas que significan lo mismo.
 */
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Building2, Package, Pencil, ArrowRightLeft, AlertTriangle, CheckCircle2, Link2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    oficinas: { type: Array, required: true },
    almacenes: { type: Array, required: true },
    bodegas: { type: Array, required: true },
    categorias: { type: Array, required: true },
    paresDeIngreso: { type: Array, default: () => [] },
    puede_cambiar: { type: Boolean, default: false },
    sin_sembrar: { type: Boolean, default: false },
});

const flash = ref(null);
const guardando = ref(false);
const verSiigo = ref(false);

const editandoOficina = ref(null);
const editandoAlmacen = ref(null);

const bodegasVisibles = computed(() =>
    props.bodegas.filter((b) => verSiigo.value || !b.de_siigo),
);

const sinMapear = computed(() =>
    props.bodegas.filter((b) => !b.de_siigo && b.movimientos > 0 && (!b.oficina_id || !b.almacen_id)).length,
);

/*
 * Lo que la persona eligió pero todavía no guardó, aparte de las props.
 *
 * Antes los dos `<select>` del mapeo escribían con `v-model` DENTRO del
 * objeto de la prop, y eso tenía dos finales malos:
 *
 *   · guardar una fila devuelve las props enteras desde el servidor, así que
 *     lo que la persona había elegido en las OTRAS filas desaparecía sin
 *     avisar —y guardar tres bodegas seguidas obligaba a volver a elegir cada
 *     vez, sin entender por qué;
 *   · y el aviso rojo de «hay N bodegas sin par» cuenta sobre las props, así
 *     que se apagaba con sólo mover el select: la pantalla decía que ya estaba
 *     resuelto cuando nada se había guardado.
 *
 * Con el borrador aparte, el aviso sigue contando lo GUARDADO y cada fila
 * puede decir si tiene cambios pendientes.
 */
const borrador = ref({});

/*
 * Al volver las props se re-siembra el borrador, PERO se respeta lo que la
 * persona dejó a medio elegir en otras filas.
 *
 * Guardar una fila devuelve las props enteras. Pisando el borrador completo,
 * mapear tres bodegas seguidas obligaba a volver a elegir las otras dos cada
 * vez, sin ningún aviso de que se habían borrado. Así que una fila se
 * actualiza sólo si lo que tenía en el borrador coincidía con lo guardado; si
 * difería, es trabajo sin guardar y se deja.
 */
const sembrarBorrador = () => {
    const previo = borrador.value || {};
    const nuevo = {};

    for (const b of props.bodegas) {
        const d = previo[b.id];
        const teniaCambios = d
            && ((d.oficina_id ?? null) !== (b.oficina_id ?? null)
                || (d.almacen_id ?? null) !== (b.almacen_id ?? null));

        nuevo[b.id] = teniaCambios
            ? { oficina_id: d.oficina_id ?? null, almacen_id: d.almacen_id ?? null }
            : { oficina_id: b.oficina_id ?? null, almacen_id: b.almacen_id ?? null };
    }

    borrador.value = nuevo;
};

sembrarBorrador();
watch(() => props.bodegas, sembrarBorrador, { deep: false });

const sinGuardar = (b) => {
    const d = borrador.value[b.id];
    if (!d) return false;
    return (d.oficina_id ?? null) !== (b.oficina_id ?? null)
        || (d.almacen_id ?? null) !== (b.almacen_id ?? null);
};

function abrirOficina(o) {
    editandoOficina.value = { ...o };
}

function abrirAlmacen(a) {
    editandoAlmacen.value = { ...a };
}

function avisar(mensaje, tipo = 'ok') {
    flash.value = { mensaje, tipo };
    setTimeout(() => (flash.value = null), 6000);
}

function enviar(url, datos, alCerrar) {
    guardando.value = true;
    router.post(url, datos, {
        preserveScroll: true,
        /*
         * `preserveState` o el borrador se pierde igual.
         *
         * Sin esto Inertia desmonta y vuelve a montar la pantalla al llegar la
         * respuesta, así que todo lo local —el borrador del mapeo incluido—
         * nace de cero: guardar una fila seguía borrando lo que la persona
         * había elegido en las otras, aunque la re-siembra del borrador
         * respete lo que tiene cambios. El que manda es este.
         */
        preserveState: true,
        onSuccess: (page) => {
            alCerrar?.();
            avisar(page.props?.flash?.success || 'Guardado.');
        },
        onError: (errores) => avisar(Object.values(errores)[0] || 'No se pudo guardar.', 'error'),
        onFinish: () => (guardando.value = false),
    });
}

const guardarOficina = () =>
    enviar('/app/inventario/catalogo-bodega/oficina', editandoOficina.value, () => (editandoOficina.value = null));

const guardarAlmacen = () =>
    enviar('/app/inventario/catalogo-bodega/almacen', editandoAlmacen.value, () => (editandoAlmacen.value = null));

function mapear(bodega) {
    const d = borrador.value[bodega.id] || {};
    enviar('/app/inventario/catalogo-bodega/mapear', {
        bodega_id: bodega.id,
        oficina_id: d.oficina_id || null,
        almacen_id: d.almacen_id || null,
    });
}
</script>

<template>
    <Head title="Catálogo de la bodega" />

    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6">

            <header>
                <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    <Building2 class="w-6 h-6 text-amber-600" />
                    Catálogo de la bodega
                </h1>
                <p class="text-sm text-gray-500 mt-1">
                    La bodega clasifica con dos cosas a la vez: de <strong>quién</strong> es la mercancía
                    (oficina) y en qué <strong>estado</strong> está (almacén). Son independientes.
                </p>
            </header>

            <div
                v-if="flash"
                class="rounded-lg px-4 py-3 text-sm border"
                :class="flash.tipo === 'error'
                    ? 'bg-red-50 border-red-200 text-red-800'
                    : 'bg-emerald-50 border-emerald-200 text-emerald-800'"
            >
                {{ flash.mensaje }}
            </div>

            <!--
                Sin catálogo la pantalla se ve rota: un cuadro que anuncia una
                regla dura y debajo cero combinaciones, dos tablas sin una fila
                y los dos selects del mapeo con una sola opción. Lo que falta
                es correr el sembrador, y eso hay que decirlo con el comando.
            -->
            <div v-if="sin_sembrar"
                 class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
                <div class="font-semibold flex items-center gap-2">
                    <AlertTriangle class="w-4 h-4" />
                    El catálogo está vacío: todavía no se sembró
                </div>
                <p class="mt-1">
                    Las dos tablas las crea la migración vacías. Mientras estén así, la recepción
                    no tiene a dónde repartir nada y todo entra a la bodega de la orden, como antes.
                </p>
                <code class="mt-2 block bg-white/70 border border-red-200 rounded px-2 py-1 text-xs font-mono">
                    php artisan db:seed --class=CatalogoOficinasAlmacenesSeeder
                </code>
            </div>

            <!-- La regla que más se olvida, dicha antes que nada. -->
            <div v-if="paresDeIngreso.length"
                 class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <div class="font-semibold flex items-center gap-2">
                    <AlertTriangle class="w-4 h-4" />
                    Al recibir un contenedor sólo se puede ingresar a estas combinaciones
                </div>
                <ul class="mt-2 space-y-1">
                    <li v-for="(p, i) in paresDeIngreso" :key="i" class="flex items-center gap-2">
                        <span class="font-medium">{{ p.oficina }}</span>
                        <span class="text-amber-500">+</span>
                        <span class="font-medium">{{ p.almacen }}</span>
                        <span class="text-amber-500">→</span>
                        <span v-if="!p.se_creara" class="text-amber-800">
                            {{ p.bodega }} <span class="text-amber-500">({{ p.bodega_codigo }})</span>
                        </span>
                        <span v-else class="italic text-amber-600">
                            todavía sin bodega · se crea sola la primera vez que reciban
                        </span>
                    </li>
                </ul>
                <p class="mt-2 text-xs text-amber-700">
                    A todo lo demás —Marketing, Sra. Tania, Exhibición, averías leves, outlet, saldos,
                    bajas y repuestos— se llega por <strong>traslado</strong>, nunca por recepción.
                </p>
            </div>

            <div class="grid lg:grid-cols-2 gap-6">

                <!-- ===================== OFICINAS ===================== -->
                <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100">
                        <h2 class="font-semibold text-gray-900 flex items-center gap-2">
                            <Building2 class="w-4 h-4 text-gray-400" />
                            Oficinas · de quién es
                        </h2>
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 bg-gray-50">
                                <th class="px-4 py-2 w-14">Cód.</th>
                                <th class="px-2 py-2">Nombre</th>
                                <th class="px-2 py-2">Ingreso</th>
                                <th class="px-2 py-2 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Una tabla vacía sin una palabra se lee como pantalla rota. -->
                            <tr v-if="!oficinas.length">
                                <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">
                                    Todavía no hay oficinas cargadas.
                                </td>
                            </tr>
                            <tr
                                v-for="o in oficinas"
                                :key="o.id"
                                class="border-t border-gray-100"
                                :class="{ 'opacity-50': !o.activa }"
                            >
                                <td class="px-4 py-2 font-mono text-gray-500">{{ o.codigo }}</td>
                                <td class="px-2 py-2">
                                    <div class="font-medium text-gray-900">{{ o.nombre }}</div>
                                    <div v-if="o.empresa" class="text-xs text-gray-500">factura con {{ o.empresa }}</div>
                                    <div v-if="o.es_respaldo_surtido" class="text-xs text-indigo-600">
                                        de aquí se completa el surtido al 100%
                                    </div>
                                </td>
                                <td class="px-2 py-2">
                                    <span
                                        v-if="o.permite_ingreso_directo"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700"
                                    >
                                        <CheckCircle2 class="w-3.5 h-3.5" /> directo
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1 text-xs text-gray-500">
                                        <ArrowRightLeft class="w-3.5 h-3.5" /> sólo traslado
                                    </span>
                                </td>
                                <td class="px-2 py-2">
                                    <button v-if="puede_cambiar" class="text-gray-400 hover:text-amber-600"
                                            :aria-label="'Editar'" @click="abrirOficina(o)">
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- ===================== ALMACENES ===================== -->
                <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100">
                        <h2 class="font-semibold text-gray-900 flex items-center gap-2">
                            <Package class="w-4 h-4 text-gray-400" />
                            Almacenes · en qué estado está
                        </h2>
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 bg-gray-50">
                                <th class="px-4 py-2 w-14">Cód.</th>
                                <th class="px-2 py-2">Nombre</th>
                                <th class="px-2 py-2">Se vende</th>
                                <th class="px-2 py-2">Ingreso</th>
                                <th class="px-2 py-2 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!almacenes.length">
                                <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">
                                    Todavía no hay almacenes cargados.
                                </td>
                            </tr>
                            <tr
                                v-for="a in almacenes"
                                :key="a.id"
                                class="border-t border-gray-100"
                                :class="{ 'opacity-50': !a.activo }"
                            >
                                <td class="px-4 py-2 font-mono text-gray-500">{{ a.codigo }}</td>
                                <td class="px-2 py-2">
                                    <div class="font-medium text-gray-900">{{ a.nombre }}</div>
                                    <div class="text-xs text-gray-500">{{ a.estado_fisico }}</div>
                                </td>
                                <td class="px-2 py-2">
                                    <span v-if="a.comercial" class="text-xs font-medium text-emerald-700">sí</span>
                                    <span v-else class="text-xs text-gray-500">no</span>
                                </td>
                                <td class="px-2 py-2">
                                    <span v-if="a.permite_ingreso_directo" class="text-xs font-medium text-emerald-700">directo</span>
                                    <span v-else class="text-xs text-gray-500">sólo traslado</span>
                                </td>
                                <td class="px-2 py-2">
                                    <button v-if="puede_cambiar" class="text-gray-400 hover:text-amber-600"
                                            :aria-label="'Editar'" @click="abrirAlmacen(a)">
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="px-4 py-2 text-xs text-gray-500 border-t border-gray-100">
                        «Se vende» no es lo mismo que «está nuevo»: las averías leves están con desperfecto
                        y aun así se comercializan con descuento.
                    </p>
                </section>
            </div>

            <!-- ===================== MAPEO ===================== -->
            <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h2 class="font-semibold text-gray-900 flex items-center gap-2">
                            <Link2 class="w-4 h-4 text-gray-400" />
                            Qué par representa cada bodega
                        </h2>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Sin esto el sistema crea una bodega nueva al lado de la que ya tiene el stock,
                            y el inventario queda partido en dos.
                        </p>
                    </div>
                    <label class="text-xs text-gray-500 flex items-center gap-2">
                        <input v-model="verSiigo" type="checkbox" class="rounded border-gray-300" />
                        ver también las importadas de SIIGO
                    </label>
                </div>

                <div v-if="sinMapear" class="px-4 py-2 bg-red-50 border-b border-red-100 text-sm text-red-800">
                    Hay <strong>{{ sinMapear }}</strong> bodega(s) con movimientos y sin par asignado.
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 bg-gray-50">
                            <th class="px-4 py-2">Bodega</th>
                            <th class="px-2 py-2 w-24">Movs.</th>
                            <th class="px-2 py-2 w-56">Oficina</th>
                            <th class="px-2 py-2 w-56">Almacén</th>
                            <th class="px-2 py-2 w-28"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!bodegasVisibles.length">
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                                No hay bodegas propias que mapear.
                                <button v-if="!verSiigo" class="underline" @click="verSiigo = true">
                                    Ver también las importadas de SIIGO
                                </button>
                            </td>
                        </tr>
                        <tr
                            v-for="b in bodegasVisibles"
                            :key="b.id"
                            class="border-t border-gray-100"
                            :class="{ 'opacity-50': !b.activa }"
                        >
                            <td class="px-4 py-2">
                                <div class="font-medium text-gray-900">{{ b.nombre }}</div>
                                <div class="text-xs text-gray-500 font-mono">{{ b.codigo }}</div>
                            </td>
                            <td class="px-2 py-2 text-gray-600">{{ b.movimientos }}</td>
                            <td class="px-2 py-2">
                                <select v-if="borrador[b.id]" v-model="borrador[b.id].oficina_id"
                                        :disabled="!puede_cambiar"
                                        :aria-label="`Oficina de ${b.nombre}`"
                                        class="w-full text-sm rounded border-gray-300 disabled:bg-gray-100">
                                    <option :value="null">— sin asignar —</option>
                                    <option v-for="o in oficinas" :key="o.id" :value="o.id">
                                        {{ o.codigo }} · {{ o.nombre }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-2 py-2">
                                <select v-if="borrador[b.id]" v-model="borrador[b.id].almacen_id"
                                        :disabled="!puede_cambiar"
                                        :aria-label="`Almacén de ${b.nombre}`"
                                        class="w-full text-sm rounded border-gray-300 disabled:bg-gray-100">
                                    <option :value="null">— sin asignar —</option>
                                    <option v-for="a in almacenes" :key="a.id" :value="a.id">
                                        {{ a.codigo }} · {{ a.nombre }}
                                    </option>
                                </select>
                            </td>
                            <td class="px-2 py-2">
                                <button
                                    v-if="puede_cambiar"
                                    class="text-xs font-medium px-3 py-1.5 rounded bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-50"
                                    :disabled="guardando || !sinGuardar(b)"
                                    @click="mapear(b)"
                                >
                                    {{ sinGuardar(b) ? 'Guardar' : 'Guardado' }}
                                </button>
                                <span v-else class="text-xs text-gray-400">sólo Gerencia</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <!-- ===================== MODAL OFICINA ===================== -->
        <div
            v-if="editandoOficina"
            class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50"
            @click.self="editandoOficina = null"
        >
            <div class="bg-white rounded-xl w-full max-w-md p-5 space-y-3">
                <h3 class="font-semibold text-gray-900">Oficina {{ editandoOficina.codigo }}</h3>

                <label class="block text-sm">
                    <span class="text-gray-600">Código</span>
                    <input v-model="editandoOficina.codigo" maxlength="4" class="mt-1 w-full rounded border-gray-300" />
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Nombre</span>
                    <input v-model="editandoOficina.nombre" maxlength="80" class="mt-1 w-full rounded border-gray-300" />
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Observación</span>
                    <input v-model="editandoOficina.observacion" maxlength="200" class="mt-1 w-full rounded border-gray-300" />
                </label>

                <label class="flex items-start gap-2 text-sm">
                    <input v-model="editandoOficina.permite_ingreso_directo" type="checkbox" class="mt-1 rounded border-gray-300" />
                    <span>
                        <span class="text-gray-900">Admite ingreso directo</span>
                        <span class="block text-xs text-gray-500">
                            Puede recibir mercancía de un contenedor. Si se desmarca, sólo llega por traslado.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm">
                    <input v-model="editandoOficina.es_respaldo_surtido" type="checkbox" class="mt-1 rounded border-gray-300" />
                    <span>
                        <span class="text-gray-900">De aquí se completa el surtido</span>
                        <span class="block text-xs text-gray-500">
                            Cuando al alistador le falta mercancía en el rack, busca acá. Sólo una puede estarlo.
                        </span>
                    </span>
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="editandoOficina.activa" type="checkbox" class="rounded border-gray-300" />
                    <span class="text-gray-900">Activa</span>
                </label>

                <div class="flex justify-end gap-2 pt-2">
                    <button class="px-3 py-1.5 text-sm text-gray-600" @click="editandoOficina = null">Cancelar</button>
                    <button
                        class="px-4 py-1.5 text-sm rounded bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-50"
                        :disabled="guardando"
                        @click="guardarOficina"
                    >
                        Guardar
                    </button>
                </div>
            </div>
        </div>

        <!-- ===================== MODAL ALMACÉN ===================== -->
        <div
            v-if="editandoAlmacen"
            class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50"
            @click.self="editandoAlmacen = null"
        >
            <div class="bg-white rounded-xl w-full max-w-md p-5 space-y-3">
                <h3 class="font-semibold text-gray-900">Almacén {{ editandoAlmacen.codigo }}</h3>

                <label class="block text-sm">
                    <span class="text-gray-600">Código</span>
                    <input v-model="editandoAlmacen.codigo" maxlength="4" class="mt-1 w-full rounded border-gray-300" />
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Nombre</span>
                    <input v-model="editandoAlmacen.nombre" maxlength="80" class="mt-1 w-full rounded border-gray-300" />
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Estado físico</span>
                    <input
                        v-model="editandoAlmacen.estado_fisico"
                        maxlength="20"
                        placeholder="BUENO · DESPERFECTO · AVERÍA"
                        class="mt-1 w-full rounded border-gray-300"
                    />
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Cómo lo trata el inventario</span>
                    <select v-model="editandoAlmacen.categoria_equivalente" class="mt-1 w-full rounded border-gray-300">
                        <option v-for="c in categorias" :key="c.valor" :value="c.valor">{{ c.label }}</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Observación</span>
                    <input v-model="editandoAlmacen.observacion" maxlength="200" class="mt-1 w-full rounded border-gray-300" />
                </label>

                <label class="flex items-start gap-2 text-sm">
                    <input v-model="editandoAlmacen.comercial" type="checkbox" class="mt-1 rounded border-gray-300" />
                    <span>
                        <span class="text-gray-900">Se vende</span>
                        <span class="block text-xs text-gray-500">
                            Puede estar con desperfecto y venderse igual, con descuento.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm">
                    <input v-model="editandoAlmacen.permite_ingreso_directo" type="checkbox" class="mt-1 rounded border-gray-300" />
                    <span>
                        <span class="text-gray-900">Admite ingreso directo</span>
                        <span class="block text-xs text-gray-500">
                            Hoy sólo el 01 PRINCIPAL y el 07 GARANTÍA reciben del contenedor.
                        </span>
                    </span>
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="editandoAlmacen.activo" type="checkbox" class="rounded border-gray-300" />
                    <span class="text-gray-900">Activo</span>
                </label>

                <div class="flex justify-end gap-2 pt-2">
                    <button class="px-3 py-1.5 text-sm text-gray-600" @click="editandoAlmacen = null">Cancelar</button>
                    <button
                        class="px-4 py-1.5 text-sm rounded bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-50"
                        :disabled="guardando"
                        @click="guardarAlmacen"
                    >
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
