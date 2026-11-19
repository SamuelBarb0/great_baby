<script setup>
import { reactive, ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Truck, Save, ArrowLeft, AlertTriangle, FileText } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    oc: { type: Object, default: null },
    // Órdenes por recibir, para elegir una sin salir de esta pantalla.
    ocs_pendientes: { type: Array, default: () => [] },
    // A dónde puede entrar lo que se descarga: los pares oficina+almacén que
    // el área marcó como de ingreso directo. Vacío = la bodega de la orden.
    destinos: { type: Array, default: () => [] },
    // Las posiciones de cada bodega, indexadas por bodega_id.
    posiciones: { type: Object, default: () => ({}) },
    bodega_orden: { type: Object, default: null },
});

const ocsPendientes = computed(() => props.ocs_pendientes ?? []);

const { money } = useMoney();

const form = reactive({
    orden_id: props.oc?.id ?? '',
    remision_proveedor: '',
    factura_proveedor: '',
    transportista: '',
    observaciones: '',
    motivo_exceso: '',
    items: props.oc?.items.map(i => ({
        orden_item_id: i.id,
        descripcion: i.descripcion,
        cantidad_pendiente: i.cantidad_pendiente,
        cantidad_recibida: i.cantidad_pendiente,
        precio_unit: i.precio_unit,
        cajas: i.cajas,
        es_componente: i.es_componente,
        sin_producto: i.sin_producto,
        referencia: i.referencia,
        color_nombre: i.color_nombre || '',
        sin_colores: i.sin_colores,
        extra: false,
        repartir: false,
        unidades_por_caja: i.unidades_por_caja,
        destino: i.destino,
        para_otra_empresa: i.para_otra_empresa,
        lote: '',
        // A dónde va ESTA parte de la línea. Vacío = la bodega de la orden,
        // que es como entró todo hasta hoy.
        destino_ingreso: '',
        posicion_ingreso: '',
        // LOG-J8 · clasificación por línea · default apto para no romper el flujo
        //   actual, pero el logístico puede cambiarlo al descargar.
        clasificacion: 'apto',
        motivo_clasificacion: '',
    })) ?? [],
});
const procesando = ref(false);

/*
 * Resolver una línea que llegó sin producto del catálogo.
 *
 * No se bloquea la recepción: un flete o un seguro son líneas legítimas sin
 * mercancía. Lo que se cierra es el silencio — la línea se ve marcada y tiene
 * botón para resolverla sin salir de acá.
 */
const linea = ref(null);
const buscando = ref(false);
const encontrados = ref([]);
const guardandoProducto = ref(false);

const abrirProducto = (it) => {
    linea.value = {
        orden_item_id: it.orden_item_id,
        descripcion: it.descripcion,
        modo: 'buscar',
        q: it.referencia || it.descripcion || '',
        producto_id: null,
        producto_nombre: '',
        nombre: it.descripcion || '',
        referencia: it.referencia || '',
        desglose_stock: false,
        color_nombre: it.color_nombre || '',
    };
    encontrados.value = [];
    buscar();
};

