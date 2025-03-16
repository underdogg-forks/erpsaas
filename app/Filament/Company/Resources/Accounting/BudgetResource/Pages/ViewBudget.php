<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\Pages;

use App\Filament\Company\Resources\Accounting\BudgetResource;
use Filament\Actions;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewBudget extends ViewRecord
{
    protected static string $resource = BudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            BudgetResource\RelationManagers\BudgetItemsRelationManager::class,
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([]);
    }
}
