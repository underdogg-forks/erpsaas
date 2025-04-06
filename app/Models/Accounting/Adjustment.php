<?php

namespace App\Models\Accounting;

use App\Casts\RateCast;
use App\Concerns\Blamable;
use App\Concerns\CompanyOwned;
use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\AdjustmentScope;
use App\Enums\Accounting\AdjustmentStatus;
use App\Enums\Accounting\AdjustmentType;
use App\Models\Common\Offering;
use App\Observers\AdjustmentObserver;
use Database\Factories\Accounting\AdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[ObservedBy(AdjustmentObserver::class)]
class Adjustment extends Model
{
    use Blamable;
    use CompanyOwned;
    use HasFactory;

    protected $table = 'adjustments';

    protected $fillable = [
        'company_id',
        'account_id',
        'name',
        'status',
        'status_reason',
        'description',
        'category',
        'type',
        'recoverable',
        'rate',
        'computation',
        'scope',
        'start_date',
        'end_date',
        'paused_at',
        'paused_until',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => AdjustmentStatus::class,
        'category' => AdjustmentCategory::class,
        'type' => AdjustmentType::class,
        'recoverable' => 'boolean',
        'rate' => RateCast::class,
        'computation' => AdjustmentComputation::class,
        'scope' => AdjustmentScope::class,
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'paused_at' => 'datetime',
        'paused_until' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function offerings(): MorphToMany
    {
        return $this->morphedByMany(Offering::class, 'adjustmentable', 'adjustmentables');
    }

    public function isSalesTax(): bool
    {
        return $this->category->isTax() && $this->type->isSales();
    }

    public function isNonRecoverablePurchaseTax(): bool
    {
        return $this->category->isTax() && $this->type->isPurchase() && $this->recoverable === false;
    }

    public function isRecoverablePurchaseTax(): bool
    {
        return $this->category->isTax() && $this->type->isPurchase() && $this->recoverable === true;
    }

    public function isSalesDiscount(): bool
    {
        return $this->category->isDiscount() && $this->type->isSales();
    }

    public function isPurchaseDiscount(): bool
    {
        return $this->category->isDiscount() && $this->type->isPurchase();
    }

    // Add these methods to your Adjustment model

    /**
     * Check if adjustment can be paused
     */
    public function canBePaused(): bool
    {
        return $this->status === AdjustmentStatus::Active;
    }

    /**
     * Check if adjustment can be resumed
     */
    public function canBeResumed(): bool
    {
        return $this->status === AdjustmentStatus::Paused;
    }

    /**
     * Check if adjustment can be archived
     */
    public function canBeArchived(): bool
    {
        return $this->status !== AdjustmentStatus::Archived;
    }

    /**
     * Calculate the natural status of the adjustment based on dates
     */
    public function calculateNaturalStatus(): AdjustmentStatus
    {
        if ($this->start_date?->isFuture()) {
            return AdjustmentStatus::Upcoming;
        }

        if ($this->end_date?->isPast()) {
            return AdjustmentStatus::Expired;
        }

        return AdjustmentStatus::Active;
    }

    /**
     * Pause the adjustment
     */
    public function pause(?string $reason = null, ?\DateTime $untilDate = null): bool
    {
        if (! $this->canBePaused()) {
            return false;
        }

        $this->paused_at = now();
        $this->paused_until = $untilDate;
        $this->status = AdjustmentStatus::Paused;
        $this->status_reason = $reason;

        return $this->save();
    }

    /**
     * Resume the adjustment
     */
    public function resume(): bool
    {
        if (! $this->canBeResumed()) {
            return false;
        }

        $this->paused_at = null;
        $this->paused_until = null;
        $this->status_reason = null;
        $this->status = $this->calculateNaturalStatus();

        return $this->save();
    }

    /**
     * Archive the adjustment
     */
    public function archive(?string $reason = null): bool
    {
        if (! $this->canBeArchived()) {
            return false;
        }

        $this->status = AdjustmentStatus::Archived;
        $this->status_reason = $reason;

        return $this->save();
    }

    /**
     * Check if the adjustment should be automatically resumed
     */
    public function shouldAutoResume(): bool
    {
        return $this->status === AdjustmentStatus::Paused &&
            $this->paused_until !== null &&
            $this->paused_until->isPast();
    }

    /**
     * Refresh the status based on current dates and conditions
     */
    public function refreshStatus(): bool
    {
        // Don't automatically change archived or paused status
        if ($this->status === AdjustmentStatus::Archived ||
            ($this->status === AdjustmentStatus::Paused && ! $this->shouldAutoResume())) {
            return false;
        }

        // Check if a paused adjustment should be auto-resumed
        if ($this->shouldAutoResume()) {
            return $this->resume();
        }

        // Calculate natural status based on dates
        $naturalStatus = $this->calculateNaturalStatus();

        // Only update if the status would change
        if ($this->status !== $naturalStatus) {
            $this->status = $naturalStatus;

            return $this->save();
        }

        return false;
    }

    protected static function newFactory(): Factory
    {
        return AdjustmentFactory::new();
    }
}
