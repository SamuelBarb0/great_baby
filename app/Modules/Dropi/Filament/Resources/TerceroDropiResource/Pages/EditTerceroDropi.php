<?php

namespace App\Modules\Dropi\Filament\Resources\TerceroDropiResource\Pages;

use App\Modules\Dropi\Filament\Resources\TerceroDropiResource;
use Filament\Resources\Pages\EditRecord;

class EditTerceroDropi extends EditRecord
{
    protected static string $resource = TerceroDropiResource::class;

    protected function getRedirectUrl(): string
    {
        return TerceroDropiResource::getUrl('index');
    }
}
