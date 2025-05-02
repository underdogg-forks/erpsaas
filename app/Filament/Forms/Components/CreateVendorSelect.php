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
                $vendor = Vendor::createWithRelations($data);

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
            ->label('Create vendor')
            ->slideOver()
            ->modalWidth(MaxWidth::ThreeExtraLarge)
            ->modalHeading('Create a new vendor');
    }
}
