<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Enums\InvigilationRole;
use App\Enums\StaffCategory;
use App\Exports\SubjectExamRosterStudentsTemplateExport;
use App\Filament\Pages\ExamScheduleGenerator;
use App\Filament\Resources\Subjects\Pages\EditSubject;
use App\Filament\Resources\Subjects\SubjectResource;
use App\Filament\Resources\SubjectExamRosters\Pages\EditSubjectExamRoster;
use App\Filament\Resources\SubjectExamRosters\Pages\ListSubjectExamRosters;
use App\Filament\Resources\SubjectExamRosters\RelationManagers\RosterStudentsRelationManager;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Filament\Resources\SubjectExamRosters\SubjectExamRosterResource;
use App\Imports\SubjectExamRosterStudentsImport;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\Invigilator;
use App\Models\InvigilatorHallRequirement;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\SubjectExamRoster;
use App\Models\User;
use App\Services\ExamHallDistributionService;
use App\Services\ExamScheduleGeneratorService;
use App\Services\InvigilatorDistributionService;
use App\Support\ShieldPermission;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Livewire\Livewire;

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
        $this->assertSame('morning', $draft->items()->where('subject_id', $core->id)->firstOrFail()->period_type);
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
        $this->assertSame(8, $approval['created_count']);
        $this->assertSame(0, $approval['skipped_existing_count']);
        $this->assertSame(8, SubjectExamOffering::query()->where('exam_schedule_draft_id', $draft->id)->count());
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
        $this->assertTrue(SubjectExamRosterResource::canViewAny());
        $this->assertSame([
            'الرقم الامتحاني',
            'اسم الطالب',
            'نوع الطالب',
            'نشط',
            'ملاحظات',
        ], (new SubjectExamRosterStudentsTemplateExport)->headings());
        $this->assertSame([
            'الرقم الامتحاني',
            'اسم الطالب',
            'ملاحظات',
        ], (new SubjectExamRosterStudentsTemplateExport('regular'))->headings());
        $this->assertSame([], SubjectResource::getRelations());

        $sameTimeA = $this->createSubject($context, 'تعارض وقت 1');
        $sameTimeB = $this->createSubject($context, 'تعارض وقت 2');
        $this->createRoster($context, $sameTimeA, [['SAME-1', 'طالب متعارض', 'regular']]);
        $this->createRoster($context, $sameTimeB, [['SAME-1', 'طالب متعارض', 'carry']]);
        $sameTimeDraft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));
        $this->assertSame(1, $sameTimeDraft->items()->where('status', 'unscheduled')->count());

        SubjectExamRoster::query()->delete();
        $sameDayA = $this->createSubject($context, 'تعارض يوم 1');
        $sameDayB = $this->createSubject($context, 'تعارض يوم 2');
        $this->createRoster($context, $sameDayA, [['SAME-DAY', 'طالب نفس اليوم', 'regular']]);
        $this->createRoster($context, $sameDayB, [['SAME-DAY', 'طالب نفس اليوم', 'carry']]);
        $sameDayDraft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'prevent_same_day' => true,
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
                ['name' => 'وسطى', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
            ],
        ]));
        $this->assertSame(1, $sameDayDraft->items()->where('status', 'unscheduled')->count());

        SubjectExamRoster::query()->delete();
        $secondLevel = StudyLevel::query()->create(['name' => 'السنة الرابعة', 'is_active' => true]);
        $carryA = $this->createSubject($context, 'تعارض حملة 1');
        $carryB = $this->createSubject($context, 'تعارض حملة 2', ['study_level_id' => $secondLevel->id]);
        $this->createRoster($context, $carryA, [['CARRY-1', 'طالب حملة', 'regular']]);
        $this->createRoster($context, $carryB, [['CARRY-1', 'طالب حملة', 'carry']], ['study_level_id' => $secondLevel->id]);
        $carryDraft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settings($context, [
            'start_date' => '2026-05-03',
            'end_date' => '2026-05-03',
            'periods' => [
                ['name' => 'صباحية', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
            ],
        ]));
        $this->assertSame(1, $carryDraft->items()->where('status', 'unscheduled')->count());
    }

    #[Test]
    public function automatic_workflow_filament_pages_render_with_polished_empty_states(): void
    {
        $context = $this->createAcademicContext();
        $user = User::factory()->create(['college_id' => $context['college']->id]);
        $user->givePermissionTo([
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamRoster'), 'web'),
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

    protected function importRosterStudentsWithoutType(SubjectExamRoster $roster, array $rows, string $studentType): void
    {
        $path = 'testing/subject-roster-'.$studentType.'.xlsx';
        Excel::store(new class($rows) implements FromArray, WithHeadings
        {
            public function __construct(private array $rows) {}

            public function headings(): array
            {
                return ['الرقم الامتحاني', 'اسم الطالب', 'ملاحظات'];
            }

            public function array(): array
            {
                return $this->rows;
            }
        }, $path, 'local');

        Excel::import(
            new SubjectExamRosterStudentsImport($roster, defaultStudentType: $studentType, markReadyAfterImport: false),
            Storage::disk('local')->path($path),
        );
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
