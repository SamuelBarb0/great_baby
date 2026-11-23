<?php

namespace App\Modules\Dropi\Filament\Resources\TerceroDropiResource\Pages;

use App\Modules\Dropi\Filament\Resources\TerceroDropiResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTercerosDropi extends ListRecords
{
    protected static string $resource = TerceroDropiResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo cliente o proveedor')];
    }
}
