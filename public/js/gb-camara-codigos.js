/*
 * Lector de códigos de barras con la cámara, para las estaciones de empaque
 * (la de la app y la del Panel Dropi). Es la lógica del antiguo «Escáner
 * cámara», llevada a donde se empaca: lo que lee la cámara entra por el mismo
 * camino que la pistola o el número escrito (20-nov-2026).
 *
 * Usa el lector nativo del navegador (BarcodeDetector: Chrome, Edge, Android).
 * Donde no existe —Safari en iPhone y iPad, Firefox— carga ZXing desde este
 * mismo servidor (public/js/vendor), sólo la primera vez que se abre la cámara:
 * la política de seguridad de producción no deja cargar scripts de un CDN.
 *
 * Uso:  window.gbCamaraCodigos.abrir({ alLeer: (codigo) => { ... } })
 */
(function () {
    if (window.gbCamaraCodigos) return;

    const FORMATOS = ['code_128', 'ean_13', 'ean_8', 'qr_code', 'code_39', 'upc_a', 'upc_e'];
    const ZXING_URL = '/js/vendor/zxing-0.21.3.min.js';
    // La cámara ve el mismo código varias veces por segundo mientras el
    // producto sigue enfrente. Solo cuenta otra vez si el código salió del
    // cuadro al menos este tiempo: así, dos unidades del mismo producto se
    // cuentan mostrando una, retirándola y mostrando la otra.
    const AUSENTE_MS = 1200;

    let estado = null;

    function cargarZXing() {
        if (window.ZXing) return Promise.resolve(window.ZXing);
        return new Promise((ok, mal) => {
            const s = document.createElement('script');
            s.src = ZXING_URL;
            s.onload = () => (window.ZXing ? ok(window.ZXing) : mal(new Error('No cargó el lector de códigos.')));
            s.onerror = () => mal(new Error('No cargó el lector de códigos.'));
            document.head.appendChild(s);
        });
    }

    function crearVentana() {
        const fondo = document.createElement('div');
        fondo.id = 'gb-camara-codigos';
        fondo.setAttribute('role', 'dialog');
        fondo.setAttribute('aria-label', 'Escanear con cámara');
        fondo.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;padding:1rem;';
        fondo.innerHTML = `
            <div style="background:#0f172a;border:1px solid rgba(245,158,11,.4);border-radius:1rem;padding:1rem;width:100%;max-width:520px;color:#e5e7eb;font-family:inherit;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.75rem;">
                    <strong style="color:#f59e0b;font-size:.85rem;letter-spacing:1px;text-transform:uppercase;">📷 Escanear con cámara</strong>
                    <button type="button" data-cerrar style="padding:.45rem .9rem;border:1px solid rgba(156,163,175,.4);border-radius:.5rem;background:transparent;color:#e5e7eb;cursor:pointer;">Cerrar</button>
                </div>
                <div style="position:relative;">
                    <video data-video playsinline muted style="width:100%;border-radius:.5rem;background:#000;aspect-ratio:4/3;object-fit:cover;"></video>
                    <div style="position:absolute;left:12%;right:12%;top:38%;bottom:38%;border:2px solid #f59e0b;border-radius:.5rem;pointer-events:none;"></div>
                </div>
                <div data-estado style="margin-top:.75rem;font-size:.9rem;color:#9ca3af;min-height:1.4em;">Abriendo la cámara…</div>
                <div style="margin-top:.25rem;font-size:.75rem;color:#6b7280;">Apunta al código de barras de la guía o del producto. La cámara sigue abierta para el siguiente; para contar otra unidad del mismo producto, retírala y vuelve a mostrarla.</div>
            </div>`;
        fondo.querySelector('[data-cerrar]').addEventListener('click', cerrar);
        fondo.addEventListener('click', (e) => { if (e.target === fondo) cerrar(); });
        document.addEventListener('keydown', alEscape);
        document.body.appendChild(fondo);
        return fondo;
    }

    function alEscape(e) { if (e.key === 'Escape') cerrar(); }

    function mostrar(texto, color) {
        const el = estado?.ventana.querySelector('[data-estado]');
        if (el) { el.textContent = texto; el.style.color = color || '#9ca3af'; }
    }

    function entregar(codigo) {
        codigo = String(codigo || '').trim();
        if (!codigo || !estado) return;
        const ahora = Date.now();
        const sigueEnfrente = codigo === estado.ultimo && ahora - estado.ultimoTs < AUSENTE_MS;
        estado.ultimo = codigo;
        estado.ultimoTs = ahora; // se renueva cada vez que se ve, aunque no cuente
        if (sigueEnfrente) return;
        if (navigator.vibrate) navigator.vibrate(60);
        mostrar('✓ Leído: ' + codigo, '#10b981');
        try { estado.alLeer(codigo); } catch (e) { console.error(e); }
    }

    async function conNativo(video) {
        const detector = new window.BarcodeDetector({ formats: FORMATOS });
        mostrar('Buscando un código…');
        const vuelta = async () => {
            if (!estado || estado.cerrado) return;
            try {
                if (video.readyState >= video.HAVE_ENOUGH_DATA) {
                    const codigos = await detector.detect(video);
                    if (codigos.length) entregar(codigos[0].rawValue);
                }
            } catch (e) { /* cuadro malo: sigue */ }
            estado.temporizador = setTimeout(vuelta, 250);
        };
        vuelta();
    }

    async function conZXing(video) {
        mostrar('Cargando el lector…');
        const ZXing = await cargarZXing();
        const pistas = new Map();
        pistas.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [
            ZXing.BarcodeFormat.CODE_128, ZXing.BarcodeFormat.EAN_13, ZXing.BarcodeFormat.EAN_8,
            ZXing.BarcodeFormat.QR_CODE, ZXing.BarcodeFormat.CODE_39, ZXing.BarcodeFormat.UPC_A, ZXing.BarcodeFormat.UPC_E,
        ]);
        pistas.set(ZXing.DecodeHintType.TRY_HARDER, true);
        const lector = new ZXing.MultiFormatReader();
        lector.setHints(pistas);
        mostrar('Buscando un código…');
        // Un cuadro del video cada 250 ms, como con el lector nativo. El bucle
        //   continuo de ZXing espera el evento de carga del video, que ya pasó
        //   porque la cámara se abre antes: así nunca leía nada.
        const lienzo = document.createElement('canvas');
        const vuelta = () => {
            if (!estado || estado.cerrado) return;
            try {
                if (video.readyState >= video.HAVE_ENOUGH_DATA && video.videoWidth) {
                    lienzo.width = video.videoWidth;
                    lienzo.height = video.videoHeight;
                    lienzo.getContext('2d', { willReadFrequently: true }).drawImage(video, 0, 0);
                    const luz = new ZXing.HTMLCanvasElementLuminanceSource(lienzo);
                    const resultado = lector.decode(new ZXing.BinaryBitmap(new ZXing.HybridBinarizer(luz)));
                    if (resultado) entregar(resultado.getText());
                }
            } catch (e) { /* sin código en este cuadro: sigue */ }
            estado.temporizador = setTimeout(vuelta, 250);
        };
        vuelta();
    }

    async function abrir(opciones) {
        if (estado) return;
        const alLeer = opciones && opciones.alLeer;
        if (typeof alLeer !== 'function') throw new Error('Falta alLeer');

        const ventana = crearVentana();
        estado = { ventana, alLeer, ultimo: '', ultimoTs: 0, cerrado: false };
        const video = ventana.querySelector('[data-video]');

        if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
            mostrar('Este navegador no deja usar la cámara. Usa la pistola o escribe el código.', '#ef4444');
            return;
        }
        try {
            estado.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
            if (!estado || estado.cerrado) { estado?.stream?.getTracks().forEach((t) => t.stop()); return; }
            video.srcObject = estado.stream;
            await video.play();
            if ('BarcodeDetector' in window) {
                await conNativo(video);
            } else {
                await conZXing(video);
            }
        } catch (e) {
            mostrar('No se pudo usar la cámara: ' + (e.message || e.name) + '. Usa la pistola o escribe el código.', '#ef4444');
        }
    }

    function cerrar() {
        if (!estado) return;
        estado.cerrado = true;
        clearTimeout(estado.temporizador);
        estado.stream?.getTracks().forEach((t) => t.stop());
        estado.ventana.remove();
        document.removeEventListener('keydown', alEscape);
        const despues = estado.alCerrar;
        estado = null;
        if (typeof despues === 'function') despues();
    }

    window.gbCamaraCodigos = {
        abrir: (opciones) => {
            abrir(opciones);
            if (estado) estado.alCerrar = opciones && opciones.alCerrar;
        },
        cerrar,
        abierta: () => !!estado,
    };
})();
