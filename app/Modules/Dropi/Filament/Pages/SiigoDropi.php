<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Siigo\Models\SiigoConfig;
use App\Modules\Siigo\Models\SiigoDocumentType;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * «SIIGO de Dropi»: con qué resolución salen a SIIGO las facturas de Dropi.
 *
 * Decidido el 7-nov: Great Baby, los puntos de venta y Dropi son la misma
 * empresa (un NIT, una cuenta de SIIGO) y SIIGO separa la contabilidad por la
 * resolución DIAN. Las credenciales son las de Great Baby y siguen en /app/siigo;
 * aquí sólo se elige la resolución de Dropi. Vacía = las facturas de Dropi se
 * quedan en el ERP (nunca salen con la resolución de Great Baby).
 */
class SiigoDropi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'SIIGO de Dropi';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'SIIGO de Dropi';

    protected static ?string $slug = 'siigo-dropi';

    protected string $view = 'dropi.pages.siigo-dropi';

    /** Document Type ID de la resolución de Dropi, como texto para el input. */
    public string $resolucion = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->esAracely() ?? false;
    }

    public function mount(): void
    {
        $this->resolucion = (string) (SiigoConfig::current()->tipo_documento_dropi_id ?? '');
    }

    public function getConfigProperty(): SiigoConfig
    {
        return SiigoConfig::current();
    }

    /** Resoluciones de factura de venta que SIIGO tiene, si ya se sincronizaron. */
    public function getResolucionesProperty(): Collection
    {
        return SiigoDocumentType::query()
            ->where('type', 'FV')->where('active', true)
            ->orderBy('name')
            ->get(['siigo_id', 'code', 'name']);
    }

    public function guardar(): void
    {
        $this->validate(
            ['resolucion' => ['nullable', 'integer', 'min:1']],
            [],
            ['resolucion' => 'resolución'],
        );

        $config = SiigoConfig::current();
        $id = $this->resolucion === '' ? null : (int) $this->resolucion;

        // Repetir la de Great Baby anularía la separación sin que nadie lo note.
        if ($id !== null && $id === (int) $config->tipo_documento_id) {
            $this->addError('resolucion', 'Esa es la resolución de Great Baby: Dropi necesita la suya.');

            return;
        }

        $config->forceFill(['tipo_documento_dropi_id' => $id])->save();

        Notification::make()
            ->title($id ? 'Resolución de Dropi guardada' : 'Resolución de Dropi quitada')
            ->body($id
                ? 'Las facturas de Dropi saldrán a SIIGO con esta resolución.'
                : 'Las facturas de Dropi se quedarán en el ERP hasta que se elija una.')
            ->success()
            ->send();
    }
}
