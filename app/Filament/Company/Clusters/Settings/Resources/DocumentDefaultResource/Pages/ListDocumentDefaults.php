<?php

namespace App\Filament\Company\Clusters\Settings\Resources\DocumentDefaultResource\Pages;

use App\Filament\Company\Clusters\Settings\Resources\DocumentDefaultResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListDocumentDefaults extends ListRecords
{
    protected static string $resource = DocumentDefaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::ScreenTwoExtraLarge;
    }
}
