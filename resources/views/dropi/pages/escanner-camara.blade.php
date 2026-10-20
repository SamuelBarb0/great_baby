<x-filament-panels::page>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start;">
        {{-- Cámara --}}
        <x-filament::section>
            <x-slot name="heading">📷 Cámara</x-slot>
            <x-slot name="description">Apunta a la guía Dropi. Detecta EAN, Code128 y QR automáticamente.</x-slot>

            <div style="position:relative;aspect-ratio:1/1;background:#000;border-radius:.75rem;overflow:hidden;">
                <video id="scanner-video" autoplay playsinline muted
                       style="width:100%;height:100%;object-fit:cover;"></video>
                <div id="scanner-overlay"
                     style="position:absolute;top:20%;left:10%;right:10%;bottom:20%;border:3px solid rgba(245,158,11,.7);border-radius:.5rem;pointer-events:none;transition:border-color .2s ease;"></div>
                <div id="scanner-flash"
                     style="position:absolute;inset:0;background:transparent;opacity:0;pointer-events:none;transition:opacity .15s ease;"></div>
            </div>

            <div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                <button type="button" onclick="scannerIniciar()" id="btn-iniciar"
                        style="flex:1;min-height:44px;padding:.75rem 1rem;background:#f59e0b;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer;">
                    ▶️ Iniciar cámara
                </button>
                <button type="button" onclick="scannerDetener()" id="btn-detener" disabled
                        style="flex:1;min-height:44px;padding:.75rem 1rem;background:#6b7280;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer;">
                    ⏸ Detener
                </button>
            </div>

            <div style="margin-top:.75rem;font-size:.85rem;">
                <label style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" id="scanner-sonido" checked>
                    Sonido y vibración al detectar
                </label>
            </div>

            <input type="text" id="scanner-manual" placeholder="O escribe la guía manualmente (Enter)"
                   style="margin-top:.75rem;width:100%;padding:.6rem .75rem;background:rgba(255,255,255,.05);border:1px solid rgba(156,163,175,.3);border-radius:.5rem;color:inherit;">
        </x-filament::section>

        {{-- Resultado --}}
        <x-filament::section>
            <x-slot name="heading">Resultado</x-slot>
            <x-slot name="description">Última guía escaneada · contexto y acción sugerida.</x-slot>

            <div id="scanner-resultado" style="min-height:220px;padding:1rem;background:rgba(255,255,255,.03);border-radius:.5rem;color:#9ca3af;text-align:center;display:flex;align-items:center;justify-content:center;">
                Esperando escaneo…
            </div>

            <div id="scanner-historial" style="margin-top:1rem;">
                <div style="font-size:.75rem;text-transform:uppercase;color:#9ca3af;margin-bottom:.5rem;">Historial de sesión</div>
                <div id="scanner-hist-lista" style="display:flex;flex-direction:column;gap:.375rem;font-size:.85rem;"></div>
            </div>
        </x-filament::section>
    </div>

    <script>
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

    (function () {
        let stream = null;
        let detector = null;
        let escaneando = false;
        let ultimaGuia = '';
        let ultimaMs = 0;
        const historial = [];

        function beep(freq = 880, dur = 120, tipo = 'sine') {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.type = tipo; o.frequency.value = freq;
                o.connect(g); g.connect(ctx.destination);
                g.gain.setValueAtTime(0.001, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.01);
                g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + dur / 1000);
                o.start(); o.stop(ctx.currentTime + dur / 1000 + 0.02);
            } catch (e) {}
        }

        function flashear(color) {
            const el = document.getElementById('scanner-flash');
            if (! el) return;
            el.style.background = color;
            el.style.opacity = '0.5';
            setTimeout(() => { el.style.opacity = '0'; }, 300);
        }

        function feedback(tipo) {
            const sonido = document.getElementById('scanner-sonido')?.checked;
            switch (tipo) {
                case 'ok':
                    flashear('rgba(16,185,129,.55)');
                    if (sonido) { beep(880, 100); if (navigator.vibrate) navigator.vibrate(80); }
                    break;
                case 'warn':
                    flashear('rgba(245,158,11,.55)');
                    if (sonido) { beep(440, 120, 'square'); if (navigator.vibrate) navigator.vibrate([80, 60, 80]); }
                    break;
                case 'err':
                    flashear('rgba(239,68,68,.55)');
                    if (sonido) { beep(220, 250, 'square'); if (navigator.vibrate) navigator.vibrate(200); }
                    break;
            }
        }

        function pintarResultado(r) {
            const el = document.getElementById('scanner-resultado');
            const colores = { success: '#10b981', warning: '#f59e0b', danger: '#ef4444', info: '#3b82f6', gray: '#6b7280' };
            const color = colores[r.color] || '#6b7280';

            let acciones = '';
            if (r.accion === 'despachar') {
                acciones = `<button onclick="confirmarDespacho(${r.pedido_id})" style="margin-top:.75rem;padding:.75rem 1rem;background:#10b981;color:#fff;border:0;border-radius:.5rem;font-weight:700;min-height:44px;cursor:pointer;">✅ Confirmar despacho</button>`;
            } else if (r.accion === 'proponer_devolucion') {
                acciones = `<a href="/admin/dropi-devoluciones/registrar?guia=${encodeURIComponent(r.guia)}" style="display:inline-block;margin-top:.75rem;padding:.75rem 1rem;background:#3b82f6;color:#fff;border-radius:.5rem;font-weight:700;text-decoration:none;">→ Registrar devolución</a>`;
            }

            el.style.textAlign = 'left';
            el.style.color = 'inherit';
            el.style.display = 'block';
            el.innerHTML = `
                <div style="border-left:4px solid ${color};padding-left:1rem;">
                    <div style="font-weight:700;font-size:1.1rem;color:${color};">${r.titulo}</div>
                    <div style="font-family:ui-monospace,monospace;font-size:.95rem;margin-top:.25rem;">${r.guia}</div>
                    <div style="font-size:.9rem;color:#9ca3af;margin-top:.5rem;">${r.detalle}</div>
                    ${r.context ? `
                        <div style="margin-top:.75rem;padding:.5rem .75rem;background:rgba(156,163,175,.1);border-radius:.375rem;font-size:.85rem;">
                            <strong>${r.context.cliente ?? ''}</strong> · ${r.context.ciudad ?? ''}<br>
                            Estado actual: ${r.context.estado ?? ''}<br>
                            Monto: $${(r.context.monto ?? 0).toLocaleString('es-CO')}
                        </div>` : ''}
                    ${acciones}
                </div>
            `;
        }

        function agregarHistorial(guia, estado) {
            const lista = document.getElementById('scanner-hist-lista');
            const div = document.createElement('div');
            const colores = { ok: '#10b981', warn: '#f59e0b', err: '#ef4444' };
            div.style.cssText = `padding:.5rem .75rem;background:rgba(156,163,175,.08);border-radius:.375rem;border-left:3px solid ${colores[estado] ?? '#6b7280'};font-family:ui-monospace,monospace;`;
            div.textContent = `${new Date().toLocaleTimeString()} · ${guia}`;
            lista.prepend(div);
            while (lista.children.length > 8) lista.lastChild.remove();
        }

        const CSRF = document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || '';

        async function postJson(url, body) {
            const r = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json', 'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });
            return r.json();
        }

        async function procesarGuia(guia) {
            const ahora = Date.now();
            if (guia === ultimaGuia && (ahora - ultimaMs) < 3000) return;
            ultimaGuia = guia; ultimaMs = ahora;

            try {
                const r = await postJson('/dropi/escaner/analizar', { guia });
                const tipoFeedback = { success: 'ok', warning: 'warn', danger: 'err', info: 'ok', gray: 'warn' }[r.color] ?? 'ok';
                feedback(tipoFeedback);
                pintarResultado(r);
                agregarHistorial(guia, tipoFeedback);
            } catch (e) { feedback('err'); console.error(e); }
        }

        window.confirmarDespacho = async function (pedidoId) {
            // Capa de seguridad: desde dónde se despachó (ver Trazabilidad).
            const gps = await window.gbUbicacion();
            const r = await postJson('/dropi/escaner/despachar', { pedido_id: pedidoId, guia: ultimaGuia, gps });
            if (r.ok) {
                feedback('ok'); ultimaGuia = '';
                document.getElementById('scanner-resultado').innerHTML =
                    '<div style="text-align:center;color:#10b981;font-weight:700;padding:2rem;">✅ ' + r.guia + ' despachado</div>';
            } else {
                // Antes el rechazo no se mostraba: el botón parecía no hacer nada.
                feedback('err');
                const aviso = document.createElement('div');
                aviso.style.cssText = 'text-align:center;color:#ef4444;font-weight:700;padding:1rem;';
                aviso.textContent = '✖ ' + (r.mensaje || 'No se pudo despachar.');
                document.getElementById('scanner-resultado').prepend(aviso);
            }
        };

        window.scannerIniciar = async function () {
            if (! ('BarcodeDetector' in window)) {
                alert('Tu navegador no soporta BarcodeDetector nativo. Prueba en Chrome/Edge o Android.');
                return;
            }
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                document.getElementById('scanner-video').srcObject = stream;
                detector = new BarcodeDetector({ formats: ['code_128', 'ean_13', 'ean_8', 'qr_code', 'code_39'] });
                escaneando = true;
                document.getElementById('btn-iniciar').disabled = true;
                document.getElementById('btn-detener').disabled = false;
                document.getElementById('btn-detener').style.background = '#ef4444';
                loop();
            } catch (e) {
                alert('No se pudo acceder a la cámara: ' + e.message);
            }
        };

        window.scannerDetener = function () {
            escaneando = false;
            if (stream) stream.getTracks().forEach(t => t.stop());
            document.getElementById('btn-iniciar').disabled = false;
            document.getElementById('btn-detener').disabled = true;
            document.getElementById('btn-detener').style.background = '#6b7280';
        };

        async function loop() {
            const video = document.getElementById('scanner-video');
            while (escaneando) {
                try {
                    if (video.readyState === video.HAVE_ENOUGH_DATA) {
                        const codes = await detector.detect(video);
                        if (codes.length > 0) {
                            const guia = codes[0].rawValue.trim();
                            if (guia) await procesarGuia(guia);
                        }
                    }
                } catch (e) { /* frame malo, sigue */ }
                await new Promise(r => setTimeout(r, 400));
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const inp = document.getElementById('scanner-manual');
            if (inp) inp.addEventListener('keydown', e => {
                if (e.key === 'Enter' && inp.value.trim()) {
                    procesarGuia(inp.value.trim());
                    inp.select();
                }
            });
        });
    })();
    </script>
</x-filament-panels::page>
