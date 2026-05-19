<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\ExamHall;
use App\Models\HallSetting;
use App\Support\HallClassification;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class ExamHallSeeder extends Seeder
{
    use UpsertsDemoRecords;

    public function run(): void
    {
        $colleges = College::query()->get()->keyBy('code');

        foreach (DemoSeedData::examHalls() as $hallData) {
            $college = $colleges->get($hallData['college_code']);

            if (! $college) {
                continue;
            }

            $hallSettings = HallSetting::current($college->id);

            $this->upsertRecord(
                ExamHall::class,
                [
                    'college_id' => $college->id,
                    'name' => $hallData['name'],
                ],
                [
                    'location' => $hallData['location'],
                    'capacity' => $hallData['capacity'],
                    'hall_type' => HallClassification::expectedTypeForCapacity($hallData['capacity'], $hallSettings)?->value,
                    'is_drawing_studio' => $hallData['is_drawing_studio'] ?? false,
                    'priority' => $hallData['priority'],
                    'is_active' => $hallData['is_active'],
                ],
            );
        }
    }
}
