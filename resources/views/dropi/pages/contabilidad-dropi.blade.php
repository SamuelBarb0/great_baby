<x-filament-panels::page>
    @php
        $d = $this->datos;
        $r = $d['resultados'];
        $b = $d['balance'];
        $c = $d['cartera'];
        $money = fn ($v) => ((float) $v < 0 ? '-$' : '$') . number_format(abs((float) $v), 0, ',', '.');
        $card = 'padding:1rem;border-radius:.75rem;border:1px solid rgba(148,163,184,.25);';
        $label = 'font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;';
        $num = 'font-size:1.5rem;font-weight:700;margin-top:.25rem;';
        $sub = 'font-size:.75rem;color:#9ca3af;margin-top:.25rem;';
        $fila = 'display:flex;justify-content:space-between;gap:1rem;padding:.3rem 0;font-size:.85rem;border-bottom:1px solid rgba(148,163,184,.12);';
        $total = 'display:flex;justify-content:space-between;gap:1rem;padding:.45rem 0;font-weight:700;';
    @endphp

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
        <div style="font-size:.9rem;color:#9ca3af;">
            Libros de la línea de negocio <b>Dropi</b> (e-commerce), separados de Great Baby ·
            {{ $d['periodo']['inicio'] }} → {{ $d['periodo']['fin'] }}
        </div>
        <x-filament::input.wrapper>
            <x-filament::input type="month" wire:model.live="mes" />
        </x-filament::input.wrapper>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:.75rem;">
        <div style="{{ $card }}">
            <div style="{{ $label }}">Ingresos del mes</div>
            <div style="{{ $num }}color:#059669;">{{ $money($r['ingresos']['total']) }}</div>
        </div>
        <div style="{{ $card }}">
            <div style="{{ $label }}">Utilidad neta</div>
            <div style="{{ $num }}{{ $r['utilidad_neta'] < 0 ? 'color:#dc2626;' : '' }}">{{ $money($r['utilidad_neta']) }}</div>
            <div style="{{ $sub }}">@if($r['margen_neto'] !== null) Margen {{ $r['margen_neto'] }} % @else Sin ingresos @endif</div>
        </div>
        <div style="{{ $card }}">
            <div style="{{ $label }}">Por cobrar</div>
            <div style="{{ $num }}">{{ $money($c['por_cobrar']) }}</div>
            <div style="{{ $sub }}">{{ $c['vencidas'] }} factura(s) vencida(s)</div>
        </div>
        <div style="{{ $card }}">
            <div style="{{ $label }}">Facturado / cobrado en el mes</div>
            <div style="{{ $num }}">{{ $money($c['facturado_mes']) }}</div>
            <div style="{{ $sub }}">Cobrado {{ $money($c['cobrado_mes']) }} · Notas crédito {{ $money($d['notas_credito']['valor']) }} ({{ $d['notas_credito']['cantidad'] }})</div>
        </div>
    </div>

    @if($d['sin_datos'])
        <x-filament::section>
            <p style="color:#9ca3af;">No hay movimientos contables de Dropi hasta esta fecha.</p>
        </x-filament::section>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(22rem,1fr));gap:1rem;">
        {{-- Estado de resultados --}}
        <x-filament::section>
            <x-slot name="heading">Estado de resultados del mes</x-slot>
            @foreach([['Ingresos', $r['ingresos']], ['Costo de ventas', $r['costo_ventas']], ['Costos de producción', $r['costos_produccion']], ['Gastos', $r['gastos']]] as [$titulo, $grupo])
                @if($grupo['total'] != 0 || $titulo === 'Ingresos')
                    <div style="{{ $total }}"><span>{{ $titulo }}</span><span>{{ $money($grupo['total']) }}</span></div>
                    @foreach($grupo['cuentas'] as $cta)
                        <div style="{{ $fila }}padding-left:.75rem;color:#6b7280;">
                            <span>{{ $cta['codigo'] }} · {{ $cta['nombre'] }}</span><span>{{ $money($cta['saldo']) }}</span>
                        </div>
                    @endforeach
                @endif
            @endforeach
            <div style="{{ $total }}border-top:2px solid rgba(148,163,184,.35);margin-top:.4rem;">
                <span>Utilidad bruta</span><span>{{ $money($r['utilidad_bruta']) }}</span>
            </div>
            <div style="{{ $total }}">
                <span>Utilidad neta</span><span style="{{ $r['utilidad_neta'] < 0 ? 'color:#dc2626;' : 'color:#059669;' }}">{{ $money($r['utilidad_neta']) }}</span>
            </div>
        </x-filament::section>

        {{-- Movimientos del mes por cuenta (no es el balance acumulado) --}}
        <x-filament::section>
            <x-slot name="heading">Movimientos del mes · {{ $d['periodo']['inicio'] }} → {{ $d['periodo']['corte'] }}</x-slot>
            <x-slot name="description">Sólo lo de este mes. El balance acumulado está en Contabilidad › Balance general (línea Dropi).</x-slot>
            @foreach([['Activo', $b['activo']], ['Pasivo', $b['pasivo']], ['Patrimonio', $b['patrimonio']]] as [$titulo, $grupo])
                <div style="{{ $total }}"><span>{{ $titulo }}</span><span>{{ $money($grupo['total']) }}</span></div>
                @foreach($grupo['cuentas'] as $cta)
                    <div style="{{ $fila }}padding-left:.75rem;color:#6b7280;">
                        <span>{{ $cta['codigo'] }} · {{ $cta['nombre'] }}</span><span>{{ $money($cta['saldo']) }}</span>
                    </div>
                @endforeach
            @endforeach
            <div style="{{ $fila }}"><span>Resultado del ejercicio</span><span>{{ $money($b['resultado']) }}</span></div>
            <div style="{{ $total }}border-top:2px solid rgba(148,163,184,.35);margin-top:.4rem;">
                <span>Pasivo + patrimonio</span><span>{{ $money($b['pasivo']['total'] + $b['total_patrimonio']) }}</span>
            </div>
            <div style="font-size:.8rem;margin-top:.35rem;color:{{ $b['cuadra'] ? '#059669' : '#dc2626' }};">
                {{ $b['cuadra'] ? '✓ Cuadra (activo = pasivo + patrimonio)' : '⚠ Descuadre de ' . $money($b['descuadre']) }}
            </div>
        </x-filament::section>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(22rem,1fr));gap:1rem;">
        <x-filament::section>
            <x-slot name="heading">Antigüedad de la cartera</x-slot>
            @foreach($c['antiguedad'] as $t)
                <div style="{{ $fila }}">
                    <span>{{ $t['tramo'] }}</span>
                    <span>{{ $money($t['monto']) }} <span style="color:#9ca3af;font-size:.75rem;">· {{ $t['facturas'] }}</span></span>
                </div>
            @endforeach
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Últimos cobros del mes</x-slot>
            @if(empty($d['ultimos_pagos']))
                <p style="color:#9ca3af;font-size:.9rem;">No hay cobros en este mes.</p>
            @else
                @foreach($d['ultimos_pagos'] as $p)
                    <div style="{{ $fila }}">
                        <span>{{ $p['fecha'] }} · {{ $p['factura'] }} <span style="color:#9ca3af;font-size:.75rem;">{{ $p['medio'] }}</span></span>
                        <span>{{ $money($p['monto']) }}</span>
                    </div>
                @endforeach
            @endif
        </x-filament::section>

        {{-- 9-nov · la wallet contra su cuenta: el enlace con la wallet y el cruce --}}
        @php
            $w = $d['wallet'];
            $walletRes = \App\Modules\Dropi\Filament\Resources\DropiWalletMovimientoResource::class;
            $cuadraWallet = abs($w['diferencia']) <= 0.01;
        @endphp
        <x-filament::section>
            <x-slot name="heading">Wallet Dropi vs. cuenta 111015 · al {{ $d['periodo']['corte'] }}</x-slot>
            <x-slot name="description">Lo que dice la wallet contra lo que dice el libro. Una diferencia se explica casi siempre por los movimientos pendientes.</x-slot>
            <div style="{{ $fila }}"><span>Saldo de la wallet (movimientos importados)</span><span>{{ $money($w['saldo_wallet']) }}</span></div>
            <div style="{{ $fila }}"><span>Saldo de la cuenta 111015 en el libro</span><span>{{ $money($w['saldo_contable']) }}</span></div>
            <div style="{{ $total }}">
                <span>Diferencia</span>
                <span style="color:{{ $cuadraWallet ? '#059669' : '#dc2626' }};">{{ $cuadraWallet ? '✓ Cuadra' : $money($w['diferencia']) }}</span>
            </div>
            <div style="font-size:.8rem;color:#9ca3af;margin-top:.35rem;">
                {{ $w['pendientes']['n'] }} movimiento(s) sin llegar al libro ({{ $money($w['pendientes']['monto']) }}):
                <a href="{{ $walletRes::urlDeEstados(['recibo_pendiente', 'sin_asiento']) }}" style="color:#3b82f6;">verlos en la wallet</a>
                · <a href="{{ \App\Modules\Dropi\Filament\Pages\CruceWalletPedidos::getUrl() }}" style="color:#3b82f6;">cruce con pedidos</a>
                @if(\App\Modules\Dropi\Filament\Pages\LibroContableDropi::canAccess())
                    · <a href="{{ \App\Modules\Dropi\Filament\Pages\LibroContableDropi::urlDeWallet() }}" style="color:#3b82f6;">asientos de la wallet</a>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
