<?php

namespace App\Filament\Company\Resources\Sales\ClientResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Filament\Company\Resources\Sales\ClientResource;
use App\Models\Common\Client;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Model;

class CreateClient extends CreateRecord
{
    use HandlePageRedirect;

    protected static string $resource = ClientResource::class;

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::FiveExtraLarge;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return Client::createWithRelations($data);
    }
}
