<?php

namespace App\Filament\Company\Resources\Purchases\VendorResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Filament\Company\Resources\Purchases\VendorResource;
use App\Models\Common\Vendor;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Model;

class EditVendor extends EditRecord
{
    use HandlePageRedirect;

    protected static string $resource = VendorResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Vendor $vendor */
        $vendor = parent::handleRecordUpdate($record, $data);

        if (isset($data['contact'], $data['contact']['first_name'])) {
            $vendor->contact()->updateOrCreate(
                ['is_primary' => true],
                [
                    'first_name' => $data['contact']['first_name'],
                    'last_name' => $data['contact']['last_name'],
                    'email' => $data['contact']['email'],
                    'phones' => $data['contact']['phones'] ?? [],
                ]
            );
        }

        if (isset($data['address'], $data['address']['type'], $data['address']['address_line_1'])) {
            $vendor->address()->updateOrCreate(
                ['type' => $data['address']['type']],
                [
                    'address_line_1' => $data['address']['address_line_1'],
                    'address_line_2' => $data['address']['address_line_2'] ?? null,
                    'country_code' => $data['address']['country_code'] ?? null,
                    'state_id' => $data['address']['state_id'] ?? null,
                    'city' => $data['address']['city'] ?? null,
                    'postal_code' => $data['address']['postal_code'] ?? null,
                ]
            );
        }

        return $vendor;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::FiveExtraLarge;
    }
}
