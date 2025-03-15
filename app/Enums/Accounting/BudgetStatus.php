<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BudgetStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    public function getLabel(): ?string
    {
        return $this->name;
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Closed => 'warning',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Active]);
    }

    public static function editableStatuses(): array
    {
        return [self::Draft, self::Active];
    }
}
