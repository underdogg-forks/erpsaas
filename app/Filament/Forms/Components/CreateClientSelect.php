<?php

namespace App\Filament\Forms\Components;

use App\Enums\Common\AddressType;
use App\Filament\Company\Resources\Sales\ClientResource;
use App\Models\Common\Client;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\DB;

class CreateClientSelect extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->searchable()
            ->preload()
            ->createOptionForm(fn (Form $form) => $this->createClientForm($form))
            ->createOptionAction(fn (Action $action) => $this->createClientAction($action));

        $this->relationship('client', 'name');

        $this->createOptionUsing(static function (array $data) {
            return DB::transaction(static function () use ($data) {
                /** @var Client $client */
                $client = Client::create([
                    'name' => $data['name'],
                    'website' => $data['website'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                if (isset($data['primaryContact'], $data['primaryContact']['first_name'])) {
                    $client->primaryContact()->create([
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
                            $client->secondaryContacts()->create([
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
                    $client->billingAddress()->create([
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
                        $billingAddress = $client->billingAddress;
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
                            $client->shippingAddress()->create($shippingAddress);
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
                        $client->shippingAddress()->create($shippingAddress);
                    }
                }

                return $client->getKey();
            });
        });
    }

    protected function createClientForm(Form $form): Form
    {
        return ClientResource::form($form);
    }

    protected function createClientAction(Action $action): Action
    {
        return $action
            ->label('Add client')
            ->slideOver()
            ->modalWidth(MaxWidth::ThreeExtraLarge);
    }
}