const buscar = async () => {
    const q = (linea.value?.q || '').trim();
    if (q.length < 2) { encontrados.value = []; return; }
    buscando.value = true;
    try {
        const r = await fetch(`/app/catalogo/productos-buscar?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await r.json();
        encontrados.value = (Array.isArray(data) ? data : (data.datos || data.data || [])).slice(0, 12);
    } catch {
        encontrados.value = [];
    } finally {
        buscando.value = false;
    }
};

const guardarProducto = () => {
    if (!linea.value || guardandoProducto.value) return;
    const l = linea.value;
    if (l.modo === 'buscar' && !l.producto_id) return;
    if (l.modo === 'crear' && (!l.nombre.trim() || !l.referencia.trim())) return;

    guardandoProducto.value = true;
    router.post(`/app/compras/oc/linea/${l.orden_item_id}/producto`,
        l.modo === 'buscar'
            ? { producto_id: l.producto_id, color_nombre: l.color_nombre || null }
            : { nombre: l.nombre, referencia: l.referencia, desglose_stock: l.desglose_stock, color_nombre: l.color_nombre || null },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                /*
                 * Los avisos de la línea se refrescan acá, a mano.
                 *
                 * `form.items` se arma una sola vez al abrir la pantalla —y
                 * encima se repisa con el conteo guardado en el navegador—,
                 * así que recargar las props no lo toca: la línea seguía
                 * diciendo «no está en el catálogo» después de enlazarla, y
                 * quien está descargando no tiene por qué dudar de si quedó.
                 *
                 * Se copian TODAS las banderas que el servidor recalculó, no
                 * sólo `sin_producto`: el producto que acabo de enlazar puede
                 * tener stock sin colores, y ese otro aviso —«el color queda
                 * en esta recepción y el stock sigue siendo uno solo»— se
                 * quedaba con el valor de antes de que la línea tuviera
                 * producto, o sea en falso para siempre.
                 */
                // Las líneas vuelven dentro de `oc`, y ahí la clave de la
                // línea de la orden se llama `id`.
                const fresca = (page?.props?.oc?.items ?? [])
                    .find(f => f.id === l.orden_item_id);

                for (const it of form.items) {
                    if (it.orden_item_id !== l.orden_item_id) continue;

                    it.sin_producto = false;

                    if (fresca) {
                        it.sin_colores = fresca.sin_colores ?? it.sin_colores;
                        it.referencia = fresca.referencia ?? it.referencia;
                    }
                }
                linea.value = null;
            },
            onFinish: () => { guardandoProducto.value = false; },
        });
};

// Descargar un contenedor toma horas: si se cierra la página, no se pierde lo contado.
const claveConteo = props.oc ? `conteo-oc-${props.oc.id}` : null;
const hayConteoGuardado = ref(false);
// Hubo que ajustar algo de lo guardado contra la orden de hoy: se dice, no se
// corrige a escondidas.
const conteoRecortado = ref(false);
if (claveConteo) {
    try {
        const guardado = JSON.parse(localStorage.getItem(claveConteo) || 'null');
        if (Array.isArray(guardado)) {
            const base = Object.fromEntries(form.items.map(i => [i.orden_item_id, i]));
            
/*
 * Las posiciones que se le pueden ofrecer a este renglón.
 *
 * Dependen del destino elegido: cada par (oficina, almacén) resuelve a una
 * bodega, y sólo los estantes DE ESA bodega son un destino válido. Sin destino
 * elegido la mercancía va a la bodega de la orden, y ahí tampoco hay posición
 * que elegir hasta que se sepa a cuál.
 *
 * El servidor vuelve a comprobarlo al guardar: esto es comodidad, no candado.
 */
const posicionesDe = (fila) => {
    if (! fila?.destino_ingreso) return [];
    const d = props.destinos.find(x => x.valor === fila.destino_ingreso);
    if (! d?.bodega_id) return [];
    return props.posiciones[d.bodega_id] || [];
};

/*
 * Cambiar de destino limpia la posición: un estante de la bodega anterior no
 * existe en la nueva, y el servidor lo rechazaría al guardar.
 */
watch(() => form.items.map(f => f.destino_ingreso).join('|'), () => {
    form.items.forEach((f) => {
        if (f.posicion_ingreso && ! posicionesDe(f).some(p => p.id === f.posicion_ingreso)) {
            f.posicion_ingreso = '';
        }
    });
});

const destinosValidos = new Set(props.destinos.map(d => d.valor));

            /*
             * Lo guardado se revisa contra la orden de HOY antes de pintarlo.
             *
             * Entre el conteo de ayer y el de hoy la orden pudo recibir otra
             * entrega, así que el pendiente ya no es el mismo: restaurar 50
             * sobre un pendiente de 20 dejaba la pantalla mostrando un conteo
             * que el servidor iba a rechazar, y la persona no tenía forma de
             * saber de dónde salía ese número. La rama vieja —la del objeto—
             * sí tenía este tope; la nueva, la que soporta el reparto, se
             * quedó sin él.
             *
             * Y el destino también: un par que dejó de admitir ingreso, o un
             * almacén que Gerencia apagó, deja el select en blanco mandando el
             * valor viejo escondido adentro.
             */
            form.items = guardado
                .filter(g => base[g.orden_item_id])
                .map(g => {
                    const fila = { ...base[g.orden_item_id], ...g };

                    /*
                     * El tope NO se aplica en una importación.
                     *
                     * De China llega agrupado y en otras cantidades por color,
                     * así que contar más de lo que esa línea tenía pendiente es
                     * legítimo: el tope real es el total de la referencia, y de
                     * eso se encarga el aviso de excesos, que pide el motivo.
                     * Recortando acá se borraba en silencio el número físico que
                     * alguien contó con las cajas en la mano y se lo reemplazaba
                     * por el pendiente de la orden. Los otros cuatro sitios que
                     * topan sí preguntan si es importación; éste no preguntaba.
                     */
                    const tope = Number(base[g.orden_item_id].cantidad_pendiente || 0);
                    if (! props.oc?.es_importacion && Number(fila.cantidad_recibida) > tope) {
                        fila.cantidad_recibida = tope;
                        conteoRecortado.value = true;
                    }
                    if (fila.destino_ingreso && !destinosValidos.has(fila.destino_ingreso)) {
                        fila.destino_ingreso = '';
                        conteoRecortado.value = true;
                    }
                    return fila;
                });
            hayConteoGuardado.value = true;
        } else if (guardado) {
            for (const it of form.items) {
                const g = guardado[it.orden_item_id];
                if (g && Number(g.cantidad_recibida) <= it.cantidad_pendiente) Object.assign(it, g);
            }
            hayConteoGuardado.value = true;
        }
    } catch { /* sin almacenamiento local: el conteo sigue funcionando, sólo no se recuerda */ }
    watch(() => form.items, (items) => {
        try {
            localStorage.setItem(claveConteo, JSON.stringify(items.map(i => ({
                orden_item_id: i.orden_item_id, cantidad_recibida: i.cantidad_recibida, lote: i.lote,
                clasificacion: i.clasificacion, motivo_clasificacion: i.motivo_clasificacion,
                color_nombre: i.color_nombre, extra: i.extra, destino_ingreso: i.destino_ingreso,
            }))));
        } catch { /* ídem */ }
    }, { deep: true });
}

// Otro color dentro de la misma línea: de China llega agrupado.
const otroColor = (it, color = '') => {
    form.items.splice(form.items.indexOf(it) + 1, 0, {
        ...it, cantidad_recibida: 0, color_nombre: color, lote: '', clasificacion: 'apto', motivo_clasificacion: '', extra: true, repartir: true,
    });
};
const quitarFila = (it) => form.items.splice(form.items.indexOf(it), 1);

// Repartir una línea entre dos destinos: «de estas 50, 30 a Great Baby y 20 a
// Dropi». Es el mismo mecanismo de los colores —un renglón hermano justo
// debajo— para que no haya dos formas distintas de partir una línea.
const otroDestino = (it) => {
    /*
     * Lo que queda es el pendiente menos lo que YA está contado en todos los
     * renglones de esa línea, no sólo en este.
     *
     * Restando sólo este renglón, partir una línea en tres proponía de más:
     * 40 pendientes repartidos 20/20 ofrecían otros 20 para el tercero, o sea
     * 60 de una orden de 40, y la persona tenía que darse cuenta sola. Y
     * cuando ya no queda nada, el renglón nuevo nace en cero —que es correcto,
     * pero hay que decirlo.
     */
    const yaContado = form.items
        .filter(o => o.orden_item_id === it.orden_item_id)
        .reduce((t, o) => t + Number(o.cantidad_recibida || 0), 0);

    const restante = Math.max(0, Number(it.cantidad_pendiente || 0) - yaContado);

    form.items.splice(form.items.indexOf(it) + 1, 0, {
        ...it, cantidad_recibida: restante, lote: '', destino_ingreso: '', posicion_ingreso: '',
        clasificacion: 'apto', motivo_clasificacion: '', extra: true, repartir: true,
    });
};

// Cuánto se está mandando a cada sitio, para que quien cuenta lo vea sin sumar
// de cabeza.
const repartoDe = (it) => form.items
    .filter(o => o.orden_item_id === it.orden_item_id && Number(o.cantidad_recibida) > 0)
    .map(o => ({
        /*
         * El destino elegido sólo se respeta si la mercancía llegó apta.
         *
         * Lo que llegó roto entra a averías y lo marcado «faltante» no entra a
         * ninguna parte: no se recibió. Este resumen es lo que mira quien está
         * descargando para confirmar antes de guardar, y decía que 10 unidades
         * averiadas iban a la bodega principal.
         */
        destino: o.clasificacion === 'faltante'
            ? 'no entra · no llegó'
            : (o.clasificacion && o.clasificacion !== 'apto')
                ? `averías · ${o.clasificacion}`
                : (o.destino_ingreso
                    ? (props.destinos.find(d => d.valor === o.destino_ingreso)?.label ?? '—')
                    : (props.bodega_orden?.nombre ?? 'la bodega de la orden')),
        cantidad: Number(o.cantidad_recibida),
        aparte: o.clasificacion && o.clasificacion !== 'apto',
    }));

const estaRepartida = (it) => form.items.filter(o => o.orden_item_id === it.orden_item_id).length > 1;

// Las líneas que se partieron en más de un destino, con la suma, para avisar
// antes de guardar si se está repartiendo más de lo que queda pendiente.
const lineasRepartidas = computed(() => {
    const vistas = new Set();
    const salida = [];
    for (const it of form.items) {
        if (vistas.has(it.orden_item_id)) continue;
        vistas.add(it.orden_item_id);
        const partes = repartoDe(it);
        if (partes.length < 2) continue;
        salida.push({
            orden_item_id: it.orden_item_id,
            descripcion: it.descripcion,
            pendiente: Number(it.cantidad_pendiente || 0),
            partes,
            // Lo faltante no cuenta contra el pendiente: no entra al
            // inventario y la orden lo sigue teniendo pendiente. Sumándolo, el
            // aviso rojo salía por una suma que estaba bien.
            suma: partes.filter(p => !p.destino.startsWith('no entra'))
                .reduce((s, p) => s + p.cantidad, 0),
        });
    }
    return salida;
});

// La cuenta que importa es la de la referencia: cada color puede subir o bajar.
const cuentas = computed(() => {
    const grupos = {};
    for (const it of form.items) {
        if (it.es_componente) continue;
        const clave = it.referencia || `Línea ${it.orden_item_id}`;
        grupos[clave] ??= { referencia: clave, contado: 0, esperado: 0, lineas: new Set() };
        const g = grupos[clave];
        if (!g.lineas.has(it.orden_item_id)) {
            g.lineas.add(it.orden_item_id);
            g.esperado += Number(it.cantidad_pendiente) || 0;
        }
        if (it.clasificacion !== 'faltante') g.contado += Number(it.cantidad_recibida) || 0;
    }
    return Object.values(grupos);
});
const excesos = computed(() => props.oc?.es_importacion ? cuentas.value.filter(c => c.contado > c.esperado + 0.0001) : []);
const conReparto = computed(() => props.oc?.es_importacion ? cuentas.value.filter(c => c.lineas.size > 1 || form.items.some(i => i.extra && (i.referencia || `Línea ${i.orden_item_id}`) === c.referencia)) : []);
const distinto = (it) => props.oc?.es_importacion && !it.es_componente && !it.extra && Number(it.cantidad_recibida) !== Number(it.cantidad_pendiente);

const cajasOtraEmpresa = computed(() => form.items.filter(i => i.para_otra_empresa));
const sinReferencia = computed(() => props.oc?.items.filter(i => i.no_sube) ?? []);

// Re-audit M2 UX-A7 · clamp reactivo — antes `:max` era solo hint HTML, el
// usuario podía escribir 30 sobre pendiente 20 y romper el saldo.
const clampCantidad = (it) => {
    const n = Number(it.cantidad_recibida);
    if (isNaN(n) || n < 0) it.cantidad_recibida = 0;
    // En una importación el tope es el total de la referencia, no cada línea.
    else if (!props.oc?.es_importacion && n > it.cantidad_pendiente) it.cantidad_recibida = it.cantidad_pendiente;
};

// Re-audit M2 UX-M12 · deshabilitar submit si ningún ítem tiene cantidad > 0.
const itemsAConfirmar = computed(() => form.items.filter(i => Number(i.cantidad_recibida) > 0));
// Lo marcado como faltante no llegó: no suma, igual que al guardar.
const valorLinea = (i) => (i.clasificacion === 'faltante' ? 0 : Number(i.cantidad_recibida || 0) * Number(i.precio_unit || 0));
const totalRecepcion = computed(() => itemsAConfirmar.value.reduce((s, i) => s + valorLinea(i), 0));

const guardar = () => {
    if (procesando.value || itemsAConfirmar.value.length === 0) return;
    if (excesos.value.length && !form.motivo_exceso.trim()) return;
    procesando.value = true;
    router.post('/app/compras/recepcion', {
        orden_id: form.orden_id,
        remision_proveedor: form.remision_proveedor,
        factura_proveedor: form.factura_proveedor,
        transportista: form.transportista,
        observaciones: form.observaciones,
        motivo_exceso: form.motivo_exceso || null,
        items: itemsAConfirmar.value.map(i => ({
            orden_item_id: i.orden_item_id,
            cantidad_recibida: i.cantidad_recibida,
            lote: i.lote || null,
            clasificacion: i.clasificacion || 'apto',
            motivo_clasificacion: i.motivo_clasificacion || null,
            color_nombre: i.color_nombre || null,
            destino_ingreso: i.destino_ingreso || null,
            posicion_ingreso: i.posicion_ingreso || null,
        })),
    }, {
        preserveScroll: true,
        onSuccess: () => { try { localStorage.removeItem(claveConteo); } catch { /* ídem */ } },
        onFinish: () => procesando.value = false,
    });
};
</script>

<template>
    <Head title="Recibir mercancía"/>
    <AppLayout>
        <div class="max-w-4xl mx-auto space-y-4">
            <Link :href="oc?.importacion_id ? `/app/compras/importacion/${oc.importacion_id}` : (oc ? `/app/compras/oc/${oc.id}` : '/app/compras')" class="btn-ghost inline-flex items-center gap-1 text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver
            </Link>

            <h1 class="text-2xl font-bold flex items-center gap-2">
                <Truck class="h-6 w-6 text-brand-600"/>
                Recibir mercancía
            </h1>

            <div v-if="$page.props.errors && Object.keys($page.props.errors).length" class="p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm space-y-1">
                <div v-for="(msg, k) in $page.props.errors" :key="k">{{ msg }}</div>
            </div>

            <!-- Sin OC elegida: se listan acá las que están por llegar. Antes
                 esto mandaba a /app/compras, que para quien recibe la mercancía
                 responde 403. -->
            <div v-if="!oc" class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800">
                    <h3 class="font-semibold">Elegí la orden que estás recibiendo</h3>
                    <p class="text-xs text-surface-500 mt-0.5">
                        Órdenes aprobadas o recibidas a medias, de la más reciente a la más vieja.
                    </p>
                </div>

                <div v-if="!ocsPendientes.length" class="p-8 text-center text-surface-500">
                    No hay órdenes de compra pendientes por recibir.
                </div>

                <table v-tabla-movil v-else class="w-full text-sm">
                    <thead class="text-xs uppercase text-surface-500 bg-surface-50 dark:bg-surface-900 border-b">
                        <tr>
                            <th class="p-3 text-left">Orden</th>
                            <th class="p-3 text-left">Proveedor</th>
                            <th class="p-3 text-left">Contenedor</th>
                            <th class="p-3 text-left">Estado</th>
                            <th class="p-3 text-left">Se esperaba</th>
                            <th class="p-3 text-right">Total</th>
                            <th class="p-3 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                        <tr v-for="o in ocsPendientes" :key="o.id" class="hover:bg-brand-50/40 dark:hover:bg-brand-950/20">
                            <td class="p-3 font-mono text-xs font-bold">{{ o.numero }}</td>
                            <td class="p-3">{{ o.proveedor || '—' }}</td>
                            <td class="p-3 font-mono text-xs">{{ o.contenedor || '—' }}</td>
                            <td class="p-3 text-xs">{{ o.estado }}</td>
                            <td class="p-3 text-xs">{{ o.fecha_esperada || '—' }}</td>
                            <td class="p-3 text-right">{{ money(o.total) }}</td>
                            <td class="p-3 text-right">
                                <Link :href="`/app/compras/recepcion/nueva?oc=${o.id}`"
                                      class="inline-flex items-center px-3 py-2 min-h-11 bg-brand-600 hover:bg-brand-700 text-white rounded text-xs font-bold">
                                    Recibir esta →
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="space-y-4">
                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-1">OC de origen</div>
                    <div class="font-mono font-bold">{{ oc.numero }}</div>
                    <div class="text-sm text-surface-500">{{ oc.proveedor }}</div>
                    <div v-if="oc.contenedor" class="text-sm text-surface-500">Contenedor <span class="font-mono">{{ oc.contenedor }}</span></div>
                    <p v-if="oc.es_importacion" class="text-sm mt-2">Cuente producto por producto y corrija lo que llegó distinto. <b>Nada sube al inventario hasta que confirme.</b></p>
                    <p v-if="hayConteoGuardado" class="text-xs text-surface-500 mt-1">Se recuperó su conteo anterior.</p>
                    <!-- Si hubo que ajustar algo contra la orden de hoy se dice: corregir
                         números a escondidas deja a quien cuenta sin entender qué pasó. -->
                    <p v-if="conteoRecortado" class="text-xs text-amber-700 dark:text-amber-400 mt-1">
                        Ojo: la orden cambió desde ese conteo. Se ajustó alguna cantidad al pendiente de hoy
                        y se borró algún destino que ya no admite ingreso. Revisá antes de guardar.
                    </p>
                </div>

                <div v-if="oc.falta_tasa" class="p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border-l-4 border-red-500 text-red-700 dark:text-red-300 text-sm">{{ oc.falta_tasa }}</div>

                <div v-if="sinReferencia.length" class="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-sm">
                    <b>{{ sinReferencia.length }} línea(s) sin referencia no subirán al inventario.</b>
                    Asígnesela en la <a :href="`/app/compras/oc/${oc.id}`" class="underline">orden de compra</a> antes de confirmar.
                </div>

                <datalist id="colores-recepcion"><option v-for="c in oc.colores" :key="c" :value="c"/></datalist>

                <div v-if="conReparto.length" class="p-3 rounded-lg bg-surface-50 dark:bg-surface-900 border-l-4 border-surface-400 text-sm">
                    <b>Total por referencia.</b> Cada color puede subir o bajar; lo que importa es que el total cuadre.
                    <div v-for="c in conReparto" :key="c.referencia" :class="c.contado !== c.esperado ? 'text-amber-700 dark:text-amber-400 font-semibold' : ''">
                        {{ c.referencia }}: {{ c.contado }} de {{ c.esperado }}
                    </div>
                </div>

                <div v-if="excesos.length" class="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-sm space-y-2">
                    <div><b>Se contó de más:</b> <span v-for="(c, n) in excesos" :key="c.referencia"><template v-if="n">, </template>{{ c.referencia }} ({{ c.contado }} de {{ c.esperado }})</span>. Se puede guardar, pero diga por qué.</div>
                    <input v-model="form.motivo_exceso" maxlength="300" required class="input w-full text-sm" placeholder="Motivo de lo que llegó de más" aria-label="Motivo de lo que llegó de más"/>
                </div>

                <div v-if="cajasOtraEmpresa.length" class="p-3 rounded-lg bg-sky-50 dark:bg-sky-950/40 border-l-4 border-sky-500 text-sky-800 dark:text-sky-200 text-sm">
                    <b>Para separar:</b>
                    <div v-for="i in cajasOtraEmpresa" :key="i.orden_item_id">{{ i.cajas }} cajas de {{ i.descripcion }} son para {{ i.destino }}.</div>
                </div>

                <!--
                    Los papeles del contenedor, acá, donde se cuenta.
                    Quien descarga cuenta CONTRA la factura final y la lista de
                    empaque que mandó China, no contra lo que la orden pidió
                    hace tres meses. Estaban cargados, pero en otra pantalla.
                -->
                <div v-if="oc?.documentos?.length" class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2 flex items-center gap-1.5">
                        <FileText class="h-3.5 w-3.5"/>
                        Papeles del contenedor {{ oc.contenedor }} · cuente contra éstos
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="d in oc.documentos" :key="d.id"
                              :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs',
                                       d.clave
                                           ? 'border-brand-500 bg-brand-50 text-brand-800 font-semibold dark:bg-brand-950/40 dark:text-brand-200'
                                           : 'border-surface-200 text-surface-600']">
                            <a :href="d.url" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 hover:underline">
                                <FileText class="h-3.5 w-3.5 flex-shrink-0"/>
                                <span>{{ d.tipo }}</span>
                                <span class="text-surface-400 font-normal truncate max-w-[13rem]">{{ d.nombre }}</span>
                            </a>
                            <!-- Compararlo con la orden ANTES de contar: saber que
                                 embarcaron 8.700 donde se pidieron 9.000 cambia cómo
                                 se cuenta. El comparador existía, pero sólo se llegaba
                                 desde la pantalla del contenedor. -->
                            <a v-if="d.comparar_url" :href="d.comparar_url"
                               class="ml-1 pl-2 border-l border-current/30 hover:underline whitespace-nowrap">
                                comparar con la orden
                            </a>
                        </span>
                    </div>
                </div>

                <!-- Sin papeles cargados hay que decirlo: contar sin la factura final
                     es contar contra lo que se pidió, no contra lo que embarcaron. -->
                <div v-else-if="oc?.es_importacion && oc?.importacion_id"
                     class="card p-3 border-l-4 border-amber-500 bg-amber-50 dark:bg-amber-950/30 text-xs text-amber-900 dark:text-amber-200 flex items-start gap-2">
                    <AlertTriangle class="h-4 w-4 flex-shrink-0 mt-0.5"/>
                    <div>
                        El contenedor <strong>{{ oc.contenedor }}</strong> no tiene cargada la factura final
                        ni la lista de empaque. Sin ellas se cuenta contra lo que se pidió, no contra lo que
                        de verdad embarcaron.
                        <a :href="`/app/compras/importacion/${oc.importacion_id}?tab=documentos`"
                           class="underline font-semibold">Cargarlas en el contenedor</a>.
                    </div>
                </div>

                <div class="card p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Remisión proveedor</label><input v-model="form.remision_proveedor" class="input w-full" maxlength="100"/></div>
                    <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Factura proveedor</label><input v-model="form.factura_proveedor" class="input w-full" maxlength="100"/></div>
                    <div><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Transportista</label><input v-model="form.transportista" class="input w-full" maxlength="100"/></div>
                    <div class="md:col-span-3"><label class="text-xs font-semibold text-surface-600 dark:text-surface-300">Observaciones</label><textarea v-model="form.observaciones" rows="2" class="input w-full" maxlength="1000"></textarea></div>
                </div>

                <!-- Una orden ya recibida no es un error, pero la pantalla tiene que
                     decirlo: antes mostraba una tabla vacía con el texto genérico
                     «no hay nada con estos filtros», y acá no hay filtros. -->
                <div v-if="oc && !form.items.length"
                     class="card p-4 border-l-4 border-emerald-500 bg-emerald-50/60 dark:bg-emerald-950/30">
                    <div class="font-bold text-emerald-900 dark:text-emerald-200">Esta orden ya se recibió completa</div>
                    <p class="text-sm text-surface-700 dark:text-surface-300 mt-0.5">
                        No le queda nada pendiente, así que no hay nada que contar.
                        <Link :href="`/app/compras/oc/${oc.id}`" class="underline font-medium">Ver la orden</Link>
                        o <Link href="/app/compras/recepcion/nueva" class="underline font-medium">elegir otra</Link>.
                    </p>
                </div>

                <div class="card p-4">
                    <div class="text-xs uppercase font-bold text-brand-600 mb-2">Ítems a recibir ({{ form.items.length }})</div>
                    <div class="overflow-x-auto">
                        <table v-tabla-movil data-vacia="Esta orden ya se recibió completa: no queda nada pendiente." class="w-full text-sm">
                            <thead class="text-[10px] text-surface-500 uppercase border-b border-surface-200 dark:border-surface-800">
                                <tr>
                                    <th class="text-left p-2">Descripción</th>
                                    <th class="text-right p-2">Pendiente</th>
                                    <th class="text-right p-2">A recibir</th>
                                    <th class="text-right p-2">Subtotal línea</th>
                                    <th class="text-left p-2">Lote</th>
                                    <!-- A qué bodega entra esta parte de la línea: el par oficina+almacén. -->
                                    <th class="text-left p-2">Va para</th>
                                    <!-- LOG-J8 · en qué estado llegó · cierra el descuadre que mencionó Don Jorge -->
                                    <th class="text-left p-2">Estado</th>
                                    <th class="text-left p-2">Motivo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                                <tr v-for="(it, i) in form.items" :key="i"
                                    :class="it.clasificacion !== 'apto' || distinto(it) ? 'bg-amber-50/40 dark:bg-amber-950/20' : ''">
                                    <td class="p-2" :title="it.descripcion">
                                        {{ it.descripcion }}
                                        <span v-if="it.es_componente" class="block text-xs text-sky-700 dark:text-sky-400 font-semibold">Componente · se cuenta, pero no sube al inventario</span>
                                        <button v-if="oc.es_importacion && !it.es_componente && !it.repartir && !it.color_nombre && !it.extra"
                                                type="button" @click="it.repartir = true" class="block text-xs text-brand-600 hover:underline mt-0.5">Repartir por color</button>
                                        <span v-else-if="oc.es_importacion && !it.es_componente" class="flex flex-wrap items-center gap-1 mt-1">
                                            <input v-model="it.color_nombre" list="colores-recepcion" maxlength="60" placeholder="Color"
                                                   class="input w-40 text-xs" :aria-label="`Color de ${it.descripcion}`"/>
                                            <button type="button" @click="otroColor(it)" class="btn-ghost text-xs">+ Otro color</button>
                                            <button type="button" @click="otroColor(it, 'Sin identificar')" class="btn-ghost text-xs">+ Sin identificar</button>
                                            <button v-if="it.extra" type="button" @click="quitarFila(it)" class="btn-ghost text-xs text-red-600">Quitar</button>
                                        </span>
                                        <span v-if="it.sin_colores && it.color_nombre" class="block text-xs text-amber-700 dark:text-amber-400 mt-0.5">Este producto ya tiene stock sin colores: el color queda en esta recepción y el stock sigue siendo uno solo.</span>
                                        <!-- Sin producto del catálogo esta línea NO suma inventario. A
                                             veces está bien (un flete, un seguro); lo que no puede ser es
                                             que nadie se entere. -->
                                        <span v-if="it.sin_producto && !it.extra" class="block mt-1 text-xs">
                                            <span class="inline-block px-1.5 py-0.5 rounded bg-amber-100 text-amber-900 font-semibold">
                                                No está en el catálogo · no va a sumar inventario
                                            </span>
                                            <button type="button" @click="abrirProducto(it)" class="ml-1 text-brand-600 hover:underline font-medium">
                                                Enlazar o crear producto
                                            </button>
                                        </span>
                                        <span v-if="it.cajas" class="block text-xs text-surface-500">{{ it.cajas }} cajas de {{ it.unidades_por_caja }}<template v-if="it.destino"> · {{ it.destino }}</template></span>
                                    </td>
                                    <td class="p-2 text-right font-mono">{{ it.cantidad_pendiente }}</td>
                                    <td class="p-2">
                                        <!-- Es la casilla que más se usa de la pantalla y no tenía
                                             etiqueta: con ocho columnas y la tabla deslizándose de
                                             lado, sin ver bien no se sabe en cuál se está escribiendo. -->
                                        <input type="number" step="0.001" min="0" :max="oc.es_importacion ? null : it.cantidad_pendiente"
                                               v-model.number="it.cantidad_recibida"
                                               @change="clampCantidad(it)"
                                               @blur="clampCantidad(it)"
                                               :aria-label="`Cantidad recibida de ${it.descripcion}`"
                                               class="input w-24 text-right text-sm"/>
                                    </td>
                                    <td class="p-2 text-right font-mono text-xs">{{ money(valorLinea(it)) }}</td>
                                    <td class="p-2"><input v-model="it.lote" class="input w-full text-sm" placeholder="Opcional" maxlength="80"
                                                           :aria-label="`Lote de ${it.descripcion}`"/></td>
                                    <td class="p-2">
                                        <select v-model="it.destino_ingreso"
                                                :disabled="it.es_componente || it.clasificacion !== 'apto'"
                                                class="input text-xs w-full"
                                                :aria-label="`Destino de ${it.descripcion}`">
                                            <option value="">{{ bodega_orden?.nombre || 'La bodega de la orden' }}</option>
                                            <option v-for="d in destinos" :key="d.valor" :value="d.valor">{{ d.label }}</option>
                                        </select>
                                        <!--
                                            En qué estante quedó.
                                            El procedimiento del área lo pide: «el registro deberá
                                            contener también la nomenclatura de la ubicación
                                            asignada», y su flujograma pone el almacenamiento en
                                            racks ANTES del ingreso al sistema. Cuando quien recibe
                                            ya bajó la caja a su estante, puede decirlo acá y se
                                            ahorra el traslado posterior.
                                            Sólo aparece si esa bodega tiene posiciones cargadas.
                                        -->
                                        <select v-if="posicionesDe(it).length"
                                                v-model="it.posicion_ingreso"
                                                :disabled="it.es_componente || it.clasificacion !== 'apto'"
                                                class="input text-xs w-full mt-1"
                                                :aria-label="`Posición dentro de la bodega para ${it.descripcion}`">
                                            <option value="">Sin posición · entra a la bodega</option>
                                            <option v-for="p in posicionesDe(it)" :key="p.id" :value="p.id">{{ p.codigo }}</option>
                                        </select>

                                        <button v-if="!it.es_componente && it.clasificacion === 'apto'"
                                                type="button" @click="otroDestino(it)"
                                                class="btn-ghost text-xs mt-0.5">+ Repartir</button>
                                        <!-- En una importación el «Quitar» ya está arriba, junto al
                                             color: con los dos, el mismo renglón mostraba dos botones
                                             rojos idénticos y no se sabía si borraban cosas distintas. -->
                                        <button v-if="it.extra && (!oc.es_importacion || it.es_componente)"
                                                type="button" @click="quitarFila(it)"
                                                class="btn-ghost text-xs text-red-600 mt-0.5">Quitar</button>
                                        <span v-if="it.clasificacion !== 'apto'" class="block text-[10px] text-surface-500 mt-0.5">
                                            Lo que no llegó apto va a la zona no vendible, no acá.
                                        </span>
                                    </td>
                                    <td class="p-2">
                                        <select v-model="it.clasificacion" class="input text-xs w-full"
                                                :aria-label="`Cómo llegó ${it.descripcion}`">
                                            <option value="apto">✓ Apto venta</option>
                                            <option value="averia">⚠ Avería</option>
                                            <option value="cuarentena">⏳ Cuarentena</option>
                                            <option value="revision">🔍 Revisión</option>
                                            <option value="faltante">✕ Faltante</option>
                                        </select>
                                    </td>
                                    <td class="p-2">
                                        <input v-model="it.motivo_clasificacion"
                                               :placeholder="it.clasificacion === 'apto' ? '—' : 'Describí la novedad'"
                                               :required="it.clasificacion !== 'apto'"
                                               class="input w-full text-xs" maxlength="180"/>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t-2 border-surface-300 dark:border-surface-700 font-bold">
                                <tr>
                                    <td colspan="3" class="p-2 text-right">Total recepción</td>
                                    <td class="p-2 text-right font-mono">{{ money(totalRecepcion) }}</td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Quien cuenta no tiene por qué sumar de cabeza: si una
                         línea se repartió, acá se ve a dónde fue cada parte. -->
                    <div v-if="lineasRepartidas.length" class="mt-3 rounded-lg border border-brand-200 dark:border-brand-900 bg-brand-50/60 dark:bg-brand-950/30 p-3 text-xs space-y-1.5">
                        <div class="font-semibold text-brand-700 dark:text-brand-300">Cómo queda repartido</div>
                        <div v-for="l in lineasRepartidas" :key="l.orden_item_id">
                            <span class="font-medium">{{ l.descripcion }}</span>
                            <span class="text-surface-500"> · pendiente {{ l.pendiente }}</span>
                            <ul class="ml-3 mt-0.5">
                                <li v-for="(p, k) in l.partes" :key="k"
                                    :class="p.aparte ? 'text-amber-700 dark:text-amber-400' : ''">
                                    {{ p.cantidad }} → {{ p.destino }}
                                </li>
                            </ul>
                            <div v-if="l.suma > l.pendiente + 0.0001" class="text-red-600 font-semibold">
                                Está repartiendo {{ l.suma }} y sólo quedan {{ l.pendiente }} pendientes.
                            </div>
                        </div>
                    </div>

                    <div v-if="itemsAConfirmar.length === 0" class="mt-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border-l-4 border-amber-500 text-amber-800 dark:text-amber-200 text-xs flex items-start gap-2">
                        <AlertTriangle class="h-4 w-4 flex-shrink-0 mt-0.5"/>
                        <div>Marca al menos un ítem con cantidad mayor a 0 antes de confirmar.</div>
                    </div>
                </div>

                <div class="h-16" aria-hidden="true"></div>
                <div class="card p-3 fixed bottom-4 right-4 left-4 md:left-auto md:right-6 md:max-w-2xl z-30 flex items-center justify-between gap-3 flex-wrap shadow-lg">
                    <p class="text-sm">Total a recibir: <b>{{ money(totalRecepcion) }}</b></p>
                    <button @click="guardar" :disabled="procesando || itemsAConfirmar.length === 0 || !!oc.falta_tasa" class="btn-primary disabled:opacity-50">
                        <Save class="h-4 w-4"/> {{ procesando ? 'Guardando…' : (oc.es_importacion ? 'Aceptar y subir al inventario' : 'Confirmar recepción') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ============ Resolver la línea sin producto ============ -->
        <div v-if="linea" class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50" @click.self="linea = null">
            <div class="bg-white dark:bg-surface-900 rounded-xl w-full max-w-lg p-5 space-y-3">
                <div>
                    <h3 class="font-semibold">Producto de esta línea</h3>
                    <p class="text-xs text-surface-500 mt-0.5">{{ linea.descripcion }}</p>
                </div>

                <div class="flex gap-2 text-sm">
                    <button type="button" @click="linea.modo = 'buscar'"
                            :class="linea.modo === 'buscar' ? 'bg-brand-600 text-white' : 'bg-surface-100 dark:bg-surface-800'"
                            class="px-3 py-1.5 rounded">Buscar uno que ya existe</button>
                    <button type="button" @click="linea.modo = 'crear'"
                            :class="linea.modo === 'crear' ? 'bg-brand-600 text-white' : 'bg-surface-100 dark:bg-surface-800'"
                            class="px-3 py-1.5 rounded">Crear uno nuevo</button>
                </div>

                <template v-if="linea.modo === 'buscar'">
                    <input v-model="linea.q" @input="buscar" placeholder="Referencia, nombre o color…"
                           class="input w-full text-sm" aria-label="Buscar producto"/>
                    <div class="max-h-56 overflow-y-auto border border-surface-200 dark:border-surface-800 rounded">
                        <div v-if="buscando" class="p-3 text-xs text-surface-500">Buscando…</div>
                        <div v-else-if="!encontrados.length" class="p-3 text-xs text-surface-500">
                            Nada con eso. Si de verdad no existe, creálo en la otra pestaña.
                        </div>
                        <button v-for="p in encontrados" :key="p.id ?? p.producto_id" type="button"
                                @click="linea.producto_id = p.id ?? p.producto_id; linea.producto_nombre = p.nombre"
                                class="w-full text-left px-3 py-2 text-sm hover:bg-surface-50 dark:hover:bg-surface-800 border-b border-surface-100 dark:border-surface-800"
                                :class="(linea.producto_id === (p.id ?? p.producto_id)) ? 'bg-brand-50 dark:bg-brand-950/40' : ''">
                            <span class="font-medium">{{ p.nombre }}</span>
                            <span class="text-xs text-surface-500 block">{{ p.referencia || p.sku }}</span>
                        </button>
                    </div>
                </template>

                <template v-else>
                    <label class="block text-sm">
                        <span class="text-surface-600">Nombre</span>
                        <input v-model="linea.nombre" maxlength="200" class="input w-full mt-1"/>
                    </label>
                    <label class="block text-sm">
                        <span class="text-surface-600">Referencia / SKU</span>
                        <input v-model="linea.referencia" maxlength="100" class="input w-full mt-1"/>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="linea.desglose_stock" type="checkbox" class="mt-1 rounded"/>
                        <span>
                            <span>Se cuenta por color</span>
                            <span class="block text-xs text-surface-500">
                                Decidilo ahora: un producto que ya tiene movimientos no puede empezar a
                                contar por color sin rehacer su kardex.
                            </span>
                        </span>
                    </label>
                </template>

                <label v-if="linea.desglose_stock || linea.modo === 'buscar'" class="block text-sm">
                    <span class="text-surface-600">Color (opcional)</span>
                    <input v-model="linea.color_nombre" maxlength="60" class="input w-full mt-1" placeholder="Sólo si se cuenta por color"/>
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" class="btn-ghost text-sm" @click="linea = null">Cancelar</button>
                    <button type="button" class="btn-primary text-sm"
                            :disabled="guardandoProducto"
                            @click="guardarProducto">Guardar</button>
                </div>
            </div>
        </div>

    </AppLayout>
</template>
