<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $aviso->titulo }}</title>
</head>
<body style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#111827;line-height:1.55;background:#f3f4f6;margin:0;padding:20px;">
    <div style="max-width:600px;margin:0 auto;background:white;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);">

        {{-- Outlook no soporta gradientes: color sólido + bgcolor de respaldo. --}}
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="{{ $color }}" style="background:{{ $color }};">
            <tr>
                <td bgcolor="{{ $color }}" style="padding:22px 28px;background:{{ $color }};color:#ffffff;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;">
                    <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:.85;">GREAT BABY · ERP</div>
                    <div style="font-size:20px;font-weight:800;margin-top:6px;color:#ffffff;">{{ $aviso->titulo }}</div>
                </td>
            </tr>
        </table>

        <div style="padding:26px 28px;">
            @if($aviso->mensaje)
                <p style="margin:0 0 20px;white-space:pre-line;">{{ $aviso->mensaje }}</p>
            @endif

            @if($enlace)
                <p style="margin:0 0 24px;">
                    <a href="{{ $enlace }}"
                       style="display:inline-block;background:{{ $color }};color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:6px;font-weight:700;">
                        Abrir en el ERP
                    </a>
                </p>
            @endif

            <p style="margin:0;color:#6b7280;font-size:.85rem;">
                Este aviso también está en la campana del sistema.
                Si no te corresponde, pedile a Gerencia que te saque de la lista
                en Configuración → Reglas.
            </p>
        </div>

        <div style="padding:14px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:.75rem;">
            GREAT BABY S.A.S. · enviado automáticamente, no respondas a este correo.
        </div>
    </div>
</body>
</html>
