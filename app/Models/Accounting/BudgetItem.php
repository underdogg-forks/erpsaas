<?php

namespace App\Models\Accounting;

use App\Casts\MoneyCast;
use App\Concerns\Blamable;
use App\Concerns\CompanyOwned;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetItem extends Model
{
    use Blamable;
    use CompanyOwned;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'budget_id',
        'account_id',
        'start_date',
        'end_date',
        'amount', // in cents
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'amount' => MoneyCast::class,
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get actual transaction amount for this account and date range.
     */
    protected function actualAmount(): Attribute
    {
        return Attribute::make(
            get: function () {
                return Transaction::whereHas('journalEntries', function ($query) {
                    $query->where('account_id', $this->account_id);
                })
                    ->whereBetween('posted_at', [$this->start_date, $this->end_date])
                    ->sum('amount');
            }
        );
    }

    /**
     * Get variance (budget - actual) for this budget item.
     */
    protected function variance(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->amount - $this->actualAmount;
            }
        );
    }

    /**
     * Get variance percentage for this budget item.
     */
    protected function variancePercentage(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->amount == 0) {
                    return 0;
                }

                return ($this->variance / $this->amount) * 100;
            }
        );
    }
}
