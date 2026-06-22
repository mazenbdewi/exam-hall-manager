<?php

namespace Tests\Feature;

use App\Enums\ExamOfferingStatus;
use App\Filament\Pages\ReportsDashboard;
use App\Filament\Resources\FixedExamPrograms\FixedExamProgramResource;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\FixedExamProgram;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ExamScheduleGeneratorService;
use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExamSchedulePrintTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function approving_draft_saves_immutable_fixed_program_snapshot_that_can_be_printed_later(): void
    {
        SystemSetting::current()->update(['university_name' => 'جامعة اللاذقية']);
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المعلوماتية', 'is_active' => true]);
        $firstLevel = StudyLevel::query()->create(['name' => 'الأولى', 'sort_order' => 1, 'is_active' => true]);
        $secondLevel = StudyLevel::query()->create(['name' => 'الثانية', 'sort_order' => 2, 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الأول', 'sort_order' => 1, 'is_active' => true]);
        $firstSubject = $this->createSubject($college, $department, $firstLevel, 'تحليل رياضي 1');
        $secondSubject = $this->createSubject($college, $department, $secondLevel, 'برمجة 2');
        $draft = ExamScheduleDraft::query()->create([
            'faculty_id' => $college->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-04',
            'status' => 'draft',
            'settings_json' => ['department_id' => $department->id],
        ]);

        $this->createDraftItem($draft, $firstSubject, $department, '2026-05-03', '09:00:00', '11:00:00');
        $this->createDraftItem($draft, $secondSubject, $department, '2026-05-03', '12:00:00', '14:00:00');

        $result = app(ExamScheduleGeneratorService::class)->approveDraft($draft);
        $fixedProgram = FixedExamProgram::query()->firstOrFail();

        $this->assertSame($fixedProgram->id, $result['fixed_program_id']);
        $this->assertDatabaseHas('fixed_exam_programs', [
            'id' => $fixedProgram->id,
            'college_id' => $college->id,
            'department_id' => $department->id,
            'academic_year' => '2025-2026',
            'semester' => 'الفصل الأول',
            'status' => 'fixed',
        ]);
        $this->assertSame('جامعة اللاذقية', data_get($fixedProgram->snapshot_data, 'meta.university_name'));
        $this->assertSame('قسم المعلوماتية', data_get($fixedProgram->snapshot_data, 'meta.department_name'));
        $this->assertSame('تحليل رياضي 1', data_get($fixedProgram->snapshot_data, 'entries.0.subject_name'));
        $this->assertSame('الأحد', data_get($fixedProgram->snapshot_data, 'rows.0.day_name'));
        $this->assertSame('2026-05-03', data_get($fixedProgram->snapshot_data, 'rows.0.exam_date'));
        $this->assertSame('الأولى', data_get($fixedProgram->snapshot_data, 'rows.0.cells.'.$firstLevel->id.'.0.subject_level'));

        $department->update(['name' => 'قسم معدل']);
        $college->update(['name' => 'كلية معدلة']);
        $academicYear->update(['name' => '2030-2031']);
        $semester->update(['name' => 'فصل معدل']);
        $firstSubject->update(['name' => 'تحليل معدل']);
        Storage::disk('public')->put('settings/university/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
        ));
        SystemSetting::current()->update([
            'university_name' => 'جامعة معدلة',
            'university_logo' => 'settings/university/logo.png',
        ]);

        $user = User::factory()->create(['college_id' => $college->id]);
        $user->assignRole(Role::findOrCreate(RoleNames::ADMIN, 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('view', 'FixedExamProgram'), 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'FixedExamProgram'), 'web'));
        $user->givePermissionTo(Permission::findOrCreate('view_exam_schedule_generator', 'web'));

        $response = $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.fixed-exam-programs.print', ['fixedExamProgram' => $fixedProgram]));

        $response
            ->assertOk()
            ->assertSee('برنامج امتحان الفصل الأول للعام الدراسي 2025-2026')
            ->assertSee('جامعة معدلة')
            ->assertSee('data:image/png;base64', false)
            ->assertSee('كلية الهندسة')
            ->assertSee('قسم المعلوماتية')
            ->assertDontSee('اسم الجامعة')
            ->assertDontSee('اسم الكلية')
            ->assertDontSee('اسم القسم')
            ->assertDontSee('وثيقة رسمية')
            ->assertSee('السنة الأولى')
            ->assertSee('السنة الثانية')
            ->assertSee('تحليل رياضي 1')
            ->assertSee('برمجة 2')
            ->assertSee('(9 - 11)')
            ->assertSee('(12 - 14)')
            ->assertSee('رئيس القسم')
            ->assertSee('رئيس الدائرة الامتحانية')
            ->assertSee('عميد الكلية')
            ->assertDontSee('تحليل معدل')
            ->assertDontSee('كلية معدلة')
            ->assertDontSee('قسم معدل')
            ->assertDontSee('اسم الجامعة');

        $pdfResponse = $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.fixed-exam-programs.print', [
                'fixedExamProgram' => $fixedProgram,
                'download' => 1,
            ]));

        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $pdfResponse->streamedContent());

        $this
            ->actingAs($user)
            ->get('/adminpanel/exam-schedule-generator')
            ->assertOk();

        $this
            ->actingAs($user)
            ->get(FixedExamProgramResource::getUrl('index'))
            ->assertOk()
            ->assertSee('البرامج الامتحانية المثبتة');

        $this
            ->actingAs($user)
            ->get(FixedExamProgramResource::getUrl('view', ['record' => $fixedProgram]))
            ->assertOk()
            ->assertSee('برنامج امتحان الفصل الأول للعام الدراسي 2025-2026');
    }

    #[Test]
    public function draft_exam_schedule_can_be_printed_for_a_selected_department(): void
    {
        SystemSetting::current()->update(['university_name' => 'جامعة اللاذقية']);
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $firstDepartment = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المعلوماتية', 'is_active' => true]);
        $secondDepartment = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم الاتصالات', 'is_active' => true]);
        $firstLevel = StudyLevel::query()->create(['name' => 'الأولى', 'sort_order' => 1, 'is_active' => true]);
        $secondLevel = StudyLevel::query()->create(['name' => 'الثانية', 'sort_order' => 2, 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الأول', 'sort_order' => 1, 'is_active' => true]);
        $firstSubject = $this->createSubject($college, $firstDepartment, $firstLevel, 'تحليل رياضي 1');
        $secondSubject = $this->createSubject($college, $secondDepartment, $secondLevel, 'اتصالات 1');
        $draft = ExamScheduleDraft::query()->create([
            'faculty_id' => $college->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-04',
            'status' => 'approved',
            'settings_json' => [],
        ]);

        $this->createDraftItem($draft, $firstSubject, $firstDepartment, '2026-05-03', '09:00:00', '11:00:00');
        $this->createDraftItem($draft, $secondSubject, $secondDepartment, '2026-05-04', '12:00:00', '14:00:00');

        $user = User::factory()->create(['college_id' => $college->id]);
        $user->assignRole(Role::findOrCreate(RoleNames::ADMIN, 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'FixedExamProgram'), 'web'));
        $user->givePermissionTo(Permission::findOrCreate('view_exam_schedule_generator', 'web'));

        $response = $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.exam-schedules.print', [
                'source' => 'draft',
                'college_id' => $college->id,
                'department_id' => $firstDepartment->id,
                'academic_year_id' => $academicYear->id,
                'semester_id' => $semester->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('مسودة البرنامج')
            ->assertSee('قسم المعلوماتية')
            ->assertSee('تحليل رياضي 1')
            ->assertDontSee('اتصالات 1')
            ->assertSee('طباعة مسودة البرنامج');

        $this
            ->actingAs($user)
            ->get('/adminpanel/reports')
            ->assertOk()
            ->assertSee('مسودة البرنامج')
            ->assertSee('القسم');

        $pdfResponse = $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.exam-schedules.print', [
                'source' => 'draft',
                'college_id' => $college->id,
                'department_id' => $firstDepartment->id,
                'academic_year_id' => $academicYear->id,
                'semester_id' => $semester->id,
                'download' => 1,
            ]));

        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $pdfResponse->streamedContent());
    }

    #[Test]
    public function draft_print_link_opens_latest_matching_draft_without_year_or_semester_filters(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المعلوماتية', 'is_active' => true]);
        $level = StudyLevel::query()->create(['name' => 'الأولى', 'sort_order' => 1, 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        Semester::query()->create(['name' => 'الفصل الأول', 'sort_order' => 1, 'is_active' => true]);
        $secondSemester = Semester::query()->create(['name' => 'الفصل الثاني', 'sort_order' => 2, 'is_active' => true]);
        $subject = $this->createSubject($college, $department, $level, 'برمجة متقدمة');
        $draft = ExamScheduleDraft::query()->create([
            'faculty_id' => $college->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $secondSemester->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'status' => ExamScheduleDraft::STATUS_COMPLETED,
            'settings_json' => [],
        ]);

        $this->createDraftItem($draft, $subject, $department, '2026-06-01', '09:00:00', '11:00:00');

        $user = User::factory()->create(['college_id' => $college->id]);
        $user->assignRole(Role::findOrCreate(RoleNames::ADMIN, 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

        $response = $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.exam-schedules.print', [
                'source' => 'draft',
                'draft_id' => $draft->id,
                'college_id' => $college->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('مسودة البرنامج')
            ->assertSee('الفصل الثاني')
            ->assertSee('برمجة متقدمة');

        $dashboard = new ReportsDashboard;
        $dashboard->college_id = $college->id;

        $this->assertStringContainsString('draft_id='.$draft->id, $dashboard->draftExamSchedulePrintUrl());
    }

    #[Test]
    public function draft_print_rejects_incomplete_or_failed_draft_id(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الثاني', 'sort_order' => 2, 'is_active' => true]);
        $failedDraft = ExamScheduleDraft::query()->create([
            'faculty_id' => $college->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'status' => ExamScheduleDraft::STATUS_FAILED,
            'summary_json' => ['status' => 'failed'],
        ]);
        $user = User::factory()->create(['college_id' => $college->id]);
        $user->assignRole(Role::findOrCreate(RoleNames::ADMIN, 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

        $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.exam-schedules.print', [
                'source' => 'draft',
                'draft_id' => $failedDraft->id,
                'college_id' => $college->id,
                'academic_year_id' => $academicYear->id,
                'semester_id' => $semester->id,
            ]))
            ->assertStatus(422)
            ->assertSee('لا يمكن طباعة هذه المسودة لأنها غير مكتملة أو فشل توليدها');
    }

    #[Test]
    public function cleanup_drafts_command_deletes_only_incomplete_drafts_and_preserves_completed_approved_and_pinned_offerings(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المعلوماتية', 'is_active' => true]);
        $level = StudyLevel::query()->create(['name' => 'الأولى', 'sort_order' => 1, 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الثاني', 'sort_order' => 2, 'is_active' => true]);
        $subject = $this->createSubject($college, $department, $level, 'خوارزميات');
        $failedDraft = ExamScheduleDraft::query()->create([
            'faculty_id' => $college->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'status' => ExamScheduleDraft::STATUS_FAILED,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
        $completedDraft = ExamScheduleDraft::query()->create([
            'faculty_id' => $college->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'status' => ExamScheduleDraft::STATUS_COMPLETED,
            'summary_json' => ['status' => 'success'],
        ]);
        $approvedDraft = ExamScheduleDraft::query()->create([
            'faculty_id' => $college->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'status' => ExamScheduleDraft::STATUS_APPROVED,
        ]);
        $this->createDraftItem($completedDraft, $subject, $department, '2026-06-01', '09:00:00', '11:00:00');
        $this->createDraftItem($approvedDraft, $subject, $department, '2026-06-02', '09:00:00', '11:00:00');
        $pinnedOffering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'exam_schedule_draft_id' => $failedDraft->id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'is_pinned' => true,
            'status' => ExamOfferingStatus::Draft,
        ]);

        Artisan::call('exam-schedules:cleanup-drafts', ['--dry-run' => true]);
        $this->assertDatabaseHas('exam_schedule_drafts', ['id' => $failedDraft->id]);

        Artisan::call('exam-schedules:cleanup-drafts');

        $this->assertDatabaseMissing('exam_schedule_drafts', ['id' => $failedDraft->id]);
        $this->assertDatabaseHas('exam_schedule_drafts', ['id' => $completedDraft->id]);
        $this->assertDatabaseHas('exam_schedule_drafts', ['id' => $approvedDraft->id]);
        $this->assertTrue($pinnedOffering->refresh()->is_pinned);
        $this->assertSame('2026-06-01', $pinnedOffering->exam_date?->toDateString());
        $this->assertSame('09:00:00', substr((string) $pinnedOffering->exam_start_time, 0, 8));
    }

    #[Test]
    public function manually_entered_draft_offerings_can_be_printed_as_draft_schedule(): void
    {
        $college = College::query()->create(['name' => 'كلية الاقتصاد', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المحاسبة', 'is_active' => true]);
        $level = StudyLevel::query()->create(['name' => 'الأولى', 'sort_order' => 1, 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الثاني', 'sort_order' => 2, 'is_active' => true]);
        $draftSubject = $this->createSubject($college, $department, $level, 'مبادئ محاسبة 2');
        $readySubject = $this->createSubject($college, $department, $level, 'إدارة مالية');

        SubjectExamOffering::query()->create([
            'subject_id' => $draftSubject->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'exam_date' => '2026-07-13',
            'exam_start_time' => '09:30:00',
            'status' => ExamOfferingStatus::Draft,
        ]);

        SubjectExamOffering::query()->create([
            'subject_id' => $readySubject->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'exam_date' => '2026-07-14',
            'exam_start_time' => '09:30:00',
            'status' => ExamOfferingStatus::Ready,
        ]);

        $user = User::factory()->create(['college_id' => $college->id]);
        $user->assignRole(Role::findOrCreate(RoleNames::ADMIN, 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

        $response = $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.exam-schedules.print', [
                'source' => 'draft',
                'college_id' => $college->id,
                'academic_year_id' => $academicYear->id,
                'semester_id' => $semester->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('مسودة البرنامج')
            ->assertSee('كلية الاقتصاد')
            ->assertSee('الفصل الثاني')
            ->assertSee('مبادئ محاسبة 2')
            ->assertDontSee('إدارة مالية');

        $pdfResponse = $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.exam-schedules.print', [
                'source' => 'draft',
                'college_id' => $college->id,
                'academic_year_id' => $academicYear->id,
                'semester_id' => $semester->id,
                'download' => 1,
            ]));

        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $pdfResponse->streamedContent());
    }

    protected function createSubject(College $college, Department $department, StudyLevel $studyLevel, string $name): Subject
    {
        return Subject::query()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'study_level_id' => $studyLevel->id,
            'name' => $name,
            'is_active' => true,
            'is_shared_subject' => false,
            'shared_subject_scheduling_mode' => 'auto',
            'is_core_subject' => false,
            'preferred_exam_period' => 'none',
            'core_subject_priority' => 'preference',
        ]);
    }

    protected function createDraftItem(
        ExamScheduleDraft $draft,
        Subject $subject,
        Department $department,
        string $examDate,
        string $startTime,
        string $endTime,
    ): ExamScheduleDraftItem {
        return ExamScheduleDraftItem::query()->create([
            'exam_schedule_draft_id' => $draft->id,
            'subject_id' => $subject->id,
            'department_id' => $department->id,
            'exam_date' => $examDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'period_type' => 'morning',
            'student_count' => 0,
            'regular_count' => 0,
            'carry_count' => 0,
            'is_shared_subject' => false,
            'is_core_subject' => false,
            'status' => 'scheduled',
        ]);
    }
}
