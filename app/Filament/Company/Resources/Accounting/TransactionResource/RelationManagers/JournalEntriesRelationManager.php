<?php

namespace App\Filament\Company\Resources\Accounting\TransactionResource\RelationManagers;

use App\Utilities\Currency\CurrencyAccessor;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

class JournalEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'journalEntries';

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Type'),
                Tables\Columns\TextColumn::make('account.name')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('account.category')
                    ->label('Category')
                    ->badge(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->weight(FontWeight::SemiBold)
                    ->sortable()
                    ->currency(CurrencyAccessor::getDefaultCurrency()),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ]);
    }
}
