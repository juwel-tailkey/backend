<?php

namespace App\Services\Settings;

use App\Models\Setting;

/**
 * System-wide settings shared by every project/organization (e.g. which
 * pipeline generates reports). Not scoped per project or per report.
 */
class AppSettingsService
{
    public const REPORT_SOURCE = 'report_source';

    public const REPORT_SOURCE_BIGQUERY = 'bigquery';

    public const REPORT_SOURCE_CUSTOM_MODEL = 'custom_model';

    /**
     * How many minutes an already-generated report response may be reused
     * for an identical request (same report + project + payload) instead of
     * running a new BigQuery query or dispatching a new custom-model job.
     * "0" (the default) disables reuse entirely.
     */
    public const REPORT_REUSE_WINDOW_MINUTES = 'report_reuse_window_minutes';

    public function get(string $key, ?string $default = null): ?string
    {
        $value = Setting::query()->where('key', $key)->value('value');

        return $value ?? $default;
    }

    public function set(string $key, ?string $value): Setting
    {
        return Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function reportSource(): string
    {
        return $this->get(self::REPORT_SOURCE, self::REPORT_SOURCE_BIGQUERY);
    }

    public function reportReuseWindowMinutes(): int
    {
        return max(0, (int) $this->get(self::REPORT_REUSE_WINDOW_MINUTES, '0'));
    }
}
