<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Enums\StaffCategory;
use App\Exceptions\ExamScheduleGenerationException;
use App\Exports\SubjectExamRosterStudentsTemplateExport;
use App\Filament\Pages\ComprehensiveStudentDistribution;
use App\Filament\Pages\ExamScheduleGenerator;
use App\Filament\Resources\SubjectExamOfferings\Pages\ListSubjectExamOfferings;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Filament\Resources\SubjectExamRosters\Pages\EditSubjectExamRoster;
use App\Filament\Resources\SubjectExamRosters\Pages\ListSubjectExamRosters;
use App\Filament\Resources\SubjectExamRosters\RelationManagers\RosterStudentsRelationManager;
use App\Filament\Resources\SubjectExamRosters\SubjectExamRosterResource;
use App\Filament\Resources\Subjects\Pages\EditSubject;
use App\Filament\Resources\Subjects\SubjectResource;
use App\Imports\SubjectExamRosterStudentsImport;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamScheduleDraft;
use App\Models\ExamStudent;
use App\Models\ExamStudentHallAssignment;
use App\Models\HallAssignment;
use App\Models\HallAssignmentSubject;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorHallRequirement;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\SubjectExamRoster;
use App\Models\StudentDistributionRun;
use App\Models\StudentDistributionRunIssue;
use App\Models\User;
use App\Services\ExamHallDistributionService;
use App\Services\ExamScheduleGeneratorService;
use App\Services\InvigilatorDistributionService;
use App\Support\ShieldPermission;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AutomaticExamWorkflowEndToEndTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function automatic_schedule_workflow_runs_from_rosters_to_halls_and_invigilators(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        $core = $this->createSubject($context, 'خوارزميات', [
            'is_core_subject' => true,
            'preferred_exam_period' => 'morning',
            'core_subject_priority' => 'preference',
        ]);
        $normal = $this->createSubject($context, 'فيزياء');
        $allTogetherA = $this->createSubject($context, 'ثقافة مشتركة', [
            'code' => 'SHARED-ALL',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $allTogetherB = $this->createSubject($context, 'ثقافة مشتركة', [
            'department_id' => $context['second_department']->id,
            'code' => 'SHARED-ALL',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'all_departments_together',
        ]);
        $separateA = $this->createSubject($context, 'مهارات منفصلة', [
            'code' => 'SHARED-SEP',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'separate_departments',
        ]);
        $separateB = $this->createSubject($context, 'مهارات منفصلة', [
            'department_id' => $context['second_department']->id,
            'code' => 'SHARED-SEP',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'separate_departments',
        ]);
        $autoA = $this->createSubject($context, 'مقرر تلقائي', [
            'code' => 'SHARED-AUTO',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'auto',
        ]);
        $autoB = $this->createSubject($context, 'مقرر تلقائي', [
            'department_id' => $context['second_department']->id,
            'code' => 'SHARED-AUTO',
            'is_shared_subject' => true,
            'shared_subject_scheduling_mode' => 'auto',
        ]);

        $importedRoster = $this->createRoster($context, $core, [], ['status' => 'draft']);
        $this->importRosterStudents($importedRoster, [
            ['CORE-001', 'طالب مستجد', 'مستجد', 'نعم', ''],
            ['CORE-002', 'طالب حملة', 'حملة', 'نعم', ''],
        ]);

        $this->createRoster($context, $normal, [['N-001', 'طالب فيزياء', 'regular']]);
        $this->createRoster($context, $allTogetherA, [['ALL-A', 'طالب مشترك أ', 'regular']]);
        $this->createRoster($context, $allTogetherB, [['ALL-B', 'طالب مشترك ب', 'regular']], ['department_id' => $context['second_department']->id]);
        $this->createRoster($context, $separateA, [['SEP-A', 'طالب منفصل أ', 'regular']]);
        $this->createRoster($context, $separateB, [['SEP-B', 'طالب منفصل ب', 'regular']], ['department_id' => $context['second_department']->id]);
        $this->createRoster($context, $autoA, [['AUTO-A', 'طالب تلقائي أ', 'regular']]);
        $this->createRoster($context, $autoB, [['AUTO-B', 'طالب تلقائي ب', 'regular']], ['department_id' => $context['second_department']->id]);

        $previousYear = AcademicYear::query()->create(['name' => '2024-2025', 'is_active' => true]);
        $oldOffering = SubjectExamOffering::query()->create([
            'subject_id' => $core->id,
            'academic_year_id' => $previousYear->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-04-01',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);
        $oldOffering->examStudents()->create([
            'student_number' => 'STALE-999',
            'full_name' => 'طالب قديم',
            'student_type' => ExamStudentType::Regular->value,
        ]);

        $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'periods' => [
                ['name' => 'مسائية', 'start_time' => '15:00', 'end_time' => '17:00', 'period_type' => 'evening'],
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
            ],
        ]));

        $this->assertSame(8, $draft->items()->count());
        $this->assertSame(0, $draft->items()->where('status', 'unscheduled')->count());
        $this->assertNotNull($draft->items()->where('subject_id', $core->id)->firstOrFail()->period_type);
        $this->assertSame(['CORE-001', 'CORE-002'], $draft->items()->where('subject_id', $core->id)->firstOrFail()->metadata['student_numbers']);
        $this->assertNotContains('STALE-999', $draft->items()->where('subject_id', $core->id)->firstOrFail()->metadata['student_numbers']);

        $allTogetherItems = $draft->items()->whereIn('subject_id', [$allTogetherA->id, $allTogetherB->id])->get();
        $this->assertSame(1, $allTogetherItems->pluck('exam_date')->unique()->count());
        $this->assertSame(1, $allTogetherItems->pluck('start_time')->unique()->count());

        $separateItems = $draft->items()->whereIn('subject_id', [$separateA->id, $separateB->id])->get();
        $this->assertSame(2, $separateItems->pluck('exam_date')->unique()->count());

        $autoItems = $draft->items()->whereIn('subject_id', [$autoA->id, $autoB->id])->get();
        $this->assertSame(1, $autoItems->pluck('exam_date')->unique()->count());
        $this->assertSame(1, $autoItems->pluck('start_time')->unique()->count());

        $approval = app(ExamScheduleGeneratorService::class)->approveDraft($draft);

        $this->assertSame('success', $approval['status']);
        $this->assertSame(0, $approval['created_count']);
        $this->assertSame(8, $approval['updated_count']);
        $this->assertSame(0, $approval['skipped_existing_count']);
        $this->assertSame(8, SubjectExamOffering::query()->where('exam_schedule_draft_id', $draft->id)->count());
        $this->assertSame(0, SubjectExamOffering::query()->where('exam_schedule_draft_id', $draft->id)->where('status', ExamOfferingStatus::Draft->value)->count());
        $this->assertSame(9, ExamStudent::query()->whereHas('subjectExamOffering', fn ($query) => $query->where('exam_schedule_draft_id', $draft->id))->count());
        $this->assertSame(8, SubjectExamRoster::query()->where('status', 'used')->count());

        $visibleOfferingIds = SubjectExamOfferingResource::getEloquentQuery()
            ->where('exam_schedule_draft_id', $draft->id)
            ->pluck('id');
        $this->assertCount(8, $visibleOfferingIds);
        $this->assertSame('/adminpanel/subject-exam-offerings', parse_url(SubjectExamOfferingResource::getUrl('index'), PHP_URL_PATH));

        $this->createHallAndInvigilatorSetup($context['college']);

        $hallResult = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-05-03',
            toDate: '2026-05-07',
            redistribute: true,
        );

        $this->assertSame('success', $hallResult['status']);
        $this->assertSame(9, $hallResult['distributed_students']);
        $this->assertSame(0, $hallResult['unassigned_students']);

        $invigilatorResult = app(InvigilatorDistributionService::class)->distributeForFaculty(
            $context['college'],
            Carbon::parse('2026-05-03'),
            Carbon::parse('2026-05-07'),
        );

        $this->assertSame('success', $invigilatorResult['status']);
        $this->assertGreaterThan(0, $invigilatorResult['assigned_count']);
        $this->assertSame(0, $invigilatorResult['shortage_count']);
    }

    #[Test]
    public function workflow_validation_checks_conflicts_labels_navigation_permissions_and_templates(): void
    {
        $context = $this->createAcademicContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamRoster'), 'web'));
        $this->actingAs($user);

        $this->assertSame('قوائم طلاب المواد', SubjectExamRosterResource::getPluralModelLabel());
        $this->assertSame('قائمة طلاب مادة', SubjectExamRosterResource::getModelLabel());
        $this->assertSame(9, SubjectExamRosterResource::getNavigationSort());
        $this->assertSame(10, ExamScheduleGenerator::getNavigationSort());
        $this->assertSame(11, SubjectExamOfferingResource::getNavigationSort());
        $this->assertSame(12, ComprehensiveStudentDistribution::getNavigationSort());
        $this->assertSame('/adminpanel/subject-exam-offerings', parse_url(SubjectExamOfferingResource::getUrl('index'), PHP_URL_PATH));
        $this->assertSame('/adminpanel/comprehensive-student-distribution', parse_url(ComprehensiveStudentDistribution::getUrl(), PHP_URL_PATH));
        $this->assertNotSame(SubjectExamOfferingResource::getUrl('index'), ComprehensiveStudentDistribution::getUrl());
        $this->assertSame([
            'filament.adminpanel.resources.subject-exam-offerings.index',
            'filament.adminpanel.resources.subject-exam-offerings.create',
            'filament.adminpanel.resources.subject-exam-offerings.edit',
        ], SubjectExamOfferingResource::getNavigationItemActiveRoutePattern());
        $this->assertSame(
            'filament.adminpanel.pages.comprehensive-student-distribution',
            ComprehensiveStudentDistribution::getNavigationItemActiveRoutePattern(),
        );
        $this->assertTrue(SubjectExamRosterResource::canViewAny());
        $this->assertSame([
            'الرقم الامتحاني',
            'اسم الطالب',
            'نوع الطالب',
            'نشط',
            'ملاحظات',
        ], (new SubjectExamRosterStudentsTemplateExport)->headings());
        $this->assertSame([], SubjectResource::getRelations());

        $sameTimeA = $this->createSubject($context, 'تعارض وقت 1');
        $sameTimeB = $this->createSubject($context, 'تعارض وقت 2');
        $this->createRoster($context, $sameTimeA, [['SAME-1', 'طالب متعارض', 'regular']]);
        $this->createRoster($context, $sameTimeB, [['SAME-1', 'طالب متعارض', 'carry']]);
        $draftsBeforeFailure = ExamScheduleDraft::query()->count();

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-03',
                'periods' => [
                    ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ],
            ]));
            $this->fail('Expected same-time student conflict to stop generation.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('student_conflict', $exception->reasonCode);
        }

        $this->assertSame($draftsBeforeFailure, ExamScheduleDraft::query()->count());

        SubjectExamRoster::query()->delete();
        $sameDayA = $this->createSubject($context, 'تعارض يوم 1');
        $sameDayB = $this->createSubject($context, 'تعارض يوم 2');
        $this->createRoster($context, $sameDayA, [['SAME-DAY', 'طالب نفس اليوم', 'regular']]);
        $this->createRoster($context, $sameDayB, [['SAME-DAY', 'طالب نفس اليوم', 'carry']]);
        $draftsBeforeFailure = ExamScheduleDraft::query()->count();

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-03',
                'prevent_same_day' => true,
                'periods' => [
                    ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                    ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
                ],
            ]));
            $this->fail('Expected same-day student conflict to stop generation.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('student_conflict', $exception->reasonCode);
        }

        $this->assertSame($draftsBeforeFailure, ExamScheduleDraft::query()->count());

        SubjectExamRoster::query()->delete();
        $secondLevel = StudyLevel::query()->create(['name' => 'السنة الرابعة', 'is_active' => true]);
        $carryA = $this->createSubject($context, 'تعارض حملة 1');
        $carryB = $this->createSubject($context, 'تعارض حملة 2', ['study_level_id' => $secondLevel->id]);
        $this->createRoster($context, $carryA, [['CARRY-1', 'طالب حملة', 'regular']]);
        $this->createRoster($context, $carryB, [['CARRY-1', 'طالب حملة', 'carry']], ['study_level_id' => $secondLevel->id]);
        $draftsBeforeFailure = ExamScheduleDraft::query()->count();

        try {
            app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-03',
                'periods' => [
                    ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ],
            ]));
            $this->fail('Expected carry student conflict to stop generation.');
        } catch (ExamScheduleGenerationException $exception) {
            $this->assertSame('student_conflict', $exception->reasonCode);
        }

        $this->assertSame($draftsBeforeFailure, ExamScheduleDraft::query()->count());
    }

    #[Test]
    public function automatic_workflow_filament_pages_render_with_polished_empty_states(): void
    {
        $context = $this->createAcademicContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo([
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamRoster'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'),
            Permission::findOrCreate('view_exam_schedule_generator', 'web'),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(ListSubjectExamRosters::class)
            ->assertSee('الخطوة الأولى قبل توليد البرنامج الامتحاني')
            ->assertSee('هذه القوائم هي مصدر الطلاب قبل توليد البرنامج الامتحاني.')
            ->assertSee('لا توجد قوائم طلاب جاهزة.');

        Livewire::actingAs($user)
            ->test(ExamScheduleGenerator::class)
            ->assertSee('جاهزية قوائم طلاب المواد')
            ->assertSee('يجب استيراد الطلاب وتحديد القوائم كجاهزة قبل توليد البرنامج.')
            ->assertSee('لم يتم توليد مسودة بعد.');

        Livewire::actingAs($user)
            ->test(ListSubjectExamOfferings::class)
            ->assertSee('مسودة البرنامج الامتحاني');

        Livewire::actingAs($user)
            ->test(ComprehensiveStudentDistribution::class)
            ->assertSee('توزيع شامل للطلاب على القاعات')
            ->assertSee('آخر عملية توزيع محفوظة في قاعدة البيانات')
            ->assertSee(__('exam.global_hall_distribution.no_previous_run'));
    }

    #[Test]
    public function clear_student_hall_distribution_action_only_resets_the_current_college(): void
    {
        $selectedContext = $this->createAcademicContext();
        $otherContext = $this->createAcademicContext();
        $selectedDistribution = $this->createStoredStudentDistribution($selectedContext, 'مختارة');
        $otherDistribution = $this->createStoredStudentDistribution($otherContext, 'أخرى');
        $selectedRoster = $this->createRoster($selectedContext, $selectedDistribution['subject'], [
            ['ROSTER-001', 'طالب قائمة', ExamStudentType::Regular->value],
        ]);
        $selectedInvigilator = Invigilator::query()->create([
            'college_id' => $selectedContext['college']->id,
            'name' => 'مراقب محفوظ',
            'phone' => '0998000001',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => true,
        ]);
        $selectedInvigilatorAssignment = InvigilatorAssignment::query()->create([
            'college_id' => $selectedContext['college']->id,
            'subject_exam_offering_id' => $selectedDistribution['offering']->id,
            'exam_date' => '2026-05-03',
            'start_time' => '09:00:00',
            'exam_hall_id' => $selectedDistribution['hall']->id,
            'invigilator_id' => $selectedInvigilator->id,
            'invigilation_role' => InvigilationRole::Regular->value,
            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
        ]);
        $user = User::factory()->create(['college_id' => $selectedContext['college']->id]);
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(ComprehensiveStudentDistribution::class)
            ->callAction('clearStudentHallDistribution')
            ->assertRedirect(ComprehensiveStudentDistribution::getUrl());

        $this->assertDatabaseMissing('student_distribution_runs', ['id' => $selectedDistribution['run']->id]);
        $this->assertDatabaseMissing('student_distribution_run_issues', ['id' => $selectedDistribution['issue']->id]);
        $this->assertDatabaseMissing('exam_student_hall_assignments', ['id' => $selectedDistribution['student_hall_assignment']->id]);
        $this->assertDatabaseMissing('hall_assignment_subjects', ['id' => $selectedDistribution['hall_assignment_subject']->id]);
        $this->assertDatabaseMissing('hall_assignments', ['id' => $selectedDistribution['hall_assignment']->id]);
        $this->assertSame(ExamOfferingStatus::Ready, $selectedDistribution['offering']->refresh()->status);

        $this->assertDatabaseHas('student_distribution_runs', ['id' => $otherDistribution['run']->id]);
        $this->assertDatabaseHas('student_distribution_run_issues', ['id' => $otherDistribution['issue']->id]);
        $this->assertDatabaseHas('exam_student_hall_assignments', ['id' => $otherDistribution['student_hall_assignment']->id]);
        $this->assertDatabaseHas('hall_assignment_subjects', ['id' => $otherDistribution['hall_assignment_subject']->id]);
        $this->assertDatabaseHas('hall_assignments', ['id' => $otherDistribution['hall_assignment']->id]);
        $this->assertSame(ExamOfferingStatus::Distributed, $otherDistribution['offering']->refresh()->status);

        $this->assertDatabaseHas('subjects', ['id' => $selectedDistribution['subject']->id]);
        $this->assertDatabaseHas('subject_exam_offerings', ['id' => $selectedDistribution['offering']->id]);
        $this->assertDatabaseHas('exam_students', ['id' => $selectedDistribution['student']->id]);
        $this->assertDatabaseHas('subject_exam_rosters', ['id' => $selectedRoster->id]);
        $this->assertDatabaseHas('exam_halls', ['id' => $selectedDistribution['hall']->id]);
        $this->assertDatabaseHas('invigilators', ['id' => $selectedInvigilator->id]);
        $this->assertDatabaseHas('invigilator_assignments', ['id' => $selectedInvigilatorAssignment->id]);

        Livewire::actingAs($user)
            ->test(ComprehensiveStudentDistribution::class)
            ->assertSee(__('exam.global_hall_distribution.no_previous_run'));

        $redistribution = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $selectedContext['college']->id,
            fromDate: '2026-05-03',
            toDate: '2026-05-03',
        );

        $this->assertSame('success', $redistribution['status']);
        $this->assertDatabaseHas('student_distribution_runs', [
            'college_id' => $selectedContext['college']->id,
            'status' => 'success',
        ]);
        $this->assertTrue($selectedDistribution['offering']->refresh()->status === ExamOfferingStatus::Distributed);
    }

    #[Test]
    public function subject_exam_rosters_page_uses_one_excel_import_action(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'مادة استيراد موحد');
        $this->createRoster($context, $subject, []);

        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo([
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamRoster'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('view', 'SubjectExamRoster'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('update', 'SubjectExamRoster'), 'web'),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(ListSubjectExamRosters::class)
            ->assertSee('تحميل قالب Excel')
            ->assertSee('استيراد الطلاب من Excel')
            ->assertSee('عرض الطلاب')
            ->assertSee('أرشفة')
            ->assertDontSee('تحميل الطلاب المستجدين')
            ->assertDontSee('تحميل طلاب الحملة');
    }

    #[Test]
    public function unified_roster_import_requires_valid_student_type_column(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'مادة تحقق نوع الطالب');
        $roster = $this->createRoster($context, $subject, [], ['status' => 'draft']);
        $path = 'testing/subject-roster-missing-type.xlsx';

        Excel::store(new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['الرقم الامتحاني', 'اسم الطالب', 'نشط', 'ملاحظات'];
            }

            public function array(): array
            {
                return [
                    ['S-001', 'طالب بدون نوع', 'نعم', null],
                ];
            }
        }, $path, 'local');

        try {
            Excel::import(new SubjectExamRosterStudentsImport($roster, markReadyAfterImport: false), Storage::disk('local')->path($path));
            $this->fail('Expected roster import to require the student type column.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('نوع الطالب مطلوب ويجب أن يكون مستجد أو حملة.', collect($exception->errors())->flatten()->implode(' '));
        }

        $invalidTypePath = 'testing/subject-roster-invalid-type.xlsx';
        Excel::store(new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['الرقم الامتحاني', 'اسم الطالب', 'نوع الطالب', 'نشط', 'ملاحظات'];
            }

            public function array(): array
            {
                return [
                    ['S-002', 'طالب بنوع غير صحيح', 'قديم', 'نعم', null],
                ];
            }
        }, $invalidTypePath, 'local');

        try {
            Excel::import(new SubjectExamRosterStudentsImport($roster, markReadyAfterImport: false), Storage::disk('local')->path($invalidTypePath));
            $this->fail('Expected roster import to reject invalid student type values.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('نوع الطالب مطلوب ويجب أن يكون مستجد أو حملة.', collect($exception->errors())->flatten()->implode(' '));
        }
    }

    #[Test]
    public function subject_edit_page_shows_compact_roster_summary_without_duplicate_import_ui(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'تحليل من صفحة المادة');
        $roster = $this->createRoster($context, $subject, [
            ['REG-001', 'طالب مستجد', 'regular'],
            ['CAR-001', 'طالب حملة', 'carry'],
        ]);
        $roster->update(['status' => 'ready']);

        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo([
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'Subject'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('view', 'Subject'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('update', 'Subject'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamRoster'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('view', 'SubjectExamRoster'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('update', 'SubjectExamRoster'), 'web'),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(EditSubject::class, ['record' => $subject->getKey()])
            ->assertSee('قوائم طلاب هذه المادة')
            ->assertSee('قوائم الطلاب تُدار من صفحة')
            ->assertSee('إدارة قوائم طلاب هذه المادة')
            ->assertSee('إنشاء قائمة جديدة لهذه المادة')
            ->assertSee('عدد القوائم')
            ->assertSee('القوائم الجاهزة')
            ->assertDontSee('تحميل الطلاب المستجدين')
            ->assertDontSee('تحميل طلاب الحملة')
            ->assertDontSee('تحميل قالب Excel');
    }

    #[Test]
    public function subject_exam_roster_students_relation_manager_uses_arabic_labels(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'مادة ترجمة القوائم');
        $roster = $this->createRoster($context, $subject, [
            ['REG-001', 'طالب مستجد', 'regular'],
        ]);

        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo([
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamRoster'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('view', 'SubjectExamRoster'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('update', 'SubjectExamRoster'), 'web'),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(RosterStudentsRelationManager::class, [
                'ownerRecord' => $roster,
                'pageClass' => EditSubjectExamRoster::class,
            ])
            ->assertSee('إضافة طالب إلى القائمة')
            ->assertSee('الرقم الامتحاني')
            ->assertSee('اسم الطالب')
            ->assertSee('نوع الطالب')
            ->assertSee('نشط')
            ->assertSee('ملاحظات')
            ->assertDontSee('subject exam roster student')
            ->assertDontSee('مؤهل');
    }

    protected function importRosterStudents(SubjectExamRoster $roster, array $rows): void
    {
        $path = 'testing/subject-roster.xlsx';
        Excel::store(new class($rows) implements FromArray, WithHeadings
        {
            public function __construct(private array $rows) {}

            public function headings(): array
            {
                return ['الرقم الامتحاني', 'اسم الطالب', 'نوع الطالب', 'نشط', 'ملاحظات'];
            }

            public function array(): array
            {
                return $this->rows;
            }
        }, $path, 'local');

        Excel::import(new SubjectExamRosterStudentsImport($roster), Storage::disk('local')->path($path));
    }

    protected function createAcademicContext(): array
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'code' => 'ENG', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المعلوماتية', 'is_active' => true]);
        $secondDepartment = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم الاتصالات', 'is_active' => true]);
        $studyLevel = StudyLevel::query()->create(['name' => 'السنة الثالثة', 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الثاني', 'is_active' => true]);

        return compact('college', 'department', 'secondDepartment', 'studyLevel', 'academicYear', 'semester') + [
            'second_department' => $secondDepartment,
            'study_level' => $studyLevel,
            'academic_year' => $academicYear,
        ];
    }

    protected function createSubject(array $context, string $name, array $overrides = []): Subject
    {
        $subject = Subject::query()->create([
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

        if ($subject->is_shared_subject && isset($context['second_department'])) {
            $subject->sharedDepartments()->sync([
                $context['department']->id,
                $context['second_department']->id,
            ]);
        }

        return $subject;
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
            'status' => $overrides['status'] ?? 'ready',
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

    /**
     * @return array<string, mixed>
     */
    protected function createStoredStudentDistribution(array $context, string $suffix): array
    {
        $subject = $this->createSubject($context, 'مادة توزيع '.$suffix);
        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-05-03',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Distributed->value,
        ]);
        $student = ExamStudent::query()->create([
            'subject_exam_offering_id' => $offering->id,
            'student_number' => 'DIST-'.$suffix,
            'full_name' => 'طالب توزيع '.$suffix,
            'student_type' => ExamStudentType::Regular->value,
        ]);
        $hall = ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة توزيع '.$suffix,
            'location' => 'المبنى الأول',
            'capacity' => 40,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);
        $hallAssignment = HallAssignment::query()->create([
            'exam_hall_id' => $hall->id,
            'exam_date' => '2026-05-03',
            'exam_start_time' => '09:00:00',
            'college_id' => $context['college']->id,
            'total_capacity' => 40,
            'assigned_students_count' => 1,
            'remaining_capacity' => 39,
        ]);
        $hallAssignmentSubject = HallAssignmentSubject::query()->create([
            'hall_assignment_id' => $hallAssignment->id,
            'subject_exam_offering_id' => $offering->id,
            'assigned_students_count' => 1,
        ]);
        $studentHallAssignment = ExamStudentHallAssignment::query()->create([
            'exam_student_id' => $student->id,
            'hall_assignment_id' => $hallAssignment->id,
            'subject_exam_offering_id' => $offering->id,
            'seat_number' => 1,
        ]);
        $run = StudentDistributionRun::query()->create([
            'college_id' => $context['college']->id,
            'from_date' => '2026-05-03',
            'to_date' => '2026-05-03',
            'status' => 'success',
            'total_offerings' => 1,
            'total_slots' => 1,
            'total_students' => 1,
            'distributed_students' => 1,
            'unassigned_students' => 0,
            'total_capacity' => 40,
            'used_halls' => 1,
            'capacity_shortage' => 0,
            'executed_at' => now(),
            'summary_json' => [
                'slots' => [[
                    'exam_date' => '2026-05-03',
                    'exam_start_time' => '09:00:00',
                    'used_halls_count' => 1,
                    'assigned_students_count' => 1,
                ]],
            ],
        ]);
        $issue = StudentDistributionRunIssue::query()->create([
            'student_distribution_run_id' => $run->id,
            'exam_date' => '2026-05-03',
            'start_time' => '09:00:00',
            'subject_exam_offering_id' => $offering->id,
            'issue_type' => 'test_issue',
            'message' => 'اختبار',
            'affected_students_count' => 1,
            'payload_json' => ['source' => 'test'],
        ]);

        return compact(
            'subject',
            'offering',
            'student',
            'hall',
            'hallAssignment',
            'hallAssignmentSubject',
            'studentHallAssignment',
            'run',
            'issue',
        ) + [
            'hall_assignment' => $hallAssignment,
            'hall_assignment_subject' => $hallAssignmentSubject,
            'student_hall_assignment' => $studentHallAssignment,
        ];
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
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
                ['name' => 'مسائية', 'start_time' => '15:00', 'end_time' => '17:00', 'period_type' => 'evening'],
            ],
            'prevent_same_day' => false,
        ], $overrides);
    }

    protected function createHallAndInvigilatorSetup(College $college): void
    {
        ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => 'قاعة شاملة',
            'location' => 'المبنى الأول',
            'capacity' => 40,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        InvigilatorHallRequirement::query()->create([
            'college_id' => $college->id,
            'hall_type' => ExamHallType::Large->value,
            'hall_head_count' => 0,
            'secretary_count' => 0,
            'regular_count' => 1,
            'reserve_count' => 0,
        ]);

        for ($index = 1; $index <= 20; $index++) {
            Invigilator::query()->create([
                'college_id' => $college->id,
                'name' => 'مراقب '.$index,
                'phone' => '0998'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                'staff_category' => StaffCategory::Doctor->value,
                'invigilation_role' => InvigilationRole::Regular->value,
                'is_active' => true,
            ]);
        }
    }
}
