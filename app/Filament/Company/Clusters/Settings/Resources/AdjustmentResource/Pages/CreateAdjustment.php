<?php

namespace App\Filament\Company\Clusters\Settings\Resources\AdjustmentResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Filament\Company\Clusters\Settings\Resources\AdjustmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdjustment extends CreateRecord
{
    use HandlePageRedirect;

    protected static string $resource = AdjustmentResource::class;
}
