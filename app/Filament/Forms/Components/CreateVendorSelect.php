<?php

namespace App\Filament\Forms\Components;

use App\Filament\Company\Resources\Purchases\VendorResource;
use App\Models\Common\Vendor;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\DB;

class CreateVendorSelect extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->searchable()
            ->preload()
            ->createOptionForm(fn (Form $form) => $this->createVendorForm($form))
            ->createOptionAction(fn (Action $action) => $this->createVendorAction($action));

        $this->relationship('vendor', 'name');

        $this->createOptionUsing(static function (array $data) {
            return DB::transaction(static function () use ($data) {
                /** @var Vendor $vendor */
                $vendor = Vendor::create([
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'currency_code' => $data['currency_code'] ?? null,
                    'contractor_type' => $data['contractor_type'] ?? null,
                    'ssn' => $data['ssn'] ?? null,
                    'ein' => $data['ein'] ?? null,
                    'account_number' => $data['account_number'] ?? null,
                    'website' => $data['website'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                if (isset($data['contact'], $data['contact']['first_name'])) {
                    $vendor->contact()->create([
                        'is_primary' => true,
                        'first_name' => $data['contact']['first_name'],
                        'last_name' => $data['contact']['last_name'],
                        'email' => $data['contact']['email'],
                        'phones' => $data['contact']['phones'] ?? [],
                    ]);
                }

                if (isset($data['address'], $data['address']['type'], $data['address']['address_line_1'])) {
                    $vendor->address()->create([
                        'type' => $data['address']['type'],
                        'address_line_1' => $data['address']['address_line_1'],
                        'address_line_2' => $data['address']['address_line_2'] ?? null,
                        'country_code' => $data['address']['country_code'] ?? null,
                        'state_id' => $data['address']['state_id'] ?? null,
                        'city' => $data['address']['city'] ?? null,
                        'postal_code' => $data['address']['postal_code'] ?? null,
                    ]);
                }

                return $vendor->getKey();
            });
        });
    }

    protected function createVendorForm(Form $form): Form
    {
        return VendorResource::form($form);
    }

    protected function createVendorAction(Action $action): Action
    {
        return $action
            ->label('Add vendor')
            ->slideOver()
            ->modalWidth(MaxWidth::ThreeExtraLarge);
    }
}
