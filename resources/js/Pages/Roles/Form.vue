<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowLeft, ShieldCheck, Lock, Users, Search, Save } from 'lucide-vue-next';

const props = defineProps({
    rol: Object,         // null = rol nuevo
    catalogo: Object,    // { grupo: [{seccion, permiso, etiqueta, acciones}] }
    // Las cuatro columnas de la grilla: ver, crear, editar, eliminar.
    acciones: { type: Array, default: () => [] },
    usuarios: Array,
});

const esNuevo = ! props.rol;

const nombre = ref(props.rol?.nombre ?? '');
const permisos = ref([...(props.rol?.permisos ?? [])]);
const asignados = ref([...(props.rol?.usuarios ?? [])]);
const guardando = ref(false);
const buscar = ref('');

const esRoot = props.rol?.root ?? false;
const esProtegido = props.rol?.protegido ?? false;

const tiene = (p) => permisos.value.includes(p);

const poner = (p) => { if (! tiene(p)) permisos.value.push(p); };
const quitar = (p) => {
    const i = permisos.value.indexOf(p);
    if (i !== -1) permisos.value.splice(i, 1);
};

/*
 * «Ver» es la puerta.
 *
 * Quitar el ver de una sección se lleva sus otras acciones: dejar marcado
 * «editar» sobre algo que la persona no puede abrir es una casilla que miente
 * —el servidor la rechaza igual— y al revisar la pantalla parecería que tiene
 * un permiso que no sirve.
 *
 * Y marcar cualquier acción enciende el ver, porque nadie marca «eliminar»
 * queriendo decir «pero que no lo vea».
 */
const alternar = (p, fila = null) => {
    const esVer = fila && p === fila.permiso;

    if (tiene(p)) {
        quitar(p);
        if (esVer) fila.acciones.forEach(a => quitar(a.permiso));
        return;
    }

    poner(p);
    if (! esVer && fila) poner(fila.permiso);
};

/** Cuántas acciones tiene marcadas una sección, para el resumen de la fila. */
const accionesDe = (fila) => fila.acciones.filter(a => tiene(a.permiso)).length;

const grupoCompleto = (items) => items.every(i => tiene(i.permiso));

const alternarGrupo = (items) => {
    if (grupoCompleto(items)) {
        // Quitar el grupo se lleva todo lo suyo, acciones incluidas.
        items.forEach(i => {
            quitar(i.permiso);
            i.acciones.forEach(a => quitar(a.permiso));
        });
    } else {
        // Marcar todo da acceso completo, que es lo que se espera del botón.
        items.forEach(i => {
            poner(i.permiso);
            i.acciones.forEach(a => poner(a.permiso));
        });
    }
};

/** Sólo las secciones marcadas, para el contador de arriba. */
const seccionesMarcadas = computed(() =>
    permisos.value.filter(p => p.startsWith('ver.')).length);

const usuariosFiltrados = computed(() => {
    const q = buscar.value.trim().toLowerCase();
    if (! q) return props.usuarios;
    return props.usuarios.filter(u =>
        u.nombre.toLowerCase().includes(q) || u.email.toLowerCase().includes(q));
});

const alternarUsuario = (id) => {
    const i = asignados.value.indexOf(id);
    i === -1 ? asignados.value.push(id) : asignados.value.splice(i, 1);
};

const guardar = () => {
    guardando.value = true;
    router.post(esNuevo ? '/app/roles' : `/app/roles/${props.rol.id}`, {
        nombre: nombre.value,
        permisos: permisos.value,
        usuarios: asignados.value,
    }, { onFinish: () => { guardando.value = false; } });
};
</script>

