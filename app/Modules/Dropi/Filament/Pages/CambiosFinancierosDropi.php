<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Models\DropiRemision;
use App\Modules\Dropi\Models\DropiSancion;
use App\Modules\Dropi\Models\DropiWalletMovimiento;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Models\Audit;

/**
 * «Cambios financieros» (Reportes › Auditoría): quién creó, cambió o borró un
 * movimiento de wallet, una sanción o una remisión de Dropi, con el antes y el
 * después de cada campo. Lee la tabla `audits` (owen-it/laravel-auditing).
 *
 * Ojo: `audit.console` está en false, así que sólo queda lo que hace una
 * persona desde la web; lo que trae la sincronización con Dropi no se audita
 * (eso está en «Wallet Dropi»). La página lo dice para que nadie lo lea mal.
 */
class CambiosFinancierosDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'Cambios financieros';

    protected static ?string $slug = 'cambios-financieros-dropi';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'dropi.pages.cambios-financieros-dropi';

    public const PASO = 100;

    /** Lo que se audita aquí, con su nombre para la pantalla. */
    public const MODELOS = [
        DropiWalletMovimiento::class => 'Wallet',
        DropiSancion::class => 'Sanción',
        DropiRemision::class => 'Remisión',
    ];

    public const EVENTOS = ['created' => 'Creó', 'updated' => 'Cambió', 'deleted' => 'Borró', 'restored' => 'Restauró'];

    public string $tipo = '';

    public string $evento = '';

    public string $desde = '';

    public string $hasta = '';

    public int $mostrar = self::PASO;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if (! $u) return false;
        // Se llega desde «Reportes Dropi»: quien ve ese tablero puede ver la
        //   auditoría de wallet, sanciones y remisiones. Antes era una lista de
        //   nombres de rol y un rol nuevo recibía 403 en el enlace.
        return \App\Auth\Permisos::puede($u, 'dropi_reportes');
    }

    public function updated(string $propiedad): void
    {
        if ($propiedad !== 'mostrar') {
            $this->mostrar = self::PASO;
        }
    }

    public function verMas(): void
    {
        $this->mostrar += self::PASO;
    }

    protected function consulta(): Builder
    {
        $tipos = $this->tipo !== '' && isset(self::MODELOS[$this->tipo])
            ? [$this->tipo]
            : array_keys(self::MODELOS);

        return Audit::query()
            ->whereIn('auditable_type', $tipos)
            ->when($this->evento !== '', fn ($q) => $q->where('event', $this->evento))
            ->when($this->desde !== '', fn ($q) => $q->where('created_at', '>=', $this->desde.' 00:00:00'))
            ->when($this->hasta !== '', fn ($q) => $q->where('created_at', '<=', $this->hasta.' 23:59:59'));
    }

    public function getTotal(): int
    {
        return $this->consulta()->count();
    }

    public function getFilas(): Collection
    {
        return $this->consulta()
            ->with('user')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($this->mostrar)
            ->get();
    }

    /**
     * Los campos que cambiaron, como [campo, antes, después]. En un alta sólo
     * hay «después»; en un borrado, sólo «antes».
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function cambios(Audit $a): array
    {
        $antes = (array) ($a->old_values ?? []);
        $despues = (array) ($a->new_values ?? []);
        $campos = array_values(array_unique([...array_keys($antes), ...array_keys($despues)]));

        return array_map(fn (string $c) => [
            $c,
            self::valor($antes[$c] ?? null),
            self::valor($despues[$c] ?? null),
        ], array_values(array_filter($campos, fn ($c) => $c !== 'id')));
    }

    private static function valor(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
