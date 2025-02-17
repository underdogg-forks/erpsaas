<?php

namespace App\Filament\Company\Clusters\Settings\Resources\DocumentDefaultResource\Pages;

use App\Filament\Company\Clusters\Settings\Resources\DocumentDefaultResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocumentDefault extends EditRecord
{
    protected static string $resource = DocumentDefaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
