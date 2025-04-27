<?php

namespace App\Filament\Company\Clusters\Settings\Resources\CurrencyResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Filament\Company\Clusters\Settings\Resources\CurrencyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCurrency extends CreateRecord
{
    use HandlePageRedirect;

    protected static string $resource = CurrencyResource::class;
}
