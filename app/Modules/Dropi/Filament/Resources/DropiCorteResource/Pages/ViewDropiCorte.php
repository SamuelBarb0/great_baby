<?php

namespace App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages;

use App\Modules\Dropi\Filament\Resources\DropiCorteResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * La pantalla de un corte (26-nov-2026): cómo van sus pedidos y, debajo, cada
 * pedido con su vista previa (PedidosRelationManager).
 */
class ViewDropiCorte extends ViewRecord
{
    protected static string $resource = DropiCorteResource::class;

    public function getTitle(): string
    {
        return 'Corte ' . $this->getRecord()->etiqueta();
    }

    protected function getHeaderActions(): array
    {
        return DropiCorteResource::accionesDelCorte();
    }
}
