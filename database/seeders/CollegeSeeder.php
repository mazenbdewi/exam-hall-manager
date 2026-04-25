<?php

namespace Database\Seeders;

use App\Models\College;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class CollegeSeeder extends Seeder
{
    use UpsertsDemoRecords;

    public function run(): void
    {
        foreach (DemoSeedData::colleges() as $collegeData) {
            $this->upsertRecord(
                College::class,
                ['code' => $collegeData['code']],
                [
                    'name' => $collegeData['name'],
                    'is_active' => true,
                ],
            );
        }
    }
}
