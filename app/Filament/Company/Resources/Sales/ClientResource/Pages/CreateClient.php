<?php

namespace App\Filament\Company\Resources\Sales\ClientResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Enums\Common\AddressType;
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
        /** @var Client $record */
        $record = parent::handleRecordCreation($data);

        if (isset($data['primaryContact'], $data['primaryContact']['first_name'])) {
            $record->primaryContact()->create([
                'is_primary' => true,
                'first_name' => $data['primaryContact']['first_name'],
                'last_name' => $data['primaryContact']['last_name'],
                'email' => $data['primaryContact']['email'],
                'phones' => $data['primaryContact']['phones'] ?? [],
            ]);
        }

        if (isset($data['secondaryContacts'])) {
            foreach ($data['secondaryContacts'] as $contactData) {
                if (isset($contactData['first_name'])) {
                    $record->secondaryContacts()->create([
                        'is_primary' => false,
                        'first_name' => $contactData['first_name'],
                        'last_name' => $contactData['last_name'],
                        'email' => $contactData['email'],
                        'phones' => $contactData['phones'] ?? [],
                    ]);
                }
            }
        }

        if (isset($data['billingAddress'], $data['billingAddress']['address_line_1'])) {
            $record->billingAddress()->create([
                'type' => AddressType::Billing,
                'address_line_1' => $data['billingAddress']['address_line_1'],
                'address_line_2' => $data['billingAddress']['address_line_2'] ?? null,
                'country_code' => $data['billingAddress']['country_code'] ?? null,
                'state_id' => $data['billingAddress']['state_id'] ?? null,
                'city' => $data['billingAddress']['city'] ?? null,
                'postal_code' => $data['billingAddress']['postal_code'] ?? null,
            ]);
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
                    $record->shippingAddress()->create($shippingAddress);
                }
            } elseif (isset($shippingData['address_line_1'])) {
                $shippingAddress = [
                    ...$shippingAddress,
                    'address_line_1' => $shippingData['address_line_1'],
                    'address_line_2' => $shippingData['address_line_2'] ?? null,
                    'country_code' => $shippingData['country_code'] ?? null,
                    'state_id' => $shippingData['state_id'] ?? null,
                    'city' => $shippingData['city'] ?? null,
                    'postal_code' => $shippingData['postal_code'] ?? null,
                ];
                $record->shippingAddress()->create($shippingAddress);
            }
        }

        return $record;
    }
}
