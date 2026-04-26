<?php

namespace Database\Seeders;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TwoWeekExamScheduleWithStudentsSeeder extends Seeder
{
    protected array $firstNames = [
        'أحمد', 'محمد', 'سارة', 'لين', 'نور', 'رامي', 'هبة', 'يزن', 'مازن', 'سامر',
        'خالد', 'علي', 'مريم', 'رنا', 'عبد الرحمن', 'ليث', 'كنان', 'جود', 'رغد', 'كريم',
    ];

    protected array $middleNames = [
        'محمد', 'خالد', 'محمود', 'أحمد', 'سامر', 'علي', 'فادي', 'مازن', 'حسن', 'إبراهيم',
        'مصطفى', 'يوسف', 'عبد الله', 'رامي', 'نادر', 'وليد', 'طارق', 'باسل', 'جمال', 'هاني',
    ];

    protected array $familyNames = [
        'العلي', 'حسن', 'إبراهيم', 'يوسف', 'الخطيب', 'سليمان', 'الحسن', 'مصطفى', 'الأحمد', 'النجار',
        'حداد', 'المصري', 'الحلبي', 'الشامي', 'القاسم', 'المحمود', 'الصفدي', 'المرعي', 'الخضر', 'العباس',
    ];

    public function run(): void
    {
        $academicYear = AcademicYear::query()->updateOrCreate(
            ['name' => '2025-2026'],
            ['is_active' => true, 'is_current' => true],
        );
        $semester = Semester::query()->updateOrCreate(
            ['name' => 'الفصل الثاني'],
            ['sort_order' => 2, 'is_active' => true],
        );
        $studyLevel = StudyLevel::query()->updateOrCreate(
            ['name' => 'السنة الثالثة'],
            ['sort_order' => 3, 'is_active' => true],
        );

        $colleges = $this->demoColleges();
        $examDays = $this->twoWeekExamDays();
        $times = ['09:00:00', '12:00:00'];

        $offeringCount = 0;
        $studentCount = 0;
        $subjectCount = 0;
        $hallCount = 0;

        foreach ($colleges as $collegeSpec) {
            $college = College::query()->updateOrCreate(
                ['code' => $collegeSpec['code']],
                ['name' => $collegeSpec['name'], 'is_active' => true],
            );

            $departments = $this->upsertDepartments($college, $collegeSpec['departments']);
            $subjects = $this->upsertSubjects($college, $departments, $studyLevel, $collegeSpec['subjects']);
            $subjectCount += count($subjects);
            $hallCount += $this->ensureHalls($college);

            $slots = $this->buildCollegeSlots($subjects, $examDays, $times);

            foreach ($slots as $slotIndex => $slot) {
                foreach ($slot['subjects'] as $subjectIndex => $subject) {
                    $offering = SubjectExamOffering::query()->updateOrCreate(
                        [
                            'subject_id' => $subject->id,
                            'academic_year_id' => $academicYear->id,
                            'semester_id' => $semester->id,
                            'exam_date' => $slot['date']->toDateString(),
                            'exam_start_time' => $slot['time'],
                        ],
                        [
                            'status' => ExamOfferingStatus::Ready->value,
                            'notes' => 'بيانات تجريبية لاختبار التوزيع الشامل للطلاب والمراقبين.',
                        ],
                    );

                    $offeringCount++;
                    $studentCount += $this->seedStudentsForOffering(
                        offering: $offering,
                        collegeCode: $collegeSpec['code'],
                        count: $this->studentCountFor($slotIndex, $subjectIndex),
                    );
                }
            }
        }

        $this->printSummary(
            collegesCount: count($colleges),
            offeringsCount: $offeringCount,
            studentsCount: $studentCount,
            subjectsCount: $subjectCount,
            hallsCount: $hallCount,
            fromDate: $examDays[0]->toDateString(),
            toDate: $examDays[array_key_last($examDays)]->toDateString(),
        );
    }

