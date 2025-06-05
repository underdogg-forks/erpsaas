<?php

namespace App\Filament\Company\Resources\Sales\EstimateResource\Pages;

use App\Enums\Accounting\DocumentType;
use App\Filament\Company\Resources\Sales\ClientResource;
use App\Filament\Company\Resources\Sales\EstimateResource;
use App\Filament\Infolists\Components\BannerEntry;
use App\Filament\Infolists\Components\DocumentPreview;
use App\Models\Accounting\Estimate;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\IconPosition;
use Illuminate\Support\HtmlString;

class ViewEstimate extends ViewRecord
{
    protected static string $resource = EstimateResource::class;

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                BannerEntry::make('inactiveAdjustments')
                    ->label('Inactive adjustments')
                    ->warning()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn (Estimate $record) => $record->hasInactiveAdjustments() && $record->canBeApproved())
                    ->columnSpanFull()
                    ->description(function (Estimate $record) {
                        $inactiveAdjustments = collect();

                        foreach ($record->lineItems as $lineItem) {
                            foreach ($lineItem->adjustments as $adjustment) {
                                if ($adjustment->isInactive() && $inactiveAdjustments->doesntContain($adjustment->name)) {
                                    $inactiveAdjustments->push($adjustment->name);
                                }
                            }
                        }

                        $adjustmentsList = $inactiveAdjustments->map(static function ($name) {
                            return "<span class='font-medium'>{$name}</span>";
                        })->join(', ');

                        $output = "<p class='text-sm'>This estimate contains inactive adjustments that need to be addressed before approval: {$adjustmentsList}</p>";

                        return new HtmlString($output);
                    }),
                Section::make('Estimate Details')
                    ->columns(4)
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                TextEntry::make('estimate_number')
                                    ->label('Estimate #'),
                                TextEntry::make('status')
                                    ->badge(),
                                TextEntry::make('client.name')
                                    ->label('Client')
                                    ->url(static fn (Estimate $record) => $record->client_id ? ClientResource::getUrl('view', ['record' => $record->client_id]) : null)
                                    ->link(),
                                TextEntry::make('expiration_date')
                                    ->label('Expiration date')
                                    ->asRelativeDay(),
                                TextEntry::make('approved_at')
                                    ->label('Approved at')
                                    ->placeholder('Not Approved')
                                    ->date(),
                                TextEntry::make('last_sent_at')
                                    ->label('Last sent')
                                    ->placeholder('Never')
                                    ->date(),
                                TextEntry::make('accepted_at')
                                    ->label('Accepted at')
                                    ->placeholder('Not Accepted')
                                    ->date(),
                            ])->columnSpan(1),
                        DocumentPreview::make()
                            ->type(DocumentType::Estimate),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Edit estimate')
                ->outlined(),
            Actions\ActionGroup::make([
                Actions\ActionGroup::make([
                    Estimate::getApproveDraftAction(),
                    Estimate::getMarkAsSentAction(),
                    Estimate::getMarkAsAcceptedAction(),
                    Estimate::getMarkAsDeclinedAction(),
                    Estimate::getPrintDocumentAction(),
                    Estimate::getReplicateAction(),
                    Estimate::getConvertToInvoiceAction(),
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
}
