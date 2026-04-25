<?php

namespace Database\Seeders;

use App\Models\HallSetting;
use Illuminate\Database\Seeder;

class HallSettingSeeder extends Seeder
{
    public function run(): void
    {
        $existing = HallSetting::query()->first();

        if ($existing) {
            $existing->update([
                'large_hall_min_capacity' => 100,
                'amphitheater_min_capacity' => 200,
            ]);

            return;
        }

        HallSetting::query()->create([
            'large_hall_min_capacity' => 100,
            'amphitheater_min_capacity' => 200,
        ]);
    }
}