    protected function demoColleges(): array
    {
        return [
            [
                'code' => 'ENG',
                'name' => 'كلية الهندسة المعلوماتية',
                'departments' => [
                    ['code' => 'SW', 'name' => 'قسم البرمجيات'],
                    ['code' => 'NET', 'name' => 'قسم الشبكات'],
                ],
                'subjects' => [
                    ['code' => 'ENG-PROG1', 'name' => 'البرمجة 1', 'department_code' => 'SW'],
                    ['code' => 'ENG-DB', 'name' => 'قواعد البيانات', 'department_code' => 'SW'],
                    ['code' => 'ENG-OS', 'name' => 'نظم التشغيل', 'department_code' => 'SW'],
                    ['code' => 'ENG-NET', 'name' => 'الشبكات', 'department_code' => 'NET'],
                    ['code' => 'ENG-ALG', 'name' => 'تحليل الخوارزميات', 'department_code' => 'SW'],
                ],
            ],
            [
                'code' => 'ECO',
                'name' => 'كلية الاقتصاد',
                'departments' => [
                    ['code' => 'ACC', 'name' => 'قسم المحاسبة'],
                    ['code' => 'BUS', 'name' => 'قسم إدارة الأعمال'],
                ],
                'subjects' => [
                    ['code' => 'ECO-ACC1', 'name' => 'مبادئ المحاسبة', 'department_code' => 'ACC'],
                    ['code' => 'ECO-MICRO', 'name' => 'الاقتصاد الجزئي', 'department_code' => 'BUS'],
                    ['code' => 'ECO-BUS', 'name' => 'إدارة الأعمال', 'department_code' => 'BUS'],
                    ['code' => 'ECO-STAT', 'name' => 'الإحصاء', 'department_code' => 'ACC'],
                    ['code' => 'ECO-MKT', 'name' => 'التسويق', 'department_code' => 'BUS'],
                ],
            ],
            [
                'code' => 'SCI',
                'name' => 'كلية العلوم',
                'departments' => [
                    ['code' => 'MATH', 'name' => 'قسم الرياضيات'],
                    ['code' => 'PHY', 'name' => 'قسم الفيزياء'],
                ],
                'subjects' => [
                    ['code' => 'SCI-ANALYSIS', 'name' => 'تحليل رياضي', 'department_code' => 'MATH'],
                    ['code' => 'SCI-LINEAR', 'name' => 'جبر خطي', 'department_code' => 'MATH'],
                    ['code' => 'SCI-PHYSICS', 'name' => 'فيزياء عامة', 'department_code' => 'PHY'],
                    ['code' => 'SCI-CHEM', 'name' => 'كيمياء عامة', 'department_code' => 'PHY'],
                    ['code' => 'SCI-STAT', 'name' => 'إحصاء تطبيقي', 'department_code' => 'MATH'],
                ],
            ],
        ];
    }

    protected function upsertDepartments(College $college, array $departmentSpecs): array
    {
        $departments = [];

        foreach ($departmentSpecs as $departmentSpec) {
            $departments[$departmentSpec['code']] = Department::query()->updateOrCreate(
                ['college_id' => $college->id, 'code' => $departmentSpec['code']],
                ['name' => $departmentSpec['name'], 'is_active' => true],
            );
        }

        return $departments;
    }

    protected function upsertSubjects(College $college, array $departments, StudyLevel $studyLevel, array $subjectSpecs): array
    {
        $subjects = [];

        foreach ($subjectSpecs as $subjectSpec) {
            $department = $departments[$subjectSpec['department_code']];
            $subjects[] = Subject::query()->updateOrCreate(
                ['code' => $subjectSpec['code']],
                [
                    'college_id' => $college->id,
                    'department_id' => $department->id,
                    'study_level_id' => $studyLevel->id,
                    'name' => $subjectSpec['name'],
                    'is_active' => true,
                ],
            );
        }

        return $subjects;
    }

    protected function ensureHalls(College $college): int
    {
        $halls = [
            ['name' => 'المدرج الأول', 'location' => 'المبنى الرئيسي', 'capacity' => 220, 'hall_type' => ExamHallType::Amphitheater->value, 'priority' => ExamHallPriority::High->value],
            ['name' => 'المدرج الثاني', 'location' => 'المبنى الرئيسي', 'capacity' => 180, 'hall_type' => ExamHallType::Amphitheater->value, 'priority' => ExamHallPriority::High->value],
            ['name' => 'القاعة 101', 'location' => 'الطابق الأول', 'capacity' => 80, 'hall_type' => ExamHallType::Large->value, 'priority' => ExamHallPriority::Medium->value],
            ['name' => 'القاعة 102', 'location' => 'الطابق الأول', 'capacity' => 60, 'hall_type' => ExamHallType::Small->value, 'priority' => ExamHallPriority::Medium->value],
            ['name' => 'القاعة 201', 'location' => 'الطابق الثاني', 'capacity' => 45, 'hall_type' => ExamHallType::Small->value, 'priority' => ExamHallPriority::Low->value],
        ];

        foreach ($halls as $hall) {
            ExamHall::query()->updateOrCreate(
                ['college_id' => $college->id, 'name' => $hall['name']],
                [...$hall, 'is_active' => true],
            );
        }

        return count($halls);
    }

