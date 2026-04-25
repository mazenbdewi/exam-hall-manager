<?php

namespace Database\Seeders;

use App\Models\Semester;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class SemesterSeeder extends Seeder
{
    use UpsertsDemoRecords;

    public function run(): void
    {
        collect(DemoSeedData::semesters())->each(function (string $name, int $sortOrder): void {
            $this->upsertRecord(
                Semester::class,
                ['sort_order' => $sortOrder],
                [
                    'name' => $name,
                    'is_active' => true,
                ],
            );
        });
    }
}
