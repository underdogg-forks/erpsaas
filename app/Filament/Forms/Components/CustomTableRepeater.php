<?php

namespace App\Filament\Forms\Components;

use Awcodes\TableRepeater\Components\TableRepeater;
use Closure;

class CustomTableRepeater extends TableRepeater
{
    protected bool | Closure | null $spreadsheet = null;

    public function spreadsheet(bool | Closure $condition = true): static
    {
        $this->spreadsheet = $condition;

        return $this;
    }

    public function isSpreadsheet(): bool
    {
        return $this->evaluate($this->spreadsheet) ?? false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->extraAttributes(function (): array {
            $attributes = [];

            if ($this->isSpreadsheet()) {
                $attributes['class'] = 'is-spreadsheet';
            }

            return $attributes;
        });
    }
}
