<?php

namespace App\Filament\Forms\Components;

use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\AdjustmentScope;
use App\Enums\Accounting\AdjustmentType;
use App\Models\Accounting\Adjustment;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CreateAdjustmentSelect extends Select
{
    protected ?AdjustmentCategory $category = null;

    protected ?AdjustmentType $type = null;

    public function category(AdjustmentCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function type(AdjustmentType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCategory(): ?AdjustmentCategory
    {
        return $this->category;
    }

    public function getType(): ?AdjustmentType
    {
        return $this->type;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->searchable()
            ->preload()
            ->createOptionForm($this->createAdjustmentForm())
            ->createOptionAction(fn (Action $action) => $this->createAdjustmentAction($action));

        $this->relationship(
            name: 'adjustments',
            titleAttribute: 'name',
            modifyQueryUsing: function (Builder $query) {
                if ($this->category) {
                    $query->where('category', $this->category);
                }

                if ($this->type) {
                    $query->where('type', $this->type);
                }

                return $query->orderBy('name');
            },
        );

        $this->createOptionUsing(static function (array $data, CreateAdjustmentSelect $component) {
            return DB::transaction(static function () use ($data, $component) {
                $category = $data['category'] ?? $component->getCategory();
                $type = $data['type'] ?? $component->getType();

                $adjustment = Adjustment::create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'category' => $category,
                    'type' => $type,
                    'computation' => $data['computation'],
                    'rate' => $data['rate'],
                    'scope' => $data['scope'] ?? null,
                    'recoverable' => $data['recoverable'] ?? false,
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                ]);

                return $adjustment->getKey();
            });
        });
    }

    protected function createAdjustmentForm(): array
    {
        return [
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),

            Textarea::make('description')
                ->label('Description'),

            Select::make('category')
                ->label('Category')
                ->options(AdjustmentCategory::class)
                ->default(AdjustmentCategory::Tax)
                ->hidden(fn () => (bool) $this->getCategory())
                ->live()
                ->required(),

            Select::make('type')
                ->label('Type')
                ->options(AdjustmentType::class)
                ->default(AdjustmentType::Sales)
                ->hidden(fn () => (bool) $this->getType())
                ->live()
                ->required(),

            Select::make('computation')
                ->label('Computation')
                ->options(AdjustmentComputation::class)
                ->default(AdjustmentComputation::Percentage)
                ->live()
                ->required(),

            TextInput::make('rate')
                ->label('Rate')
                ->rate(static fn (Get $get) => $get('computation'))
                ->required(),

            Select::make('scope')
                ->label('Scope')
                ->options(AdjustmentScope::class),

            Checkbox::make('recoverable')
                ->label('Recoverable')
                ->default(false)
                ->helperText('When enabled, tax is tracked separately as claimable from the government. Non-recoverable taxes are treated as part of the expense.')
                ->visible(function (Get $get) {
                    $category = $this->getCategory() ?? AdjustmentCategory::parse($get('category'));
                    $type = $this->getType() ?? AdjustmentType::parse($get('type'));

                    return $category->isTax() && $type->isPurchase();
                }),

            Group::make()
                ->schema([
                    DateTimePicker::make('start_date'),
                    DateTimePicker::make('end_date'),
                ])
                ->visible(function (Get $get) {
                    $category = $this->getCategory() ?? AdjustmentCategory::parse($get('category'));

                    return $category->isDiscount();
                }),
        ];
    }

    protected function createAdjustmentAction(Action $action): Action
    {
        $categoryLabel = $this->getCategory()?->getLabel() ?? 'Adjustment';
        $typeLabel = $this->getType()?->getLabel() ?? '';
        $label = trim($typeLabel . ' ' . $categoryLabel);

        return $action
            ->label('Create ' . $label)
            ->slideOver()
            ->modalWidth(MaxWidth::ExtraLarge)
            ->modalHeading('Create a new ' . strtolower($label));
    }
}
