<?php

namespace App\Filament\Company\Clusters\Settings\Resources;

use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\AdjustmentScope;
use App\Enums\Accounting\AdjustmentStatus;
use App\Enums\Accounting\AdjustmentType;
use App\Filament\Company\Clusters\Settings;
use App\Filament\Company\Clusters\Settings\Resources\AdjustmentResource\Pages;
use App\Models\Accounting\Adjustment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AdjustmentResource extends Resource
{
    protected static ?string $model = Adjustment::class;

    protected static ?string $cluster = Settings::class;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('General')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->autofocus()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('description')
                            ->label('Description'),
                    ]),
                Forms\Components\Section::make('Configuration')
                    ->schema([
                        Forms\Components\Select::make('category')
                            ->localizeLabel()
                            ->options(AdjustmentCategory::class)
                            ->default(AdjustmentCategory::Tax)
                            ->live()
                            ->required(),
                        Forms\Components\Select::make('type')
                            ->localizeLabel()
                            ->options(AdjustmentType::class)
                            ->default(AdjustmentType::Sales)
                            ->live()
                            ->required(),
                        Forms\Components\Checkbox::make('recoverable')
                            ->label('Recoverable')
                            ->default(false)
                            ->helperText('When enabled, tax is tracked separately as claimable from the government. Non-recoverable taxes are treated as part of the expense.')
                            ->visible(fn (Forms\Get $get) => AdjustmentCategory::parse($get('category'))->isTax() && AdjustmentType::parse($get('type'))->isPurchase()),
                    ])
                    ->columns()
                    ->visibleOn('create'),
                Forms\Components\Section::make('Adjustment Details')
                    ->schema([
                        Forms\Components\Select::make('computation')
                            ->localizeLabel()
                            ->options(AdjustmentComputation::class)
                            ->default(AdjustmentComputation::Percentage)
                            ->live()
                            ->required(),
                        Forms\Components\TextInput::make('rate')
                            ->localizeLabel()
                            ->rate(static fn (Forms\Get $get) => $get('computation'))
                            ->required(),
                        Forms\Components\Select::make('scope')
                            ->localizeLabel()
                            ->options(AdjustmentScope::class),
                    ])
                    ->columns(),
                Forms\Components\Section::make('Dates')
                    ->schema([
                        Forms\Components\DateTimePicker::make('start_date'),
                        Forms\Components\DateTimePicker::make('end_date')
                            ->after('start_date'),
                    ])
                    ->columns()
                    ->visible(fn (Forms\Get $get) => AdjustmentCategory::parse($get('category'))->isDiscount()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('category')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rate')
                    ->localizeLabel()
                    ->rate(static fn (Adjustment $record) => $record->computation->value)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('paused_until')
                    ->label('Auto-Resume Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('start_date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('end_date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->native(false)
                    ->default('unarchived')
                    ->options(
                        collect(AdjustmentStatus::cases())
                            ->mapWithKeys(fn (AdjustmentStatus $status) => [$status->value => $status->getLabel()])
                            ->merge([
                                'unarchived' => 'Unarchived',
                            ])
                            ->toArray()
                    )
                    ->indicateUsing(function (Tables\Filters\SelectFilter $filter, array $state) {
                        if (blank($state['value'] ?? null)) {
                            return [];
                        }

                        $label = collect($filter->getOptions())
                            ->mapWithKeys(fn (string | array $label, string $value): array => is_array($label) ? $label : [$value => $label])
                            ->get($state['value']);

                        if (blank($label)) {
                            return [];
                        }

                        $indicator = $filter->getIndicator();

                        if (! $indicator instanceof Indicator) {
                            if ($state['value'] === 'unarchived') {
                                $indicator = $label;
                            } else {
                                $indicator = Indicator::make("{$indicator}: {$label}");
                            }
                        }

                        return [$indicator];
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        if ($data['value'] !== 'unarchived') {
                            return $query->where('status', $data['value']);
                        } else {
                            return $query->where('status', '!=', AdjustmentStatus::Archived->value);
                        }
                    }),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Category')
                    ->native(false)
                    ->options(AdjustmentCategory::class),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->native(false)
                    ->options(AdjustmentType::class),
                Tables\Filters\SelectFilter::make('computation')
                    ->label('Computation')
                    ->native(false)
                    ->options(AdjustmentComputation::class),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('pause')
                        ->label('Pause')
                        ->icon('heroicon-m-pause')
                        ->form([
                            Forms\Components\DateTimePicker::make('paused_until')
                                ->label('Auto-resume date')
                                ->helperText('When should this adjustment automatically resume? Leave empty to keep paused indefinitely.')
                                ->after('now'),
                            Forms\Components\Textarea::make('status_reason')
                                ->label('Reason for pausing')
                                ->maxLength(255),
                        ])
                        ->databaseTransaction()
                        ->successNotificationTitle('Adjustment paused')
                        ->failureNotificationTitle('Failed to pause adjustment')
                        ->visible(fn (Adjustment $record) => $record->canBePaused())
                        ->action(function (Adjustment $record, array $data, Tables\Actions\Action $action) {
                            $pausedUntil = $data['paused_until'] ?? null;
                            $reason = $data['status_reason'] ?? null;
                            $record->pause($reason, $pausedUntil);

                            $action->success();
                        }),
                    Tables\Actions\Action::make('resume')
                        ->label('Resume')
                        ->icon('heroicon-m-play')
                        ->requiresConfirmation()
                        ->databaseTransaction()
                        ->successNotificationTitle('Adjustment resumed')
                        ->failureNotificationTitle('Failed to resume adjustment')
                        ->visible(fn (Adjustment $record) => $record->canBeResumed())
                        ->action(function (Adjustment $record, Tables\Actions\Action $action) {
                            $record->resume();

                            $action->success();
                        }),
                    Tables\Actions\Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-m-archive-box')
                        ->color('danger')
                        ->form([
                            Forms\Components\Textarea::make('status_reason')
                                ->label('Reason for archiving')
                                ->maxLength(255),
                        ])
                        ->databaseTransaction()
                        ->successNotificationTitle('Adjustment archived')
                        ->failureNotificationTitle('Failed to archive adjustment')
                        ->visible(fn (Adjustment $record) => $record->canBeArchived())
                        ->action(function (Adjustment $record, array $data, Tables\Actions\Action $action) {
                            $reason = $data['status_reason'] ?? null;
                            $record->archive($reason);

                            $action->success();
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('pause')
                        ->label('Pause')
                        ->icon('heroicon-m-pause')
                        ->form([
                            Forms\Components\DateTimePicker::make('paused_until')
                                ->label('Auto-resume date')
                                ->helperText('When should these adjustments automatically resume? Leave empty to keep paused indefinitely.')
                                ->after('now'),
                            Forms\Components\Textarea::make('status_reason')
                                ->label('Reason for pausing')
                                ->maxLength(255),
                        ])
                        ->databaseTransaction()
                        ->successNotificationTitle('Adjustments paused')
                        ->failureNotificationTitle('Failed to pause adjustments')
                        ->beforeFormFilled(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $isInvalid = $records->contains(fn (Adjustment $record) => ! $record->canBePaused());

                            if ($isInvalid) {
                                Notification::make()
                                    ->title('Pause failed')
                                    ->body('Only adjustments that are currently active can be paused. Please adjust your selection and try again.')
                                    ->persistent()
                                    ->danger()
                                    ->send();

                                $action->cancel(true);
                            }
                        })
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data, Tables\Actions\BulkAction $action) {
                            $pausedUntil = $data['paused_until'] ?? null;
                            $reason = $data['status_reason'] ?? null;

                            $records->each(function (Adjustment $record) use ($reason, $pausedUntil) {
                                $record->pause($reason, $pausedUntil);
                            });

                            $action->success();
                        }),
                    Tables\Actions\BulkAction::make('resume')
                        ->label('Resume')
                        ->icon('heroicon-m-play')
                        ->databaseTransaction()
                        ->requiresConfirmation()
                        ->successNotificationTitle('Adjustments resumed')
                        ->failureNotificationTitle('Failed to resume adjustments')
                        ->before(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $isInvalid = $records->contains(fn (Adjustment $record) => ! $record->canBeResumed());

                            if ($isInvalid) {
                                Notification::make()
                                    ->title('Resume failed')
                                    ->body('Only adjustments that are currently paused can be resumed. Please adjust your selection and try again.')
                                    ->persistent()
                                    ->danger()
                                    ->send();

                                $action->cancel(true);
                            }
                        })
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $records->each(function (Adjustment $record) {
                                $record->resume();
                            });

                            $action->success();
                        }),
                    Tables\Actions\BulkAction::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-m-archive-box')
                        ->color('danger')
                        ->form([
                            Forms\Components\Textarea::make('status_reason')
                                ->label('Reason for archiving')
                                ->maxLength(255),
                        ])
                        ->databaseTransaction()
                        ->successNotificationTitle('Adjustments archived')
                        ->failureNotificationTitle('Failed to archive adjustments')
                        ->beforeFormFilled(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $isInvalid = $records->contains(fn (Adjustment $record) => ! $record->canBeArchived());

                            if ($isInvalid) {
                                Notification::make()
                                    ->title('Archive failed')
                                    ->body('Only adjustments that are currently active or paused can be archived. Please adjust your selection and try again.')
                                    ->persistent()
                                    ->danger()
                                    ->send();

                                $action->cancel(true);
                            }
                        })
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data, Tables\Actions\BulkAction $action) {
                            $reason = $data['status_reason'] ?? null;

                            $records->each(function (Adjustment $record) use ($reason) {
                                $record->archive($reason);
                            });

                            $action->success();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdjustments::route('/'),
            'create' => Pages\CreateAdjustment::route('/create'),
            'edit' => Pages\EditAdjustment::route('/{record}/edit'),
        ];
    }
}
