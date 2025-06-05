<?php

namespace App\Filament\Company\Resources\Banking\AccountResource\Pages;

use App\Filament\Company\Resources\Banking\AccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

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
