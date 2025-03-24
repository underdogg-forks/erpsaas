<?php

namespace App\Enums\Accounting;

use App\Enums\Concerns\ParsesEnum;
use Filament\Support\Contracts\HasLabel;

enum BudgetSourceType: string implements HasLabel
{
    use ParsesEnum;

    case Budget = 'budget';
    case Actuals = 'actuals';

    public function getLabel(): string
    {
        return match ($this) {
            self::Budget => 'Copy from a previous budget',
            self::Actuals => 'Use historical actuals',
        };
    }

    public function isBudget(): bool
    {
        return $this === self::Budget;
    }

    public function isActuals(): bool
    {
        return $this === self::Actuals;
    }
}
