<?php

namespace App\Modules\Dropi\Filament\Resources\DropiDevolucionResource\Pages;

use App\Modules\Dropi\Filament\Resources\DropiDevolucionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListDropiDevoluciones extends ListRecords
{
    protected static string $resource = DropiDevolucionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registrar')
                ->label('Registrar devolución')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->url(fn () => DropiDevolucionResource::getUrl('registrar'))
                ->visible(fn () => DropiDevolucionResource::canCreate()),
        ];
    }
}
