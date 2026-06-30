<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Filament\Resources\SubjectExamOfferings\Pages\CreateSubjectExamOffering;
use App\Filament\Resources\SubjectExamOfferings\Pages\EditSubjectExamOffering;
use App\Filament\Resources\SubjectExamOfferings\RelationManagers\CarryStudentsRelationManager;
use App\Filament\Resources\SubjectExamOfferings\RelationManagers\RegularStudentsRelationManager;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\SubjectExamRoster;
use App\Models\User;
use App\Services\ExamHallDistributionService;
use App\Services\SubjectExamOfferingRosterSyncService;
use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualSubjectExamOfferingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function manually_created_exam_offering_syncs_regular_and_carry_roster_students(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'تحليل يدوي');
        $this->createRoster($context, $subject, [
            ['MAN-001', 'طالب مستجد', ExamStudentType::Regular->value],
            ['MAN-002', 'طالب حملة', ExamStudentType::Carry->value],
        ]);
        $user = $this->adminUser($context['college']);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(CreateSubjectExamOffering::class)
            ->set('data.department_id', $context['department']->id)
            ->set('data.subject_id', $subject->id)
            ->set('data.academic_year_id', $context['academicYear']->id)
            ->set('data.semester_id', $context['semester']->id)
            ->set('data.status', ExamOfferingStatus::Ready->value)
            ->set('data.exam_date', '2026-05-04')
            ->set('data.exam_start_time', '09:00')
            ->call('create');

        $offering = SubjectExamOffering::query()->firstOrFail();

        $this->assertSame($subject->id, $offering->subject_id);
        $this->assertSame($context['college']->id, $offering->subject->college_id);
        $this->assertSame($context['department']->id, $offering->subject->department_id);
        $this->assertSame(1, $offering->regularStudents()->count());
        $this->assertSame(1, $offering->carryStudents()->count());
        $this->assertSame('1', RegularStudentsRelationManager::getBadge($offering, EditSubjectExamOffering::class));
        $this->assertSame('1', CarryStudentsRelationManager::getBadge($offering, EditSubjectExamOffering::class));
    }

    #[Test]
    public function manual_offering_edit_form_hydrates_college_and_department_from_subject(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'برمجة مرئية');
        $offering = $this->createOffering($context, $subject);
        $user = $this->superAdminUser();

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(EditSubjectExamOffering::class, ['record' => $offering->getKey()])
            ->assertSet('data.college_id', $context['college']->id)
            ->assertSet('data.department_id', $context['department']->id)
            ->assertSet('data.subject_id', $subject->id);
    }

    #[Test]
    public function student_distribution_uses_manually_created_offerings_after_roster_sync(): void
    {
        $context = $this->createAcademicContext();
        $subject = $this->createSubject($context, 'توزيع يدوي');
        $this->createRoster($context, $subject, [
            ['DIST-001', 'طالب أول', ExamStudentType::Regular->value],
            ['DIST-002', 'طالب ثان', ExamStudentType::Carry->value],
        ]);
        $this->createHall($context['college']);

        $offering = $this->createOffering($context, $subject);
        $this->assertSame(0, $offering->examStudents()->count());

        app(SubjectExamOfferingRosterSyncService::class)->syncOffering($offering);
        $this->assertSame(2, $offering->refresh()->examStudents()->count());

        $result = app(ExamHallDistributionService::class)->distributeForOffering($offering->refresh());

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertSame(ExamOfferingStatus::Distributed, $offering->refresh()->status);
    }

    #[Test]
    public function changing_manual_offering_subject_replaces_stale_students_from_the_new_roster(): void
    {
        $context = $this->createAcademicContext();
        $oldSubject = $this->createSubject($context, 'مادة قديمة');
        $newSubject = $this->createSubject($context, 'مادة جديدة');
        $offering = $this->createOffering($context, $oldSubject);
        $offering->examStudents()->create([
            'student_number' => 'OLD-001',
            'full_name' => 'طالب قديم',
            'student_type' => ExamStudentType::Regular->value,
        ]);
        $this->createRoster($context, $newSubject, [
            ['NEW-001', 'طالب جديد', ExamStudentType::Regular->value],
            ['NEW-002', 'طالب حملة جديد', ExamStudentType::Carry->value],
        ]);
        $user = $this->adminUser($context['college']);

        Filament::setCurrentPanel(Filament::getPanel('adminpanel'));

        Livewire::actingAs($user)
            ->test(EditSubjectExamOffering::class, ['record' => $offering->getKey()])
            ->set('data.subject_id', $newSubject->id)
            ->call('save');

        $offering->refresh();

        $this->assertSame($newSubject->id, $offering->subject_id);
        $this->assertSame(['NEW-001', 'NEW-002'], ExamStudent::query()
            ->where('subject_exam_offering_id', $offering->id)
            ->orderBy('student_number')
            ->pluck('student_number')
            ->all());
    }

    protected function createAcademicContext(): array
    {
        $college = College::query()->create(['name' => 'كلية الهندسة', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم المعلوماتية', 'is_active' => true]);
        $studyLevel = StudyLevel::query()->create(['name' => 'السنة الثالثة', 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الثاني', 'is_active' => true]);

        return compact('college', 'department', 'studyLevel', 'academicYear', 'semester');
    }

    protected function createSubject(array $context, string $name): Subject
    {
        return Subject::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $context['department']->id,
            'study_level_id' => $context['studyLevel']->id,
            'name' => $name,
            'is_active' => true,
            'is_shared_subject' => false,
            'shared_subject_scheduling_mode' => 'auto',
            'is_core_subject' => false,
            'preferred_exam_period' => 'none',
            'core_subject_priority' => 'preference',
        ]);
    }

    protected function createRoster(array $context, Subject $subject, array $students): SubjectExamRoster
    {
        $roster = SubjectExamRoster::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $subject->department_id,
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academicYear']->id,
            'semester_id' => $context['semester']->id,
            'study_level_id' => $subject->study_level_id,
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

    protected function createOffering(array $context, Subject $subject): SubjectExamOffering
    {
        return SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academicYear']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => '2026-05-04',
            'exam_start_time' => '09:00:00',
            'status' => ExamOfferingStatus::Ready->value,
        ]);
    }

    protected function createHall(College $college): ExamHall
    {
        return ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => 'قاعة 1',
            'location' => 'المبنى A',
            'capacity' => 50,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);
    }

    protected function adminUser(College $college): User
    {
        $user = User::factory()->create(['college_id' => $college->id]);
        $user->givePermissionTo([
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('view', 'SubjectExamOffering'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('create', 'SubjectExamOffering'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('update', 'SubjectExamOffering'), 'web'),
        ]);

        return $user;
    }

    protected function superAdminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(RoleNames::SUPER_ADMIN, 'web'));
        $user->givePermissionTo([
            Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('view', 'SubjectExamOffering'), 'web'),
            Permission::findOrCreate(ShieldPermission::resource('update', 'SubjectExamOffering'), 'web'),
        ]);

        return $user;
    }
}
