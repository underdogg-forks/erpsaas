<?php

namespace App\Models\Accounting;

use App\Concerns\Blamable;
use App\Concerns\CompanyOwned;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Facades\Accounting;
use App\Models\Banking\BankAccount;
use App\Models\Setting\Currency;
use App\Observers\AccountObserver;
use App\Utilities\Currency\CurrencyAccessor;
use Database\Factories\Accounting\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

#[ObservedBy(AccountObserver::class)]
class Account extends Model
{
    use Blamable;
    use CompanyOwned;
    use HasFactory;

    protected $table = 'accounts';

    protected $fillable = [
        'company_id',
        'subtype_id',
        'parent_id',
        'category',
        'type',
        'code',
        'name',
        'currency_code',
        'description',
        'archived',
        'default',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'category' => AccountCategory::class,
        'type' => AccountType::class,
        'archived' => 'boolean',
        'default' => 'boolean',
    ];

    public function subtype(): BelongsTo
    {
        return $this->belongsTo(AccountSubtype::class, 'subtype_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(__CLASS__, 'parent_id')
            ->whereKeyNot($this->getKey());
    }

    public function children(): HasMany
    {
        return $this->hasMany(__CLASS__, 'parent_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class, 'account_id');
    }

    public function adjustment(): HasOne
    {
        return $this->hasOne(Adjustment::class, 'account_id');
    }

    public function scopeBudgetable(Builder $query): Builder
    {
        return $query->whereIn('category', [
            AccountCategory::Revenue,
            AccountCategory::Expense,
        ])
            ->whereNotIn('type', [
                AccountType::ContraRevenue,
                AccountType::ContraExpense,
                AccountType::UncategorizedRevenue,
                AccountType::UncategorizedExpense,
            ])
            ->whereDoesntHave('subtype', function (Builder $query) {
                $query->whereIn('name', [
                    'Receivables',
                    'Input Tax Recoverable',
                ]);
            })
            ->whereNotIn('name', [
                'Gain on Foreign Exchange',
                'Loss on Foreign Exchange',
            ])
            ->where('currency_code', CurrencyAccessor::getDefaultCurrency());
    }

    public function scopeWithLastTransactionDate(Builder $query): Builder
    {
        return $query->addSelect([
            'last_transaction_date' => JournalEntry::select(DB::raw('MAX(transactions.posted_at)'))
                ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
                ->whereColumn('journal_entries.account_id', 'accounts.id')
                ->limit(1),
        ]);
    }

    protected function endingBalance(): Attribute
    {
        return Attribute::get(function () {
            $company = $this->company;
            $fiscalYearStart = $company->locale->fiscalYearStartDate();
            $fiscalYearEnd = $company->locale->fiscalYearEndDate();

            return Accounting::getEndingBalance($this, $fiscalYearStart, $fiscalYearEnd);
        });
    }

    public function isUncategorized(): bool
    {
        return $this->type->isUncategorized();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'account_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'account_id');
    }

    public static function getAccountsReceivableAccount(?int $companyId = null): self
    {
        return self::where('name', 'Accounts Receivable')
            ->when($companyId, function (Builder $query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->firstOrFail();
    }

    public static function getAccountsPayableAccount(?int $companyId = null): self
    {
        return self::where('name', 'Accounts Payable')
            ->when($companyId, function (Builder $query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->firstOrFail();
    }

    public static function getSalesDiscountAccount(?int $companyId = null): self
    {
        return self::where('name', 'Sales Discount')
            ->when($companyId, function (Builder $query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->firstOrFail();
    }

    public static function getPurchaseDiscountAccount(?int $companyId = null): self
    {
        return self::where('name', 'Purchase Discount')
            ->when($companyId, function (Builder $query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->firstOrFail();
    }

    protected static function newFactory(): Factory
    {
        return AccountFactory::new();
    }
}
