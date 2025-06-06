<?php

namespace App\Filament\Company\Resources\Common\OfferingResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Filament\Company\Resources\Common\OfferingResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOffering extends CreateRecord
{
    use HandlePageRedirect;

    protected static string $resource = OfferingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $attributes = array_flip($data['attributes'] ?? []);

        $data['sellable']    = isset($attributes['Sellable']);
        $data['purchasable'] = isset($attributes['Purchasable']);

        unset($data['attributes']);

        return parent::handleRecordCreation($data); // TODO: Change the autogenerated stub
    }
}
