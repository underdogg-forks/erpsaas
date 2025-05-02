<?php

namespace App\Filament\Forms\Components;

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
                $client = Client::createWithRelations($data);

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
            ->label('Create client')
            ->slideOver()
            ->modalWidth(MaxWidth::ThreeExtraLarge)
            ->modalHeading('Create a new client');
    }
}
