<?php

namespace App\Jobs;

use App\Enums\Accounting\RecurringInvoiceStatus;
use App\Models\Accounting\RecurringInvoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateRecurringInvoices implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        RecurringInvoice::query()
            ->where('status', RecurringInvoiceStatus::Active)
            ->chunk(100, function ($recurringInvoices) {
                foreach ($recurringInvoices as $recurringInvoice) {
                    $recurringInvoice->generateDueInvoices();
                }
            });
    }
}
