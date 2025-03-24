<?php

namespace App\Filament\Tables\Columns;

use Closure;
use Filament\Tables\Columns\TextInputColumn;

class DeferredTextInputColumn extends TextInputColumn
{
    protected string $view = 'filament.tables.columns.deferred-text-input-column';

    protected bool | Closure $batchMode = false;

    public function batchMode(bool | Closure $condition = true): static
    {
        $this->batchMode = $condition;

        return $this;
    }

    public function getBatchMode(): bool
    {
        return $this->evaluate($this->batchMode);
    }
}
