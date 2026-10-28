{{--
    Fase G · El recuadro de control documental.

    Va idéntico en los seis formatos y por eso vive acá una sola vez: cuando
    estaba copiado en cada plantilla, las fechas ya habían empezado a
    diferir entre sí y una imprimía la fecha del registro en vez de la de la
    versión.

    Los tres datos salen del listado maestro (`formatos_controlados`), que
    mantiene el área. El código en rojo cuando no lo han asignado: un código
    inventado por el sistema parecería válido, y es peor que ninguno.

    Espera: $empresa, $formato.
--}}
<table class="enc">
    <tr>
        <td class="marca" rowspan="3">{{ $empresa->nombre_comercial ?: $empresa->razon_social }}</td>
        <td class="titulo" rowspan="3">
            PROCESO DE {{ mb_strtoupper($formato->proceso) }}<br>
            FORMATO {{ mb_strtoupper($formato->nombre) }}
        </td>
        <td class="meta">
            Código:
            @if ($formato->tieneCodigo())
                {{ $formato->codigo }}
            @else
                <span style="color:#b91c1c;">sin asignar</span>
            @endif
        </td>
    </tr>
    <tr><td class="meta">Versión: {{ $formato->version }}</td></tr>
    <tr><td class="meta">Fecha: {{ $formato->fechaImpresa() }}</td></tr>
</table>
