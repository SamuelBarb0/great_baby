<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Exceptions\DropiSesionNoDisponible;
use App\Modules\Dropi\Models\DropiWebSesion;
use App\Modules\Dropi\Services\DropiWebAuth;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Conecta la sesión del driver `web` de Dropi: login + código que Dropi manda al
 * correo de la cuenta, o, si Dropi bloquea el login, pegar el token del navegador.
 */
class ConexionDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Conexión Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Dropi';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Conexión con Dropi';

    protected string $view = 'dropi.pages.conexion-dropi';

    public string $codigo = '';

    public string $tokenManual = '';

    public string $cuentaEmail = '';

    public string $cuentaPassword = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public function mount(): void
    {
        $this->cuentaEmail = app(DropiWebAuth::class)->cuenta()['email'];
    }

    /** 'crm', 'env' o null: de dónde sale la cuenta con la que entra el ERP. */
    public function getOrigenCuentaProperty(): ?string
    {
        return app(DropiWebAuth::class)->origenCuenta();
    }

    public function guardarCuenta(): void
    {
        $this->validate([
            'cuentaEmail' => ['required', 'email'],
            'cuentaPassword' => ['nullable', 'string', 'max:255'],
        ], [], ['cuentaEmail' => 'correo', 'cuentaPassword' => 'contraseña']);

        try {
            app(DropiWebAuth::class)->cambiarCuenta($this->cuentaEmail, $this->cuentaPassword, auth()->id());
        } catch (\InvalidArgumentException $e) {
            $this->addError('cuentaPassword', $e->getMessage());
            return;
        }

        $this->cuentaPassword = '';
        $this->cuentaEmail = app(DropiWebAuth::class)->cuenta()['email'];
        Notification::make()
            ->title('Cuenta de Dropi guardada.')
            ->body('Pulsa «Conectar» para entrar con ella. Si Dropi pide código, llegará al correo de esa cuenta.')
            ->success()
            ->send();
    }

    public function usarCuentaDelEnv(): void
    {
        $auth = app(DropiWebAuth::class);
        $auth->usarCuentaDelEnv();
        $this->cuentaPassword = '';
        $this->cuentaEmail = $auth->cuenta()['email'];
        Notification::make()->title('Se volvió a la cuenta del .env.')->body('Pulsa «Conectar» para entrar con ella.')->success()->send();
    }

    public function getSesionProperty(): ?DropiWebSesion
    {
        try {
            return app(DropiWebAuth::class)->sesion();
        } catch (DropiSesionNoDisponible) {
            return null;
        }
    }

    public function conectar(): void
    {
        $this->ejecutar(fn (DropiWebAuth $auth) => $auth->iniciar(auth()->id()));
    }

    public function confirmarCodigo(): void
    {
        if (trim($this->codigo) === '') {
            Notification::make()->title('Escribe el código que llegó al correo.')->warning()->send();
            return;
        }
        $this->ejecutar(fn (DropiWebAuth $auth) => $auth->confirmarCodigo($this->codigo, auth()->id()));
        $this->codigo = '';
    }

    public function guardarToken(): void
    {
        if (trim($this->tokenManual) === '') {
            Notification::make()->title('Pega el token primero.')->warning()->send();
            return;
        }
        $this->ejecutar(fn (DropiWebAuth $auth) => $auth->usarTokenManual($this->tokenManual, auth()->id()));
        $this->tokenManual = '';
    }

    public function desconectar(): void
    {
        app(DropiWebAuth::class)->desconectar();
        Notification::make()->title('Sesión con Dropi cerrada.')->success()->send();
    }

    protected function ejecutar(callable $paso): void
    {
        try {
            $estado = $paso(app(DropiWebAuth::class));
        } catch (\Throwable $e) {
            Notification::make()->title('No se pudo hablar con Dropi')->body($e->getMessage())->danger()->send();
            return;
        }

        $sesion = $this->sesion;
        match (true) {
            $estado === DropiWebSesion::CONECTADO => Notification::make()->title('Conectado con Dropi.')->success()->send(),
            $estado === DropiWebSesion::ESPERANDO_CODIGO && ! $sesion?->ultimo_error => Notification::make()->title('Dropi mandó un código al correo de la cuenta.')->body('Escríbelo abajo para terminar de conectar.')->info()->send(),
            default => Notification::make()->title('Dropi no dejó conectar')->body($sesion?->ultimo_error)->danger()->send(),
        };
    }
}
