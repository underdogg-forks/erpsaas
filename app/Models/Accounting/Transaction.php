<?php

namespace App\Models\Accounting;

use App\Casts\TransactionAmountCast;
use App\Concerns\Blamable;
use App\Concerns\CompanyOwned;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\PaymentMethod;
use App\Enums\Accounting\TransactionType;
use App\Models\Banking\BankAccount;
use App\Models\Common\Contact;
use App\Observers\TransactionObserver;
use Database\Factories\Accounting\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

#[ObservedBy(TransactionObserver::class)]
class Transaction extends Model
{
    use Blamable;
    use CompanyOwned;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'account_id', // Account from Chart of Accounts (Income/Expense accounts)
        'bank_account_id', // Cash/Bank Account
        'plaid_transaction_id',
        'contact_id',
        'type', // 'deposit', 'withdrawal', 'journal'
        'payment_channel',
        'payment_method',
        'is_payment',
        'description',
        'notes',
        'reference',
        'amount',
        'pending',
        'reviewed',
        'posted_at',
        'created_by',
        'updated_by',
        'meta',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'payment_method' => PaymentMethod::class,
        'amount' => TransactionAmountCast::class,
        'pending' => 'boolean',
        'reviewed' => 'boolean',
        'posted_at' => 'date',
        'meta' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'transaction_id');
    }

    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isUncategorized(): bool
    {
        return $this->journalEntries->contains(fn (JournalEntry $entry) => $entry->account->isUncategorized());
    }

    public function updateAmountIfBalanced(): void
    {
        if ($this->journalEntries->areBalanced() && $this->journalEntries->sumDebits()->formatSimple() !== $this->getAttributeValue('amount')) {
            $this->setAttribute('amount', $this->journalEntries->sumDebits()->formatSimple());
            $this->save();
        }
    }

    public static function getBankAccountOptions(?int $excludedAccountId = null, ?int $currentBankAccountId = null): array
    {
        return BankAccount::query()
            ->whereHas('account', function (Builder $query) {
                $query->where('archived', false);
            })
            ->with(['account' => function ($query) {
                $query->where('archived', false);
            }, 'account.subtype' => function ($query) {
                $query->select(['id', 'name']);
            }])
            ->when($excludedAccountId, fn (Builder $query) => $query->where('account_id', '!=', $excludedAccountId))
            ->when($currentBankAccountId, fn (Builder $query) => $query->orWhere('id', $currentBankAccountId))
            ->get()
            ->groupBy('account.subtype.name')
            ->map(fn (Collection $bankAccounts, string $subtype) => $bankAccounts->pluck('account.name', 'id'))
            ->toArray();
    }

    public static function getBankAccountAccountOptions(?int $excludedBankAccountId = null, ?int $currentAccountId = null): array
    {
        return Account::query()
            ->whereHas('bankAccount', function (Builder $query) use ($excludedBankAccountId) {
                // Exclude the specific bank account if provided
                if ($excludedBankAccountId) {
                    $query->whereNot('id', $excludedBankAccountId);
                }
            })
            ->where(function (Builder $query) use ($currentAccountId) {
                $query->where('archived', false)
                    ->orWhere('id', $currentAccountId);
            })
            ->get()
            ->groupBy(fn (Account $account) => $account->category->getPluralLabel())
            ->map(fn (Collection $accounts, string $category) => $accounts->pluck('name', 'id'))
            ->toArray();
    }

    public static function getChartAccountOptions(?TransactionType $type = null, ?bool $nominalAccountsOnly = null, ?int $currentAccountId = null): array
    {
        $nominalAccountsOnly ??= false;

        $excludedCategory = match ($type) {
            TransactionType::Deposit => AccountCategory::Expense,
            TransactionType::Withdrawal => AccountCategory::Revenue,
            default => null,
        };

        return Account::query()
            ->when($nominalAccountsOnly, fn (Builder $query) => $query->doesntHave('bankAccount'))
            ->when($excludedCategory, fn (Builder $query) => $query->whereNot('category', $excludedCategory))
            ->where(function (Builder $query) use ($currentAccountId) {
                $query->where('archived', false)
                    ->orWhere('id', $currentAccountId);
            })
            ->get()
            ->groupBy(fn (Account $account) => $account->category->getPluralLabel())
            ->map(fn (Collection $accounts, string $category) => $accounts->pluck('name', 'id'))
            ->toArray();
    }

    public static function getUncategorizedAccountByType(TransactionType $type): ?Account
    {
        [$category, $accountName] = match ($type) {
            TransactionType::Deposit => [AccountCategory::Revenue, 'Uncategorized Income'],
            TransactionType::Withdrawal => [AccountCategory::Expense, 'Uncategorized Expense'],
            default => [null, null],
        };

        return Account::where('category', $category)
            ->where('name', $accountName)
            ->first();
    }

    protected static function newFactory(): Factory
    {
        return TransactionFactory::new();
    }
}
