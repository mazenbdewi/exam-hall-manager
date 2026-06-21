<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AppSettingsService
{
    public const DATABASE_BACKUP_TIME = 'database_backup_time';
    public const UNIVERSITY_NAME = 'university_name';
    public const UNIVERSITY_LOGO = 'university_logo';
    public const ALLOW_NORMAL_SUBJECTS_IN_DRAWING_STUDIOS = 'allow_normal_subjects_in_drawing_studios';

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->supports($key)) {
            return $default;
        }

        try {
            if (! Schema::hasTable('system_settings') || ! Schema::hasColumn('system_settings', $key)) {
                return $default;
            }

            return SystemSetting::current()->getAttribute($key) ?: $default;
        } catch (Throwable) {
            return $default;
        }
    }

    public function set(string $key, mixed $value): void
    {
        if (! $this->supports($key)) {
            return;
        }

        $setting = SystemSetting::current();
        $setting->forceFill([$key => $value])->save();
    }

    protected function supports(string $key): bool
    {
        return in_array($key, [
            self::DATABASE_BACKUP_TIME,
            self::UNIVERSITY_NAME,
            self::UNIVERSITY_LOGO,
            self::ALLOW_NORMAL_SUBJECTS_IN_DRAWING_STUDIOS,
        ], true);
    }
}
