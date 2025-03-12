<?php

namespace App\Listeners;

use App\Events\StartTransactionImport;
use App\Jobs\ProcessTransactionImport;
use App\Models\Accounting\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class HandleTransactionImport
{
    /**
     * Handle the event.
     */
    public function handle(StartTransactionImport $event): void
    {
        DB::transaction(function () use ($event) {
            $this->processTransactionImport($event);
        });
    }

    public function processTransactionImport(StartTransactionImport $event): void
    {
        $company = $event->company;
        $connectedBankAccount = $event->connectedBankAccount;
        $selectedBankAccountId = $event->selectedBankAccountId;
        $startDate = $event->startDate;

        if ($selectedBankAccountId === 'new') {
            $defaultAccountSubtypeName = $connectedBankAccount->type->getDefaultSubtype();

            $accountSubtype = $company->accountSubtypes()
                ->where('name', $defaultAccountSubtypeName)
                ->first();

            if ($accountSubtype === null) {
                throw new ModelNotFoundException("Account subtype '{$defaultAccountSubtypeName}' not found for company '{$company->name}'");
            }

            /** @var Account $account */
            $account = $company->accounts()->create([
                'name' => $connectedBankAccount->name,
                'currency_code' => $connectedBankAccount->currency_code,
                'description' => $connectedBankAccount->name,
                'subtype_id' => $accountSubtype->id,
            ]);

            $bankAccount = $account->bankAccount()->create([
                'company_id' => $company->id,
                'institution_id' => $connectedBankAccount->institution_id,
                'type' => $connectedBankAccount->type,
                'number' => $connectedBankAccount->mask,
                'enabled' => $company->bankAccounts()->where('enabled', true)->doesntExist(),
            ]);
        } else {
            $bankAccount = $company->bankAccounts()->find($selectedBankAccountId);

            if ($bankAccount === null) {
                throw new ModelNotFoundException("Bank account '{$selectedBankAccountId}' not found for company '{$company->name}'");
            }

            $account = $bankAccount->account;
        }

        $connectedBankAccount->update([
            'bank_account_id' => $bankAccount->id,
            'import_transactions' => true,
        ]);

        ProcessTransactionImport::dispatch(
            $company,
            $account,
            $bankAccount,
            $connectedBankAccount,
            $startDate,
        );
    }
}
