<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Department;
use App\Models\StudyLevel;
use App\Models\Subject;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    use UpsertsDemoRecords;

    public function run(): void
    {
        $colleges = College::query()->get()->keyBy('code');
        $departments = Department::query()->get()->keyBy(fn (Department $department): string => $department->college_id . ':' . $department->code);
        $studyLevels = StudyLevel::query()->get()->keyBy('sort_order');

        foreach (DemoSeedData::subjects() as $subjectData) {
            $college = $colleges->get($subjectData['college_code']);

            if (! $college) {
                continue;
            }

            $department = $departments->get($college->id . ':' . $subjectData['department_code']);
            $studyLevel = $studyLevels->get($subjectData['study_level']);

            if (! $department || ! $studyLevel) {
                continue;
            }

            $this->upsertRecord(
                Subject::class,
                ['code' => $subjectData['code']],
                [
                    'college_id' => $college->id,
                    'department_id' => $department->id,
                    'study_level_id' => $studyLevel->id,
                    'name' => $subjectData['name'],
                    'is_active' => true,
                ],
            );
        }
    }
}
