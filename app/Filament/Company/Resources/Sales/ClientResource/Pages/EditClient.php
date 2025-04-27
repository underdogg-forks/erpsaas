<?php

namespace App\Filament\Company\Resources\Sales\ClientResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Enums\Common\AddressType;
use App\Filament\Company\Resources\Sales\ClientResource;
use App\Models\Common\Client;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    use HandlePageRedirect;

    protected static string $resource = ClientResource::class;

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

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Client $record */
        $record = parent::handleRecordUpdate($record, $data);

        if (isset($data['primaryContact'], $data['primaryContact']['first_name'])) {
            $record->primaryContact()->updateOrCreate(
                ['is_primary' => true],
                [
                    'first_name' => $data['primaryContact']['first_name'],
                    'last_name' => $data['primaryContact']['last_name'],
                    'email' => $data['primaryContact']['email'],
                    'phones' => $data['primaryContact']['phones'] ?? [],
                ]
            );
        }

        if (isset($data['secondaryContacts'])) {
            // Delete removed contacts
            $existingIds = collect($data['secondaryContacts'])->pluck('id')->filter()->all();
            $record->secondaryContacts()->whereNotIn('id', $existingIds)->delete();

            // Update or create contacts
            foreach ($data['secondaryContacts'] as $contactData) {
                if (isset($contactData['first_name'])) {
                    $record->secondaryContacts()->updateOrCreate(
                        ['id' => $contactData['id'] ?? null],
                        [
                            'is_primary' => false,
                            'first_name' => $contactData['first_name'],
                            'last_name' => $contactData['last_name'],
                            'email' => $contactData['email'],
                            'phones' => $contactData['phones'] ?? [],
                        ]
                    );
                }
            }
        }

        if (isset($data['billingAddress'], $data['billingAddress']['address_line_1'])) {
            $record->billingAddress()->updateOrCreate(
                ['type' => AddressType::Billing],
                [
                    'address_line_1' => $data['billingAddress']['address_line_1'],
                    'address_line_2' => $data['billingAddress']['address_line_2'] ?? null,
                    'country_code' => $data['billingAddress']['country_code'] ?? null,
                    'state_id' => $data['billingAddress']['state_id'] ?? null,
                    'city' => $data['billingAddress']['city'] ?? null,
                    'postal_code' => $data['billingAddress']['postal_code'] ?? null,
                ]
            );
        }

        if (isset($data['shippingAddress'])) {
            $shippingData = $data['shippingAddress'];
            $shippingAddress = [
                'type' => AddressType::Shipping,
                'recipient' => $shippingData['recipient'] ?? null,
                'phone' => $shippingData['phone'] ?? null,
                'notes' => $shippingData['notes'] ?? null,
            ];

            if ($shippingData['same_as_billing'] ?? false) {
                $billingAddress = $record->billingAddress;
                if ($billingAddress) {
                    $shippingAddress = [
                        ...$shippingAddress,
                        'parent_address_id' => $billingAddress->id,
                        'address_line_1' => $billingAddress->address_line_1,
                        'address_line_2' => $billingAddress->address_line_2,
                        'country_code' => $billingAddress->country_code,
                        'state_id' => $billingAddress->state_id,
                        'city' => $billingAddress->city,
                        'postal_code' => $billingAddress->postal_code,
                    ];
                }
            } elseif (isset($shippingData['address_line_1'])) {
                $shippingAddress = [
                    ...$shippingAddress,
                    'parent_address_id' => null,
                    'address_line_1' => $shippingData['address_line_1'],
                    'address_line_2' => $shippingData['address_line_2'] ?? null,
                    'country_code' => $shippingData['country_code'] ?? null,
                    'state_id' => $shippingData['state_id'] ?? null,
                    'city' => $shippingData['city'] ?? null,
                    'postal_code' => $shippingData['postal_code'] ?? null,
                ];
            }

            $record->shippingAddress()->updateOrCreate(
                ['type' => AddressType::Shipping],
                $shippingAddress
            );
        }

        return $record;
    }
}
