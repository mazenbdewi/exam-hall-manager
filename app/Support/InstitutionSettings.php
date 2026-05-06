<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InstitutionSettings
{
    public const DEFAULT_UNIVERSITY_NAME = 'الجامعة الافتراضية السورية';

    protected ?SystemSetting $setting = null;

    public static function make(): self
    {
        return app(self::class);
    }

    public static function invalidateCache(): void
    {
        try {
            Cache::forget('institution-settings');
        } catch (Throwable) {
            //
        }
    }

    public function setting(): SystemSetting
    {
        if ($this->setting) {
            return $this->setting;
        }

        try {
            return $this->setting = SystemSetting::current();
        } catch (Throwable) {
            return $this->setting = new SystemSetting(SystemSetting::defaults());
        }
    }

    public function universityName(): string
    {
        return filled($this->setting()->university_name)
            ? (string) $this->setting()->university_name
            : self::DEFAULT_UNIVERSITY_NAME;
    }

    public function universityLogo(): ?string
    {
        $logo = $this->setting()->university_logo;

        return filled($logo) ? (string) $logo : null;
    }

    public function logoDataUri(): ?string
    {
        $path = $this->universityLogo();

        if (! $path) {
            return null;
        }

        try {
            if (Storage::disk('public')->exists($path)) {
                $contents = Storage::disk('public')->get($path);
                $mimeType = Storage::disk('public')->mimeType($path) ?: File::mimeType(Storage::disk('public')->path($path));

                return 'data:'.($mimeType ?: 'image/png').';base64,'.base64_encode($contents);
            }

            $publicStoragePath = public_path('storage/'.$path);

            if (File::exists($publicStoragePath) && File::isReadable($publicStoragePath)) {
                return 'data:'.(File::mimeType($publicStoragePath) ?: 'image/png').';base64,'.base64_encode((string) File::get($publicStoragePath));
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return object{university_name:string,university_logo:?string,college_name:string,department_name:string,academic_year:string,logo_data_uri:?string}
     */
    public function reportContext(?string $collegeName = null, ?string $departmentName = null, ?string $academicYear = null): object
    {
        return (object) [
            'university_name' => $this->universityName(),
            'university_logo' => $this->universityLogo(),
            'college_name' => filled($collegeName) ? (string) $collegeName : '—',
            'department_name' => filled($departmentName) ? (string) $departmentName : '—',
            'academic_year' => filled($academicYear) ? (string) $academicYear : '—',
            'logo_data_uri' => $this->logoDataUri(),
        ];
    }
}
