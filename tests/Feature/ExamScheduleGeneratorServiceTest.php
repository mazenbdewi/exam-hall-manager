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
use App\Models\SubjectExamRoster;
use App\Models\User;
use App\Services\ExamScheduleGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExamScheduleGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generator_uses_roster_students(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'تحليل رياضي');
        $this->createRoster($context, $subject, [
            ['S-001', 'طالب أول', 'regular'],
            ['S-002', 'طالب ثان', 'carry'],
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $item = $draft->items()->firstOrFail();

        $this->assertSame(2, $item->student_count);
        $this->assertSame(1, $item->regular_count);
        $this->assertSame(1, $item->carry_count);
        $this->assertSame(['S-001', 'S-002'], $item->metadata['student_numbers']);
    }

    #[Test]
    public function generator_does_not_use_subject_exam_offering_students_or_old_fallbacks(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'فيزياء');
        $this->createRoster($context, $subject, [
            ['R-001', 'طالب من القائمة', 'regular'],
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
            'student_number' => 'OLD-001',
            'full_name' => 'طالب قديم',
            'student_type' => ExamStudentType::Regular->value,
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $item = $draft->items()->firstOrFail();

        $this->assertSame(1, $item->student_count);
        $this->assertSame(['R-001'], $item->metadata['student_numbers']);
        $this->assertArrayNotHasKey('source_offering_id', $item->metadata ?? []);
    }

    #[Test]
    public function same_student_same_time_conflict_is_prevented(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'تحليل 1'), [['S-001', 'طالب مشترك', 'regular']]);
        $this->createRoster($context, $this->createSubject($context, 'فيزياء 1'), [['S-001', 'طالب مشترك', 'carry']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $this->assertSame(1, $draft->items()->where('status', 'unscheduled')->count());
    }

    #[Test]
    public function same_student_same_day_conflict_is_prevented_when_enabled(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $this->createRoster($context, $this->createSubject($context, 'تحليل 2'), [['S-001', 'طالب مشترك', 'regular']]);
        $this->createRoster($context, $this->createSubject($context, 'فيزياء 2'), [['S-001', 'طالب مشترك', 'carry']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'prevent_same_day' => true,
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
            ],
        ]));

        $this->assertSame(1, $draft->items()->where('status', 'unscheduled')->count());
    }

    #[Test]
    public function carry_student_conflict_across_years_is_detected(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $secondLevel = StudyLevel::query()->create(['name' => 'السنة الرابعة', 'is_active' => true]);
        $regular = $this->createSubject($context, 'شبكات', ['study_level_id' => $context['study_level']->id]);
        $carry = $this->createSubject($context, 'برمجة قديمة', ['study_level_id' => $secondLevel->id]);
        $this->createRoster($context, $regular, [['S-777', 'طالب حملة', 'regular']]);
        $this->createRoster($context, $carry, [['S-777', 'طالب حملة', 'carry']], ['study_level_id' => $secondLevel->id]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $this->assertSame(1, $draft->items()->where('status', 'unscheduled')->count());
    }

    #[Test]
    public function core_subject_is_preferred_in_morning(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'خوارزميات', [
            'is_core_subject' => true,
            'preferred_exam_period' => 'morning',
        ]);
        $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'periods' => [
                ['name' => 'مسائية', 'start_time' => '15:00', 'end_time' => '17:00', 'period_type' => 'evening'],
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));

        $this->assertSame('morning', $draft->items()->firstOrFail()->period_type);
    }

    #[Test]
    public function strict_core_subject_fails_if_morning_slot_unavailable(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'نظم تشغيل', [
            'is_core_subject' => true,
            'preferred_exam_period' => 'morning',
            'core_subject_priority' => 'strict',
        ]);
        $this->createRoster($context, $subject, [['S-001', 'طالب', 'regular']]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'periods' => [
                ['name' => 'مسائية', 'start_time' => '15:00', 'end_time' => '17:00', 'period_type' => 'evening'],
            ],
        ]));

        $this->assertSame('unscheduled', $draft->items()->firstOrFail()->status);
    }

    #[Test]
    public function shared_subject_all_departments_together_groups_rosters(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $secondDepartment = Department::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قسم الاتصالات',
            'is_active' => true,
        ]);
        $first = $this->createSubject($context, 'ثقافة', [
            'code' => 'SHARED-101',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $second = $this->createSubject($context, 'ثقافة', [
            'department_id' => $secondDepartment->id,
            'code' => 'SHARED-101',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $this->createRoster($context, $first, [['S-001', 'طالب أول', 'regular']]);
        $this->createRoster($context, $second, [['S-002', 'طالب ثان', 'regular']], ['department_id' => $secondDepartment->id]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $items = $draft->items()->get();

        $this->assertCount(2, $items);
        $this->assertSame(1, $items->pluck('shared_group_key')->unique()->count());
        $this->assertSame(1, $items->pluck('exam_date')->unique()->count());
        $this->assertSame(1, $items->pluck('start_time')->unique()->count());
    }

    #[Test]
    public function approval_creates_official_offering_and_copies_roster_students(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));
        $subject = $this->createSubject($context, 'قواعد معطيات');
        $this->createRoster($context, $subject, [
            ['S-001', 'طالب أول', 'regular'],
            ['S-002', 'طالب ثان', 'carry'],
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context));
        $result = app(ExamScheduleGeneratorService::class)->approveDraft($draft);
        $offering = SubjectExamOffering::query()->where('exam_schedule_draft_id', $draft->id)->firstOrFail();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['created_count']);
        $this->assertSame(2, $offering->examStudents()->count());
        $this->assertSame(1, $offering->carryStudents()->count());
    }

    #[Test]
    public function manual_workflow_still_allows_direct_offering_students(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'يدوي');
        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-05-03',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        $offering->examStudents()->create([
            'student_number' => 'M-001',
            'full_name' => 'طالب يدوي',
            'student_type' => ExamStudentType::Regular->value,
        ]);

        $this->assertSame(1, $offering->examStudents()->count());
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

    protected function createSubject(array $context, string $name, array $overrides = []): Subject
    {
        return Subject::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $overrides['department_id'] ?? $context['department']->id,
            'study_level_id' => $overrides['study_level_id'] ?? $context['study_level']->id,
            'name' => $name,
            'code' => $overrides['code'] ?? null,
            'is_active' => true,
            'is_shared_subject' => $overrides['is_shared_subject'] ?? false,
            'shared_subject_scheduling_mode' => $overrides['shared_subject_scheduling_mode'] ?? 'auto',
            'is_core_subject' => $overrides['is_core_subject'] ?? false,
            'preferred_exam_period' => $overrides['preferred_exam_period'] ?? 'none',
            'core_subject_priority' => $overrides['core_subject_priority'] ?? 'preference',
        ]);
    }

    protected function createRoster(array $context, Subject $subject, array $students, array $overrides = []): SubjectExamRoster
    {
        $roster = SubjectExamRoster::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $overrides['department_id'] ?? $subject->department_id,
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'study_level_id' => $overrides['study_level_id'] ?? $subject->study_level_id,
            'status' => 'ready',
            'source' => 'manual',
        ]);

        foreach ($students as [$number, $name, $type]) {
            $roster->rosterStudents()->create([
                'student_number' => $number,
                'full_name' => $name,
                'student_type' => $type,
                'is_eligible' => true,
            ]);
        }

        return $roster;
    }

    protected function settings(array $context, array $overrides = []): array
    {
        return array_replace([
            'faculty_id' => $context['college']->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-07',
            'excluded_weekdays' => [5, 6],
            'holidays' => [],
            'periods' => [
                ['name' => 'الفترة الأولى', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'الفترة الثانية', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
            ],
            'prevent_same_day' => false,
        ], $overrides);
    }
}
