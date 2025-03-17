<?php

namespace App\Enums\Accounting;

use App\Enums\Concerns\ParsesEnum;
use Filament\Support\Contracts\HasLabel;

enum BudgetIntervalType: string implements HasLabel
{
    use ParsesEnum;

    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Quarter => 'Quarterly',
            self::Year => 'Yearly',
        };
    }

    public function isMonth(): bool
    {
        return $this === self::Month;
    }

    public function isQuarter(): bool
    {
        return $this === self::Quarter;
    }

    public function isYear(): bool
    {
        return $this === self::Year;
    }
}
