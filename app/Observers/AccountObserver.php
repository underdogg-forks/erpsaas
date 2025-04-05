<?php

namespace App\Observers;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Models\Accounting\Account;
use App\Utilities\Accounting\AccountCode;
use App\Utilities\Currency\CurrencyAccessor;

class AccountObserver
{
    public function creating(Account $account): void
    {
        $this->setCategoryAndType($account);
        $this->ensureDefaultCurrency($account);
    }

    public function updating(Account $account): void
    {
        if ($account->isDirty('subtype_id')) {
            $this->setCategoryAndType($account);
        }

        $this->ensureDefaultCurrency($account);
    }

    private function setCategoryAndType(Account $account): void
    {
        if ($subtype = $account->subtype) {
            $account->category = $subtype->category;
            $account->type = $subtype->type;
        } else {
            $account->category = AccountCategory::Asset;
            $account->type = AccountType::CurrentAsset;
        }
    }

    private function ensureDefaultCurrency(Account $account): void
    {
        if (! $account->currency_code) {
            $account->currency_code = CurrencyAccessor::getDefaultCurrency();
        }
    }

    private function setAccountCode(Account $account): void
    {
        $generatedAccountCode = AccountCode::generate($account->subtype);

        $account->code = $generatedAccountCode;
    }

    /**
     * Handle the Account "created" event.
     */
    public function created(Account $account): void
    {
        if (! $account->code) {
            $this->setAccountCode($account);
            $account->saveQuietly();
        }
    }
}
