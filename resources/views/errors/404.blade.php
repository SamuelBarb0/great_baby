<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    @php
        /*
         * El 404 cuenta DOS historias distintas y hay que saber cuál.
         *
         * Esta pantalla nació para los links de factura que se le mandan al
         * cliente: ahí «expiró o fue revocado · pedile al vendedor un nuevo
         * link» es exactamente lo que hay que decir. Pero la misma pantalla
         * sale cuando alguien escribe mal una dirección, y entonces manda a la
         * persona a pedir un link que no necesita y que no le va a servir.
         *
         * Los `abort(404, '...')` del ERP traen SU mensaje, escrito en
         * castellano («Documento no disponible.», «El archivo ya no está.»).
         * Una ruta que no existe trae el de Symfony, que empieza con «The
         * route» —y es en inglés, así que tampoco se le puede mostrar a nadie—.
         * Esa es la diferencia que se usa acá.
         */
        $mensaje = trim((string) ($exception?->getMessage() ?? ''));
        $esRutaInexistente = $mensaje === '' || str_starts_with($mensaje, 'The route');

        $titulo = $esRutaInexistente ? 'Esta dirección no existe' : 'Documento no disponible';
        $detalle = $esRutaInexistente
            ? 'La dirección que escribiste no corresponde a ninguna pantalla del sistema. Revisá el enlace, o volvé al inicio y buscá desde el menú.'
            // Lo que escribió el propio ERP dice más que cualquier texto
            // genérico: «El archivo ya no está» no es lo mismo que «expiró».
            : $mensaje.' Si esperabas encontrar una factura, pídele al vendedor un nuevo link.';
    @endphp
    <title>{{ $titulo }} · GREAT BABY ERP</title>
    <style>
        :root { color-scheme: light dark; }
        html,body { height:100%; margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f8fafc; color:#0f172a; }
        @media (prefers-color-scheme: dark) { html,body { background:#0f172a; color:#e2e8f0; } }
        .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card {
            max-width: 480px; width:100%;
            background: rgba(255,255,255,.9);
            border: 1px solid rgba(15,23,42,.08);
            border-radius: 16px;
            padding: 40px 28px;
            text-align: center;
            box-shadow: 0 10px 40px -20px rgba(15,23,42,.3);
        }
        @media (prefers-color-scheme: dark) { .card { background: rgba(30,41,59,.9); border-color: rgba(226,232,240,.08); } }
        .brand { font-weight: 800; letter-spacing: .12em; font-size: 12px; color:#f43f5e; margin-bottom: 24px; }
        .code { font-size: 72px; font-weight: 800; line-height: 1; margin: 0 0 8px; background: linear-gradient(135deg,#f43f5e,#ec4899); -webkit-background-clip: text; background-clip:text; color: transparent; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { color:#64748b; font-size: 15px; margin: 0 0 24px; line-height: 1.55; }
        @media (prefers-color-scheme: dark) { p { color: #94a3b8; } }
        a.btn { display:inline-block; padding: 10px 20px; border-radius:10px; background:#f43f5e; color:#fff; text-decoration:none; font-weight:600; font-size: 14px; }
        a.btn:hover { background:#e11d48; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="brand">GREAT BABY ERP</div>
            <p class="code">404</p>
            <h1>{{ $titulo }}</h1>
            <p>{{ $detalle }}</p>
            <a class="btn" href="{{ url('/') }}">Volver al inicio</a>
        </div>
    </div>
</body>
</html>
