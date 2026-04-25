<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $existing = SystemSetting::query()->first();

        if ($existing) {
            $existing->update([
                'university_name' => 'الجامعة الافتراضية السورية',
                'university_logo' => null,
            ]);

            return;
        }

        SystemSetting::query()->create([
            'university_name' => 'الجامعة الافتراضية السورية',
            'university_logo' => null,
        ]);
    }
}
