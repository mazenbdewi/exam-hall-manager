<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\HallSetting;
use Illuminate\Database\Seeder;

class HallSettingSeeder extends Seeder
{
    public function run(): void
    {
        College::query()
            ->pluck('id')
            ->each(function (int $collegeId): void {
                HallSetting::query()->updateOrCreate(
                    ['college_id' => $collegeId],
                    HallSetting::defaults(),
                );
            });
    }
}
