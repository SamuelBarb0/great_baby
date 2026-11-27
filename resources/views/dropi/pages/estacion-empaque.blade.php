<x-filament-panels::page>
    @php
        $p = $this->pedidoActivo();
        $m = $this->metricas();
        $r = $this->ranking();
    @endphp

    <style>
        @media (max-width: 900px) {
            .estacion-panel-top { grid-template-columns: 1fr !important; }
            /* Las dos métricas lado a lado: apiladas empujaban el pedido activo fuera de la pantalla. */
            .estacion-metricas { grid-template-rows: none !important; grid-template-columns: 1fr 1fr; }
        }
        /* Celular: el campo del escáner se lee completo (a 1.5rem se cortaba el texto guía). */
        @media (max-width: 480px) {
            #scan-input { font-size: 1rem !important; letter-spacing: 0 !important; padding: .85rem 1rem !important; min-width: 0; }
            .estacion-camara-texto { display: none; }
        }
    </style>
    {{-- Panel superior: escáner + métricas en vivo --}}
    <div class="estacion-panel-top" style="display:grid;grid-template-columns:1.4fr 1fr;gap:1rem;">

        {{-- Escáner --}}
        <div data-scanner-panel style="padding:1.5rem;background:linear-gradient(135deg,#111827,#1f2937);border:2px solid rgba(180,83,9,.4);border-radius:1rem;box-shadow:0 8px 32px rgba(180,83,9,.15);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;">
                <div style="font-size:.75rem;color:#f59e0b;text-transform:uppercase;letter-spacing:2px;font-weight:700;">🔫 Estación de Empaque</div>
                <div style="display:flex;align-items:center;gap:.5rem;"
                     x-data="{ mute: (localStorage.getItem('gb.mute')==='1'), volumen: parseInt(localStorage.getItem('gb.vol')||'60')/100 }">
                    {{-- Re-audit DR-α UX (UX-M9) · reloj arranca desde hora
                         servidor Bogotá (evita tablet con hora errónea) y
                         luego avanza local. `serverStart` es Date.now() del
                         momento en que renderizó Blade con TZ Bogotá. --}}
                    <div style="font-size:.8rem;color:#9ca3af;"
                         x-data="{ h: '', delta: (Date.now() - {{ (int) (now()->timezone('America/Bogota')->timestamp * 1000) }}) }"
                         x-init="setInterval(() => { const t = new Date(Date.now() - delta); h = t.toLocaleTimeString('es-CO'); }, 1000)"
                         x-text="h" title="Hora servidor · Bogotá"></div>
                    <button type="button"
                            @click="mute = !mute; localStorage.setItem('gb.mute', mute?'1':'0'); window.gbEmpaqueMute = mute;"
                            :title="mute ? 'Sonidos silenciados — click para activar' : 'Sonidos activos — click para silenciar'"
                            style="padding:.35rem .55rem;background:transparent;border:1px solid rgba(245,158,11,.3);border-radius:.4rem;cursor:pointer;font-size:.85rem;"
                            :style="mute ? 'color:#6b7280' : 'color:#f59e0b'">
                        <span x-text="mute ? '🔇' : '🔊'"></span>
                    </button>
                    <button type="button"
                            onclick="const el=document.documentElement;if(document.fullscreenElement){document.exitFullscreen()}else{el.requestFullscreen()}"
                            title="Pantalla completa (F11)"
                            style="padding:.35rem .6rem;background:transparent;color:#f59e0b;border:1px solid rgba(245,158,11,.3);border-radius:.4rem;cursor:pointer;font-size:.85rem;">
                        ⛶
                    </button>
                    {{-- inicializa la bandera global antes de cualquier scan --}}
                    <span x-init="window.gbEmpaqueMute = mute" style="display:none"></span>
                </div>
            </div>

            {{-- Un solo camino: wire:submit del form. Adiós Enter duplicado. --}}
            <form wire:submit.prevent="escanear" class="estacion-escaner" style="display:flex;gap:.5rem;align-items:stretch;">
                <label for="scan-input" class="sr-only" style="position:absolute;left:-9999px;">Código de guía o variante</label>
                <input id="scan-input"
                       type="text"
                       wire:model="codigo"
                       autofocus
                       autocomplete="off"
                       inputmode="text"
                       placeholder="Escanea guía o producto…"
                       style="flex:1;min-width:0;width:100%;padding:1rem 1.25rem;font-size:1.5rem;font-family:ui-monospace,'Courier New',monospace;background:#0f172a;color:#f59e0b;border:2px solid #b45309;border-radius:.75rem;outline:none;letter-spacing:2px;font-weight:600;"
                       onfocus="this.style.borderColor='#f59e0b';this.style.boxShadow='0 0 0 3px rgba(245,158,11,.2)';"
                       onblur="this.style.borderColor='#b45309';this.style.boxShadow='';">
                {{-- Lo que lee la cámara entra por el mismo camino que la pistola (public/js/gb-camara-codigos.js). --}}
                <button type="button"
                        x-data
                        x-on:click="window.gbCamaraCodigos.abrir({ alLeer: (c) => { $wire.codigo = c; $wire.escanear(); } })"
                        title="Escanear con cámara"
                        aria-label="Escanear con cámara"
                        style="flex-shrink:0;padding:0 1rem;background:#1f2937;color:#f59e0b;border:2px solid #b45309;border-radius:.75rem;font-weight:700;font-size:.9rem;cursor:pointer;white-space:nowrap;">
                    📷 <span class="estacion-camara-texto">Cámara</span>
                </button>
            </form>
            <script src="{{ asset('js/gb-camara-codigos.js') }}" defer></script>

            @if($ultimoResultado)
                <div style="margin-top:.75rem;padding:.75rem 1rem;border-radius:.5rem;font-weight:600;
                    background:{{ $ultimoResultado['sonido']==='ok' ? 'rgba(16,185,129,.15)' : ($ultimoResultado['sonido']==='warn' ? 'rgba(245,158,11,.15)' : 'rgba(239,68,68,.15)') }};
                    color:{{ $ultimoResultado['sonido']==='ok' ? '#10b981' : ($ultimoResultado['sonido']==='warn' ? '#f59e0b' : '#ef4444') }};
                    border-left:3px solid currentColor;">
                    {{ $ultimoResultado['mensaje'] }}
                </div>
            @endif
        </div>

        {{-- Métricas del día --}}
        <div class="estacion-metricas" style="display:grid;grid-template-rows:1fr 1fr;gap:.75rem;">
            <div style="padding:1rem 1.25rem;background:rgba(16,185,129,.1);border-left:4px solid #10b981;border-radius:.75rem;">
                <div style="font-size:.7rem;color:#9ca3af;text-transform:uppercase;">Empacados hoy</div>
                <div style="font-size:2.5rem;font-weight:800;color:#10b981;line-height:1;">{{ $m['mis_empaques_hoy'] }}</div>
                <div style="font-size:.75rem;color:#9ca3af;">Promedio: {{ $m['promedio_display'] }}</div>
            </div>
            <div style="padding:1rem 1.25rem;background:rgba(59,130,246,.1);border-left:4px solid #3b82f6;border-radius:.75rem;">
                <div style="font-size:.7rem;color:#9ca3af;text-transform:uppercase;">Mejor tiempo hoy</div>
                <div style="font-size:2rem;font-weight:800;color:#3b82f6;line-height:1.1;">
                    @if($m['mejor_tiempo_seg']>0){{ gmdate('i:s', $m['mejor_tiempo_seg']) }}@else—@endif
                </div>
                <div style="font-size:.75rem;color:#9ca3af;">min:seg</div>
            </div>
        </div>
    </div>

    {{-- Pedido activo --}}
    @if($p)
        <div style="margin-top:1rem;padding:1.25rem 1.5rem;background:linear-gradient(135deg,#1f2937,#111827);border:2px solid #b45309;border-radius:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
                <div>
                    <div style="font-size:.75rem;color:#f59e0b;text-transform:uppercase;letter-spacing:2px;font-weight:700;">📦 Pedido activo</div>
                    <div style="font-family:ui-monospace,monospace;font-size:2.25rem;font-weight:800;color:#f59e0b;line-height:1.1;">{{ $p->guia }}</div>
                    @if($p->tieneGuiaReal())
                        <div style="font-size:.85rem;color:#fbbf24;">🏷 Pega el sticker de esta guía</div>
                    @endif
                    {{-- Mientras se carga el inventario del ERP también se empaca lo que
                         no tiene stock allá (regla «Exigir stock del ERP», 24-nov-2026). --}}
                    @if($p->abiertoSinStockErp())
                        <div style="display:inline-block;margin-top:.35rem;padding:.2rem .6rem;background:rgba(244,63,94,.15);border:1px solid rgba(244,63,94,.5);color:#fda4af;border-radius:.4rem;font-size:.8rem;font-weight:700;">
                            ⚠ Sin stock en el ERP: empácalo con la mercancía de la bodega
                        </div>
                    @endif
                    <div style="color:#e5e7eb;margin-top:.25rem;">{{ $p->cliente_nombre }} · {{ $p->cliente_ciudad }}</div>
                    <div style="font-size:.85rem;color:#9ca3af;">{{ $p->transportadora }} · Corte {{ $p->corte?->numero }}</div>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button wire:click="cancelarPedido"
                            style="padding:.6rem 1rem;background:transparent;color:#9ca3af;border:1px solid rgba(156,163,175,.35);border-radius:.5rem;cursor:pointer;">
                        Cancelar
                    </button>
                    {{-- Producto que todavía no está listo (27-nov-2026): al final de la fila. --}}
                    <button wire:click="dejarParaDespues" wire:loading.attr="disabled" wire:target="dejarParaDespues"
                            title="Lo manda al final de la fila y abre el siguiente"
                            style="padding:.6rem 1rem;background:transparent;color:#fbbf24;border:1px solid rgba(251,191,36,.45);border-radius:.5rem;cursor:pointer;">
                        ⏭ Dejar para después
                    </button>
                    @php $registroActivo = $this->registroActivo(); @endphp
                    @if($registroActivo?->foto_path)
                        <span style="padding:.6rem 1rem;background:rgba(16,185,129,.15);color:#10b981;border:1px solid #10b981;border-radius:.5rem;font-weight:600;font-size:.85rem;display:inline-flex;align-items:center;gap:.35rem;">
                            📸 Foto capturada
                        </span>
                    @endif
                    <button type="button" onclick="window.abrirCamara()"
                            style="padding:.75rem 1.25rem;background:{{ $registroActivo?->foto_path ? '#374151' : 'linear-gradient(90deg,#f59e0b,#b45309)' }};color:white;border:0;border-radius:.5rem;font-weight:700;font-size:.95rem;cursor:pointer;box-shadow:0 4px 12px rgba(245,158,11,.35);">
                        📸 {{ $registroActivo?->foto_path ? 'Cambiar foto' : (setting('empaque.foto_obligatoria', true) ? 'Foto paquete' : 'Foto (opcional)') }}
                    </button>
                    {{-- Capa de seguridad: manda la ubicación del equipo con la confirmación (ver Trazabilidad). --}}
                    <button type="button"
                            x-data="{ ocupado: false }"
                            x-on:click="if (ocupado) return; ocupado = true; window.gbUbicacion().then(g => $wire.confirmarYSiguiente(g)).finally(() => ocupado = false)"
                            x-bind:disabled="ocupado"
                            wire:loading.attr="disabled" wire:target="confirmarYSiguiente"
                            aria-label="Confirmar empaque y pasar al siguiente pedido"
                            style="padding:.75rem 1.5rem;background:linear-gradient(90deg,#10b981,#059669);color:white;border:0;border-radius:.5rem;font-weight:700;font-size:1rem;cursor:pointer;box-shadow:0 4px 12px rgba(16,185,129,.4);disabled:opacity:.6;disabled:cursor:wait;">
                        <span wire:loading.remove wire:target="confirmarYSiguiente">✅ CONFIRMAR EMPAQUE</span>
                        <span wire:loading wire:target="confirmarYSiguiente">⏳ Confirmando…</span>
                    </button>
                </div>
            </div>

            {{-- La guía a la vista desde que se abre el pedido, y al escanear lo último
                 se imprime sola (señora Lahida, 25-nov-2026: «aparezca la guía y ya»).
                 Viene del ERP (StickerPedidoController) para poder mandarla a imprimir. --}}
            @if($p->stickerUrl())
                @php $stickerErp = route('dropi.pedido.sticker', $p); @endphp
                <div wire:key="sticker-{{ $p->id }}" style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start;">
                    <iframe src="{{ $stickerErp }}#toolbar=0&navpanes=0&view=FitH" title="Sticker de la guía {{ $p->guia }}"
                            style="width:min(420px,100%);height:340px;border:2px solid #fbbf24;border-radius:.5rem;background:#fff;"></iframe>
                    <div style="flex:1;min-width:200px;">
                        <div style="font-size:1rem;font-weight:700;color:#fbbf24;">🏷 Guía {{ $p->guia }}</div>
                        <div style="font-size:.85rem;color:#9ca3af;margin-top:.25rem;">
                            Se imprime sola al escanear lo último del pedido{{ setting('empaque.foto_obligatoria', true) ? '' : ', y el empaque se confirma solo' }}.
                        </div>
                        <button type="button" onclick="window.gbImprimirSticker(@js($stickerErp))"
                                style="margin-top:.6rem;padding:.6rem 1.1rem;background:#059669;color:white;border:0;border-radius:.5rem;font-weight:700;font-size:.9rem;cursor:pointer;">
                            🖨 Imprimir guía
                        </button>
                    </div>
                </div>
            @endif

            <div style="margin-top:1rem;">
                @php $total = $p->items->count(); $pickados = $p->items->whereNotNull('pickeado_at')->count(); @endphp
                <div style="display:flex;justify-content:space-between;font-size:.85rem;color:#9ca3af;margin-bottom:.35rem;">
                    <span>Progreso de escaneo</span>
                    <span><strong style="color:#10b981;">{{ $pickados }}/{{ $total }}</strong></span>
                </div>
                <div style="height:8px;background:rgba(156,163,175,.2);border-radius:9999px;overflow:hidden;">
                    <div style="height:100%;width:{{ $total>0 ? round($pickados/$total*100) : 0 }}%;background:linear-gradient(90deg,#10b981,#f59e0b);transition:width .35s;"></div>
                </div>
            </div>

            <div style="margin-top:1rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(280px,100%),1fr));gap:.75rem;">
                @foreach($p->items as $it)
                    @php $isPicked = ! is_null($it->pickeado_at ?? null); @endphp
                    <div style="padding:.75rem 1rem;background:{{ $isPicked ? 'rgba(16,185,129,.18)' : '#0f172a' }};border-left:3px solid {{ $isPicked ? '#10b981' : '#6b7280' }};border-radius:.5rem;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;">
                            {{-- La foto del producto, para reconocerlo en la mesa sin leer (24-nov-2026). --}}
                            @if($img = ($it->variante?->producto ?? $it->producto)?->imagenes->first())
                                <img src="{{ $img->url() }}" alt="" loading="lazy"
                                     style="width:72px;height:72px;object-fit:cover;border-radius:.5rem;flex-shrink:0;background:#1f2937;">
                            @endif
                            <div style="flex:1;min-width:0;">
                                {{-- Un ítem es de una variante o de un producto agregado (sin variantes):
                                     el agregado se escanea por su referencia (ProcesarEscaneoEmpaque). --}}
                                <div style="font-weight:600;color:#e5e7eb;">{{ ($it->variante?->producto ?? $it->producto)?->nombre ?? $it->sku_dropi }}</div>
                                @if(($varItem = $it->nombreVariante()) !== '')
                                    <div style="font-size:1rem;font-weight:700;color:#fbbf24;">{{ $varItem }}</div>
                                @endif
                                <div style="font-family:monospace;font-size:.72rem;color:#cbd5e1;margin-top:.25rem;">{{ $it->variante?->codigo_barras ?? $it->producto?->referencia ?? '—' }}</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:1.25rem;font-weight:700;color:#f59e0b;">×{{ $it->cantidad ?? 1 }}</div>
                                {{-- Lo escaneado de esta línea, para que una de varias unidades no parezca sin tocar. --}}
                                @if(! $isPicked && (int) $it->cantidad_pickeada > 0)
                                    <div style="font-size:.75rem;color:#10b981;font-weight:700;">{{ (int) $it->cantidad_pickeada }} de {{ $it->cantidad }}</div>
                                @endif
                                <div style="font-size:1.5rem;">{{ $isPicked ? '✅' : '⏳' }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        {{-- Lo que Aracely imprimió, en fila con la pila de stickers (ColaDeEmpaque,
             24-nov-2026). En turno, la pantalla mira cada 3 s y abre el siguiente sola. --}}
        @php $info = $this->colaInfo; @endphp
        <div @if($enTurno) wire:poll.3s="esperarPedido" @endif
             style="margin-top:1rem;padding:2.5rem;text-align:center;background:rgba(255,255,255,.03);border:2px dashed rgba(156,163,175,.3);border-radius:1rem;">
            <div style="font-size:3rem;">📦</div>
            @if($enTurno)
                <div style="font-size:1.25rem;font-weight:800;color:#f59e0b;">Esperando guías impresas…</div>
                <div style="font-size:.9rem;color:#9ca3af;margin-top:.5rem;">Apenas Aracely imprima, el pedido se abre solo aquí.</div>
                <button type="button" wire:click="pausarTurno"
                        style="margin-top:.75rem;padding:.5rem 1.25rem;background:transparent;color:#9ca3af;border:1px solid rgba(156,163,175,.4);border-radius:.5rem;font-weight:600;cursor:pointer;">
                    ⏸ Pausar
                </button>
            @else
                <button type="button" wire:click="siguientePedido" wire:loading.attr="disabled" wire:target="siguientePedido"
                        style="margin-top:.75rem;padding:1rem 2rem;background:linear-gradient(90deg,#f59e0b,#b45309);color:white;border:0;border-radius:.75rem;font-weight:800;font-size:1.25rem;cursor:pointer;box-shadow:0 4px 14px rgba(245,158,11,.4);">
                    ▶ Empezar a empacar
                </button>
                <div style="font-size:.9rem;color:#9ca3af;margin-top:.75rem;">Desde ahí los pedidos van llegando solos, en el orden de los stickers.</div>
                <div style="font-size:.8rem;color:#6b7280;margin-top:.25rem;">También puedes escanear un producto o una guía.</div>
            @endif
            <div style="font-size:.85rem;color:#d1d5db;margin-top:1rem;">
                {{ $info['en_cola'] }} impreso(s) por empacar
                @if($info['sin_imprimir'] > 0)
                    · <span style="color:#f59e0b;">{{ $info['sin_imprimir'] }} pendiente(s) sin imprimir: no llegan hasta que Aracely los imprima</span>
                @endif
            </div>
        </div>
    @endif

    {{-- Lo empacado hoy por este operario, con su guía de Dropi: el sticker se
         imprime desde aquí aunque la página se haya recargado (24-nov-2026). --}}
    @php $stickers = $this->misStickers(); @endphp
    @if(!empty($stickers))
        <div style="margin-top:1rem;padding:1rem 1.25rem;background:#111827;border:1px solid rgba(16,185,129,.35);border-radius:1rem;">
            <div style="font-size:.75rem;color:#10b981;text-transform:uppercase;letter-spacing:2px;font-weight:700;margin-bottom:.6rem;">
                🏷 Mis stickers de hoy ({{ count($stickers) }})
            </div>
            @foreach($stickers as $s)
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem;padding:.55rem 0;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(255,255,255,.08);' }}">
                    <div style="flex:1;min-width:0;">
                        <div style="font-family:ui-monospace,monospace;font-weight:700;color:#e5e7eb;font-size:.9rem;">
                            {{ $s['guia'] ?: 'Sin guía' }} <span style="color:#9ca3af;font-weight:400;">· orden {{ $s['orden'] }}</span>
                        </div>
                        <div style="font-size:.75rem;color:#9ca3af;">{{ $s['transportadora'] }} · {{ $s['ciudad'] }} · {{ $s['hora'] }}</div>
                    </div>
                    @if($s['sticker_url'])
                        <button type="button" onclick="window.gbImprimirSticker(@js(route('dropi.pedido.sticker', $s['pedido_id'])))"
                                style="padding:.45rem .9rem;background:#059669;color:white;border:0;border-radius:.5rem;font-weight:700;font-size:.85rem;cursor:pointer;">
                            🖨 Imprimir sticker
                        </button>
                    @else
                        <button type="button" wire:click="pedirGuia({{ $s['pedido_id'] }})" wire:loading.attr="disabled" wire:target="pedirGuia"
                                style="padding:.45rem .9rem;background:#d97706;color:white;border:0;border-radius:.5rem;font-weight:700;font-size:.85rem;cursor:pointer;">
                            <span wire:loading.remove wire:target="pedirGuia">{{ $s['guia'] ? '↻ Traer sticker' : '↻ Pedir guía' }}</span>
                            <span wire:loading wire:target="pedirGuia">Pidiendo a Dropi…</span>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Cola de corte: lo que Aracely genera e imprime va apareciendo aquí, en el
         orden de los stickers (25-nov-2026). Es una isla de Livewire: se refresca
         sola cada 5 s sin tocar el pedido activo ni la casilla del escáner; con
         `always` también se refresca cuando la estación entera se actualiza. --}}
    @island(name: 'cola-de-corte', always: true)
        @php
            $cola = $this->proximosPedidos(6);
            $porEmpacar = app(\App\Modules\Dropi\Support\ColaDeEmpaque::class)->contar((int) auth()->id())
                - ($this->pedidoActivoId ? 1 : 0);
            $cortes = collect($cola)->pluck('corte')->filter()->unique()->values();
        @endphp
        <div wire:poll.5s style="margin-top:1rem;padding:1rem 1.25rem;background:#111827;border:1px solid rgba(59,130,246,.35);border-radius:1rem;">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:baseline;gap:.5rem;margin-bottom:.6rem;">
                <div style="font-size:.75rem;color:#3b82f6;text-transform:uppercase;letter-spacing:2px;font-weight:700;">
                    📦 Cola de corte @if($cortes->isNotEmpty())· {{ $cortes->join(' / ') }}@endif
                </div>
                <div style="font-size:.8rem;color:#9ca3af;">{{ max(0, $porEmpacar) }} por empacar</div>
            </div>
            @if(empty($cola))
                <div style="font-size:.85rem;color:#9ca3af;">Todavía no hay pedidos del corte: aparecen aquí apenas Aracely genere el corte.</div>
            @else
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(220px,100%),1fr));gap:.5rem;">
                    @foreach($cola as $q)
                        <div wire:key="cola-{{ $q['id'] }}" style="padding:.6rem .75rem;background:#1e293b;border-left:3px solid #3b82f6;border-radius:.4rem;">
                            <div style="font-family:ui-monospace,monospace;font-weight:700;color:#93c5fd;font-size:.85rem;">{{ $q['guia'] }}</div>
                            <div style="font-size:.8rem;color:#e5e7eb;">{{ \Illuminate\Support\Str::limit($q['cliente'], 22) }}</div>
                            <div style="font-size:.7rem;color:#9ca3af;">{{ $q['ciudad'] }} · {{ $q['items'] }} {{ $q['items'] === 1 ? 'ítem' : 'ítems' }}@if($cortes->count() > 1 && $q['corte']) · {{ $q['corte'] }}@endif</div>
                        </div>
                    @endforeach
                </div>
                @if($porEmpacar > count($cola))
                    <div style="font-size:.75rem;color:#6b7280;margin-top:.5rem;">y {{ $porEmpacar - count($cola) }} más detrás.</div>
                @endif
            @endif
        </div>
    @endisland

    {{-- Modal cámara (nativo, sin dependencias) + fallback file --}}
    <div id="modal-camara" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9998;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:#0f172a;border:1px solid rgba(245,158,11,.4);border-radius:1rem;padding:1.25rem;max-width:560px;width:100%;">
            <div style="font-size:.75rem;color:#f59e0b;text-transform:uppercase;letter-spacing:2px;font-weight:700;margin-bottom:.75rem;">
                📸 Foto del paquete cerrado
            </div>
            <video id="cam-video" autoplay playsinline muted
                   style="width:100%;border-radius:.5rem;background:#000;aspect-ratio:4/3;object-fit:cover;"></video>
            <canvas id="cam-canvas" style="display:none;"></canvas>

            {{-- Aviso de error si webcam no está disponible + fallback --}}
            <div id="cam-error" style="display:none;padding:.75rem 1rem;background:rgba(239,68,68,.1);border-left:3px solid #ef4444;border-radius:.4rem;color:#fca5a5;font-size:.85rem;margin-top:.75rem;">
                <div style="font-weight:600;margin-bottom:.4rem;">No pudimos acceder a la cámara.</div>
                <div id="cam-error-msg" style="opacity:.85;margin-bottom:.6rem;font-size:.8rem;"></div>
                <label style="display:inline-block;padding:.6rem 1rem;background:#f59e0b;color:white;border-radius:.4rem;cursor:pointer;font-weight:600;font-size:.85rem;">
                    📁 Subir foto desde archivo
                    <input type="file" accept="image/*" capture="environment" style="display:none;" onchange="window.subirFotoArchivo(event)">
                </label>
            </div>

            <div style="display:flex;gap:.5rem;margin-top:.75rem;">
                <button type="button" onclick="window.cerrarCamara()"
                        style="flex:1;padding:.75rem;background:transparent;color:#9ca3af;border:1px solid rgba(156,163,175,.35);border-radius:.5rem;cursor:pointer;">
                    Cancelar
                </button>
                <button type="button" id="btn-capturar" onclick="window.capturarFoto()"
                        style="flex:2;padding:.9rem;background:linear-gradient(90deg,#f59e0b,#b45309);color:white;border:0;border-radius:.5rem;font-weight:700;font-size:1rem;cursor:pointer;">
                    📷 CAPTURAR
                </button>
            </div>
        </div>
    </div>

    {{-- Ranking del día --}}
    @if(!empty($r))
        <div style="margin-top:1rem;padding:1.25rem 1.5rem;background:rgba(255,255,255,.03);border-radius:1rem;">
            <div style="font-size:.75rem;color:#f59e0b;text-transform:uppercase;letter-spacing:2px;font-weight:700;margin-bottom:.75rem;">🏆 Ranking del día</div>
            <table style="width:100%;font-size:.9rem;">
                <thead>
                    <tr style="text-align:left;color:#9ca3af;font-size:.7rem;text-transform:uppercase;border-bottom:1px solid rgba(156,163,175,.2);">
                        <th style="padding:.5rem 0;width:30px;">#</th>
                        <th>Operario</th>
                        <th style="text-align:right;">Empacados</th>
                        <th style="text-align:right;">Promedio</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($r as $i => $row)
                    <tr style="border-bottom:1px solid rgba(156,163,175,.1);">
                        <td style="padding:.5rem 0;font-weight:700;color:{{ $i===0 ? '#f59e0b' : '#9ca3af' }};">
                            @if($i===0)🥇@elseif($i===1)🥈@elseif($i===2)🥉@else{{ $i+1 }}@endif
                        </td>
                        <td style="font-weight:{{ $row['nombre']===auth()->user()->name ? '700' : '400' }};color:{{ $row['nombre']===auth()->user()->name ? '#f59e0b' : '#e5e7eb' }};">
                            {{ $row['nombre'] }}@if($row['nombre']===auth()->user()->name) <span style="font-size:.7rem;color:#9ca3af;">(tú)</span>@endif
                        </td>
                        <td style="text-align:right;font-weight:600;">{{ $row['total'] }}</td>
                        <td style="text-align:right;color:#9ca3af;">{{ $row['prom']>0 ? gmdate('i:s', $row['prom']) : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Desde aquí se imprime el sticker: fuera de lo que Livewire repinta, para que
         abrir el siguiente pedido no corte la impresión. No va con display:none
         porque así Chrome no carga el PDF. --}}
    <div wire:ignore>
        <iframe id="gb-print-frame" title="Impresión del sticker" tabindex="-1" aria-hidden="true"
                style="position:fixed;right:0;bottom:0;width:2px;height:2px;border:0;opacity:0;pointer-events:none;"></iframe>
    </div>

    {{-- Sonidos + voz + flash + auto-focus --}}
    <script>
        // Imprime el sticker de una guía sin abrir pestañas. Con Chrome abierto con
        //   --kiosk-printing va directo a la impresora; si no, sale el cuadro de
        //   imprimir y basta un Enter. Si el navegador no lo deja, se abre el PDF.
        window.gbImprimirSticker = function (url) {
            const f = document.getElementById('gb-print-frame');
            const imprimir = () => {
                try { f.contentWindow.focus(); f.contentWindow.print(); }
                catch (e) { window.open(url, '_blank'); }
            };
            if (f.dataset.url === url && f.dataset.listo === '1') { imprimir(); return; }
            f.dataset.url = url;
            f.dataset.listo = '0';
            f.onload = () => { f.dataset.listo = '1'; setTimeout(imprimir, 300); };
            f.src = url;
        };

        // Lo último escaneado: confirma (si la foto no es obligatoria) e imprime.
        //   Primero sale la confirmación, para que el cuadro de imprimir no la frene.
        window.addEventListener('empaque-completo', (e) => {
            const d = (e.detail && (e.detail.sticker !== undefined || e.detail.confirmar !== undefined)) ? e.detail : (e.detail?.[0] || {});
            if (d.confirmar) {
                const c = window.Livewire.all().find(x => (x.name || '').includes('EstacionEmpaque'));
                if (c) window.gbUbicacion().then(g => c.$wire.confirmarYSiguiente(g));
            }
            if (d.sticker) window.gbImprimirSticker(d.sticker);
        });

        // «Confirmar empaque» sin el último escaneo: la guía sale al confirmar.
        window.addEventListener('empaque-imprimir', (e) => {
            const d = (e.detail && e.detail.sticker !== undefined) ? e.detail : (e.detail?.[0] || {});
            if (d.sticker) window.gbImprimirSticker(d.sticker);
        });

        // Ubicación del equipo para la trazabilidad. Nunca rechaza: si se niega
        // el permiso o no hay GPS, manda el motivo y el servidor decide.
        window.gbUbicacion = window.gbUbicacion || function (timeoutMs = 8000) {
            return new Promise((resolve) => {
                if (!navigator.geolocation) return resolve({ estado: 'no_disponible' });
                navigator.geolocation.getCurrentPosition(
                    (p) => resolve({ estado: 'ok', lat: p.coords.latitude, lng: p.coords.longitude, precision: Math.round(p.coords.accuracy) }),
                    (e) => resolve({ estado: e && e.code === 1 ? 'denegado' : 'no_disponible' }),
                    { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 60000 },
                );
            });
        };

        // === Voz TTS en español para operador (manos-libres) ===
        // Elige explícitamente una voz es-* para evitar que Windows lea en inglés
        let vozEs = null;
        const cargarVozEs = () => {
            if (!('speechSynthesis' in window)) return;
            const voces = window.speechSynthesis.getVoices();
            vozEs = voces.find(v => v.lang === 'es-CO')
                 || voces.find(v => v.lang === 'es-MX')
                 || voces.find(v => v.lang === 'es-US')
                 || voces.find(v => v.lang && v.lang.startsWith('es'))
                 || null;
        };
        if ('speechSynthesis' in window) {
            cargarVozEs();
            window.speechSynthesis.onvoiceschanged = cargarVozEs;
        }

        const hablar = (texto, {tasa = 1.1, tono = 1} = {}) => {
            if (window.gbEmpaqueMute) return;
            if (!('speechSynthesis' in window)) return;
            try {
                const u = new SpeechSynthesisUtterance(texto);
                if (vozEs) u.voice = vozEs;
                u.lang = 'es-CO';
                u.rate = tasa;
                u.pitch = tono;
                u.volume = 1;
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(u);
            } catch (e) {}
        };

        // === Flash borde del panel del escáner (verde/amarillo/rojo) ===
        const flashPanel = (color) => {
            const panel = document.querySelector('[data-scanner-panel]');
            if (!panel) return;
            panel.style.transition = 'border-color .18s, box-shadow .18s';
            panel.style.borderColor = color;
            panel.style.boxShadow = `0 0 32px ${color}66`;
            setTimeout(() => {
                panel.style.borderColor = '';
                panel.style.boxShadow = '';
            }, 500);
        };

        // === Confeti minimalista con throttle diario (cada 5° empaque del DÍA, reset a medianoche) ===
        const hoyKey = 'gb.confeti.' + new Date().toISOString().slice(0, 10);
        // Limpiar contadores de días previos
        Object.keys(localStorage).filter(k => k.startsWith('gb.confeti.') && k !== hoyKey).forEach(k => localStorage.removeItem(k));
        let confetiContador = parseInt(localStorage.getItem(hoyKey) || '0');
        const dispararConfeti = () => {
            if (window.gbEmpaqueMute) return;
            confetiContador++;
            localStorage.setItem(hoyKey, confetiContador);
            // Solo en milestones del día: cada 5 empaques (5, 10, 15…).
            if (confetiContador % 5 !== 0) return;

            const emojis = ['🎉','🎊','✨','🎁','⭐','💛'];
            const box = document.createElement('div');
            box.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;pointer-events:none;z-index:9999;overflow:hidden;';
            for (let i = 0; i < 40; i++) {
                const s = document.createElement('span');
                s.textContent = emojis[Math.floor(Math.random() * emojis.length)];
                s.style.cssText = `position:absolute;top:-40px;left:${Math.random()*100}%;font-size:${18+Math.random()*22}px;transition:transform 2s ease-out,opacity 2s;opacity:1;`;
                box.appendChild(s);
                requestAnimationFrame(() => {
                    s.style.transform = `translateY(${window.innerHeight+80}px) rotate(${Math.random()*720-360}deg)`;
                    s.style.opacity = '0';
                });
            }
            document.body.appendChild(box);
            setTimeout(() => box.remove(), 2200);
        };

        window.addEventListener('empaque-scan', (e) => {
            const s = e.detail.sonido || e.detail[0]?.sonido;
            const mensaje = e.detail.mensaje || e.detail[0]?.mensaje || '';

            // Re-audit DR-α UX (UX-C1) · SINGLETON AudioContext.
            //   Antes: `new AudioContext()` en CADA scan. Chrome/Edge tapan
            //   a los 6 contextos abiertos y el beep desaparece silenciosamente
            //   sin error. Ahora reutilizamos un ctx global (`window.__gbAudioCtx`)
            //   creado una sola vez y reactivado si está suspendido (autoplay policy).
            if (! window.gbEmpaqueMute) {
                try {
                    if (! window.__gbAudioCtx) {
                        window.__gbAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    }
                    const ctx = window.__gbAudioCtx;
                    if (ctx.state === 'suspended') ctx.resume();
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain); gain.connect(ctx.destination);
                    osc.type = 'sine';
                    if (s === 'ok') { osc.frequency.value = 880; gain.gain.setValueAtTime(0.2, ctx.currentTime); }
                    else if (s === 'warn') { osc.frequency.value = 440; gain.gain.setValueAtTime(0.2, ctx.currentTime); }
                    else { osc.frequency.value = 220; gain.gain.setValueAtTime(0.3, ctx.currentTime); }
                    osc.start();
                    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
                    osc.stop(ctx.currentTime + 0.25);
                } catch (e) {}
            }

            // 2. Flash visual del panel
            flashPanel(s === 'ok' ? '#10b981' : (s === 'warn' ? '#f59e0b' : '#ef4444'));

            // 3. Voz: solo para OK+empaque completado (evita ruido excesivo)
            if (mensaje.startsWith('✅')) {
                hablar(mensaje.replace(/[✅✓]/g, '').trim());
                dispararConfeti();
            } else if (s === 'ok' && mensaje.includes('abierto')) {
                hablar('Pedido abierto');
            } else if (s === 'error') {
                hablar('Error');
            }

            // 4. Foco de vuelta al input
            setTimeout(() => {
                const inp = document.querySelector('input[wire\\:model="codigo"]');
                if (inp) inp.focus({ preventScroll: true });
            }, 100);
        });

        // Autofocus permanente
        setInterval(() => {
            const inp = document.querySelector('input[wire\\:model="codigo"]');
            if (inp && document.activeElement !== inp
                && !document.querySelector('.fi-modal-window')
                && document.getElementById('modal-camara')?.style.display !== 'flex'
                && !(window.gbCamaraCodigos && window.gbCamaraCodigos.abierta())) {
                inp.focus({ preventScroll: true });
            }
        }, 2000);

        // === Cámara webcam para foto del paquete (con fallback file) ===
        let camStream = null;

        const mostrarModoFallback = (mensaje) => {
            document.getElementById('cam-video').style.display = 'none';
            document.getElementById('btn-capturar').style.display = 'none';
            const errBox = document.getElementById('cam-error');
            const errMsg = document.getElementById('cam-error-msg');
            errMsg.textContent = mensaje;
            errBox.style.display = 'block';
        };
        const resetModalCam = () => {
            document.getElementById('cam-video').style.display = '';
            document.getElementById('btn-capturar').style.display = '';
            document.getElementById('cam-error').style.display = 'none';
        };

        window.abrirCamara = async () => {
            const modal = document.getElementById('modal-camara');
            const video = document.getElementById('cam-video');
            resetModalCam();
            modal.style.display = 'flex';
            try {
                if (! (navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
                    mostrarModoFallback('Tu navegador no expone la API de cámara. Usá el botón "Subir foto desde archivo".');
                    return;
                }
                camStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: {ideal: 1280}, height: {ideal: 960} },
                    audio: false,
                });
                video.srcObject = camStream;
            } catch (err) {
                mostrarModoFallback('Motivo: ' + (err.message || err.name) + '. Podés subir la foto desde archivo (o desde la cámara de tu celu).');
            }
        };
        window.cerrarCamara = () => {
            document.getElementById('modal-camara').style.display = 'none';
            if (camStream) { camStream.getTracks().forEach(t => t.stop()); camStream = null; }
        };
        window.capturarFoto = () => {
            const video = document.getElementById('cam-video');
            const canvas = document.getElementById('cam-canvas');
            if (! video.videoWidth) return;
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            const dataUri = canvas.toDataURL('image/jpeg', 0.82);
            const c = window.Livewire.all().find(x => (x.name || '').includes('EstacionEmpaque'));
            if (c) c.$wire.call('guardarFoto', dataUri).then(() => window.cerrarCamara());
        };
        // Fallback: input type=file (funciona en cualquier dispositivo, en móvil abre la cámara nativa)
        window.subirFotoArchivo = (evt) => {
            const file = evt.target.files && evt.target.files[0];
            if (!file) return;
            if (file.size > 3_000_000) {
                alert('La foto pesa más de 3MB — comprimila o tomá otra.');
                return;
            }
            const reader = new FileReader();
            reader.onload = () => {
                const c = window.Livewire.all().find(x => (x.name || '').includes('EstacionEmpaque'));
                if (c) c.$wire.call('guardarFoto', reader.result).then(() => window.cerrarCamara());
            };
            reader.readAsDataURL(file);
        };
    </script>
</x-filament-panels::page>
