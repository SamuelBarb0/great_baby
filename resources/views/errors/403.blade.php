{{--
    PANTALLA DE 403.

    No existía, y eso convertía cada desajuste de permisos en «se me rompió el
    sistema»: el usuario salía del ERP y caía en la página pelada de Laravel,
    sin menú, sin saber qué pasó y sin manera de volver.

    Dice tres cosas que la página por defecto no decía:
      · que no es un error del sistema sino una puerta cerrada,
      · a quién pedirle el acceso,
      · y con qué cuenta está entrando, porque la mitad de estos casos son
        «me quedé con la sesión de otro usuario abierta».

    Si el `abort()` trae mensaje propio —varios lo traen y explican bien el
    caso— se muestra ése en vez del genérico.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sin acceso · GREAT BABY ERP</title>
    <style>
        :root { color-scheme: light dark; }
        html,body { height:100%; margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f8fafc; color:#0f172a; }
        @media (prefers-color-scheme: dark) { html,body { background:#0f172a; color:#e2e8f0; } }
        .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card {
            max-width: 520px; width:100%;
            background: rgba(255,255,255,.9);
            border: 1px solid rgba(15,23,42,.08);
            border-radius: 16px;
            padding: 40px 28px;
            text-align: center;
            box-shadow: 0 10px 40px -20px rgba(15,23,42,.3);
        }
        @media (prefers-color-scheme: dark) { .card { background: rgba(30,41,59,.9); border-color: rgba(226,232,240,.08); } }
        .brand { font-weight: 800; letter-spacing: .12em; font-size: 12px; color:#b45309; margin-bottom: 24px; }
        .code { font-size: 72px; font-weight: 800; line-height: 1; margin: 0 0 8px; background: linear-gradient(135deg,#b45309,#f59e0b); -webkit-background-clip: text; background-clip:text; color: transparent; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { color:#64748b; font-size: 15px; margin: 0 0 20px; line-height: 1.55; }
        @media (prefers-color-scheme: dark) { p { color: #94a3b8; } }
        .quien { font-size: 13px; color:#94a3b8; margin: 0 0 24px; }
        .acciones { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
        a.btn { display:inline-block; padding: 10px 20px; border-radius:10px; background:#b45309; color:#fff; text-decoration:none; font-weight:600; font-size: 14px; }
        a.btn:hover { background:#92400e; }
        a.btn.sec { background: transparent; color:#b45309; border:1px solid rgba(180,83,9,.35); }
        a.btn.sec:hover { background: rgba(180,83,9,.08); }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            @php
                /*
                 * Quién está adentro puede NO ser un usuario del ERP.
                 *
                 * `@auth` resuelve contra el guard activo, y en el Portal B2B
                 * ese guard devuelve un `Contacto`, que no tiene roles. La
                 * página pedía `getRoleNames()` a secas y reventaba con
                 * «Call to undefined method Contacto::getRoleNames()»: el
                 * cliente que intentaba abrir una factura ajena —cosa que el
                 * sistema hace bien en negarle— recibía un error 500 en vez
                 * del «esto no es tuyo». La puerta estaba bien; la explicación
                 * era la que se caía.
                 */
                $quien = auth()->user() ?: auth('cliente')->user();
                $esDelErp = $quien && method_exists($quien, 'getRoleNames');
                $roles = $esDelErp ? $quien->getRoleNames() : collect();
                $nombre = $quien?->name ?? $quien?->nombre_completo;
            @endphp

            <div class="brand">{{ $esDelErp ? 'GREAT BABY ERP' : 'PORTAL GREAT BABY' }}</div>
            <p class="code">403</p>
            <h1>Esta parte no es tuya</h1>

            <p>
                @if (! empty($exception) && $exception->getMessage())
                    {{ $exception->getMessage() }}
                @elseif ($esDelErp)
                    No es un error: esta pantalla pertenece a otra área. Si la necesitás para tu
                    trabajo, pedile a Gerencia que te habilite el acceso desde Roles y permisos.
                @else
                    {{-- Al cliente no se le habla de roles ni de áreas internas. --}}
                    Este documento es de otra cuenta. Si creés que deberías verlo,
                    escribinos por el WhatsApp comercial y lo revisamos.
                @endif
            </p>

            {{--
                Con qué cuenta está entrando. La mitad de estos casos son una
                sesión de otro usuario que quedó abierta, y saberlo ahorra el
                rodeo entero.
            --}}

            @if ($nombre)
                <p class="quien">
                    Estás dentro como <strong>{{ $nombre }}</strong>
                    @if ($roles->isNotEmpty())
                        · {{ $roles->implode(', ') }}
                    @endif
                </p>
            @endif

            <div class="acciones">
                {{-- Cada quien vuelve a SU casa, no siempre al ERP. --}}
                <a class="btn" href="{{ url($esDelErp ? '/app' : '/portal') }}">Volver a lo mío</a>
                @if ($esDelErp)
                    <a class="btn sec" href="{{ url('/app/mi-espacio') }}">Mi espacio</a>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
