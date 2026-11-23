<?php

namespace App\Modules\Dropi\Filament\Resources\DropiCorteResource\Pages;

use App\Modules\Dropi\Filament\Resources\DropiCorteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDropiCorte extends EditRecord
{
    protected static string $resource = DropiCorteResource::class;

    protected function getHeaderActions(): array
    {
        return [DropiCorteResource::accionPedidosPdf(), DeleteAction::make()];
    }
}
