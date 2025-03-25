<?php

namespace App\Models\Accounting;

use App\Casts\MoneyCast;
use App\Concerns\CompanyOwned;
use App\Enums\Accounting\BudgetIntervalType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetAllocation extends Model
{
    use CompanyOwned;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'budget_item_id',
        'period',
        'interval_type',
        'start_date',
        'end_date',
        'amount',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'interval_type' => BudgetIntervalType::class,
        'amount' => MoneyCast::class,
    ];

    public function budgetItem(): BelongsTo
    {
        return $this->belongsTo(BudgetItem::class);
    }

    public function isCurrentPeriod(): bool
    {
        return now()->between($this->start_date, $this->end_date);
    }
}
