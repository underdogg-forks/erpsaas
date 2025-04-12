<?php

namespace App\Concerns;

trait RedirectToViewPage
{
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
