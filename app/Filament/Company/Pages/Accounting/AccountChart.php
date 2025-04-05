<?php

namespace App\Filament\Company\Pages\Accounting;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Banking\BankAccountType;
use App\Filament\Forms\Components\CreateCurrencySelect;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Utilities\Accounting\AccountCode;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class AccountChart extends Page
{
    protected static ?string $title = 'Chart of Accounts';

    protected static ?string $slug = 'accounting/chart';

    protected static string $view = 'filament.company.pages.accounting.chart';

    #[Url]
    public ?string $activeTab = AccountCategory::Asset->value;

    protected function configureAction(Action $action): void
    {
        $action
            ->modal()
            ->slideOver()
            ->modalWidth(MaxWidth::TwoExtraLarge);
    }

    #[Computed]
    public function categories(): Collection
    {
        return AccountSubtype::withCount('accounts')
            ->with(['accounts' => function ($query) {
                $query->withLastTransactionDate()->with('adjustment');
            }])
            ->get()
            ->groupBy('category');
    }

    public function editChartAction(): Action
    {
        return EditAction::make()
            ->iconButton()
            ->name('editChart')
            ->label('Edit account')
            ->modalHeading('Edit Account')
            ->icon('heroicon-m-pencil-square')
            ->record(fn (array $arguments) => Account::find($arguments['chart']))
            ->form(fn (Form $form) => $this->getChartForm($form)->operation('edit'));
    }

    public function createChartAction(): Action
    {
        return CreateAction::make()
            ->link()
            ->name('createChart')
            ->model(Account::class)
            ->label('Add a new account')
            ->icon('heroicon-o-plus-circle')
            ->form(fn (Form $form) => $this->getChartForm($form)->operation('create'))
            ->fillForm(fn (array $arguments): array => $this->getChartFormDefaults($arguments['subtype']));
    }

    private function getChartFormDefaults(int $subtypeId): array
    {
        $accountSubtype = AccountSubtype::find($subtypeId);
        $generatedCode = AccountCode::generate($accountSubtype);

        return [
            'subtype_id' => $subtypeId,
            'code' => $generatedCode,
        ];
    }

    private function getChartForm(Form $form, bool $useActiveTab = true): Form
    {
        return $form
            ->schema([
                $this->getTypeFormComponent($useActiveTab),
                $this->getCodeFormComponent(),
                $this->getNameFormComponent(),
                ...$this->getBankAccountFormComponents(),
                $this->getCurrencyFormComponent(),
                $this->getDescriptionFormComponent(),
                $this->getArchiveFormComponent(),
            ]);
    }

    protected function getTypeFormComponent(bool $useActiveTab = true): Component
    {
        return Select::make('subtype_id')
            ->label('Type')
            ->required()
            ->live()
            ->disabledOn('edit')
            ->options($this->getChartSubtypeOptions($useActiveTab))
            ->afterStateUpdated(static function (?string $state, Set $set): void {
                if ($state) {
                    $accountSubtype = AccountSubtype::find($state);
                    $generatedCode = AccountCode::generate($accountSubtype);
                    $set('code', $generatedCode);

                    $set('is_bank_account', false);
                    $set('bankAccount.type', null);
                    $set('bankAccount.number', null);
                }
            });
    }

    protected function getCodeFormComponent(): Component
    {
        return TextInput::make('code')
            ->label('Code')
            ->required()
            ->hiddenOn('edit')
            ->validationAttribute('account code')
            ->unique(table: Account::class, column: 'code', ignoreRecord: true)
            ->validateAccountCode(static fn (Get $get) => $get('subtype_id'));
    }

    protected function getBankAccountFormComponents(): array
    {
        return [
            Checkbox::make('is_bank_account')
                ->live()
                ->visible(function (Get $get, string $operation) {
                    if ($operation === 'edit') {
                        return false;
                    }

                    $subtype = $get('subtype_id');
                    if (empty($subtype)) {
                        return false;
                    }

                    $accountSubtype = AccountSubtype::find($subtype);

                    if (! $accountSubtype) {
                        return false;
                    }

                    return in_array($accountSubtype->category, [
                        AccountCategory::Asset,
                        AccountCategory::Liability,
                    ]) && $accountSubtype->multi_currency;
                })
                ->afterStateUpdated(static function ($state, Get $get, Set $set) {
                    if ($state) {
                        $subtypeId = $get('subtype_id');

                        if (empty($subtypeId)) {
                            return;
                        }

                        $subtype = AccountSubtype::find($subtypeId);

                        if (! $subtype) {
                            return;
                        }

                        // Set default bank account type based on account category
                        if ($subtype->category === AccountCategory::Asset) {
                            $set('bankAccount.type', BankAccountType::Depository->value);
                        } elseif ($subtype->category === AccountCategory::Liability) {
                            $set('bankAccount.type', BankAccountType::Credit->value);
                        }
                    } else {
                        // Clear bank account fields
                        $set('bankAccount.type', null);
                        $set('bankAccount.number', null);
                    }
                }),
            Group::make()
                ->relationship('bankAccount')
                ->schema([
                    Select::make('type')
                        ->label('Bank account type')
                        ->options(function (Get $get) {
                            $subtype = $get('../subtype_id');

                            if (empty($subtype)) {
                                return [];
                            }

                            $accountSubtype = AccountSubtype::find($subtype);

                            if (! $accountSubtype) {
                                return [];
                            }

                            if ($accountSubtype->category === AccountCategory::Asset) {
                                return [
                                    BankAccountType::Depository->value => BankAccountType::Depository->getLabel(),
                                    BankAccountType::Investment->value => BankAccountType::Investment->getLabel(),
                                ];
                            } elseif ($accountSubtype->category === AccountCategory::Liability) {
                                return [
                                    BankAccountType::Credit->value => BankAccountType::Credit->getLabel(),
                                    BankAccountType::Loan->value => BankAccountType::Loan->getLabel(),
                                ];
                            }

                            return [];
                        })
                        ->searchable()
                        ->columnSpan(1)
                        ->disabledOn('edit')
                        ->required(),
                    TextInput::make('number')
                        ->label('Bank account number')
                        ->unique(ignoreRecord: true, modifyRuleUsing: static function (Unique $rule, $state) {
                            $companyId = Auth::user()->currentCompany->id;

                            return $rule->where('company_id', $companyId)->where('number', $state);
                        })
                        ->maxLength(20)
                        ->validationAttribute('account number'),
                ])
                ->visible(static function (Get $get, ?Account $record, string $operation) {
                    if ($operation === 'create') {
                        return (bool) $get('is_bank_account');
                    }

                    if ($operation === 'edit' && $record) {
                        return (bool) $record->bankAccount;
                    }

                    return false;
                }),
        ];
    }

    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->label('Name')
            ->required();
    }

    protected function getCurrencyFormComponent(): Component
    {
        return CreateCurrencySelect::make('currency_code')
            ->disabledOn('edit')
            ->required(false)
            ->requiredIfAccepted('is_bank_account')
            ->validationMessages([
                'required_if_accepted' => 'The currency is required for bank accounts.',
            ])
            ->visible(function (Get $get): bool {
                return filled($get('subtype_id')) && AccountSubtype::find($get('subtype_id'))->multi_currency;
            });
    }

    protected function getDescriptionFormComponent(): Component
    {
        return Textarea::make('description')
            ->label('Description');
    }

    protected function getArchiveFormComponent(): Component
    {
        return Checkbox::make('archived')
            ->label('Archive account')
            ->helperText('Archived accounts will not be available for selection in transactions.')
            ->hiddenOn('create');
    }

    private function getChartSubtypeOptions($useActiveTab = true): array
    {
        $subtypes = $useActiveTab ?
            AccountSubtype::where('category', $this->activeTab)->get() :
            AccountSubtype::all();

        return $subtypes->groupBy(fn (AccountSubtype $subtype) => $subtype->type->getLabel())
            ->map(fn (Collection $subtypes, string $type) => $subtypes->mapWithKeys(static fn (AccountSubtype $subtype) => [$subtype->id => $subtype->name]))
            ->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->button()
                ->label('Add new account')
                ->model(Account::class)
                ->form(fn (Form $form) => $this->getChartForm($form, false)->operation('create')),
        ];
    }

    public function getCategoryLabel($categoryValue): string
    {
        return AccountCategory::from($categoryValue)->getPluralLabel();
    }
}
