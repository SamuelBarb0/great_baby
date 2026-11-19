<x-filament-panels::page>
    @php
        $imp = $this->getImportacion();
        $previsto = $this->previsto();
        $esPrevisto = $imp->lineas->isEmpty();
        $cop = fn ($v) => '$ ' . number_format((float) $v, 0, ',', '.');
        $estado = $imp->estado instanceof \App\Modules\Compras\Enums\EstadoImportacion ? $imp->estado : null;
        $comprobantes = $imp->documentos->groupBy('gasto_id');
        $celda = 'padding:.5rem .6rem;border-bottom:1px solid rgba(156,163,175,.2);';
        $num = $celda . 'text-align:right;white-space:nowrap;';
        $etiqueta = 'color:#9ca3af;font-size:.75rem;';
        $seg = $this->seguimiento();
        $res = $seg['resumen'];
        $documentos = $imp->documentos->whereNull('gasto_id')->groupBy('tipo');
        $tipos = \App\Modules\Compras\Models\ImportacionDocumento::TIPOS;
        $selectividad = \App\Modules\Compras\Models\ImportacionDeclaracion::SELECTIVIDAD;
        $tiposNovedad = \App\Modules\Compras\Models\ImportacionInspeccion::TIPOS;
    @endphp

    <x-filament::section>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:1rem;font-size:.9rem;">
            <div><div style="{{ $etiqueta }}">Estado</div><x-filament::badge :color="$estado?->color() ?? 'gray'">{{ $estado?->label() ?? (string) $imp->estado }}</x-filament::badge></div>
            <div><div style="{{ $etiqueta }}">Contenedor · BL</div>{{ $imp->contenedor ?: '—' }} · {{ $imp->bl_awb ?: '—' }}</div>
            <div><div style="{{ $etiqueta }}">Ruta</div>{{ $imp->puerto_origen ?: '—' }} → {{ $imp->puerto_destino ?: '—' }} {{ $imp->incoterm ? "({$imp->incoterm})" : '' }}</div>
            <div><div style="{{ $etiqueta }}">Moneda · tasa</div>{{ $imp->moneda_origen }} a {{ (float) $imp->tasa_cambio_liquidacion > 0 ? number_format((float) $imp->tasa_cambio_liquidacion, 2, ',', '.') : 'sin tasa' }}</div>
            <div><div style="{{ $etiqueta }}">Zarpe · ETA</div>{{ $imp->fecha_zarpe?->format('Y-m-d') ?? '—' }} · {{ $imp->eta?->format('Y-m-d') ?? '—' }}</div>
            <div><div style="{{ $etiqueta }}">Liquidada</div>{{ $imp->fecha_liquidacion?->format('Y-m-d') ?? '—' }}</div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Seguimiento</x-slot>
        <x-slot name="description">
            @if (! $res['etapa'])
                Todavía no hay etapas marcadas.
            @elseif ($res['terminado'])
                Llegó a bodega.
            @else
                Va en «{{ $res['etapa_label'] }}» hace {{ $res['dias_en_etapa'] }} día(s).
                Sigue «{{ $res['siguiente_label'] }}»{{ $res['esperados'] !== null ? ", que suele tardar {$res['esperados']} día(s)" : '' }}.
                @if ($res['dias_atraso'] > 0)
                    <strong style="color:#ef4444;">{{ $res['dias_atraso'] }} día(s) de atraso.</strong>
                @endif
                @if ($res['llegada_estimada'])
                    Llegada estimada a bodega: {{ $res['llegada_estimada'] }}.
                @endif
            @endif
        </x-slot>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <thead><tr style="color:#9ca3af;text-align:left;">
                    <th style="{{ $celda }}">Etapa</th><th style="{{ $celda }}">Fecha</th>
                    <th style="{{ $num }}">Días</th><th style="{{ $num }}">Esperados</th>
                    <th style="{{ $celda }}">Notas</th><th style="{{ $celda }}">Marcó</th><th style="{{ $celda }}"></th>
                </tr></thead>
                <tbody>
                @foreach ($seg['etapas'] as $e)
                    @php $tarde = $e['dias'] !== null && $e['esperados'] !== null && $e['dias'] > $e['esperados']; @endphp
                    <tr style="{{ $e['fecha'] ? '' : 'color:#9ca3af;' }}">
                        <td style="{{ $celda }}">{{ $e['label'] }}</td>
                        <td style="{{ $celda }}">{{ $e['fecha'] ?? '—' }}</td>
                        <td style="{{ $num }}{{ $tarde ? 'color:#ef4444;font-weight:600;' : '' }}">{{ $e['dias'] ?? '—' }}</td>
                        <td style="{{ $num }}" title="{{ $e['muestras'] > 0 ? "Promedio {$e['promedio']} en {$e['muestras']} contenedor(es)" : 'Sin historia: plazo de referencia' }}">{{ $e['esperados'] ?? '—' }}</td>
                        <td style="{{ $celda }}">{{ $e['notas'] }}</td>
                        <td style="{{ $celda }}">{{ $e['registrado_por'] }}</td>
                        <td style="{{ $celda }}text-align:right;white-space:nowrap;">
                            {{ ($this->marcarEtapaAction)(['etapa' => $e['etapa'], 'marcada' => (bool) $e['fecha']]) }}
                            @if ($e['quitable'])
                                {{ ($this->quitarEtapaAction)(['etapa' => $e['etapa']]) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ $esPrevisto ? 'Lo que saldría si se liquida ahora' : 'Liquidación' }}</x-slot>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:1rem;">
            <div><div style="{{ $etiqueta }}">Mercancía (FOB en pesos)</div><strong style="font-size:1.2rem;">{{ $cop($esPrevisto ? $previsto['fob'] : $imp->lineas->sum('costo_fob_total')) }}</strong></div>
            <div><div style="{{ $etiqueta }}">Gastos al inventario</div><strong style="font-size:1.2rem;">{{ $cop($previsto['gastos_al_inventario']) }}</strong></div>
            <div><div style="{{ $etiqueta }}">Todos los gastos</div><strong style="font-size:1.2rem;">{{ $cop($imp->gastos->sum('monto_base')) }}</strong></div>
            @if ($esPrevisto)
                <div><div style="{{ $etiqueta }}">Líneas sin recibir</div><strong style="font-size:1.2rem;{{ $previsto['sin_recibir'] > 0 ? 'color:#f59e0b;' : '' }}">{{ $previsto['sin_recibir'] }}</strong></div>
            @else
                <div><div style="{{ $etiqueta }}">Costo total</div><strong style="font-size:1.2rem;">{{ $cop($imp->valor_total_costo) }}</strong></div>
            @endif
        </div>
        @if ($esPrevisto && $previsto['aviso'])
            <div style="margin-top:.75rem;font-size:.85rem;color:#ef4444;">{{ $previsto['aviso'] }}</div>
        @elseif ($esPrevisto && $previsto['sin_recibir'] > 0)
            <div style="margin-top:.75rem;font-size:.85rem;color:#9ca3af;">Lo que bodega todavía no recibe se liquida por lo pedido, a la tasa de su orden.</div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Órdenes en el contenedor</x-slot>
        @forelse ($imp->ordenes as $o)
            <div style="display:flex;flex-wrap:wrap;gap:1rem;justify-content:space-between;{{ $celda }}font-size:.85rem;">
                <a href="{{ \App\Modules\Dropi\Filament\Pages\VerOrdenCompraDropi::getUrl(['orden' => $o->id]) }}" style="color:#3b82f6;font-weight:600;">{{ $o->numero }}</a>
                <span>{{ $o->proveedor?->razon_social ?: $o->proveedor?->nombre_completo }}</span>
                <span>{{ $o->moneda }} {{ number_format((float) $o->total, 2, ',', '.') }}</span>
                <span>{{ $o->estado instanceof \App\Modules\Compras\Enums\EstadoOrdenCompra ? $o->estado->label() : (string) $o->estado }}</span>
                <span style="color:#9ca3af;">{{ rtrim(rtrim(number_format((float) $o->pivot->volumen_m3, 4, ',', '.'), '0'), ',') ?: '0' }} m³ · {{ rtrim(rtrim(number_format((float) $o->pivot->peso_kg, 3, ',', '.'), '0'), ',') ?: '0' }} kg</span>
            </div>
        @empty
            <div style="color:#9ca3af;font-size:.85rem;">Todavía no hay órdenes. Use «Agregar orden» con una orden de importación aprobada.</div>
        @endforelse
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Gastos</x-slot>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <thead><tr style="color:#9ca3af;text-align:left;">
                    <th style="{{ $celda }}">Fecha</th><th style="{{ $celda }}">Concepto</th><th style="{{ $celda }}">Descripción</th>
                    <th style="{{ $num }}">Monto</th><th style="{{ $num }}">En pesos</th><th style="{{ $celda }}">Al inventario</th>
                    <th style="{{ $celda }}">Reparto</th><th style="{{ $celda }}">Soporte</th>
                </tr></thead>
                <tbody>
                @forelse ($imp->gastos as $g)
                    <tr>
                        <td style="{{ $celda }}">{{ $g->fecha?->format('Y-m-d') }}</td>
                        <td style="{{ $celda }}">{{ $g->concepto instanceof \App\Modules\Compras\Enums\ConceptoGastoImportacion ? $g->concepto->label() : (string) $g->concepto }}</td>
                        <td style="{{ $celda }}">{{ $g->descripcion }}</td>
                        <td style="{{ $num }}">{{ $g->moneda }} {{ number_format((float) $g->monto, 2, ',', '.') }}</td>
                        <td style="{{ $num }}">{{ $cop($g->monto_base) }}</td>
                        <td style="{{ $celda }}">{{ \App\Modules\Compras\Enums\ConceptoGastoImportacion::gastoEsIvaImportacion($g) ? 'No (IVA)' : ($g->capitalizable ? 'Sí' : 'No') }}</td>
                        <td style="{{ $celda }}">{{ ucfirst((string) $g->metodo_prorrateo) }}</td>
                        <td style="{{ $celda }}">
                            @forelse ($comprobantes[$g->id] ?? [] as $d)
                                <a href="{{ route('app.compras.importacion.documento.ver', $d->id, false) }}" target="_blank" style="color:#3b82f6;">{{ $d->nombre }}</a>
                            @empty
                                <span style="color:#9ca3af;">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="{{ $celda }}color:#9ca3af;">Sin gastos todavía: flete, agencia de aduanas, arancel, IVA de importación…</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Declaraciones</x-slot>
        <x-slot name="afterHeader">{{ $this->guardarDeclaracionAction }}</x-slot>
        @forelse ($seg['declaraciones'] as $d)
            <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;{{ $celda }}font-size:.85rem;">
                <strong>{{ $d['numero'] ?: 'Sin número' }}</strong>
                <span>{{ $selectividad[$d['selectividad']] ?? $d['selectividad'] }}</span>
                <span style="color:#9ca3af;">Cita: {{ $d['fecha_cita'] ?? '—' }}</span>
                <span style="{{ $d['fecha_levante'] ? '' : 'color:#f59e0b;' }}">Levante: {{ $d['fecha_levante'] ?? 'pendiente' }}</span>
                <span style="color:#9ca3af;flex-basis:100%;">{{ $d['notas'] }}</span>
                <span>
                    {{ ($this->guardarDeclaracionAction)(['declaracion' => $d['id']]) }}
                    {{ ($this->quitarDeclaracionAction)(['declaracion' => $d['id']]) }}
                </span>
            </div>
        @empty
            <div style="color:#9ca3af;font-size:.85rem;">Sin declaraciones. Para marcar el levante, todas deben tener su fecha de levante.</div>
        @endforelse
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Novedades</x-slot>
        <x-slot name="afterHeader">{{ $this->registrarInspeccionAction }}</x-slot>
        @forelse ($seg['inspecciones'] as $i)
            <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;{{ $celda }}font-size:.85rem;">
                <strong>{{ $tiposNovedad[$i['tipo']] ?? $i['tipo'] }}</strong>
                <span>{{ $i['fecha'] }}</span>
                <span style="color:#9ca3af;">{{ $i['registrado_por'] }}</span>
                @if ($i['resuelta_en'])
                    <x-filament::badge color="success">Resuelta {{ $i['resuelta_en'] }}</x-filament::badge>
                @else
                    <span><x-filament::badge color="warning">Abierta</x-filament::badge> {{ ($this->resolverInspeccionAction)(['inspeccion' => $i['id']]) }}</span>
                @endif
                @if ($i['notas'])
                    <span style="color:#9ca3af;flex-basis:100%;">{{ $i['notas'] }}</span>
                @endif
            </div>
        @empty
            <div style="color:#9ca3af;font-size:.85rem;">Sin novedades: inspección forzosa en puerto, aleatorio de la DUTA…</div>
        @endforelse
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Documentos</x-slot>
        <x-slot name="description">Con «Subir documento». Cada tipo guarda todas sus versiones; la más nueva va primero.</x-slot>
        @forelse ($documentos as $tipo => $versiones)
            <div style="{{ $celda }}font-size:.85rem;">
                <div style="font-weight:600;margin-bottom:.25rem;">{{ $tipos[$tipo] ?? $tipo }}</div>
                @foreach ($versiones->sortByDesc('version') as $doc)
                    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;{{ $loop->first ? '' : 'color:#9ca3af;' }}">
                        <span>v{{ $doc->version }}</span>
                        <a href="{{ route('app.compras.importacion.documento.ver', $doc->id, false) }}" target="_blank" style="color:#3b82f6;">{{ $doc->nombre }}</a>
                        <span>{{ $doc->created_at?->timezone('America/Bogota')->format('Y-m-d H:i') }}</span>
                        <span>{{ $doc->autor?->name }}</span>
                        @if ($doc->notas)<span>{{ $doc->notas }}</span>@endif
                        {{ ($this->quitarDocumentoAction)(['documento' => $doc->id]) }}
                    </div>
                @endforeach
            </div>
        @empty
            <div style="color:#9ca3af;font-size:.85rem;">Sin documentos: proforma, lista de empaque, BL, invoice, DUTA, declaración…</div>
        @endforelse
    </x-filament::section>

    @unless ($esPrevisto)
        <x-filament::section>
            <x-slot name="heading">Costo por producto</x-slot>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                    <thead><tr style="color:#9ca3af;text-align:left;">
                        <th style="{{ $num }}">Cantidad</th><th style="{{ $num }}">FOB unit.</th><th style="{{ $num }}">Gasto repartido</th><th style="{{ $num }}">Costo final unit.</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($imp->lineas as $l)
                        <tr>
                            <td style="{{ $num }}">{{ rtrim(rtrim(number_format((float) $l->cantidad, 3, ',', '.'), '0'), ',') }}</td>
                            <td style="{{ $num }}">{{ $cop($l->costo_fob_unit) }}</td>
                            <td style="{{ $num }}">{{ $cop($l->gasto_prorrateado) }}</td>
                            <td style="{{ $num }}"><strong>{{ $cop($l->costo_final_unit) }}</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
