<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Enums\StaffCategory;
use App\Imports\InvigilatorsImport;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\HallAssignment;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorDistributionSetting;
use App\Models\InvigilatorHallRequirement;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\User;
use App\Services\ExamHallDistributionService;
use App\Services\InvigilatorDistributionService;
use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Carbon\Carbon;
use Database\Seeders\InvigilatorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvigilatorDistributionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_invigilators_and_updates_duplicates_by_phone(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        Invigilator::query()->create([
            'college_id' => $college->id,
            'name' => 'اسم قديم',
            'phone' => '0999',
            'staff_category' => StaffCategory::Other->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => false,
        ]);

        $path = 'testing/invigilators.xlsx';
        Excel::store(new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return [
                    'اسم المراقب',
                    'نوع الكادر',
                    'رقم الهاتف',
                    'نوع المراقبة',
                    'الحد الأقصى للمراقبات',
                    'الحد الأقصى في اليوم',
                    'نسبة تخفيض المراقبات',
                    'فعال',
                    'ملاحظات',
                ];
            }

            public function array(): array
            {
                return [
                    ['د. أحمد', 'دكتور', '0999', 'رئيس قاعة', 4, 1, '25%', 'نعم', 'محدث'],
                    ['سارة', 'موظف إداري', '0998', 'مراقب عادي', null, null, null, 'yes', null],
                ];
            }
        }, $path, 'local');

        $import = new InvigilatorsImport($college);
        Excel::import($import, Storage::disk('local')->path($path));

        $this->assertSame(2, $import->getImportedCount());
        $this->assertSame(2, Invigilator::query()->count());

        $updated = Invigilator::query()->where('phone', '0999')->first();
        $this->assertSame('د. أحمد', $updated->name);
        $this->assertSame(StaffCategory::Doctor, $updated->staff_category);
        $this->assertSame(InvigilationRole::HallHead, $updated->invigilation_role);
        $this->assertSame(25, $updated->workload_reduction_percentage);
        $this->assertTrue($updated->is_active);
    }

    #[Test]
    public function it_rejects_imported_invigilators_without_phone(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $path = 'testing/invigilators-missing-phone.xlsx';
        Excel::store(new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['اسم المراقب', 'نوع الكادر', 'رقم الهاتف', 'نوع المراقبة'];
            }

            public function array(): array
            {
                return [
                    ['د. أحمد', 'دكتور', null, 'رئيس قاعة'],
                ];
            }
        }, $path, 'local');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('exam.validation.invigilator_phone_required_in_import'));

        Excel::import(new InvigilatorsImport($college), Storage::disk('local')->path($path));
    }

    #[Test]
    public function it_assigns_required_invigilators_per_hall_type_and_prevents_same_time_conflicts(): void
    {
        $context = $this->createSlotContext();
        $largeHall = $this->createUsedHall($context['college'], 'القاعة الكبيرة', ExamHallType::Large);
        $smallHall = $this->createUsedHall($context['college'], 'القاعة الصغيرة', ExamHallType::Small);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 10,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 3,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);
        $this->createRequirement($context['college'], ExamHallType::Large, 1, 1, 2, 0);
        $this->createRequirement($context['college'], ExamHallType::Small, 1, 0, 1, 0);

        $this->createInvigilators($context['college'], InvigilationRole::HallHead, 2);
        $this->createInvigilators($context['college'], InvigilationRole::Secretary, 1);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 3);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('success', $result['status']);
        $this->assertSame(6, InvigilatorAssignment::query()->count());
        $this->assertSame(4, $largeHall->invigilatorAssignments()->count());
        $this->assertSame(2, $smallHall->invigilatorAssignments()->count());

        $duplicateAtSameTime = InvigilatorAssignment::query()
            ->select('invigilator_id')
            ->whereDate('exam_date', '2026-06-01')
            ->whereTime('start_time', '09:00:00')
            ->groupBy('invigilator_id')
            ->havingRaw('count(*) > 1')
            ->exists();

        $this->assertFalse($duplicateAtSameTime);
    }

    #[Test]
    public function it_respects_assignment_limits_and_reports_shortage(): void
    {
        $context = $this->createSlotContext();
        $this->createUsedHall($context['college'], 'قاعة 1', ExamHallType::Large);
        $this->createUsedHall($context['college'], 'قاعة 2', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 1,
            'allow_multiple_assignments_per_day' => false,
            'max_assignments_per_day' => 1,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('warning', $result['status']);
        $this->assertSame(1, InvigilatorAssignment::query()->count());
        $this->assertSame(1, $result['shortage_count']);
    }

    #[Test]
    public function it_blocks_invigilator_distribution_until_student_hall_distribution_is_complete(): void
    {
        $context = $this->createSlotContext();

        ExamStudent::query()->create([
            'subject_exam_offering_id' => $context['offering']->id,
            'student_number' => '20260001',
            'full_name' => 'طالب أول',
            'student_type' => ExamStudentType::Regular->value,
        ]);

        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);
        $this->createInvigilators($context['college'], InvigilationRole::Regular, 1);

        $result = app(InvigilatorDistributionService::class)->distributeForFaculty(
            $context['college'],
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-01'),
        );

        $this->assertSame('danger', $result['status']);
        $this->assertSame(__('exam.readiness.reasons.student_distribution_missing'), $result['message']);
        $this->assertSame(0, InvigilatorAssignment::query()->count());
        $this->assertSame(1, $result['readiness']['incomplete_slots_count']);
    }

    #[Test]
    public function it_distributes_students_globally_by_college_and_groups_same_time_offerings(): void
    {
        $context = $this->createSlotContext();
        $otherSubject = Subject::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $context['offering']->subject->department_id,
            'study_level_id' => $context['offering']->subject->study_level_id,
            'name' => 'جبر',
            'is_active' => true,
        ]);
        $otherOffering = SubjectExamOffering::query()->create([
            'subject_id' => $otherSubject->id,
            'academic_year_id' => $context['offering']->academic_year_id,
            'semester_id' => $context['offering']->semester_id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة شاملة',
            'location' => 'المبنى الأول',
            'capacity' => 50,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        foreach ([$context['offering'], $otherOffering] as $offeringIndex => $offering) {
            for ($index = 1; $index <= 5; $index++) {
                ExamStudent::query()->create([
                    'subject_exam_offering_id' => $offering->id,
                    'student_number' => '2026'.$offeringIndex.$index,
                    'full_name' => 'طالب '.$offeringIndex.'-'.$index,
                    'student_type' => ExamStudentType::Regular->value,
                ]);
            }
        }

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['offerings_count']);
        $this->assertSame(1, $result['slots_count']);
        $this->assertSame(10, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertSame(1, HallAssignment::query()->count());
        $this->assertDatabaseHas('hall_assignment_subjects', [
            'subject_exam_offering_id' => $context['offering']->id,
            'assigned_students_count' => 5,
        ]);
        $this->assertDatabaseHas('hall_assignment_subjects', [
            'subject_exam_offering_id' => $otherOffering->id,
            'assigned_students_count' => 5,
        ]);
    }

    #[Test]
    public function it_uses_workload_reduction_percentage_when_calculating_effective_max_assignments(): void
    {
        $context = $this->createSlotContext();
        $this->createUsedHall($context['college'], 'قاعة 1', ExamHallType::Large);
        $this->createUsedHall($context['college'], 'قاعة 2', ExamHallType::Large);
        $this->createRequirement($context['college'], ExamHallType::Large, 0, 0, 1, 0);

        InvigilatorDistributionSetting::query()->create([
            'college_id' => $context['college']->id,
            'default_max_assignments_per_invigilator' => 2,
            'allow_multiple_assignments_per_day' => true,
            'max_assignments_per_day' => 2,
            'distribution_pattern' => 'balanced',
            'day_preference' => 'balanced',
        ]);

        Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب مخفض',
            'phone' => '0988000001',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'workload_reduction_percentage' => 50,
            'is_active' => true,
        ]);

        $result = app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertSame('warning', $result['status']);
        $this->assertSame(1, InvigilatorAssignment::query()->count());
        $this->assertSame(1, $result['shortage_count']);
    }

    #[Test]
    public function it_preserves_manual_assignments_when_rerunning_distribution(): void
    {
        $context = $this->createSlotContext();
        $hall = $this->createUsedHall($context['college'], 'قاعة يدوية', ExamHallType::Small);
        $this->createRequirement($context['college'], ExamHallType::Small, 0, 0, 1, 0);

        $manualInvigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب يدوي',
            'phone' => '0988000002',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => true,
        ]);

        $automaticInvigilator = Invigilator::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مراقب آلي',
            'phone' => '0988000003',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => true,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $context['college']->id,
            'exam_date' => '2026-06-01',
            'start_time' => '09:00:00',
            'exam_hall_id' => $hall->id,
            'invigilator_id' => $manualInvigilator->id,
            'invigilation_role' => InvigilationRole::Regular->value,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);

        app(InvigilatorDistributionService::class)->distributeForSlot($context['college'], '2026-06-01', '09:00:00');

        $this->assertDatabaseHas('invigilator_assignments', [
            'invigilator_id' => $manualInvigilator->id,
            'assignment_status' => InvigilatorAssignmentStatus::Manual->value,
        ]);
        $this->assertDatabaseMissing('invigilator_assignments', [
            'invigilator_id' => $automaticInvigilator->id,
            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
        ]);
        $this->assertSame(1, InvigilatorAssignment::query()->count());
    }

    #[Test]
    public function faculty_admin_cannot_access_another_college_invigilator(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $otherCollege = College::query()->create(['name' => 'كلية العلوم', 'is_active' => true]);
        $user = User::factory()->create(['college_id' => $college->id]);
        Role::findOrCreate(RoleNames::ADMIN, 'web');
        Permission::findOrCreate(ShieldPermission::resource('view', 'Invigilator'), 'web');
        $user->assignRole(RoleNames::ADMIN);
        $user->givePermissionTo(ShieldPermission::resource('view', 'Invigilator'));

        $invigilator = Invigilator::query()->create([
            'college_id' => $otherCollege->id,
            'name' => 'مراقب خارج النطاق',
            'phone' => '0988000004',
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::Regular->value,
            'is_active' => true,
        ]);

        $this->assertFalse($user->can('view', $invigilator));
    }

    #[Test]
    public function invigilator_seeder_creates_repeatable_invigilators_with_required_unique_phones(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'code' => 'ENG', 'is_active' => true]);

        $this->seed(InvigilatorSeeder::class);
        $this->seed(InvigilatorSeeder::class);

        $this->assertSame(35, Invigilator::query()->where('college_id', $college->id)->count());
        $this->assertSame(0, Invigilator::query()->where('college_id', $college->id)->whereNull('phone')->count());

        $duplicatePhones = Invigilator::query()
            ->where('college_id', $college->id)
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('count(*) > 1')
            ->exists();

        $this->assertFalse($duplicatePhones);
        $this->assertDatabaseHas('invigilator_distribution_settings', ['college_id' => $college->id]);
        $this->assertSame(3, InvigilatorHallRequirement::query()->where('college_id', $college->id)->count());
    }

    protected function createSlotContext(): array
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المعلوماتية', 'is_active' => true]);
        $studyLevel = StudyLevel::query()->create(['name' => 'السنة الثالثة', 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الثاني', 'is_active' => true]);
        $subject = Subject::query()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'study_level_id' => $studyLevel->id,
            'name' => 'تحليل',
            'is_active' => true,
        ]);

        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        return compact('college', 'offering');
    }

    protected function createUsedHall(College $college, string $name, ExamHallType $type): ExamHall
    {
        $hall = ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => $name,
            'location' => 'المبنى الأول',
            'capacity' => 80,
            'hall_type' => $type->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        HallAssignment::query()->create([
            'exam_hall_id' => $hall->id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'college_id' => $college->id,
            'total_capacity' => 80,
            'assigned_students_count' => 20,
            'remaining_capacity' => 60,
        ]);

        return $hall;
    }

    protected function createRequirement(College $college, ExamHallType $type, int $heads, int $secretaries, int $regulars, int $reserves): void
    {
        InvigilatorHallRequirement::query()->create([
            'college_id' => $college->id,
            'hall_type' => $type->value,
            'hall_head_count' => $heads,
            'secretary_count' => $secretaries,
            'regular_count' => $regulars,
            'reserve_count' => $reserves,
        ]);
    }

    protected function createInvigilators(College $college, InvigilationRole $role, int $count): void
    {
        $roleOffset = match ($role) {
            InvigilationRole::HallHead => 100,
            InvigilationRole::Secretary => 200,
            InvigilationRole::Regular => 300,
            InvigilationRole::Reserve => 400,
        };

        for ($index = 1; $index <= $count; $index++) {
            Invigilator::query()->create([
                'college_id' => $college->id,
                'name' => $role->value.'-'.$index,
                'phone' => '0977'.str_pad((string) ($college->id * 1000 + $roleOffset + $index), 6, '0', STR_PAD_LEFT),
                'staff_category' => StaffCategory::Doctor->value,
                'invigilation_role' => $role->value,
                'is_active' => true,
            ]);
        }
    }
}
