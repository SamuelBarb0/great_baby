{{--
    Fase G · El pie de los formatos controlados.

    Repite el código y la versión a propósito. Una hoja impresa se separa de
    las demás y se archiva sola; si sólo lo dijera el encabezado, la página
    suelta no se podría rastrear contra el listado maestro.

    Espera: $formato. Opcional: $generadoPor.
--}}
<div class="pie">
    Una vez impreso este documento se considera COPIA NO CONTROLADA y es responsabilidad
    del Líder del Proceso asegurar su actualización.<br>
    {{ $formato->codigoImpreso() }} · versión {{ $formato->version }} ·
    generado desde el ERP @isset($generadoPor) por {{ $generadoPor }} @endisset
    el {{ now()->format('d/m/Y H:i') }}.
</div>
