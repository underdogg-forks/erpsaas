<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AdjustmentStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Upcoming = 'upcoming';
    case Expired = 'expired';
    case Archived = 'archived';

    public function getLabel(): ?string
    {
        return $this->name;
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::Active => 'primary',
            self::Upcoming => 'warning',
            self::Expired => 'danger',
            self::Archived => 'gray',
        };
    }
}
