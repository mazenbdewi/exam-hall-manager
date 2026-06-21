<?php

namespace App\Models;

use App\Support\InstitutionSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'university_name',
        'university_logo',
        'database_backup_time',
        'allow_normal_subjects_in_drawing_studios',
    ];

    public static function defaults(): array
    {
        return [
            'university_name' => InstitutionSettings::DEFAULT_UNIVERSITY_NAME,
            'university_logo' => null,
            'database_backup_time' => '02:00',
            'allow_normal_subjects_in_drawing_studios' => false,
        ];
    }

    protected function casts(): array
    {
        return [
            'allow_normal_subjects_in_drawing_studios' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(static::defaults());
    }

    protected static function booted(): void
    {
        static::saved(function (self $setting): void {
            InstitutionSettings::invalidateCache();

            if (! $setting->wasChanged('university_logo')) {
                return;
            }

            $oldLogo = $setting->getOriginal('university_logo');
            $newLogo = $setting->university_logo;

            if (! filled($oldLogo) || $oldLogo === $newLogo) {
                return;
            }

            try {
                Storage::disk('public')->delete($oldLogo);
            } catch (Throwable) {
                //
            }
        });

        static::deleted(fn (): mixed => InstitutionSettings::invalidateCache());
    }
}
