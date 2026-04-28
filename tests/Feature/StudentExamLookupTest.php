<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamStudentType;
use App\Livewire\StudentExamLookup;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\ExamStudentHallAssignment;
use App\Models\HallAssignment;
use App\Models\Semester;
use App\Models\StudentPublicLookupSetting;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudentExamLookupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_only_shows_student_halls_inside_the_configured_visibility_window(): void
    {
        $this->createLookupContext([
            'visibility_before_minutes' => 30,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-10 08:29:00', config('app.timezone')));

        Livewire::test(StudentExamLookup::class)
            ->set('studentNumber', 'STD-100')
            ->call('search')
            ->assertSet('results', [])
            ->assertSee('لا توجد قاعات متاحة للعرض حاليًا.');

        Carbon::setTestNow(Carbon::parse('2026-05-10 08:30:00', config('app.timezone')));

        Livewire::test(StudentExamLookup::class)
            ->set('studentNumber', 'STD-100')
            ->call('search')
            ->assertSet('results.0.hall', 'مدرج A')
            ->assertSet('visibilityBadge', 'يتم عرض القاعة المتاحة حسب الوقت المحدد')
            ->assertDontSee('مدرج B');
    }

    #[Test]
    public function it_shows_all_student_assignments_when_public_settings_allow_all_assignments(): void
    {
        $this->createLookupContext([
            'show_all_student_assignments' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-09 08:00:00', config('app.timezone')));

        Livewire::test(StudentExamLookup::class)
            ->set('studentNumber', 'STD-100')
            ->call('search')
            ->assertSet('results.0.hall', 'مدرج A')
            ->assertSet('results.1.hall', 'مدرج B')
            ->assertSet('visibilityBadge', 'يتم عرض كامل القاعات الامتحانية');
    }

    #[Test]
    public function it_shows_unassigned_message_for_visible_exams_without_hall_distribution(): void
    {
        $this->createLookupContext(assignTodayHall: false);

        Carbon::setTestNow(Carbon::parse('2026-05-10 08:30:00', config('app.timezone')));

        Livewire::test(StudentExamLookup::class)
            ->set('studentNumber', 'STD-100')
            ->call('search')
            ->assertSet('results.0.assigned', false)
            ->assertSet('results.0.hall', null)
            ->assertSee('لم يتم تحديد القاعة بعد لهذه المادة.');
    }

    protected function createLookupContext(array $settings = [], bool $assignTodayHall = true): array
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
            'name' => 'السنة الأولى',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->create([
            'name' => '2025-2026',
            'is_active' => true,
            'is_current' => true,
        ]);

        $semester = Semester::query()->create([
            'name' => 'الفصل الأول',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $todaySubject = Subject::query()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'study_level_id' => $studyLevel->id,
            'name' => 'الخوارزميات',
            'is_active' => true,
        ]);

        $futureSubject = Subject::query()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'study_level_id' => $studyLevel->id,
            'name' => 'قواعد البيانات',
            'is_active' => true,
        ]);

        $todayOffering = $this->createOffering($todaySubject, $academicYear, $semester, '2026-05-10');
        $futureOffering = $this->createOffering($futureSubject, $academicYear, $semester, '2026-05-12');

        $todayStudent = $this->createStudent($todayOffering);
        $futureStudent = $this->createStudent($futureOffering);

        StudentPublicLookupSetting::query()->create([
            'college_id' => $college->id,
            'show_all_student_assignments' => false,
            'visibility_before_minutes' => 60,
            'visibility_after_minutes' => 180,
            ...$settings,
        ]);

        $todayHall = $this->createHall($college, 'مدرج A');
        $futureHall = $this->createHall($college, 'مدرج B');

        if ($assignTodayHall) {
            $this->assignHall($college, $todayHall, $todayStudent, $todayOffering);
        }

        $this->assignHall($college, $futureHall, $futureStudent, $futureOffering);

        return [
            'college' => $college,
            'today_student' => $todayStudent,
            'future_student' => $futureStudent,
        ];
    }

    protected function createOffering(Subject $subject, AcademicYear $academicYear, Semester $semester, string $date): SubjectExamOffering
    {
        return SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'exam_date' => $date,
            'exam_start_time' => '09:00:00',
        ]);
    }

    protected function createStudent(SubjectExamOffering $offering): ExamStudent
    {
        return ExamStudent::query()->create([
            'subject_exam_offering_id' => $offering->id,
            'student_number' => 'STD-100',
            'full_name' => 'محمد أحمد',
            'student_type' => ExamStudentType::Regular->value,
        ]);
    }

    protected function createHall(College $college, string $name): ExamHall
    {
        return ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => $name,
            'location' => 'المبنى الرئيسي',
            'capacity' => 120,
            'hall_type' => ExamHallType::Amphitheater->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);
    }

    protected function assignHall(College $college, ExamHall $hall, ExamStudent $student, SubjectExamOffering $offering): void
    {
        $hallAssignment = HallAssignment::query()->create([
            'exam_hall_id' => $hall->id,
            'exam_date' => $offering->exam_date,
            'exam_start_time' => $offering->exam_start_time,
            'college_id' => $college->id,
            'total_capacity' => $hall->capacity,
            'assigned_students_count' => 1,
            'remaining_capacity' => $hall->capacity - 1,
        ]);

        ExamStudentHallAssignment::query()->create([
            'exam_student_id' => $student->id,
            'hall_assignment_id' => $hallAssignment->id,
            'subject_exam_offering_id' => $offering->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
