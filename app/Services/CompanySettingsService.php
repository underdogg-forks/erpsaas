<?php

namespace App\Services;

use App\Enums\Setting\DateFormat;
use App\Enums\Setting\WeekStart;
use App\Models\Company;
use App\Models\Setting\Currency;
use Illuminate\Support\Facades\Cache;

class CompanySettingsService
{
    protected static array $requestCache = [];

    public static function getSettings(?int $companyId = null): array
    {
        if ( ! $companyId) {
            return self::getDefaultSettings();
        }

        if (isset(self::$requestCache[$companyId])) {
            return self::$requestCache[$companyId];
        }

        $cacheKey = "company_settings_{$companyId}";

        $settings = Cache::rememberForever($cacheKey, function () use ($companyId) {
            $company = Company::with(['locale'])->find($companyId);

            if ( ! $company) {
                return self::getDefaultSettings();
            }

            $defaultCurrency = Currency::query()
                ->where('company_id', $companyId)
                ->where('enabled', true)
                ->value('code') ?? 'USD';

            return [
                'default_language'    => $company->locale->language ?? config('transmatic.source_locale'),
                'default_timezone'    => $company->locale->timezone ?? config('app.timezone'),
                'default_currency'    => $defaultCurrency,
                'default_date_format' => $company->locale->date_format->value ?? DateFormat::DEFAULT,
                'default_week_start'  => $company->locale->week_start->value ?? WeekStart::DEFAULT,
            ];
        });

        self::$requestCache[$companyId] = $settings;

        return $settings;
    }

    public static function invalidateSettings(int $companyId): void
    {
        $cacheKey = "company_settings_{$companyId}";

        Cache::forget($cacheKey);

        unset(self::$requestCache[$companyId]);
    }

    public static function getDefaultSettings(): array
    {
        return [
            'default_language'    => config('transmatic.source_locale'),
            'default_timezone'    => config('app.timezone'),
            'default_currency'    => 'USD',
            'default_date_format' => DateFormat::DEFAULT,
            'default_week_start'  => WeekStart::DEFAULT,
        ];
    }

    public static function getSpecificSetting(?int $companyId, string $key, $default = null)
    {
        $settings = self::getSettings($companyId);

        return $settings[$key] ?? $default;
    }

    public static function getDefaultLanguage(?int $companyId = null): string
    {
        return self::getSpecificSetting($companyId, 'default_language', config('transmatic.source_locale'));
    }

    public static function getDefaultTimezone(?int $companyId = null): string
    {
        return self::getSpecificSetting($companyId, 'default_timezone', config('app.timezone'));
    }

    public static function getDefaultCurrency(?int $companyId = null): string
    {
        return self::getSpecificSetting($companyId, 'default_currency', 'USD');
    }

    public static function getDefaultDateFormat(?int $companyId = null): string
    {
        return self::getSpecificSetting($companyId, 'default_date_format', DateFormat::DEFAULT);
    }

    public static function getDefaultWeekStart(?int $companyId = null): string
    {
        return self::getSpecificSetting($companyId, 'default_week_start', WeekStart::DEFAULT);
    }
}
