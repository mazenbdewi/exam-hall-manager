<?php

namespace Tests\Feature;

use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamStudent;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\User;
use App\Services\ExamScheduleGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExamScheduleGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_and_approves_a_schedule_without_using_existing_students_or_old_offerings(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        $subject = Subject::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $context['department']->id,
            'study_level_id' => $context['study_level']->id,
            'name' => 'تحليل رياضي',
            'is_active' => true,
        ]);

        $oldOffering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-04-01',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        ExamStudent::query()->create([
            'subject_exam_offering_id' => $oldOffering->id,
            'student_number' => 'S-001',
            'full_name' => 'طالب قديم',
            'student_type' => ExamStudentType::Regular->value,
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft([
            'faculty_id' => $context['college']->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-07',
            'excluded_weekdays' => [5, 6],
            'holidays' => [],
            'periods' => [
                ['name' => 'الفترة الأولى', 'start_time' => '09:00', 'end_time' => '11:00'],
            ],
        ]);

        $item = $draft->items()->firstOrFail();

        $this->assertSame(0, $item->student_count);
        $this->assertArrayNotHasKey('source_offering_id', $item->metadata ?? []);
        $this->assertArrayNotHasKey('student_numbers', $item->metadata ?? []);

        $result = app(ExamScheduleGeneratorService::class)->approveDraft($draft);

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['created_count']);
        $this->assertSame(0, $result['updated_count']);

        $newOffering = SubjectExamOffering::query()
            ->where('subject_id', $subject->id)
            ->where('exam_schedule_draft_id', $draft->id)
            ->firstOrFail();

        $this->assertNotSame($oldOffering->id, $newOffering->id);
        $this->assertSame('2026-05-03', $newOffering->exam_date->toDateString());
        $this->assertSame('09:00:00', (string) $newOffering->exam_start_time);
        $this->assertSame(0, $newOffering->examStudents()->count());
        $this->assertSame(1, $oldOffering->examStudents()->count());
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
}
