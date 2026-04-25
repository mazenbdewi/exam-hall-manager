<?php

namespace Database\Seeders;

use App\Enums\ExamStudentType;
use App\Models\AcademicYear;
use App\Models\ExamStudent;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use Database\Seeders\Concerns\UpsertsDemoRecords;
use Database\Seeders\Support\DemoSeedData;
use Illuminate\Database\Seeder;

class ExamStudentSeeder extends Seeder
{
    use UpsertsDemoRecords;

    protected array $firstNames = [
        'أحمد', 'محمد', 'علي', 'عمر', 'يوسف', 'رامي', 'سامر', 'كريم', 'ليث', 'حسام',
        'يزن', 'باسل', 'مازن', 'سليم', 'عبد الرحمن', 'إياد', 'قصي', 'طارق', 'نور', 'كنان',
    ];

    protected array $middleNames = [
        'خالد', 'حسن', 'عبد الله', 'محمود', 'إبراهيم', 'ناصر', 'مصطفى', 'فؤاد', 'عمار', 'ياسين',
        'سامي', 'هشام', 'هاني', 'زهير', 'مروان', 'عادل', 'وليد', 'مهند', 'خليل', 'شادي',
    ];

    protected array $familyNames = [
        'الأحمد', 'الحسن', 'العلي', 'الخطيب', 'المصري', 'الجاسم', 'الحموي', 'الشامي', 'حداد', 'النجار',
        'العبدالله', 'الحلبي', 'السلوم', 'القاسم', 'الدروبي', 'قاسم', 'المحمود', 'الصفدي', 'المرعي', 'الخضر',
    ];

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

            $offering = SubjectExamOffering::query()
                ->where('subject_id', $subject->id)
                ->where('academic_year_id', $academicYear->id)
                ->where('semester_id', $semester->id)
                ->whereDate('exam_date', $offeringData['exam_date'])
                ->whereTime('exam_start_time', $offeringData['exam_start_time'])
                ->first();

            if (! $offering) {
                continue;
            }

            $this->seedStudentsForOffering(
                offering: $offering,
                prefix: $this->studentNumberPrefix($offeringData['key']),
                regularCount: $offeringData['regular_students'],
                carryCount: $offeringData['carry_students'],
            );
        }
    }

    protected function seedStudentsForOffering(
        SubjectExamOffering $offering,
        string $prefix,
        int $regularCount,
        int $carryCount,
    ): void {
        $sequence = 1;

        foreach ($this->studentRows($regularCount, ExamStudentType::Regular, $prefix, $sequence, $offering->id) as $row) {
            $this->upsertStudent($offering, $row);
        }

        $sequence += $regularCount;

        foreach ($this->studentRows($carryCount, ExamStudentType::Carry, $prefix, $sequence, $offering->id) as $row) {
            $this->upsertStudent($offering, $row);
        }
    }

    protected function studentRows(
        int $count,
        ExamStudentType $type,
        string $prefix,
        int $startingSequence,
        int $offeringId,
    ): array {
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $sequence = $startingSequence + $index;

            $rows[] = [
                'student_number' => sprintf('%s-%s-%03d', $prefix, strtoupper(substr($type->value, 0, 1)), $sequence),
                'full_name' => $this->generateArabicName($offeringId, $sequence, $type),
                'student_type' => $type->value,
                'notes' => $type === ExamStudentType::Carry ? 'طالب حملة' : null,
            ];
        }

        return $rows;
    }

    protected function upsertStudent(SubjectExamOffering $offering, array $row): void
    {
        $this->upsertRecord(
            ExamStudent::class,
            [
                'subject_exam_offering_id' => $offering->id,
                'student_number' => $row['student_number'],
            ],
            [
                'full_name' => $row['full_name'],
                'student_type' => $row['student_type'],
                'notes' => $row['notes'],
            ],
        );
    }

    protected function studentNumberPrefix(string $key): string
    {
        return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($key)) ?: 'EXAM', 0, 14);
    }

    protected function generateArabicName(int $offeringId, int $sequence, ExamStudentType $type): string
    {
        $salt = $offeringId + $sequence + ($type === ExamStudentType::Carry ? 7 : 0);

        $first = $this->firstNames[$salt % count($this->firstNames)];
        $middle = $this->middleNames[($salt * 2) % count($this->middleNames)];
        $third = $this->middleNames[($salt * 3 + 1) % count($this->middleNames)];
        $family = $this->familyNames[($salt * 5 + 2) % count($this->familyNames)];

        return implode(' ', [$first, $middle, $third, $family]);
    }
}
