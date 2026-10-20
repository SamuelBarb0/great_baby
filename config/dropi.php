<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver activo
    |--------------------------------------------------------------------------
    | mock: usa fixtures locales (dev y CI)
    | api : API oficial de integraciones (token de una tienda de la cuenta en
    |       Dropi → My Shop, en el encabezado `dropi-integration-key`). Lee los
    |       pedidos por ahí; wallet, etiquetas y cambio de estatus siguen por la
    |       sesión del driver web porque la API de integraciones no los expone.
    | web : usa la API interna de app.dropi.co con la sesión de una cuenta
    |       (se conecta desde Dropi → Conexión Dropi)
    */
    'driver' => env('DROPI_DRIVER', 'mock'),

    'api' => [
        'url' => env('DROPI_API_URL', 'https://api.dropi.co/integrations/'),
        'key' => env('DROPI_API_KEY', ''),
        'timeout' => (int) env('DROPI_API_TIMEOUT', 30),
    ],

    'web' => [
        'email' => env('DROPI_WEB_EMAIL', ''),
        'password' => env('DROPI_WEB_PASSWORD', ''),
        'white_brand_id' => (int) env('DROPI_WEB_BRAND_ID', 1),
        'bff_url' => env('DROPI_WEB_BFF_URL', 'https://api-v2.dropi.co/bff'),
        // API clásico: escrituras (actualizar estatus). Exige encabezados de navegador.
        'legacy_url' => env('DROPI_WEB_LEGACY_URL', 'https://api.dropi.co/api'),
        'timeout' => (int) env('DROPI_WEB_TIMEOUT', 20),
        // Enciende el sync programado (routes/console.php). Apagado por defecto.
        'sync_activo' => (bool) env('DROPI_WEB_SYNC', false),
        // Primer día que se importa de la wallet. Cada movimiento genera su
        // asiento contable, así que no se trae la historia anterior.
        'wallet_desde' => env('DROPI_WALLET_DESDE', '2026-09-01'),
    ],

    'mock' => [
        'fixtures_path' => storage_path('app/dropi-fixtures'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cortes — §5 diseño Dropi
    |--------------------------------------------------------------------------
    | Ventanas horarias para asignar automáticamente el corte a un pedido nuevo.
    | El primer corte cierra a las 14:00; el segundo a las 18:00.
    */
    'cortes' => [
        1 => ['cierra_a' => '14:00'],
        2 => ['cierra_a' => '18:00'],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (notificaciones al cliente al despachar)
    |--------------------------------------------------------------------------
    */
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'mock'),
        'token' => env('WHATSAPP_TOKEN', ''),
        'phone_id' => env('WHATSAPP_PHONE_ID', ''),
        'template_despacho' => env('WHATSAPP_TEMPLATE_DESPACHO', 'pedido_despachado_v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertas — §14, §16, §20
    |--------------------------------------------------------------------------
    */
    'alertas' => [
        'plazo_dropi_horas' => 72,           // §16 Dropi sanciona a 72h sin despachar
        'aviso_previo_horas' => 48,          // §16 aviso a 48h para tener 24h de margen
        'devolucion_no_confirmada_dias' => 7, // §13 alerta si Dropi marcó devolución hace >7 días
        'entrega_pendiente_dias' => 7,        // §14 empieza a tardar
        'entrega_critica_dias' => 10,         // §14 crítica
        'entrega_contactar_dias' => 15,       // §14 contactar transportadora
        'retiro_wallet_normal_dias' => 15,    // §20 ciclo normal
        'retiro_wallet_aviso_dias' => 20,     // §20 aviso suave
    ],

];
