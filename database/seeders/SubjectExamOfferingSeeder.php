<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class SubjectExamOfferingSeeder extends Seeder
{
    use UpsertsDemoRecords;

    public function run(): void
    {
        $subjects = Subject::query()->get()->keyBy('code');
        $academicYears = AcademicYear::query()->get()->keyBy('name');
        $semesters = Semester::query()->get()->keyBy('name');

        foreach (DemoSeedData::offeringSpecifications() as $offeringData) {
            $subject = $subjects->get($offeringData['subject_code']);
            $academicYear = $academicYears->get($offeringData['academic_year']);
            $semester = $semesters->get($offeringData['semester']);

            if (! $subject || ! $academicYear || ! $semester) {
                continue;
            }

            $this->upsertRecord(
                SubjectExamOffering::class,
                [
                    'subject_id' => $subject->id,
                    'academic_year_id' => $academicYear->id,
                    'semester_id' => $semester->id,
                    'exam_date' => $offeringData['exam_date'],
                    'exam_start_time' => $offeringData['exam_start_time'],
                ],
                [
                    'notes' => $offeringData['notes'],
                    'status' => $offeringData['status'],
                ],
            );
        }
    }
}
