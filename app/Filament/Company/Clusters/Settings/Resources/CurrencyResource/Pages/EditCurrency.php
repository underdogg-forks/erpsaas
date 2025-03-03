<?php

namespace App\Filament\Company\Clusters\Settings\Resources\CurrencyResource\Pages;

use App\Concerns\RedirectToListPage;
use App\Filament\Company\Clusters\Settings\Resources\CurrencyResource;
use Filament\Resources\Pages\EditRecord;

class EditCurrency extends EditRecord
{
    use RedirectToListPage;

    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
