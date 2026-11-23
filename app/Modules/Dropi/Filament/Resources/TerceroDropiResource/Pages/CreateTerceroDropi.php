<?php

namespace App\Modules\Dropi\Filament\Resources\TerceroDropiResource\Pages;

use App\Modules\Dropi\Actions\RegistrarTerceroDropi;
use App\Modules\Dropi\Filament\Resources\TerceroDropiResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Crea por RegistrarTerceroDropi: un NIT que ya existe se comparte, no se duplica. */
class CreateTerceroDropi extends CreateRecord
{
    protected static string $resource = TerceroDropiResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $r = RegistrarTerceroDropi::run(['nombre' => $data['nombre_completo']] + $data);

        if ($r['ya_existia']) {
            Notification::make()->title('Ese documento ya existía')
                ->body('Se agregó a Dropi la ficha que ya había, sin duplicarla.')->info()->send();
        }

        return $r['contacto'];
    }

    protected function getRedirectUrl(): string
    {
        return TerceroDropiResource::getUrl('index');
    }
}
