<?php

namespace App\Filament\Company\Clusters\Settings\Resources\AdjustmentResource\Pages;

use App\Concerns\RedirectToListPage;
use App\Filament\Company\Clusters\Settings\Resources\AdjustmentResource;
use Filament\Resources\Pages\EditRecord;

class EditAdjustment extends EditRecord
{
    use RedirectToListPage;

    protected static string $resource = AdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
