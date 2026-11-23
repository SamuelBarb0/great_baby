<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    {{-- El 405 (bootstrap/app.php): se abrió una dirección interna del sistema
         directamente, casi siempre por una página que quedó abierta desde antes
         de una actualización o por «atrás»/recargar. No se perdió nada que la
         persona no haya visto guardarse. --}}
    <title>La página se desactualizó · GREAT BABY ERP</title>
    <style>
        :root { color-scheme: light dark; }
        html,body { height:100%; margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f8fafc; color:#0f172a; }
        @media (prefers-color-scheme: dark) { html,body { background:#0f172a; color:#e2e8f0; } }
        .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card { max-width: 480px; width:100%; background: rgba(255,255,255,.9); border: 1px solid rgba(15,23,42,.08); border-radius: 16px; padding: 40px 28px; text-align: center; box-shadow: 0 10px 40px -20px rgba(15,23,42,.3); }
        @media (prefers-color-scheme: dark) { .card { background: rgba(30,41,59,.9); border-color: rgba(226,232,240,.08); } }
        .brand { font-weight: 800; letter-spacing: .12em; font-size: 12px; color:#f43f5e; margin-bottom: 24px; }
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
            <h1>La página se desactualizó</h1>
            <p>El navegador intentó abrir una dirección interna del sistema. Pasa cuando la pantalla quedó abierta desde antes de una actualización, o al usar «atrás» o recargar. Vuelve a la pantalla y repite el último paso; si lo que estabas guardando no aparece, hazlo de nuevo.</p>
            <a class="btn" href="{{ $volver }}">Volver a la pantalla</a>
        </div>
    </div>
</body>
</html>
