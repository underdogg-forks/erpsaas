<?php

namespace App\Filament\Forms\Components;

use App\Filament\Company\Resources\Sales\ClientResource;
use App\Models\Common\Address;
use App\Models\Common\Client;
use App\Models\Common\Contact;
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
                $client = Client::create([
                    'name' => $data['name'],
                    'website' => $data['website'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                // Create primary contact
                $primaryContact = $client->contacts()->create([
                    'first_name' => $data['primary_contact']['first_name'],
                    'last_name' => $data['primary_contact']['last_name'],
                    'email' => $data['primary_contact']['email'],
                    'is_primary' => true,
                ]);

                // Add phone number
                $primaryContact->phones()->create([
                    'type' => 'primary',
                    'number' => $data['primary_contact']['phones'][0]['number'],
                ]);

                // Create billing address
                $client->addresses()->create([
                    'type' => 'billing',
                    ...$data['billing_address'],
                ]);

                // Create shipping address
                $client->addresses()->create([
                    'type' => 'shipping',
                    'recipient' => $data['shipping_address']['recipient'],
                    'phone' => $data['shipping_address']['phone'],
                    ...$data['shipping_address'],
                ]);

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
