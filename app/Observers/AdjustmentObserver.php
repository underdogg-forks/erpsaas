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
    }

    public function updating(Adjustment $adjustment): void
    {
        if ($adjustment->account) {
            $adjustment->account->update([
                'name' => $adjustment->name,
                'description' => $adjustment->description,
            ]);
        }
    }

    /**
     * Handle the Adjustment "saving" event.
     */
    public function saving(Adjustment $adjustment): void
    {
        // Handle dates changes affecting status
        // Only if the status isn't being explicitly changed and not in a manual state
        if ($adjustment->isDirty(['start_date', 'end_date']) &&
            ! $adjustment->isDirty('status') &&
            ! in_array($adjustment->status, [AdjustmentStatus::Archived, AdjustmentStatus::Paused])) {

            $adjustment->status = $adjustment->calculateNaturalStatus();
        }

        // Handle auto-resume for paused adjustments with a paused_until date
        if ($adjustment->shouldAutoResume() && ! $adjustment->isDirty('status')) {
            $adjustment->status = $adjustment->calculateNaturalStatus();
            $adjustment->paused_at = null;
            $adjustment->paused_until = null;
            $adjustment->status_reason = null;
        }

        // Ensure consistency between paused status and paused_at field
        if ($adjustment->status === AdjustmentStatus::Paused && ! $adjustment->paused_at) {
            $adjustment->paused_at = now();
        }
    }
}
