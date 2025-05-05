<?php

namespace App\Filament\Forms\Components;

use Awcodes\TableRepeater\Components\TableRepeater;
use Closure;
use Filament\Forms\Components\Actions\Action;

class CustomTableRepeater extends TableRepeater
{
    protected bool | Closure $spreadsheet = false;

    protected bool | Closure $reorderAtStart = false;

    public function spreadsheet(bool | Closure $condition = true): static
    {
        $this->spreadsheet = $condition;

        return $this;
    }

    public function isSpreadsheet(): bool
    {
        return (bool) $this->evaluate($this->spreadsheet);
    }

    public function reorderAtStart(bool | Closure $condition = true): static
    {
        $this->reorderAtStart = $condition;

        return $this;
    }

    public function isReorderAtStart(): bool
    {
        return $this->evaluate($this->reorderAtStart) && $this->isReorderable();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->minItems(1);

        $this->extraAttributes(function (): array {
            $attributes = [];

            if ($this->isSpreadsheet()) {
                $attributes['class'] = 'is-spreadsheet';
            }

            return $attributes;
        });

        $this->reorderAction(function (Action $action) {
            if ($this->isReorderAtStart()) {
                $action->icon('heroicon-m-bars-3');
            }

            return $action;
        });
    }

    public function getView(): string
    {
        return 'filament.forms.components.custom-table-repeater';
    }
}
