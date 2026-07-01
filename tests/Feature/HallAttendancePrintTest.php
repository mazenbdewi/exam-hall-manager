<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Enums\StaffCategory;
use App\Filament\Pages\ReportsDashboard;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\ExamStudentHallAssignment;
use App\Models\HallAssignment;
use App\Models\HallAssignmentSubject;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\RoleNames;
use App\Support\ShieldPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HallAttendancePrintTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function hall_attendance_sheet_prints_saved_hall_students_and_invigilators(): void
    {
        Storage::disk('public')->put('settings/university/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
        ));
        SystemSetting::current()->update([
            'university_name' => 'جامعة اللاذقية',
            'university_logo' => 'settings/university/logo.png',
        ]);

        $college = College::query()->create(['name' => 'كلية الهندسة المعلوماتية', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم هندسة البرمجيات', 'is_active' => true]);
        $studyLevel = StudyLevel::query()->create(['name' => 'الأولى', 'sort_order' => 1, 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2025-2026', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الأول', 'sort_order' => 1, 'is_active' => true]);
        $subject = Subject::query()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'study_level_id' => $studyLevel->id,
            'name' => 'دارات كهربائية 1 - طاقة',
            'is_active' => true,
            'is_shared_subject' => false,
            'shared_subject_scheduling_mode' => 'auto',
            'is_core_subject' => false,
            'preferred_exam_period' => 'none',
            'core_subject_priority' => 'preference',
        ]);
        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'exam_date' => '2026-01-28',
            'exam_start_time' => '12:00:00',
            'status' => ExamOfferingStatus::Distributed->value,
        ]);
        $hall = ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => '40',
            'location' => 'A',
            'capacity' => 40,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);
        $hallAssignment = HallAssignment::query()->create([
            'exam_hall_id' => $hall->id,
            'exam_date' => '2026-01-28',
            'exam_start_time' => '12:00:00',
            'college_id' => $college->id,
            'total_capacity' => 40,
            'assigned_students_count' => 3,
            'remaining_capacity' => 37,
        ]);

        HallAssignmentSubject::query()->create([
            'hall_assignment_id' => $hallAssignment->id,
            'subject_exam_offering_id' => $offering->id,
            'assigned_students_count' => 3,
        ]);

        foreach ([1 => 'مازن أحمد', 2 => 'لين محمد', 3 => 'سارة علي'] as $seat => $studentName) {
            $student = ExamStudent::query()->create([
                'subject_exam_offering_id' => $offering->id,
                'student_number' => '2026'.$seat,
                'full_name' => $studentName,
                'student_type' => ExamStudentType::Regular->value,
            ]);

            ExamStudentHallAssignment::query()->create([
                'exam_student_id' => $student->id,
                'hall_assignment_id' => $hallAssignment->id,
                'subject_exam_offering_id' => $offering->id,
                'seat_number' => $seat,
            ]);
        }

        $this->assignInvigilator($college, $hall, $offering, InvigilationRole::HallHead, 'د. سامر حسن');
        $this->assignInvigilator($college, $hall, $offering, InvigilationRole::Secretary, 'أ. هدى يوسف');
        $this->assignInvigilator($college, $hall, $offering, InvigilationRole::Regular, 'مراقب أول');

        $user = User::factory()->create(['college_id' => $college->id]);
        $user->assignRole(Role::findOrCreate(RoleNames::ADMIN, 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('view', 'SubjectExamOffering'), 'web'));

        $response = $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.hall-assignments.attendance-print.show', ['hallAssignment' => $hallAssignment]));

        $response
            ->assertOk()
            ->assertSee('كشف تفقد القاعة الامتحانية')
            ->assertSee('جامعة اللاذقية')
            ->assertSee('data:image/png;base64', false)
            ->assertSee('كلية الهندسة المعلوماتية')
            ->assertSee('قسم هندسة البرمجيات')
            ->assertSee('الأربعاء 28-01-2026')
            ->assertSee('دارات كهربائية 1 - طاقة')
            ->assertSee('40 / A')
            ->assertSee('عدد الطلاب')
            ->assertSee('المقعد')
            ->assertSee('الرقم الجامعي')
            ->assertSee('اسم الطالب')
            ->assertSee('الحضور')
            ->assertSee('رئيس القاعة')
            ->assertSee('أمين السر')
            ->assertSee('مراقب')
            ->assertSee('د. سامر حسن')
            ->assertSee('أ. هدى يوسف')
            ->assertSee('مراقب أول')
            ->assertSee('مازن أحمد')
            ->assertSee('لين محمد')
            ->assertSee('سارة علي');

        $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.hall-assignments.attendance-print.index', [
                'college_id' => $college->id,
                'exam_date' => '2026-01-28',
                'exam_start_time' => '12:00:00',
            ]))
            ->assertOk()
            ->assertSee('طباعة تفقد القاعة')
            ->assertSee('مازن أحمد');

        $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.reports.student-hall-assignments.print', [
                'subject_exam_offering_id' => $offering->id,
            ]))
            ->assertOk()
            ->assertSee('كشف توزيع الطلاب على القاعات حسب المادة والفترة')
            ->assertSee('جامعة اللاذقية')
            ->assertSee('data:image/png;base64', false);

        $this
            ->actingAs($user)
            ->get(SubjectExamOfferingResource::getUrl('distribution', ['record' => $offering]))
            ->assertOk();
    }

    #[Test]
    public function hall_attendance_subject_filter_limits_printed_students_to_selected_subject(): void
    {
        $context = $this->createMultiSubjectHallContext();
        $user = $this->attendanceReportUser($context['college']);

        $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.hall-assignments.attendance-print.index', [
                'college_id' => $context['college']->id,
                'exam_date' => '2026-08-13',
                'exam_start_time' => '12:00:00',
                'hall_assignment_id' => $context['main_hall_assignment']->id,
            ]))
            ->assertOk()
            ->assertSee('طالب المادة أ')
            ->assertSee('طالب المادة ب')
            ->assertSee('المادة أ')
            ->assertSee('المادة ب');

        $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.hall-assignments.attendance-print.index', [
                'college_id' => $context['college']->id,
                'exam_date' => '2026-08-13',
                'exam_start_time' => '12:00:00',
                'hall_assignment_id' => $context['main_hall_assignment']->id,
                'subject_exam_offering_id' => $context['offering_b']->id,
            ]))
            ->assertOk()
            ->assertSee('تفقد القاعة - المادة: المادة ب')
            ->assertSee('طالب المادة ب')
            ->assertSee('المادة ب')
            ->assertDontSee('طالب المادة أ')
            ->assertDontSee('المادة أ (1)');
    }

    #[Test]
    public function hall_attendance_subject_dropdown_lists_only_subjects_for_selected_hall_and_slot(): void
    {
        $context = $this->createMultiSubjectHallContext();
        $page = app(ReportsDashboard::class);
        $page->college_id = $context['college']->id;
        $page->attendance_slot = '2026-08-13|12:00:00';
        $page->hall_assignment_id = $context['main_hall_assignment']->id;

        $mainHallOptions = $page->attendanceSubjectOptions();

        $this->assertSame([
            $context['offering_a']->id => 'المادة أ',
            $context['offering_b']->id => 'المادة ب',
        ], $mainHallOptions);
        $this->assertArrayNotHasKey($context['offering_c']->id, $mainHallOptions);

        $page->hall_assignment_id = $context['second_hall_assignment']->id;
        $secondHallOptions = $page->attendanceSubjectOptions();

        $this->assertSame([$context['offering_c']->id => 'المادة ج'], $secondHallOptions);

        $page->attendance_slot = '2026-08-14|12:00:00';
        $page->hall_assignment_id = $context['next_day_hall_assignment']->id;
        $nextDateOptions = $page->attendanceSubjectOptions();

        $this->assertSame([$context['offering_d']->id => 'المادة د'], $nextDateOptions);
    }

    #[Test]
    public function hall_attendance_subject_filter_validates_missing_and_invalid_subjects(): void
    {
        $context = $this->createMultiSubjectHallContext();
        $user = $this->attendanceReportUser($context['college']);

        $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.hall-assignments.attendance-print.show', [
                'hallAssignment' => $context['main_hall_assignment'],
                'subject_exam_offering_id' => $context['offering_c']->id,
            ]))
            ->assertStatus(422)
            ->assertSee(__('exam.validation.selected_subject_not_in_hall_slot'));

        $emptyHall = $this->createHallAssignment($context['college'], 'قاعة بلا مواد', '2026-08-13', '12:00:00');

        $this
            ->actingAs($user)
            ->get(route('filament.adminpanel.hall-assignments.attendance-print.show', [
                'hallAssignment' => $emptyHall,
            ]))
            ->assertStatus(422)
            ->assertSee(__('exam.validation.no_hall_subjects_for_attendance_slot'));
    }

    protected function assignInvigilator(
        College $college,
        ExamHall $hall,
        SubjectExamOffering $offering,
        InvigilationRole $role,
        string $name,
    ): void {
        $invigilator = Invigilator::query()->create([
            'college_id' => $college->id,
            'name' => $name,
            'phone' => '09'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => $role->value,
            'max_assignments' => 10,
            'is_active' => true,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $college->id,
            'subject_exam_offering_id' => $offering->id,
            'exam_date' => '2026-01-28',
            'start_time' => '12:00:00',
            'end_time' => '14:00:00',
            'exam_hall_id' => $hall->id,
            'invigilator_id' => $invigilator->id,
            'invigilation_role' => $role->value,
            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
        ]);
    }

    protected function attendanceReportUser(College $college): User
    {
        $user = User::factory()->create(['college_id' => $college->id]);
        $user->assignRole(Role::findOrCreate(RoleNames::ADMIN, 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('viewAny', 'SubjectExamOffering'), 'web'));
        $user->givePermissionTo(Permission::findOrCreate(ShieldPermission::resource('view', 'SubjectExamOffering'), 'web'));

        return $user;
    }

    protected function createMultiSubjectHallContext(): array
    {
        $college = College::query()->create(['name' => 'كلية الاختبار', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم الاختبار', 'is_active' => true]);
        $studyLevel = StudyLevel::query()->create(['name' => 'الثانية', 'sort_order' => 2, 'is_active' => true]);
        $academicYear = AcademicYear::query()->create(['name' => '2026-2027', 'is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->create(['name' => 'الفصل الثاني', 'sort_order' => 2, 'is_active' => true]);
        $mainHallAssignment = $this->createHallAssignment($college, 'م 21', '2026-08-13', '12:00:00');
        $secondHallAssignment = $this->createHallAssignment($college, 'م 22', '2026-08-13', '12:00:00');
        $nextDayHallAssignment = $this->createHallAssignment($college, 'م 23', '2026-08-14', '12:00:00');

        $offeringA = $this->createOfferingWithStudent($college, $department, $studyLevel, $academicYear, $semester, $mainHallAssignment, 'المادة أ', 'طالب المادة أ', 'A-001', 1);
        $offeringB = $this->createOfferingWithStudent($college, $department, $studyLevel, $academicYear, $semester, $mainHallAssignment, 'المادة ب', 'طالب المادة ب', 'B-001', 2);
        $offeringC = $this->createOfferingWithStudent($college, $department, $studyLevel, $academicYear, $semester, $secondHallAssignment, 'المادة ج', 'طالب المادة ج', 'C-001', 1);
        $offeringD = $this->createOfferingWithStudent($college, $department, $studyLevel, $academicYear, $semester, $nextDayHallAssignment, 'المادة د', 'طالب المادة د', 'D-001', 1, '2026-08-14');

        $mainHallAssignment->update(['assigned_students_count' => 2, 'remaining_capacity' => 38]);

        return [
            'college' => $college,
            'main_hall_assignment' => $mainHallAssignment,
            'second_hall_assignment' => $secondHallAssignment,
            'next_day_hall_assignment' => $nextDayHallAssignment,
            'offering_a' => $offeringA,
            'offering_b' => $offeringB,
            'offering_c' => $offeringC,
            'offering_d' => $offeringD,
        ];
    }

    protected function createOfferingWithStudent(
        College $college,
        Department $department,
        StudyLevel $studyLevel,
        AcademicYear $academicYear,
        Semester $semester,
        HallAssignment $hallAssignment,
        string $subjectName,
        string $studentName,
        string $studentNumber,
        int $seatNumber,
        string $examDate = '2026-08-13',
    ): SubjectExamOffering {
        $subject = Subject::query()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'study_level_id' => $studyLevel->id,
            'name' => $subjectName,
            'is_active' => true,
            'is_shared_subject' => false,
            'shared_subject_scheduling_mode' => 'auto',
            'is_core_subject' => false,
            'preferred_exam_period' => 'none',
            'core_subject_priority' => 'preference',
        ]);
        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'exam_date' => $examDate,
            'exam_start_time' => '12:00:00',
            'status' => ExamOfferingStatus::Distributed->value,
        ]);

        HallAssignmentSubject::query()->create([
            'hall_assignment_id' => $hallAssignment->id,
            'subject_exam_offering_id' => $offering->id,
            'assigned_students_count' => 1,
        ]);

        $student = ExamStudent::query()->create([
            'subject_exam_offering_id' => $offering->id,
            'student_number' => $studentNumber,
            'full_name' => $studentName,
            'student_type' => ExamStudentType::Regular->value,
        ]);

        ExamStudentHallAssignment::query()->create([
            'exam_student_id' => $student->id,
            'hall_assignment_id' => $hallAssignment->id,
            'subject_exam_offering_id' => $offering->id,
            'seat_number' => $seatNumber,
        ]);

        return $offering;
    }

    protected function createHallAssignment(College $college, string $name, string $examDate, string $startTime): HallAssignment
    {
        $hall = ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => $name,
            'location' => 'A',
            'capacity' => 40,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        return HallAssignment::query()->create([
            'exam_hall_id' => $hall->id,
            'exam_date' => $examDate,
            'exam_start_time' => $startTime,
            'college_id' => $college->id,
            'total_capacity' => 40,
            'assigned_students_count' => 1,
            'remaining_capacity' => 39,
        ]);
    }
}
