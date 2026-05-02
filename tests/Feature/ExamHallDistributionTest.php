<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Exports\StudentDistributionUnassignedExport;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\ExamStudentHallAssignment;
use App\Models\HallAssignment;
use App\Models\StudentDistributionRun;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Services\ExamHallDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExamHallDistributionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_distributes_students_per_exam_slot_without_exceeding_three_subjects_per_hall(): void
    {
        $context = $this->createAcademicContext();

        $slotOfferings = collect([
            $this->createOfferingWithStudents($context, 'تحليل', 35),
            $this->createOfferingWithStudents($context, 'فيزياء', 35),
            $this->createOfferingWithStudents($context, 'جبر', 35),
            $this->createOfferingWithStudents($context, 'برمجة', 35),
        ]);

        $otherSlotOffering = $this->createOfferingWithStudents($context, 'خارج الجلسة', 20, startTime: '12:00:00');

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'القاعة A',
            'location' => 'المبنى الأول',
            'capacity' => 80,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'القاعة B',
            'location' => 'المبنى الثاني',
            'capacity' => 80,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::Medium->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($slotOfferings->first());

        $this->assertSame('success', $result['status']);
        $this->assertSame(140, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertSame(2, $result['used_halls_count']);

        $hallAssignments = HallAssignment::query()
            ->with(['assignmentSubjects', 'studentAssignments'])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $hallAssignments);
        $this->assertTrue($hallAssignments->every(fn (HallAssignment $assignment): bool => $assignment->assignmentSubjects->count() <= 3));
        $this->assertTrue($hallAssignments->every(fn (HallAssignment $assignment): bool => $assignment->assigned_students_count <= $assignment->total_capacity));
        $this->assertSame(140, ExamStudentHallAssignment::query()->count());
        $hallAssignments->each(function (HallAssignment $assignment): void {
            $seatNumbers = $assignment->studentAssignments->pluck('seat_number');

            $this->assertFalse($seatNumbers->contains(null));
            $this->assertSame($seatNumbers->count(), $seatNumbers->unique()->count());
            $this->assertSame(range(1, $seatNumbers->count()), $seatNumbers->sort()->values()->all());
        });
        $this->assertSame(0, $otherSlotOffering->studentHallAssignments()->count());

        $slotOfferings->each(function (SubjectExamOffering $offering): void {
            $this->assertSame(ExamOfferingStatus::Distributed, $offering->fresh()->status);
        });

        $this->assertSame(ExamOfferingStatus::Draft, $otherSlotOffering->fresh()->status);
    }

    #[Test]
    public function it_reports_unassigned_students_when_halls_are_insufficient(): void
    {
        $context = $this->createAcademicContext();

        $slotOffering = $this->createOfferingWithStudents($context, 'إحصاء', 130);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'القاعة المحدودة',
            'location' => 'الطابق الأرضي',
            'capacity' => 80,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($slotOffering);

        $this->assertSame('warning', $result['status']);
        $this->assertSame(80, $result['assigned_students_count']);
        $this->assertSame(50, $result['unassigned_students_count']);
        $this->assertSame(1, $result['used_halls_count']);
        $this->assertSame(80, ExamStudentHallAssignment::query()->count());
        $this->assertSame(ExamOfferingStatus::Ready, $slotOffering->fresh()->status);
    }

    #[Test]
    public function it_keeps_existing_mixed_hall_behavior_when_carry_separation_is_disabled(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'مادة مختلطة',
            studentsCount: 6,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة واحدة',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($offering);
        $summary = app(ExamHallDistributionService::class)->getSlotSummary($offering);

        $this->assertSame('success', $result['status']);
        $this->assertSame('mixed', $summary['hall_assignments'][0]['hall_student_type_key']);
    }

    #[Test]
    public function it_separates_carry_students_from_regular_students_when_capacity_allows(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'مادة فصل الحملة',
            studentsCount: 8,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة حملة صغيرة',
            'location' => 'المبنى الأول',
            'capacity' => 2,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة مستجدين',
            'location' => 'المبنى الثاني',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($offering, separateCarryStudents: true);
        $summary = app(ExamHallDistributionService::class)->getSlotSummary($offering);
        $hallTypes = collect($summary['hall_assignments'])->pluck('hall_student_type_key')->sort()->values()->all();

        $this->assertSame('success', $result['status']);
        $this->assertSame(['carry_only', 'regular_only'], $hallTypes);
        $this->assertSame(0, collect($summary['hall_assignments'])->where('hall_student_type_key', 'mixed')->count());
    }

    #[Test]
    public function it_records_a_warning_when_carry_separation_requires_fallback_mixing(): void
    {
        $context = $this->createAcademicContext();
        $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'مادة سعة غير كافية للفصل',
            studentsCount: 8,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة وحيدة',
            'location' => 'المبنى الأول',
            'capacity' => 8,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
            separateCarryStudents: true,
        );

        $this->assertSame('partial', $result['status']);
        $this->assertTrue($result['separate_carry_students']);
        $this->assertSame(2, $result['carry_students_count']);
        $this->assertSame(6, $result['regular_students_count']);
        $this->assertSame(1, $result['mixed_halls_count']);
        $this->assertSame(1, $result['carry_regular_mixing_cases_count']);
        $this->assertStringContainsString('لم يتم فصل جميع طلاب الحملة', $result['separation_status_message']);
        $this->assertDatabaseHas('student_distribution_run_issues', [
            'issue_type' => 'carry_regular_mixed_due_to_capacity',
        ]);
    }

    #[Test]
    public function distribution_reports_include_student_type_and_hall_classification(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'مادة تقرير الفصل',
            studentsCount: 4,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة حملة',
            'location' => 'المبنى الأول',
            'capacity' => 2,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة مستجدين',
            'location' => 'المبنى الثاني',
            'capacity' => 2,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForOffering($offering, separateCarryStudents: true);
        $summary = app(ExamHallDistributionService::class)->getSlotSummary($offering);
        $html = view('pdf.hall-distribution-slot', [
            'summary' => $summary,
            'systemSetting' => (object) ['university_name' => 'جامعة الاختبار'],
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('نوع الطالب', $html);
        $this->assertStringContainsString('قاعة طلاب حملة', $html);
        $this->assertStringContainsString('قاعة طلاب مستجدين', $html);
        $this->assertContains('نوع الطالب', (new StudentDistributionUnassignedExport(new \App\Models\StudentDistributionRun))->headings());
    }

    #[Test]
    public function global_distribution_run_keeps_unassigned_snapshot_for_reports_even_if_live_data_changes(): void
    {
        $context = $this->createAcademicContext();

        $offering = $this->createOfferingWithStudents($context, 'هياكل', 5);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة أولى',
            'location' => 'المبنى الأول',
            'capacity' => 3,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        /** @var StudentDistributionRun $firstRun */
        $firstRun = StudentDistributionRun::query()->oldest('id')->firstOrFail();
        $this->assertSame('partial', $firstRun->status);
        $this->assertSame(2, $firstRun->unassigned_students);
        $this->assertCount(2, $firstRun->summary_json['validation']['unassigned_students_list'] ?? []);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة ثانية',
            'location' => 'المبنى الثاني',
            'capacity' => 5,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
        );

        $this->assertSame(0, ExamStudent::query()->whereDoesntHave('hallAssignment')->count());
        $this->assertCount(2, app(ExamHallDistributionService::class)->unassignedStudentsForRun($firstRun->fresh('issues')));
        $this->assertSame(2, count($firstRun->fresh()->summary_json['validation']['unassigned_students_list'] ?? []));
        $this->assertSame($offering->id, $firstRun->fresh()->summary_json['validation']['unassigned_students_list'][0]['subject_exam_offering_id'] ?? null);
    }

    #[Test]
    public function global_distribution_run_stores_final_validation_based_on_real_assignments(): void
    {
        $context = $this->createAcademicContext();

        foreach (['تحليل', 'جبر', 'فيزياء', 'برمجة'] as $subjectName) {
            $this->createOfferingWithStudents($context, $subjectName, 1);
        }

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة واحدة',
            'location' => 'المبنى الأول',
            'capacity' => 4,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $run = StudentDistributionRun::query()->latest('id')->firstOrFail();

        $this->assertSame('partial', $result['status']);
        $this->assertSame('partial', $run->status);
        $this->assertSame(0, $result['capacity_shortage']);
        $this->assertSame(4, $run->summary_json['validation']['expected_students'] ?? null);
        $this->assertSame(3, $run->summary_json['validation']['assigned_students'] ?? null);
        $this->assertSame(1, $run->summary_json['validation']['unassigned_students'] ?? null);
        $this->assertSame(4, $run->summary_json['validation']['used_hall_capacity'] ?? null);
        $this->assertSame(1, $run->summary_json['validation']['remaining_capacity'] ?? null);
    }

    protected function createAcademicContext(): array
    {
        $college = College::query()->create([
            'name' => 'كلية الهندسة',
            'is_active' => true,
        ]);

        $department = Department::query()->create([
            'college_id' => $college->id,
            'name' => 'قسم المعلوماتية',
            'is_active' => true,
        ]);

        $studyLevel = StudyLevel::query()->create([
            'name' => 'السنة الثالثة',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->create([
            'name' => '2025-2026',
            'is_active' => true,
            'is_current' => true,
        ]);

        $semester = Semester::query()->create([
            'name' => 'الفصل الثاني',
            'is_active' => true,
        ]);

        return [
            'college' => $college,
            'department' => $department,
            'study_level' => $studyLevel,
            'academic_year' => $academicYear,
            'semester' => $semester,
        ];
    }

    protected function createOfferingWithStudents(
        array $context,
        string $subjectName,
        int $studentsCount,
        string $date = '2026-06-01',
        string $startTime = '09:00:00',
        array|string|ExamStudentType $studentTypes = ExamStudentType::Regular,
    ): SubjectExamOffering {
        $subject = Subject::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $context['department']->id,
            'study_level_id' => $context['study_level']->id,
            'name' => $subjectName,
            'is_active' => true,
        ]);

        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => $date,
            'exam_start_time' => $startTime,
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        for ($index = 1; $index <= $studentsCount; $index++) {
            $studentType = is_array($studentTypes)
                ? ($studentTypes[$index - 1] ?? ExamStudentType::Regular->value)
                : $studentTypes;
            $studentType = $studentType instanceof ExamStudentType ? $studentType->value : $studentType;

            ExamStudent::query()->create([
                'subject_exam_offering_id' => $offering->id,
                'student_number' => sprintf('%s-%03d', $offering->id, $index),
                'full_name' => sprintf('طالب %s %03d', $subjectName, $index),
                'student_type' => $studentType,
            ]);
        }

        return $offering;
    }
}