    protected function twoWeekExamDays(): array
    {
        $date = Carbon::now()->next(Carbon::SUNDAY);
        $days = [];

        while (count($days) < 10) {
            if (! $date->isFriday() && ! $date->isSaturday()) {
                $days[] = $date->copy();
            }

            $date->addDay();
        }

        return $days;
    }

    protected function buildCollegeSlots(array $subjects, array $examDays, array $times): array
    {
        $slots = [];
        $subjectCount = count($subjects);

        foreach ($examDays as $dayIndex => $date) {
            foreach ($times as $timeIndex => $time) {
                $base = (($dayIndex * count($times)) + $timeIndex) % $subjectCount;
                $subjectsInSlot = [
                    $subjects[$base],
                    $subjects[($base + 1) % $subjectCount],
                ];

                if (($dayIndex + $timeIndex) % 3 === 0) {
                    $subjectsInSlot[] = $subjects[($base + 2) % $subjectCount];
                }

                $slots[] = [
                    'date' => $date,
                    'time' => $time,
                    'subjects' => collect($subjectsInSlot)->unique('id')->values()->all(),
                ];
            }
        }

        return $slots;
    }

    protected function studentCountFor(int $slotIndex, int $subjectIndex): int
    {
        $counts = [45, 58, 92, 110, 155, 185, 210, 78, 125, 165];

        return $counts[($slotIndex + $subjectIndex) % count($counts)];
    }

    protected function seedStudentsForOffering(SubjectExamOffering $offering, string $collegeCode, int $count): int
    {
        $regularCount = (int) floor($count * 0.8);
        $carryCount = $count - $regularCount;
        $rows = [];
        $sequence = 1;
        $now = now();

        foreach ([ExamStudentType::Regular->value => $regularCount, ExamStudentType::Carry->value => $carryCount] as $type => $typeCount) {
            for ($index = 0; $index < $typeCount; $index++, $sequence++) {
                $rows[] = [
                    'subject_exam_offering_id' => $offering->id,
                    'student_number' => sprintf('%s2026%04d%04d', $collegeCode, $offering->id, $sequence),
                    'full_name' => $this->studentName($offering->id, $sequence),
                    'student_type' => $type,
                    'notes' => $type === ExamStudentType::Carry->value ? 'طالب حملة' : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        collect($rows)
            ->chunk(500)
            ->each(fn ($chunk) => ExamStudent::query()->upsert(
                $chunk->all(),
                ['subject_exam_offering_id', 'student_number'],
                ['full_name', 'student_type', 'notes', 'updated_at'],
            ));

        return count($rows);
    }

    protected function studentName(int $offeringId, int $sequence): string
    {
        $salt = $offeringId + $sequence;

        return implode(' ', [
            $this->firstNames[$salt % count($this->firstNames)],
            $this->middleNames[($salt * 2) % count($this->middleNames)],
            $this->middleNames[($salt * 3 + 1) % count($this->middleNames)],
            $this->familyNames[($salt * 5 + 2) % count($this->familyNames)],
        ]);
    }

    protected function printSummary(int $collegesCount, int $offeringsCount, int $studentsCount, int $subjectsCount, int $hallsCount, string $fromDate, string $toDate): void
    {
        $this->command?->info('تم إنشاء بيانات تجريبية لبرنامج امتحاني لمدة أسبوعين.');
        $this->command?->info('عدد الكليات: '.$collegesCount);
        $this->command?->info('عدد البرامج الامتحانية: '.$offeringsCount);
        $this->command?->info('عدد الطلاب: '.$studentsCount);
        $this->command?->info('عدد المواد: '.$subjectsCount);
        $this->command?->info('عدد القاعات: '.$hallsCount);
        $this->command?->info("الفترة الامتحانية من {$fromDate} إلى {$toDate}");
    }
}
