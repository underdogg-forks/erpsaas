<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AdjustmentStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Upcoming = 'upcoming';
    case Expired = 'expired';
    case Paused = 'paused';
    case Archived = 'archived';

    public function getLabel(): ?string
    {
        return $this->name;
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::Active => 'primary',
            self::Upcoming, self::Paused => 'warning',
            self::Expired => 'danger',
            self::Archived => 'gray',
        };
    }

    /**
     * Check if the status is set manually (not calculated from dates)
     */
    public function isManualStatus(): bool
    {
        return in_array($this, [self::Paused, self::Archived]);
    }

    /**
     * Check if the status is system-calculated based on dates
     */
    public function isSystemStatus(): bool
    {
        return in_array($this, [self::Active, self::Upcoming, self::Expired]);
    }
}
