<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\Pages;

use App\Filament\Company\Resources\Accounting\BudgetResource;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\MaxWidth;

class ViewBudget extends ViewRecord
{
    protected static string $resource = BudgetResource::class;

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return '8xl';
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }

    protected function getAllRelationManagers(): array
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
