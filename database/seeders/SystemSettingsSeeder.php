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
                'university_name' => 'جامعة اللاذقية',
                'university_logo' => null,
                'database_backup_time' => $existing->database_backup_time ?: '02:00',
                'allow_normal_subjects_in_drawing_studios' => $existing->allow_normal_subjects_in_drawing_studios ?? false,
            ]);

            return;
        }

        SystemSetting::query()->create([
            'university_name' => 'جامعة اللاذقية ',
            'university_logo' => null,
            'database_backup_time' => '02:00',
            'allow_normal_subjects_in_drawing_studios' => false,
        ]);
    }
}
