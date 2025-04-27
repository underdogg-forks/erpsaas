<?php

namespace App\Filament\Company\Clusters\Settings\Resources\CurrencyResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Filament\Company\Clusters\Settings\Resources\CurrencyResource;
use Filament\Resources\Pages\EditRecord;

class EditCurrency extends EditRecord
{
    use HandlePageRedirect;

    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
