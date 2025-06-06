<?php

namespace App\Enums\Setting;

use App\Enums\Concerns\ParsesEnum;
use Filament\Support\Contracts\HasLabel;

enum Template: string implements HasLabel
{
    use ParsesEnum;

    case Default = 'default';
    case Modern  = 'modern';
    case Classic = 'classic';

    public const DEFAULT = self::Default->value;

    public function getLabel(): ?string
    {
        return translate($this->name);
    }
}
