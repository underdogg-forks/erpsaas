<?php

namespace App\Observers;

use App\Events\CurrencyRateChanged;
use App\Events\DefaultCurrencyChanged;
use App\Models\Setting\Currency;
use App\Utilities\Currency\CurrencyAccessor;

class CurrencyObserver
{
    public function creating(Currency $currency): void
    {
        $this->setStandardCurrencyAttributes($currency);
    }

    /**
     * Handle the Currency "updated" event.
     */
    public function updated(Currency $currency): void
    {
        if ($currency->wasChanged('enabled') && $currency->isEnabled()) {
            event(new DefaultCurrencyChanged($currency));
        }

        if ($currency->wasChanged('rate')) {
            event(new CurrencyRateChanged($currency, $currency->getOriginal('rate'), $currency->rate));
        }
    }

    protected function setStandardCurrencyAttributes(Currency $currency): void
    {
        if (empty($currency->code)) {
            return;
        }

        $defaultCurrency = CurrencyAccessor::getDefaultCurrency();

        $hasDefaultCurrency = $defaultCurrency !== null;

        $originalRate = $currency->rate;

        $currencyAttributes = Currency::factory()
            ->forCurrency($currency->code)
            ->make([
                'enabled' => ! $hasDefaultCurrency,
            ])
            ->getAttributes();

        $currencyAttributes['rate'] = $originalRate;

        $currency->fill($currencyAttributes);
    }
}
