<?php

namespace App\Filament\Company\Pages\Service;

use App\Facades\Forex;
use App\Models\Service\CurrencyList;
use App\Models\Setting\Currency;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class LiveCurrency extends Page
{
    #[Url]
    public ?string $activeTab = null;

    protected static ?string $title = 'Live Currency';

    protected static ?string $slug = 'services/live-currency';

    protected static string $view = 'filament.company.pages.service.live-currency';

    public static function canAccess(): bool
    {
        return Forex::isEnabled();
    }

    public static function getNavigationLabel(): string
    {
        return translate(static::$title);
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make(static::getNavigationLabel())
                ->visible(static::canAccess())
                ->group(static::getNavigationGroup())
                ->parentItem(static::getNavigationParentItem())
                ->icon(static::getNavigationIcon())
                ->activeIcon(static::getActiveNavigationIcon())
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getNavigationItemActiveRoutePattern()))
                ->sort(static::getNavigationSort())
                ->badge(static::getNavigationBadge(), color: static::getNavigationBadgeColor())
                ->badgeTooltip(static::getNavigationBadgeTooltip())
                ->url(static::getNavigationUrl()),
        ];
    }

    public function getTitle(): string | Htmlable
    {
        return translate(static::$title);
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'currency-list';
    }

    public function getViewData(): array
    {
        return [
            'currencyListQuery'      => CurrencyList::query()->count(),
            'companyCurrenciesQuery' => Currency::query()->count(),
        ];
    }

    protected function loadDefaultActiveTab(): void
    {
        if (filled($this->activeTab)) {
            return;
        }

        $this->activeTab = $this->getDefaultActiveTab();
    }
}
