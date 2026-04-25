<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\ExamHall;
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

            $this->upsertRecord(
                ExamHall::class,
                [
                    'college_id' => $college->id,
                    'name' => $hallData['name'],
                ],
                [
                    'location' => $hallData['location'],
                    'capacity' => $hallData['capacity'],
                    'hall_type' => HallClassification::expectedTypeForCapacity($hallData['capacity'])?->value,
                    'priority' => $hallData['priority'],
                    'is_active' => $hallData['is_active'],
                ],
            );
        }
    }
}
