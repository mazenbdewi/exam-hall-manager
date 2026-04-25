<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class AcademicYearSeeder extends Seeder
{
    use UpsertsDemoRecords;

    public function run(): void
    {
        foreach (DemoSeedData::academicYears() as $academicYearData) {
            $this->upsertRecord(
                AcademicYear::class,
                ['name' => $academicYearData['name']],
                [
                    'is_active' => true,
                    'is_current' => $academicYearData['is_current'],
                ],
            );
        }
    }
}
