<?php

namespace App\Observers;

use App\Enums\Accounting\AdjustmentStatus;
use App\Models\Accounting\Account;
use App\Models\Accounting\Adjustment;

class AdjustmentObserver
{
    public function creating(Adjustment $adjustment): void
    {
        if (! $adjustment->account_id && ! $adjustment->isNonRecoverablePurchaseTax()) {
            $account = null;

            if ($adjustment->isSalesTax()) {
                $account = Account::factory()->forSalesTax($adjustment->name, $adjustment->description)->create();
            } elseif ($adjustment->isRecoverablePurchaseTax()) {
                $account = Account::factory()->forPurchaseTax($adjustment->name, $adjustment->description)->create();
            } elseif ($adjustment->isSalesDiscount()) {
                $account = Account::factory()->forSalesDiscount($adjustment->name, $adjustment->description)->create();
            } elseif ($adjustment->isPurchaseDiscount()) {
                $account = Account::factory()->forPurchaseDiscount($adjustment->name, $adjustment->description)->create();
            }

            if ($account) {
                $adjustment->account()->associate($account);
            }
        }

        if ($adjustment->status !== AdjustmentStatus::Archived) {
            $adjustment->status = $adjustment->calculateStatus();
        }
    }

    public function updating(Adjustment $adjustment): void
    {
        if ($adjustment->account) {
            $adjustment->account->update([
                'name' => $adjustment->name,
                'description' => $adjustment->description,
            ]);
        }

        if ($adjustment->status !== AdjustmentStatus::Archived) {
            $adjustment->status = $adjustment->calculateStatus();
        }
    }
}
