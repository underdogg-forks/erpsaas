<?php

namespace App\Casts;

use App\Models\Banking\BankAccount;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

class TransactionAmountCast implements CastsAttributes
{
    /**
     * Static cache to persist across instances
     */
    private static array $currencyCache = [];

    /**
     * Eagerly load all required bank accounts at once if needed
     */
    private function loadMissingBankAccounts(array $ids): void
    {
        $missingIds = array_filter($ids, static fn ($id) => ! isset(self::$currencyCache[$id]) && $id !== null);

        if (empty($missingIds)) {
            return;
        }

        /** @var BankAccount[] $accounts */
        $accounts = BankAccount::with('account')
            ->whereIn('id', $missingIds)
            ->get();

        foreach ($accounts as $account) {
            self::$currencyCache[$account->id] = $account->account->currency_code ?? CurrencyAccessor::getDefaultCurrency();
        }
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        // Attempt to retrieve the currency code from the related bankAccount->account model
        $bankAccountId = $attributes['bank_account_id'] ?? null;

        if ($bankAccountId !== null && ! isset(self::$currencyCache[$bankAccountId])) {
            $this->loadMissingBankAccounts([$bankAccountId]);
        }

        $currencyCode = $this->getCurrencyCodeFromBankAccountId($bankAccountId);

        if ($value !== null) {
            return CurrencyConverter::prepareForMutator($value, $currencyCode);
        }

        return '';
    }

    /**
     * @throws UnexpectedValueException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        $bankAccountId = $attributes['bank_account_id'] ?? null;

        if ($bankAccountId !== null && ! isset(self::$currencyCache[$bankAccountId])) {
            $this->loadMissingBankAccounts([$bankAccountId]);
        }

        $currencyCode = $this->getCurrencyCodeFromBankAccountId($bankAccountId);

        if (is_numeric($value)) {
            $value = (string) $value;
        } elseif (! is_string($value)) {
            throw new UnexpectedValueException('Expected string or numeric value for money cast');
        }

        return CurrencyConverter::prepareForAccessor($value, $currencyCode);
    }

    /**
     * Get currency code from the cache or use default
     */
    private function getCurrencyCodeFromBankAccountId(?int $bankAccountId): string
    {
        if ($bankAccountId === null) {
            return CurrencyAccessor::getDefaultCurrency();
        }

        return self::$currencyCache[$bankAccountId] ?? CurrencyAccessor::getDefaultCurrency();
    }
}