<template>
    <Head :title="esNuevo ? 'Nuevo rol' : `Rol ${rol.nombre}`"/>
    <AppLayout>
        <div class="space-y-5 max-w-5xl mx-auto">

            <Link href="/app/roles" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">
                <ArrowLeft class="h-4 w-4"/> Volver a roles
            </Link>

            <h1 class="text-2xl font-bold flex items-center gap-2">
                <ShieldCheck class="h-6 w-6 text-brand-600"/>
                {{ esNuevo ? 'Nuevo rol' : `Rol ${rol.nombre}` }}
            </h1>

            <!-- Nombre -->
            <div class="card p-4">
                <label class="block text-xs font-semibold uppercase text-surface-500 mb-1">Nombre del rol</label>
                <input v-model="nombre" :disabled="esProtegido" type="text" maxlength="60"
                       placeholder="Por ejemplo: Auxiliar contable"
                       class="input w-full max-w-sm"
                       :class="{ 'opacity-60 cursor-not-allowed': esProtegido }"/>
                <p v-if="esProtegido" class="text-xs text-surface-500 mt-2 flex items-center gap-1">
                    <Lock class="h-3 w-3"/>
                    Es un rol del sistema: el nombre no se puede cambiar porque el programa lo usa por dentro.
                    Sus accesos y sus personas sí se pueden ajustar.
                </p>
            </div>

            <!-- Aviso root -->
            <div v-if="esRoot" class="card p-4 bg-amber-50 border-l-4 border-amber-500 text-amber-900 text-sm">
                <b>Este rol ve todo el sistema.</b> Las casillas quedan marcadas como referencia,
                pero su acceso no depende de ellas: siempre tiene que existir alguien que pueda
                entrar a corregir una configuración equivocada.
            </div>

            <!-- Permisos -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800">
                    <h3 class="font-semibold">¿Qué puede ver y hacer?</h3>
                    <p class="text-xs text-surface-500 mt-0.5">
                        {{ seccionesMarcadas }} sección(es) marcada(s).
                        <strong>Ver</strong> abre la pantalla; las otras tres dicen qué puede hacer adentro.
                        Sin <strong>Ver</strong>, las demás no sirven.
                    </p>
                </div>

                <div class="divide-y divide-surface-100 dark:divide-surface-800">
                    <div v-for="(items, grupo) in catalogo" :key="grupo" class="p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold uppercase text-surface-500">{{ grupo }}</h4>
                            <button @click="alternarGrupo(items)" type="button"
                                    class="text-xs text-brand-600 hover:underline font-semibold">
                                {{ grupoCompleto(items) ? 'Quitar todo' : 'Marcar todo' }}
                            </button>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-[10px] uppercase text-surface-400">
                                    <th class="text-left font-semibold pb-1">Sección</th>
                                    <th v-for="a in acciones" :key="a.accion" class="w-16 pb-1 font-semibold">
                                        {{ a.texto }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-100 dark:divide-surface-800">
                                <tr v-for="i in items" :key="i.permiso"
                                    :class="tiene(i.permiso) ? '' : 'opacity-60'">
                                    <td class="py-1.5 pr-2">
                                        {{ i.etiqueta }}
                                        <span v-if="tiene(i.permiso) && accionesDe(i) === 0"
                                              class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-blue-500/20 text-blue-700">
                                            sólo lectura
                                        </span>
                                    </td>
                                    <td v-for="a in i.acciones" :key="a.permiso" class="text-center py-1.5">
                                        <input type="checkbox"
                                               :checked="tiene(a.permiso)"
                                               :disabled="a.accion !== 'ver' && ! tiene(i.permiso)"
                                               :title="a.accion !== 'ver' && ! tiene(i.permiso)
                                                   ? 'Primero hay que marcar Ver' : a.texto"
                                               @change="alternar(a.permiso, i)"
                                               class="h-4 w-4 rounded disabled:opacity-30"/>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Usuarios -->
            <div class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-800 flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h3 class="font-semibold flex items-center gap-2">
                            <Users class="h-5 w-5 text-brand-600"/>
                            ¿Quiénes tienen este rol?
                        </h3>
                        <p class="text-xs text-surface-500 mt-0.5">{{ asignados.length }} persona(s) seleccionada(s).</p>
                    </div>
                    <div class="relative">
                        <Search class="h-4 w-4 absolute left-2 top-2.5 text-surface-400"/>
                        <input v-model="buscar" type="search" placeholder="Buscar persona…" class="input pl-8 w-56"/>
                    </div>
                </div>
                <div class="max-h-80 overflow-y-auto divide-y divide-surface-100 dark:divide-surface-800">
                    <label v-for="u in usuariosFiltrados" :key="u.id"
                           class="flex items-center gap-3 p-3 cursor-pointer hover:bg-brand-50/40 dark:hover:bg-brand-950/20">
                        <input type="checkbox" :checked="asignados.includes(u.id)" @change="alternarUsuario(u.id)"
                               class="h-4 w-4 rounded shrink-0"/>
                        <div class="min-w-0">
                            <div class="font-medium truncate">{{ u.nombre }}</div>
                            <div class="text-xs text-surface-500 truncate">{{ u.email }}</div>
                        </div>
                    </label>
                    <div v-if="!usuariosFiltrados.length" class="p-6 text-center text-sm text-surface-400">
                        Nadie coincide con la búsqueda.
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <Link href="/app/roles" class="text-sm text-surface-500 hover:underline">Cancelar</Link>
                <button @click="guardar" :disabled="guardando || !nombre.trim()"
                        class="btn-primary"
                        :class="{ 'opacity-50 cursor-not-allowed': guardando || !nombre.trim() }">
                    <Save class="h-4 w-4"/>
                    {{ guardando ? 'Guardando…' : 'Guardar rol' }}
                </button>
            </div>

        </div>
    </AppLayout>
</template>
