<?php

namespace Database\Seeders;

use App\Models\StudyLevel;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class StudyLevelSeeder extends Seeder
{
    use UpsertsDemoRecords;

    public function run(): void
    {
        collect(DemoSeedData::studyLevels())->each(function (string $name, int $sortOrder): void {
            $this->upsertRecord(
                StudyLevel::class,
                ['sort_order' => $sortOrder],
                [
                    'name' => $name,
                    'is_active' => true,
                ],
            );
        });
    }
}
