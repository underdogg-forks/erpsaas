<?php

namespace App\Filament\Company\Resources\Purchases\BillResource\Pages;

use App\Filament\Company\Resources\Purchases\BillResource;
use App\Filament\Company\Resources\Purchases\VendorResource;
use App\Models\Accounting\Bill;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\IconPosition;

class ViewBill extends ViewRecord
{
    protected static string $resource = BillResource::class;

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Edit bill')
                ->outlined(),
            Actions\ActionGroup::make([
                Actions\ActionGroup::make([
                    Bill::getReplicateAction(),
                ])->dropdown(false),
                Actions\DeleteAction::make(),
            ])
                ->label('Actions')
                ->button()
                ->outlined()
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Bill Details')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('bill_number')
                            ->label('Invoice #'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('vendor.name')
                            ->label('Vendor')
                            ->url(static fn (Bill $record) => VendorResource::getUrl('view', ['record' => $record->vendor_id]))
                            ->link(),
                        TextEntry::make('total')
                            ->label('Total')
                            ->currency(fn (Bill $record) => $record->currency_code),
                        TextEntry::make('amount_due')
                            ->label('Amount due')
                            ->currency(fn (Bill $record) => $record->currency_code),
                        TextEntry::make('date')
                            ->label('Date')
                            ->date(),
                        TextEntry::make('due_date')
                            ->label('Due')
                            ->asRelativeDay(),
                        TextEntry::make('paid_at')
                            ->label('Paid at')
                            ->placeholder('Not Paid')
                            ->date(),
                    ]),
            ]);
    }

    protected function getAllRelationManagers(): array
    {
        return [
            BillResource\RelationManagers\PaymentsRelationManager::class,
        ];
    }
}
