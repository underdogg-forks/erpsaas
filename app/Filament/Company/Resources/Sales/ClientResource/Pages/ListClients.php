<?php

namespace App\Filament\Company\Resources\Sales\ClientResource\Pages;

use App\Filament\Company\Resources\Sales\ClientResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return 'max-w-8xl';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
