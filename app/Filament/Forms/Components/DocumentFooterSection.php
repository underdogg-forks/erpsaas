<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;

class DocumentFooterSection extends Section
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->collapsible();
        $this->collapsed();

        $this->schema([
            Textarea::make('footer')
                ->columnSpanFull(),
        ]);
    }
}
