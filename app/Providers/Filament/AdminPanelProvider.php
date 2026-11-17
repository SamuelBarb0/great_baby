<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use App\Modules\Dropi\Filament\Pages\DashboardDropi;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /** La lista (carga masiva), crear (`/add`) y editar (`/edit?id=`) cuelgan de aquí. */
    private const PREFIJO_PRODUCTOS_DROPI = 'https://app.dropi.co/dashboard/products';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            // El login de Filament está deshabilitado · usamos UN SOLO login,
            //   el de Vue en /app/login. Cuando un no-autenticado toca /admin/*,
            //   el middleware redirige a la ruta nombrada `login`, que apunta
            //   a /app/login (ver routes/web.php:459). La sesión 'web' se
            //   comparte entre Filament y Vue, así que entrar por /app vale
            //   para los dos.
            ->brandName('GREAT BABY · ERP')
            ->colors([
                'primary' => Color::Amber,
            ])
            // ──────────────────────────────────────────────────────────────
            //  /admin = SOLO DROPI.
            //
            //  El ERP se maneja entero en Vue (/app). Filament había quedado
            //  como un segundo sistema paralelo: 40 pantallas que duplicaban
            //  lo que ya existe en Vue, cada una con su propia autorización y
            //  su propio criterio. Eso es lo que hacía que un mismo módulo se
            //  comportara distinto según por dónde entraras.
            //
            //  Acá se dejan de descubrir los módulos que NO son Dropi. Los
            //  archivos siguen en disco a propósito: sin repositorio git, un
            //  borrado no tiene vuelta atrás, así que primero se saca de
            //  circulación y se borra cuando la clienta confirme.
            // ──────────────────────────────────────────────────────────────
            ->discoverResources(in: app_path('Modules/Dropi/Filament/Resources'), for: 'App\Modules\Dropi\Filament\Resources')
            // Organizado el 6-nov por lo que la persona va a hacer: despachar,
            // productos, plata y configuración (antes todo colgaba de «Dropi»).
            ->navigationGroups([
                'Operación',
                'Productos',
                'Dinero',
                'Compras',
                'Contabilidad',
                'Configuración',
            ])
            // «Importar productos» (Excel al catálogo del ERP) salió del panel
            //   el 17-nov: en un panel sólo de Dropi, cargar productos es
            //   cargarlos EN Dropi. El Excel al ERP sigue en
            //   /app/dropi/productos/importar. Este enlace abre la lista de
            //   productos de Dropi, donde está su «Carga masiva», y el script
            //   de abajo lo abre en una ventana al lado del panel.
            ->navigationItems([
                NavigationItem::make('Carga masiva en Dropi')
                    ->url(self::PREFIJO_PRODUCTOS_DROPI, shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-up-tray')
                    ->group('Productos')
                    ->sort(5)
                    ->visible(fn (): bool => \App\Auth\Permisos::puede(auth()->user(), 'dropi_mis_productos')),
            ])
            // «Compras» y «Contabilidad» son de la línea Dropi, separadas de las
            //   otras empresas (7-nov): ver Dropi\Filament\Support\PaginaTablaDropi.
            // «Crear producto en Dropi» vive como botón dentro de «Mis productos
            // en Dropi» (7-nov); este script lo abre en una ventana aparte.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => self::scriptVentanaDropi(),
            )
            ->discoverPages(in: app_path('Modules/Dropi/Filament/Pages'), for: 'App\Modules\Dropi\Filament\Pages')
            ->discoverWidgets(in: app_path('Modules/Dropi/Filament/Widgets'), for: 'App\Modules\Dropi\Filament\Widgets')
            ->pages([
                DashboardDropi::class,
            ])
            ->widgets([
                // Los widgets Dropi se registran directamente en DashboardDropi::getWidgets().
                AccountWidget::class,
            ])
            ->defaultAvatarProvider(\App\Support\InicialesAvatarProvider::class)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => Blade::render('<livewire:bell-notificaciones />'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('<livewire:command-palette />'),
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => '<button type="button" onclick="window.dispatchEvent(new KeyboardEvent(\'keydown\',{key:\'k\',ctrlKey:true,metaKey:true}))" style="padding:.4rem .7rem;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:.4rem;color:#f59e0b;cursor:pointer;font-size:.8rem;display:inline-flex;align-items:center;gap:.4rem;">🔍 <kbd style="padding:.05rem .3rem;background:rgba(0,0,0,.35);border-radius:.2rem;font-family:ui-monospace,monospace;font-size:.7rem;">⌘K</kbd></button>',
            )
            // Interruptor de canal Empresa <-> Dropi en la barra superior.
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                function (): string {
                    $esDropi = session('gb_canal') === 'dropi';
                    $color = $esDropi ? '#0f8fa6' : '#6b7280';
                    $bg = $esDropi ? 'rgba(15,143,166,.15)' : 'rgba(107,114,128,.12)';
                    $label = $esDropi ? 'DROPI' : 'Empresa';
                    $url = url('/set-canal/'.($esDropi ? 'empresa' : 'dropi'));

                    return '<a href="'.$url.'" title="Cambiar entre operación Empresa y Dropi" '
                        .'style="display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .75rem;border-radius:.5rem;'
                        .'background:'.$bg.';border:1px solid '.$color.';color:'.$color.';font-size:.8rem;font-weight:700;text-decoration:none;white-space:nowrap;">'
                        .'<span style="font-size:.6rem;">●</span> Modo: '.$label.'</a>';
                },
            )
            // Distintivo visual global cuando se está en modo Dropi.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                function (): string {
                    if (session('gb_canal') !== 'dropi') {
                        return '';
                    }

                    return '<div style="position:fixed;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#0f8fa6,#22d3ee);z-index:99999;"></div>'
                        .'<div style="position:fixed;bottom:16px;right:16px;z-index:99999;background:#0f8fa6;color:#fff;padding:.45rem .9rem;border-radius:999px;font-size:.75rem;font-weight:800;letter-spacing:.03em;box-shadow:0 6px 18px rgba(0,0,0,.35);display:flex;align-items:center;gap:.4rem;">📦 MODO DROPI</div>';
                },
            );
    }

    /**
     * Abre «Crear producto en Dropi» y los «Editar en Dropi» de «Mis productos
     * en Dropi» en una ventana a la derecha de la pantalla en vez de una
     * pestaña: así se ve el panel y Dropi a la vez. Atrapa todo enlace al
     * formulario de productos de Dropi.
     *
     * Dropi no deja embeberse: responde `X-Frame-Options: SAMEORIGIN` y el
     * navegador muestra el iframe bloqueado (probado el 6-nov). La ventana usa
     * la sesión de Dropi que la persona ya tiene. Sin JavaScript abre pestaña.
     */
    private static function scriptVentanaDropi(): string
    {
        $prefijo = json_encode(self::PREFIJO_PRODUCTOS_DROPI);

        return <<<HTML
<script>
document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href^=' + JSON.stringify({$prefijo}) + ']') : null;
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    var ancho = Math.min(1200, Math.round(screen.availWidth * 0.6));
    var w = window.open(a.href, 'gb_dropi_crear', 'popup,width=' + ancho + ',height=' + Math.round(screen.availHeight * 0.9) + ',left=' + (screen.availWidth - ancho) + ',top=0');
    if (w) { w.focus(); } else { window.open(a.href, '_blank'); }
}, true);
</script>
HTML;
    }
}
