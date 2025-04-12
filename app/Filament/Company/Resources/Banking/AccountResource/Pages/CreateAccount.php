<?php

namespace App\Filament\Company\Resources\Banking\AccountResource\Pages;

use App\Concerns\RedirectToListPage;
use App\Filament\Company\Resources\Banking\AccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccount extends CreateRecord
{
    use RedirectToListPage;

    protected static string $resource = AccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['enabled'] = (bool) ($data['enabled'] ?? false);

        return $data;
    }
}
