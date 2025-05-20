<?php

use App\Models\Accounting\Bill;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Transaction;
use App\Models\Common\Client;
use App\Models\Common\Vendor;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $transactions = Transaction::query()
            ->withoutGlobalScopes()
            ->whereNotNull('transactionable_id')
            ->whereNull('payeeable_id')
            ->with('transactionable')
            ->get();

        foreach ($transactions as $transaction) {
            $document = $transaction->transactionable;

            if ($document instanceof Invoice) {
                $transaction->payeeable_id = $document->client_id;
                $transaction->payeeable_type = Client::class;
                $transaction->saveQuietly();
            } elseif ($document instanceof Bill) {
                $transaction->payeeable_id = $document->vendor_id;
                $transaction->payeeable_type = Vendor::class;
                $transaction->saveQuietly();
            }
        }
    }
};
