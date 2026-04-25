<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Department;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    use UpsertsDemoRecords;

    public function run(): void
    {
        $colleges = College::query()->get()->keyBy('code');

        foreach (DemoSeedData::departments() as $collegeCode => $departments) {
            $college = $colleges->get($collegeCode);

            if (! $college) {
                continue;
            }

            foreach ($departments as $departmentData) {
                $this->upsertRecord(
                    Department::class,
                    [
                        'college_id' => $college->id,
                        'code' => $departmentData['code'],
                    ],
                    [
                        'name' => $departmentData['name'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
