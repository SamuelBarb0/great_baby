<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Printer, ArrowLeft } from 'lucide-vue-next';

const props = defineProps({
    pedido: { type: Object, required: true },
    // La lista PLANA de todo el pedido. Se queda: es el respaldo cuando el
    // reparto por zonas no está configurado.
    items: { type: Array, required: true },
    // El mismo contenido repartido por zona, con la cantidad que sale de cada
    // una. Vacío si el servidor no lo mandó.
    secciones: { type: Array, default: () => [] },
    zona_filtrada: { type: String, default: null },
});

const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n || 0);
const imprimir = () => window.print();

/*
 * TRAER DE EXHIBICIÓN
 *
 * La hoja ya decía «tome de Exhibición y reporte el traslado», pero el
 * alistador no tenía dónde reportarlo: la pantalla de traslados es del jefe de
 * bodega. O se llevaba la mercancía sin registrar —y Exhibición quedaba
 * descuadrada— o el pedido esperaba.
 *
 * El botón va acá, junto al aviso, y no en otra pantalla: es el momento en que
 * la persona se entera de que le falta. `print:hidden` lo saca del papel.
 */
const claveDe = (it) => it.variante_id ? `v${it.variante_id}` : `p${it.producto_id}`;
const traer = reactive({});
const surtiendo = ref(null);

const surtir = (it) => {
    const clave = claveDe(it);
    const cantidad = Number(traer[clave] || 0);
    const respaldo = it.ubicaciones.find((u) => u.es_respaldo);

    if (! cantidad || ! respaldo) {
        return;
    }

    surtiendo.value = clave;

    router.post(`/app/logistica/cola-jorge/${props.pedido.id}/surtir`, {
        origen_id: respaldo.id,
        producto_id: it.producto_id,
        variante_id: it.variante_id,
        cantidad,
    }, {
        preserveScroll: true,
        // Al volver, la hoja se recarga con los saldos ya descontados: así se
        // ve que la mercancía salió de Exhibición y no hay que creerle al
        // mensaje.
        onFinish: () => {
            surtiendo.value = null;
            traer[clave] = null;
        },
    });
};

// LOG-J6 · si Jorge llega acá presionando "Imprimir", disparamos el print
//   automáticamente a los 300ms para que no tenga que buscar el botón.
onMounted(() => {
    if (new URL(window.location.href).searchParams.get('auto') === '1') {
        setTimeout(() => window.print(), 300);
    }
});

const totalUnidades = (items) => items.reduce((acc, it) => acc + (it.cantidad || 0), 0);

/*
 * UNA TABLA POR ZONA, Y UNA SOLA COPIA DEL MARKUP.
 *
 * El procedimiento del área dice «picking por zonas» y la hoja lo decía nada
 * más en una casilla: salía UNA lista con los renglones de las tres zonas
 * mezclados. Un pedido compartido terminaba alistado de dos maneras, las dos
 * malas: una persona lo caminaba entero —justo lo que el reparto por zonas
 * existe para evitar— o se fotocopiaba la hoja y cada quien tachaba a ojo los
 * renglones que creía suyos.
 *
 * Cuando el servidor no manda `secciones` —o el pedido entero sale de una sola
 * zona— esto devuelve UN bloque con la lista de siempre, así que la hoja de un
 * pedido normal no cambia en nada.
 */
const bloques = computed(() => (
    props.secciones.length
        ? props.secciones
        : [{
            zona: props.pedido.zona,
            responsable: props.pedido.responsable_zona,
            items: props.items,
            unidades: totalUnidades(props.items),
        }]
));

// Partida de verdad: más de una sección, o una sola porque se pidió reimprimir
// esa. En los dos casos cada hoja tiene que bastarse sola en papel.
const partida = computed(() => bloques.value.length > 1 || !! props.zona_filtrada);

/*
 * NO QUEDA NADA POR RECOGER.
 *
 * Pasa al reimprimir la hoja de un pedido que ya salió completo, y es más común
 * de lo que parece: alguien quiere ver qué se recogió. La hoja salía con el
 * membrete entero, el aviso de «segundo viaje… esta hoja es sólo de lo que
 * falta» sobre un pedido al que no le falta nada, y debajo una tabla VACÍA bajo
 * el rótulo «sin ubicación asignada» — que suena a problema grave y no lo es.
 *
 * Quien la recibe se va a buscar una mercancía que ya está en un camión.
 */
