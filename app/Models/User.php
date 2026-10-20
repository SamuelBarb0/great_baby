<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        // Fix demo A2 · delega en la matriz única de autorización.
        return \App\Auth\Permisos::puedeAccederPanel($this);
    }

    /**
     * Fix demo A2 · atajo cómodo: `$user->puede('facturas')`.
     */
    public function puede(string $seccion): bool
    {
        return \App\Auth\Permisos::puede($this, $seccion);
    }

    public function esAracely(): bool
    {
        return $this->hasAnyRole(['Aracely', 'Gerencia']);
    }

    /**
     * CONT-C8 · Gate "root" para acciones que extraen el libro contable
     *   completo (export Excel/CSV de reportes). Solo Aracely/Gerencia.
     *   El Contador ve los reportes en pantalla pero NO los exfiltra: así
     *   evitamos que un intermediario descargue el PUC+saldos y los lleve
     *   fuera de la empresa sin dejar rastro en SIIGO.
     */
    public function esRoot(): bool
    {
        return $this->hasAnyRole(['Aracely', 'Gerencia']);
    }

    /**
     * LOG · "Equipo bodega" = quien debe poder operar inventario, recepción,
     *   traslados, kardex, ubicaciones y la Cola de alistamiento.
     *   Incluye a Aracely/Gerencia (que ven todo) + el AdminBodega de cada
     *   sede. Es el helper que reemplaza al patrón viejo `esAracely()` como
     *   gate en los controllers de bodega — así Jorge y los demás admin de
     *   bodega pueden efectivamente trabajar sin pedir 403 por todos lados.
     */
    public function esEquipoBodega(): bool
    {
        return $this->tieneAcceso('bodega');
    }

    /**
     * Puente entre los helpers de perfil y la pantalla de Roles.
     *
     * Estos helpers preguntaban `hasAnyRole([...])` con la lista escrita en
     * código, así que crear un rol nuevo y marcarle accesos no servía de nada:
     * el controller seguía preguntando por el nombre del rol.
     *
     * Delega en `Permisos::puede()`, que usa la tabla de permisos cuando está
     * sembrada y la matriz en código cuando no. No se hace OR con la lista de
     * roles vieja a propósito: si se sumara, desmarcar una casilla no quitaría
     * el acceso y la pantalla sería decorativa.
     */
    protected function tieneAcceso(string $seccion): bool
    {
        return \App\Auth\Permisos::puede($this, $seccion);
    }

    /**
     * Re-audit M5 SEG-C2 · helper unificado para acceso a Contabilidad.
     *
     * Antes: los controllers pedían `hasAnyRole(['Contador','Gerente'])` pero el
     * seeder solo crea `Aracely,Alistador,ServicioCliente,Gerencia` → Contador
     * y Gerente nunca daban true en runtime; los Filament Pages pedían
     * `esAracely()` (Aracely/Gerencia) → matriz de autorización desalineada
     * entre pantallas del mismo módulo. Este helper es LA autoridad.
     */
    public function esContable(): bool
    {
        return $this->tieneAcceso('contabilidad');
    }

    public function esAlistador(): bool
    {
        return $this->tieneAcceso('vista_alistador');
    }

    /**
     * UBIC-8 · Admin bodega · solo ve/opera su(s) ubicación(es) asignada(s).
     * Es un alistador "senior" que también puede crear traslados/conteos pero
     * solo con origen/destino = su propia bodega.
     */
    public function esAdminBodega(): bool
    {
        // Se mapea a `bodega`, no a `ubicaciones`: ese otro permiso lo tiene
        // también Gerente, y usarlo acá le abría las pantallas de administración
        // de bodega que nunca le correspondieron (+9 accesos en el barrido).
        return $this->tieneAcceso('bodega');
    }

    public function esSac(): bool
    {
        return $this->tieneAcceso('servicio_cliente');
    }

    /**
     * LOG-J9 · Rol Marketing · permisos reducidos.
     *   Puede pedir prestado a bodega (traslado de salida → MKT) y devolverlo
     *   (traslado de regreso). NO ve/edita precios ni stock comercial.
     */
    public function esMarketing(): bool
    {
        return $this->tieneAcceso('marketing');
    }

    /**
     * Rol Facturador · revisa pedidos aprobados + alistados y emite la
     *   factura en SIIGO con decisión manual de send_dian / send_mail.
     *   Separado de Contador por segregación de funciones.
     */
    public function esFacturador(): bool
    {
        return $this->tieneAcceso('facturacion');
    }

    /**
     * Pantalla donde cada rol empieza su jornada.
     *
     * Es la ÚNICA fuente de verdad del aterrizaje: la usan el login y el
     * ruteo de `/app`. Antes cada uno decidía por su lado y el fallback de
     * ambos era la Estación de Empaque, así que Gerente, Contador, Vendedor,
     * SAC, Marketing y Facturador entraban al sistema directo a un 403.
     */
    public function rutaInicial(): string
    {
        return match (true) {
            // Aracely y Gerencia entran por "Cosas del día", su lista real de
            // pendientes. `/app` es la Torre de Control, que mira la operación
            // de empaque y despacho: útil, pero no es con lo que se arranca la
            // mañana, y mostraba "Ventas hoy $0" como primera impresión.
            // Gerente no pasa por ahí: esa pantalla pide perfil de bodega.
            $this->esAracely()         => '/app/cosas-del-dia',
            $this->hasRole('Gerente')  => '/app',
            $this->esFacturador()      => '/app/facturacion/bandeja',
            $this->hasRole('Vendedor') => '/app/vendedor',
            $this->hasRole('Contador') => '/app/cartera',
            $this->esSac()             => '/app/garantias',
            $this->esMarketing()       => '/app/marketing',
            // Gestión Humana y los líderes de área arrancan por las solicitudes
            //   de personal: para ella es lo que espera su decisión, y para él
            //   es lo único suyo acá. Sin esta línea caían en el `default`, que
            //   es «Cosas del día» y pide perfil de bodega — o sea, entraban al
            //   sistema directo a un 403, que es exactamente lo que este método
            //   existe para evitar.
            // El colaborador entra a lo suyo y a nada más. Va ANTES que el
            //   resto porque es el rol más acotado: si alguien fuera las dos
            //   cosas, manda su trabajo, no su espacio personal.
            $this->tieneAcceso('portal_colaborador') && ! $this->tieneAcceso('rrhh_requisiciones')
                => '/app/mi-espacio',
            $this->tieneAcceso('rrhh_requisiciones') => '/app/rrhh/requisiciones',
            $this->esAdminBodega() || $this->esAlistador() || $this->hasRole('Despachador') => '/app/logistica/cola-jorge',
            default                    => '/app/cosas-del-dia',
        };
    }

    /**
     * Re-audit M3 λ (SEG-C2) · alcance de bodegas para un Alistador.
     *
     * Se lee de `setting('inventario.alistador_bodegas.<user_id>', [])` que
     * Aracely/Gerencia mantienen desde el panel de Reglas de Negocio.
     * Si el user tiene meta directa `bodegas_asignadas` (JSON) también se
     * respeta. Vacío = SIN acceso (fail-closed) para no permitir escalación
     * silenciosa cuando el operador aún no fue configurado.
     *
     * Aracely/Gerencia NO deben pasar por aquí: usar `esAracely()` primero.
     */
    public function bodegasAsignadasIds(): array
    {
        if (! empty($this->getAttribute('bodegas_asignadas'))) {
            $raw = $this->getAttribute('bodegas_asignadas');
            $arr = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
            return array_values(array_map('intval', $arr));
        }
        // UBIC-8 · AdminBodega hereda sus ubicaciones de responsable_user_id
        // en inventario_ubicaciones.
        //
        // A cargo de una bodega = puede operar TODAS las ubicaciones que
        // viven dentro de ella (pasillos, estantes, niveles).
        //
        // Antes se comparaba contra la ubicación exacta y la tabla era plana,
        // así que a Jorge lo dejaban responsable de «Bodega Principal Bogotá»
        // mientras los conteos y traslados ocurren en los racks, que eran
        // filas sueltas sin relación con esa bodega: podía operar 1 de 64
        // ubicaciones y todo lo real le daba 403. Desde que existe `bodega_id`
        // la pertenencia es explícita y el permiso la sigue.
        if ($this->esAdminBodega()) {
            try {
                $suyas = \DB::table('inventario_ubicaciones')
                    ->where('responsable_user_id', $this->id)
                    ->where('activa', true)
                    ->pluck('id')->map(fn ($x) => (int) $x)->all();

                if (! empty($suyas)) {
                    $dentro = \DB::table('inventario_ubicaciones')
                        ->where('activa', true)
                        ->whereIn('bodega_id', $suyas)
                        ->pluck('id')->map(fn ($x) => (int) $x)->all();

                    return array_values(array_unique(array_merge($suyas, $dentro)));
                }
            } catch (\Throwable) {}
        }
        if (function_exists('setting')) {
            $arr = (array) setting("inventario.alistador_bodegas.{$this->id}", []);
            return array_values(array_map('intval', $arr));
        }
        return [];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
