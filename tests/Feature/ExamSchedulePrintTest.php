<?php

namespace Tests\Feature;

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
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ExamScheduleGeneratorService;
use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'status' => 'generated',
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
            'status' => 'generated',
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
                'college_id' => $college->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('مسودة البرنامج')
            ->assertSee('الفصل الثاني')
            ->assertSee('برمجة متقدمة');
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