const nadaQueRecoger = computed(() => ! props.items.length);

const MOTIVOS = {
    sin_zona: 'Fuera del reparto de zonas: se recoge donde dice abajo. (Las zonas se configuran en Reglas · Logística.)',
    exhibicion: 'No alcanza en los racks: hay que traerlo de EXHIBICIÓN y reportar el traslado.',
    // La hoja sólo trae las tres ubicaciones con más saldo. Decirle «sin stock»
    // a una referencia repartida en cinco racks le hace reportar un faltante
    // que no existe — y un faltante abre novedad y retiene el pedido.
    buscar_mas: 'Puede haber más en otros estantes: la hoja sólo lista los tres con más saldo. Preguntá antes de reportar faltante.',
    sin_stock: 'No hay stock ubicado. Reportar a Jorge antes de seguir.',
};

// Reimprimir una sola sección, sin volver a sacar el pedido entero: dos copias
// de las otras zonas circulando y nadie sabe cuál se caminó.
const soloEsta = (zona) => router.get(
    `/app/logistica/cola-jorge/${props.pedido.id}/picking`,
    zona ? { zona } : {},
    { preserveScroll: true }
);
</script>

<template>
    <Head :title="`Hoja de picking · ${pedido.numero}`"/>
    <AppLayout>
        <!-- Barra de acciones (NO imprime) -->
        <div class="no-print mb-4 flex items-center justify-between flex-wrap gap-2">
            <Link href="/app/logistica/cola-jorge" class="btn-ghost text-sm">
                <ArrowLeft class="h-4 w-4"/> Volver a la cola
            </Link>
            <button @click="imprimir" class="btn-primary">
                <Printer class="h-4 w-4"/> Imprimir hoja de picking
            </button>
        </div>

        <!-- Hoja imprimible -->
        <div class="hoja bg-white text-black p-6 rounded-lg shadow-sm max-w-4xl mx-auto">
            <!-- Encabezado -->
            <div class="border-b-2 border-black pb-3 mb-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-xl font-bold uppercase">Hoja de picking</h1>
                        <div class="text-sm">GREAT BABY · {{ pedido.generado_at }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-3xl font-black">{{ pedido.numero }}</div>
                        <div class="text-sm">Factura {{ pedido.factura || '—' }}</div>
                        <div v-if="pedido.numero_picking" class="text-xs font-mono text-surface-600">
                            {{ pedido.numero_picking }}
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-3 text-sm">
                    <div>
                        <div class="font-bold">Cliente</div>
                        <div>{{ pedido.cliente }}</div>
                        <div v-if="pedido.direccion">{{ pedido.direccion }}</div>
                        <div v-if="pedido.ciudad || pedido.telefono">
                            {{ pedido.ciudad }}{{ pedido.ciudad && pedido.telefono ? ' · ' : '' }}{{ pedido.telefono }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-bold">Alistador asignado</div>
                        <div>{{ pedido.alistador || '— sin asignar —' }}</div>
                        <!--
                            Quién vendió, para que la bodega pueda preguntarle sin
                            tener que averiguar de quién era el pedido. El teléfono
                            sale de su ficha de Gestión Humana; si no tiene ficha,
                            sale el nombre solo.
                        -->
                        <div v-if="pedido.asesor" class="mt-2 text-xs">
                            <span class="font-bold">Asesor:</span> {{ pedido.asesor }}<!--
                            --><span v-if="pedido.asesor_telefono"> · {{ pedido.asesor_telefono }}</span>
                        </div>
                        <div class="mt-2 text-xs">Total pedido: <b>{{ money(pedido.total) }}</b></div>
                    </div>
                </div>
            </div>

            <!--
                POR DóNDE SE CAMINA

                El área reparte la bodega en zonas con un responsable cada una, y
                su lista de picking pide tres casillas: la zona, quién responde
                por ella y «compartido con zona». Esa última es la que importa:
                un pedido que toca racks de dos zonas necesita dos personas o un
                recorrido el doble de largo, y eso hay que verlo ANTES de
                arrancar, no a mitad de camino.

                El reparto lo manda la regla `logistica.zonas_bodega`, así que
                esta banda cambia sola cuando el área mueve un rack de zona.
            -->
            <!--
                SEGUNDO VIAJE · va primero y en negro sobre blanco, porque la
                hoja se imprime y se camina con ella.

                Una hoja que pide 2 de un pedido de 6 sin decir por qué parece
                mal generada, y lo primero que hace quien la recibe es ir a
                buscar las otras 4 — que ya se fueron en un camión.
            -->
            <div v-if="pedido.viaje_anterior && ! nadaQueRecoger" class="mb-4 border-4 border-black p-3">
                <div class="text-lg font-black uppercase">⏳ Segundo viaje de este pedido</div>
                <div class="text-sm mt-0.5">
                    De las <b>{{ pedido.total_pedido }}</b> unidades del pedido ya salieron
                    <b>{{ pedido.ya_despachado }}</b>.
                    Esta hoja es SÓLO de lo que falta: recogé lo que dice cada renglón, no la factura.
                </div>
            </div>

            <!--
                Y si no queda nada, la hoja lo dice y se acaba acá. Antes seguía
                con una tabla vacía debajo de un rótulo que suena a problema.
            -->
            <div v-if="nadaQueRecoger" class="my-6 border-4 border-black p-5 text-center">
                <div class="text-2xl font-black uppercase">Este pedido ya salió completo</div>
                <div class="text-sm mt-2">
                    Las <b>{{ pedido.total_pedido }}</b> unidades están despachadas.
                    No queda nada por recoger: esta hoja es sólo para consultar.
                </div>
            </div>

            <!--
                Cuando el pedido se reparte en varias zonas, el aviso de arriba
                es del PEDIDO; cada hoja lleva abajo su propia banda. Si sale de
                una sola, esto no aparece y la banda es la de siempre.
            -->
            <div v-if="partida" class="mb-4 border-2 border-black px-4 py-2 text-sm">
                <span class="font-black uppercase">Pedido repartido en varias zonas.</span>
                Cada hoja es de UNA zona y se camina por separado.
                <template v-if="zona_filtrada">
                    Estás viendo sólo la <b>zona {{ zona_filtrada }}</b>.
                </template>
            </div>

            <!-- Botonera para reimprimir una sola zona · no sale en el papel -->
            <div v-if="secciones.length > 1 || zona_filtrada" class="no-print mb-4 flex flex-wrap items-center gap-2">
                <span class="text-xs text-surface-500">Imprimir sólo:</span>
                <!--
                    Sólo las secciones CON zona llevan botón: el filtro viaja por
                    `?zona=` y la sección sin ubicación no tiene ninguna que
                    pasar. Pintarle un botón que muestra el pedido entero sería
                    un botón que miente sobre lo que hace.
                -->
                <button v-for="s in secciones.filter(x => x.zona)" :key="s.zona" type="button"
                        @click="soloEsta(s.zona)"
                        :class="['px-2 py-1 rounded text-xs font-semibold border',
                            zona_filtrada === String(s.zona)
                                ? 'bg-brand-600 text-white border-brand-600'
                                : 'border-surface-300 hover:bg-surface-100']">
                    Zona {{ s.zona }} ({{ s.unidades }} u.)
                </button>
                <span v-if="secciones.some(s => ! s.zona)" class="text-xs text-surface-500">
                    · la sección «sin ubicación» va siempre al final de la hoja completa
                </span>
                <button v-if="zona_filtrada" type="button" @click="soloEsta(null)"
                        class="px-2 py-1 rounded text-xs font-semibold border border-surface-300 hover:bg-surface-100">
                    Ver el pedido completo
                </button>
            </div>

            <!-- Una banda + una tabla por zona. Con una sola, es la hoja de siempre. -->
            <section v-for="(b, bi) in bloques" :key="b.zona || 'sin-zona'"
                     v-show="! nadaQueRecoger"
                     :style="bi > 0 ? 'break-before: page' : null">

                <div v-if="b.zona" class="mb-4 flex items-stretch gap-3 border-2 border-black">
                    <div class="bg-black text-white px-5 py-3 text-center">
                        <div class="text-[10px] uppercase tracking-widest opacity-80">Zona</div>
                        <div class="text-4xl font-black leading-none">{{ b.zona }}</div>
                    </div>
                    <div class="flex-1 py-2 pr-3 text-sm">
                        <div>
                            <span class="font-bold uppercase text-xs">Responsable de la zona:</span>
                            {{ b.responsable }}
                        </div>
                        <!--
                            En una hoja partida, cada página tiene que bastarse
                            sola: quien recibe la de la zona 3 no tiene el
                            encabezado del pedido, que quedó en la primera.
                        -->
                        <div v-if="partida" class="mt-1 text-xs">
                            <b>{{ pedido.numero }}</b> · {{ pedido.cliente }} ·
                            hoja {{ bi + 1 }} de {{ bloques.length }} ·
                            <b>{{ b.unidades }}</b> de {{ totalUnidades(items) }}
                            {{ pedido.viaje_anterior ? 'unidades que faltan' : 'unidades del pedido' }}
                        </div>
                        <div v-else-if="pedido.compartido_con && pedido.compartido_con.length"
                             class="mt-1 font-bold text-amber-800">
                            ⚠ Compartido con zona {{ pedido.compartido_con.join(' y ') }} ·
                            este pedido no se alista de una sola zona
                        </div>
                        <div v-else class="mt-1 text-xs text-surface-600">
                            Todo el pedido sale de esta zona.
                        </div>
                        <!--
                            Dónde se deja lo recogido. El ERP asignaba la casilla
                            desde la primera oleada y no se imprimía en ninguna
                            parte: quien terminaba su zona se quedaba con la
                            mercancía en la mano. Con la hoja partida son dos o
                            tres personas llegando por separado.
                        -->
                        <div v-if="pedido.casilla" class="mt-1 text-xs">
                            <span class="font-bold uppercase">Entregar en casilla:</span>
                            <span class="font-mono font-black">{{ pedido.casilla }}</span>
                        </div>
                    </div>
                </div>

                <!--
                    Sin zona no se inventó una: o el pedido no tiene stock ubicado, o
                    sus racks no están en el reparto. Decirlo es más útil que
                    imprimir una letra que manda a caminar a donde no es.
                -->
                <div v-else class="mb-4 border-2 border-dashed border-black px-4 py-2 text-xs">
                    <div class="font-black uppercase text-sm">Sin ubicación asignada</div>
                    <div class="mt-0.5">
                        Estas unidades no salen de ningún rack con zona. Cada renglón dice por qué.
                        <template v-if="partida">
                            · <b>{{ pedido.numero }}</b> · {{ pedido.cliente }} ·
                            hoja {{ bi + 1 }} de {{ bloques.length }}
                        </template>
                    </div>
                </div>

                <!-- El aviso de segundo viaje se repite en cada hoja: la de la
                     zona 3 no lleva el encabezado donde se explicó. -->
                <div v-if="partida && pedido.viaje_anterior" class="mb-3 border-2 border-black px-3 py-1.5 text-xs font-bold uppercase">
                    ⏳ Segundo viaje · de {{ pedido.total_pedido }} unidades ya salieron {{ pedido.ya_despachado }}
                </div>

            <!-- Tabla de items -->
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-black text-white">
                        <th class="p-2 text-left w-10">#</th>
                        <th class="p-2 text-left">SKU</th>
                        <th class="p-2 text-left">Producto</th>
                        <th class="p-2 text-center w-20">Cant.</th>
                        <th class="p-2 text-left">Ubicación (Rack · Sección · Nivel)</th>
                        <th class="p-2 text-center w-16">✓</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(it, idx) in b.items" :key="idx" class="border-b border-surface-300">
                        <td class="p-2 font-mono">{{ idx + 1 }}</td>
                        <td class="p-2 font-mono text-xs">{{ it.sku }}</td>
                        <td class="p-2">
                            <div class="font-semibold">{{ it.descripcion }}</div>
                            <div v-if="it.variante && (it.variante.color || it.variante.talla)" class="text-xs text-surface-600">
                                {{ it.variante.color }} {{ it.variante.talla }}
                            </div>
                            <div v-if="it.es_agregado" class="text-xs italic text-amber-700">
                                ⚠ Producto agregado · colores surtidos
                            </div>
                        </td>
                        <td class="p-2 text-center">
                            <span class="text-xl font-black">{{ it.cantidad }}</span>
                            <!--
                                En un segundo viaje el número de arriba es lo que
                                FALTA, no lo que pidió el cliente. Sin este
                                renglón, quien camina con la hoja ve un número
                                más chico que el de la factura y va a buscar la
                                diferencia.
                            -->
                            <div v-if="it.ya_salio > 0" class="text-[10px] leading-tight text-amber-700">
                                de {{ it.cantidad_pedida }}<br>ya salieron {{ it.ya_salio }}
                            </div>
                        </td>
                        <td class="p-2 text-xs">
                            <!--
                                En la sección de «sin ubicación», el motivo va por
                                renglón: no es lo mismo que esté en la vitrina a que
                                no esté en la bodega.

                                Va ENCIMA de las ubicaciones, no en lugar de ellas.
                                Reemplazarlas dejaba al alistador sin saber a dónde
                                ir —y sin el botón de traer de Exhibición, que es
                                justo el que necesita en ese renglón—.
                            -->
                            <div v-if="it.motivo" class="font-bold mb-1">
                                {{ MOTIVOS[it.motivo] }}
                            </div>
                            <div v-if="! it.motivo && it.ubicaciones.length === 0" class="text-red-600 font-bold">
                                ⚠ SIN STOCK — reportar a Jorge
                            </div>
                            <div v-else-if="it.ubicaciones.length" class="space-y-0.5">
                                <!--
                                    El respaldo de surtido se pinta distinto a
                                    propósito. El área lo pide por escrito: si
                                    falta en los racks hay que completar desde
                                    Exhibición, pero eso NO es un estante más
                                    del recorrido —obliga a registrar un
                                    traslado—. Mezclarlo con los demás haría
                                    que alguien tome de ahí sin saber que debe
                                    reportarlo.
                                -->
                                <div v-for="(u, i) in it.ubicaciones" :key="i"
                                     :class="[
                                         'px-1.5 py-0.5 inline-block rounded mr-1 font-mono',
                                         u.es_respaldo
                                             ? 'border-2 border-dashed border-black text-xs'
                                             : (i === 0 ? 'bg-black text-white font-bold text-sm' : 'bg-surface-200 text-xs'),
                                     ]">
                                    <span v-if="u.zona" class="mr-0.5">{{ u.zona }}·</span>{{ u.codigo }}
                                    <span class="ml-1 opacity-75">({{ u.stock }} disp.)</span>
                                    <span v-if="u.es_respaldo" class="ml-1 font-sans font-bold">· EXHIBICIÓN</span>
                                </div>
                                <div v-if="it.ubicaciones.some(u => u.es_respaldo)" class="text-[10px] font-bold mt-0.5">
                                    ⚠ Si los racks no completan la cantidad, tome de EXHIBICIÓN
                                    y reporte el traslado. El pedido sale completo o no sale.
                                </div>
                                <!--
                                    Y acá mismo se reporta, que es la mitad que
                                    faltaba: la hoja mandaba a registrar un
                                    traslado que el alistador no tenía dónde
                                    hacer. `print:hidden` lo saca del papel.
                                -->
                                <div v-if="it.ubicaciones.some(u => u.es_respaldo)" class="print:hidden mt-1 flex items-center gap-1">
                                    <input v-model.number="traer[claveDe(it)]" type="number" min="1"
                                           :max="it.cantidad" :placeholder="`máx ${it.cantidad}`"
                                           class="w-20 text-[11px] border border-surface-300 rounded px-1 py-0.5"/>
                                    <button type="button"
                                            :disabled="surtiendo === claveDe(it) || ! traer[claveDe(it)]"
                                            @click="surtir(it)"
                                            class="text-[11px] px-2 py-0.5 rounded bg-amber-600 text-white font-semibold disabled:opacity-40">
                                        {{ surtiendo === claveDe(it) ? 'Trayendo…' : 'Traer de Exhibición' }}
                                    </button>
                                </div>
                                <div v-else-if="it.ubicaciones.length > 1" class="text-[10px] text-surface-500 mt-0.5">
                                    1ª opción en negro · alternativas si está vacía
                                </div>
                            </div>
                        </td>
                        <td class="p-2 text-center">
                            <!-- Casilla físicas para marcar al tomar la mercancía -->
                            <div class="inline-block w-6 h-6 border-2 border-black rounded-sm"></div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="font-bold border-t-2 border-black">
                        <td colspan="3" class="p-2 text-right">
                            {{ partida ? 'UNIDADES DE ESTA ZONA' : 'TOTAL UNIDADES A ALISTAR' }}
                        </td>
                        <td class="p-2 text-center text-xl">{{ totalUnidades(b.items) }}</td>
                        <td colspan="2"></td>
                    </tr>
                    <!--
                        En la hoja partida se imprime también el total de ESTA
                        HOJA: así se puede cuadrar la caja sin juntar las tres.

                        El rótulo no dice «total del pedido»: en un segundo
                        viaje este número es lo que FALTA, y arriba la banda
                        grande ya dice las unidades del pedido entero. Dos
                        números distintos con el mismo nombre en la misma página
                        es la forma más fácil de recoger de más.
                    -->
                    <tr v-if="partida" class="text-xs border-t border-black">
                        <td colspan="3" class="p-1.5 text-right">
                            {{ pedido.viaje_anterior ? 'Falta recoger en total (todas las zonas)' : 'Total del pedido (todas las zonas)' }}
                        </td>
                        <td class="p-1.5 text-center font-bold">{{ totalUnidades(items) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            </section>

            <!-- Trazabilidad automática + observaciones -->
            <div class="mt-6 grid grid-cols-2 gap-6 text-sm">
                <div>
                    <div class="font-semibold mb-2 uppercase text-xs">Trazabilidad (automática)</div>
                    <table class="w-full text-xs border border-black">
                        <tbody>
                            <tr class="border-b border-black">
                                <td class="p-1.5 font-semibold bg-surface-100 w-32">Alistador</td>
                                <td class="p-1.5">{{ pedido.alistador || '— sin asignar —' }}</td>
                            </tr>
                            <tr class="border-b border-black">
                                <td class="p-1.5 font-semibold bg-surface-100">Asignado</td>
                                <td class="p-1.5 font-mono">{{ pedido.alistador_asignado_at || '—' }}</td>
                            </tr>
                            <tr class="border-b border-black">
                                <td class="p-1.5 font-semibold bg-surface-100">Hora inicio</td>
                                <td class="p-1.5 font-mono">{{ pedido.alistado_inicio_at || '— en curso al imprimir —' }}</td>
                            </tr>
                            <tr class="border-b border-black">
                                <td class="p-1.5 font-semibold bg-surface-100">Hora fin</td>
                                <td class="p-1.5 font-mono">{{ pedido.alistado_fin_at || '— pendiente —' }}</td>
                            </tr>
                            <tr>
                                <td class="p-1.5 font-semibold bg-surface-100">Impresa por</td>
                                <td class="p-1.5">{{ pedido.impreso_por }} · {{ pedido.generado_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="text-[10px] italic mt-1 text-surface-500">
                        Horas registradas por el ERP al tomar/terminar en la Cola. No se escriben a mano.
                    </div>
                </div>
                <div>
                    <div class="font-semibold mb-1">Observaciones / novedades</div>
                    <div class="border border-black h-32 p-1"></div>
                </div>
            </div>

            <div class="mt-4 text-[10px] text-center text-surface-500">
                Generado por GREAT BABY ERP · {{ pedido.generado_at }} · hoja {{ pedido.numero }}-picking
            </div>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    /* Esconder TODO lo que no es la hoja. */
    aside, header, nav, .no-print,
    [class*="sidebar"], [class*="topbar"], [class*="bell"] { display: none !important; }
    body, html { background: white !important; }
    .hoja { box-shadow: none !important; padding: 0 !important; max-width: 100% !important; }
    main { padding: 0 !important; }
    @page { size: letter; margin: 10mm; }
}
</style>
