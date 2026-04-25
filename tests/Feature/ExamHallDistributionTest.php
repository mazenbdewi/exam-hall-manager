<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\ExamStudentHallAssignment;
use App\Models\HallAssignment;
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
            ExamStudent::query()->create([
                'subject_exam_offering_id' => $offering->id,
                'student_number' => sprintf('%s-%03d', $offering->id, $index),
                'full_name' => sprintf('طالب %s %03d', $subjectName, $index),
                'student_type' => ExamStudentType::Regular->value,
            ]);
        }

        return $offering;
    }
}
