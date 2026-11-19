<x-filament-panels::page>
    @php
        $c = $this->getComparativo();
        [$a0, $a1] = $c['periodos']['actual'];
        [$p0, $p1] = $c['periodos']['anterior'];
        [$q0, $q1] = $c['periodos']['anterior_completo'];
        $f = fn ($d) => $d->locale('es')->isoFormat('D MMM');
        $num = fn (array $fila, float $v) => $fila['dinero'] ? '$' . number_format($v, 0, ',', '.') : number_format($v, 0, ',', '.');
    @endphp

    <x-filament::section>
        <x-slot name="heading">Este mes vs. mes anterior</x-slot>
        <x-slot name="description">
            Se compara lo comparable: {{ $f($a0) }} – {{ $f($a1) }} contra los mismos días del mes anterior ({{ $f($p0) }} – {{ $f($p1) }}).
            El mes anterior completo va a la derecha como referencia.
        </x-slot>

        <div style="overflow-x:auto;">
            <table style="width:100%;font-size:.92rem;border-collapse:collapse;">
                <thead><tr style="border-bottom:1px solid rgba(156,163,175,.25);">
                    <th style="text-align:left;padding:.55rem;"></th>
                    <th style="text-align:right;padding:.55rem;">Este mes<div style="font-weight:400;font-size:.72rem;color:#9ca3af;">{{ $f($a0) }} – {{ $f($a1) }}</div></th>
                    <th style="text-align:right;padding:.55rem;">Mes anterior, mismos días<div style="font-weight:400;font-size:.72rem;color:#9ca3af;">{{ $f($p0) }} – {{ $f($p1) }}</div></th>
                    <th style="text-align:right;padding:.55rem;">Variación</th>
                    <th style="text-align:right;padding:.55rem;color:#9ca3af;">Mes anterior completo<div style="font-weight:400;font-size:.72rem;">{{ $f($q0) }} – {{ $f($q1) }}</div></th>
                </tr></thead>
                <tbody>
                @foreach($c['filas'] as $fila)
                    @php
                        $var = $fila['variacion'];
                        $bueno = $var !== null && $var != 0.0 && (($var > 0) xor $fila['menos_es_mejor']);
                        $color = $var === null || $var == 0.0 ? '#9ca3af' : ($bueno ? '#059669' : '#dc2626');
                    @endphp
                    <tr style="border-bottom:1px solid rgba(156,163,175,.1);">
                        <td style="padding:.55rem;font-weight:600;">{{ $fila['nombre'] }}</td>
                        <td style="padding:.55rem;text-align:right;font-weight:700;">{{ $num($fila, $fila['actual']) }}</td>
                        <td style="padding:.55rem;text-align:right;">{{ $num($fila, $fila['anterior']) }}</td>
                        <td style="padding:.55rem;text-align:right;font-weight:700;color:{{ $color }};">
                            @if($var === null)
                                <span title="El mes anterior no tiene base para comparar">—</span>
                            @else
                                {{ $var > 0 ? '▲ +' : ($var < 0 ? '▼ ' : '') }}{{ number_format($var, 1, ',', '.') }} %
                            @endif
                        </td>
                        <td style="padding:.55rem;text-align:right;color:#9ca3af;">{{ $num($fila, $fila['anterior_completo']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div style="font-size:.78rem;color:#9ca3af;margin-top:.6rem;">Verde = mejor, rojo = peor. En devoluciones y sanciones, subir es peor.</div>
    </x-filament::section>
</x-filament-panels::page>
