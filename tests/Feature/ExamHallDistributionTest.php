<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Exports\HallDistributionByPeriodExport;
use App\Exports\StudentDistributionUnassignedExport;
use App\Filament\Pages\Reports\HallDistributionByPeriodReport;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\ExamStudentHallAssignment;
use App\Models\HallAssignment;
use App\Models\HallAssignmentSubject;
use App\Models\Semester;
use App\Models\StudentDistributionRun;
use App\Models\StudyLevel;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Models\User;
use App\Services\ExamHallDistributionService;
use App\Services\HallAttendanceSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExamHallDistributionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_distributes_students_per_exam_slot_without_exceeding_hall_capacity(): void
    {
        $context = $this->createAcademicContext();

        $slotOfferings = collect([
            $this->createOfferingWithStudents($context, 'تحليل', 35),
            $this->createOfferingWithStudents($context, 'فيزياء', 35),
            $this->createOfferingWithStudents($context, 'جبر', 35),
            $this->createOfferingWithStudents($context, 'برمجة', 35),
        ]);

        $otherSlotOffering = $this->createOfferingWithStudents($context, 'خارج الجلسة', 20, startTime: '12:00:00');

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'القاعة A',
            'location' => 'المبنى الأول',
            'capacity' => 80,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'القاعة B',
            'location' => 'المبنى الثاني',
            'capacity' => 80,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::Medium->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($slotOfferings->first());

        $this->assertSame('success', $result['status']);
        $this->assertSame(140, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertSame(2, $result['used_halls_count']);

        $hallAssignments = HallAssignment::query()
            ->with(['assignmentSubjects', 'studentAssignments'])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $hallAssignments);
        $this->assertTrue($hallAssignments->every(fn (HallAssignment $assignment): bool => $assignment->assigned_students_count <= $assignment->total_capacity));
        $this->assertSame(140, ExamStudentHallAssignment::query()->count());
        $hallAssignments->each(function (HallAssignment $assignment): void {
            $seatNumbers = $assignment->studentAssignments->pluck('seat_number');

            $this->assertFalse($seatNumbers->contains(null));
            $this->assertSame($seatNumbers->count(), $seatNumbers->unique()->count());
            $this->assertSame(range(1, $seatNumbers->count()), $seatNumbers->sort()->values()->all());
        });
        $this->assertSame(0, $otherSlotOffering->studentHallAssignments()->count());

        $slotOfferings->each(function (SubjectExamOffering $offering): void {
            $this->assertSame(ExamOfferingStatus::Distributed, $offering->fresh()->status);
        });

        $this->assertSame(ExamOfferingStatus::Draft, $otherSlotOffering->fresh()->status);
    }

    #[Test]
    public function it_reports_unassigned_students_when_halls_are_insufficient(): void
    {
        $context = $this->createAcademicContext();

        $slotOffering = $this->createOfferingWithStudents($context, 'إحصاء', 130);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'القاعة المحدودة',
            'location' => 'الطابق الأرضي',
            'capacity' => 80,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($slotOffering);

        $this->assertSame('warning', $result['status']);
        $this->assertSame(80, $result['assigned_students_count']);
        $this->assertSame(50, $result['unassigned_students_count']);
        $this->assertSame(1, $result['used_halls_count']);
        $this->assertSame(80, ExamStudentHallAssignment::query()->count());
        $this->assertSame(ExamOfferingStatus::Ready, $slotOffering->fresh()->status);
    }

    #[Test]
    public function it_can_prevent_multiple_subjects_from_sharing_the_same_hall(): void
    {
        $context = $this->createAcademicContext();

        $firstOffering = $this->createOfferingWithStudents($context, 'تحليل', 5);
        $secondOffering = $this->createOfferingWithStudents($context, 'فيزياء', 5);

        foreach (['القاعة الأولى', 'القاعة الثانية'] as $hallName) {
            ExamHall::query()->create([
                'college_id' => $context['college']->id,
                'name' => $hallName,
                'location' => 'المبنى الأول',
                'capacity' => 10,
                'hall_type' => ExamHallType::Small->value,
                'priority' => ExamHallPriority::High->value,
                'is_active' => true,
            ]);
        }

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $firstOffering,
            allowMultipleSubjectsPerHall: false,
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['used_halls_count']);
        $this->assertSame(10, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);

        $hallAssignments = HallAssignment::query()
            ->with('assignmentSubjects')
            ->get();

        $this->assertCount(2, $hallAssignments);
        $this->assertTrue($hallAssignments->every(fn (HallAssignment $assignment): bool => $assignment->assignmentSubjects->count() === 1));
        $this->assertSame(5, $secondOffering->studentHallAssignments()->count());
    }

    #[Test]
    public function it_allows_multiple_normal_subjects_to_share_one_hall_when_enabled(): void
    {
        $context = $this->createAcademicContext();

        $firstOffering = $this->createOfferingWithStudents($context, 'تحليل', 6);
        $secondOffering = $this->createOfferingWithStudents($context, 'فيزياء', 4);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'القاعة المشتركة',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة احتياطية',
            'location' => 'المبنى الثاني',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::Medium->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $firstOffering,
            allowMultipleSubjectsPerHall: true,
        );

        $assignment = HallAssignment::query()
            ->with(['examHall', 'assignmentSubjects'])
            ->firstOrFail();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['used_halls_count']);
        $this->assertSame(10, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertSame('القاعة المشتركة', $assignment->examHall?->name);
        $this->assertSame(0, $assignment->remaining_capacity);
        $this->assertCount(2, $assignment->assignmentSubjects);
        $this->assertSame(6, $firstOffering->studentHallAssignments()->count());
        $this->assertSame(4, $secondOffering->studentHallAssignments()->count());
    }

    #[Test]
    public function it_reserves_half_of_a_normal_hall_for_a_second_large_normal_subject_when_mixing_is_enabled(): void
    {
        $context = $this->createAcademicContext();

        $firstOffering = $this->createOfferingWithStudents($context, 'تحليل كبير', 50);
        $secondOffering = $this->createOfferingWithStudents($context, 'فيزياء كبيرة', 50);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة مشتركة 100',
            'location' => 'المبنى الأول',
            'capacity' => 100,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_drawing_studio' => false,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة أقل أولوية',
            'location' => 'المبنى الثاني',
            'capacity' => 100,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::Medium->value,
            'is_drawing_studio' => false,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $firstOffering,
            allowMultipleSubjectsPerHall: true,
        );

        $assignment = HallAssignment::query()
            ->with(['examHall', 'assignmentSubjects'])
            ->firstOrFail();
        $assignedByOffering = $assignment->assignmentSubjects
            ->pluck('assigned_students_count', 'subject_exam_offering_id')
            ->all();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['used_halls_count']);
        $this->assertSame('قاعة مشتركة 100', $assignment->examHall?->name);
        $this->assertSame(0, $assignment->remaining_capacity);
        $this->assertSame(50, $assignedByOffering[$firstOffering->id]);
        $this->assertSame(50, $assignedByOffering[$secondOffering->id]);
        $this->assertSame(1, HallAssignment::query()->count());
    }

    #[Test]
    public function it_assigns_seat_numbers_by_alphabetical_student_order_inside_each_hall_when_enabled(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithNamedStudents($context, 'ترتيب أبجدي', [
            ['004', 'محمد'],
            ['001', 'خالد'],
            ['002', 'أحمد'],
            ['003', 'باسل'],
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة D1',
            'location' => 'المبنى الأول',
            'capacity' => 4,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $offering,
            sortStudentsAlphabeticallyPerHall: true,
        );

        $assignments = ExamStudentHallAssignment::query()
            ->with('examStudent')
            ->orderBy('seat_number')
            ->get();

        $this->assertSame('success', $result['status']);
        $this->assertSame(['أحمد', 'باسل', 'خالد', 'محمد'], $assignments->map(fn (ExamStudentHallAssignment $assignment): string => $assignment->examStudent->full_name)->all());
        $this->assertSame([1, 2, 3, 4], $assignments->pluck('seat_number')->all());
    }

    #[Test]
    public function it_keeps_existing_student_order_inside_each_hall_when_alphabetical_sort_is_disabled(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithNamedStudents($context, 'ترتيب قديم', [
            ['004', 'محمد'],
            ['001', 'خالد'],
            ['002', 'أحمد'],
            ['003', 'باسل'],
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة D1',
            'location' => 'المبنى الأول',
            'capacity' => 4,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $offering,
            sortStudentsAlphabeticallyPerHall: false,
        );

        $assignments = ExamStudentHallAssignment::query()
            ->with('examStudent')
            ->orderBy('seat_number')
            ->get();

        $this->assertSame('success', $result['status']);
        $this->assertSame(['خالد', 'أحمد', 'باسل', 'محمد'], $assignments->map(fn (ExamStudentHallAssignment $assignment): string => $assignment->examStudent->full_name)->all());
        $this->assertSame([1, 2, 3, 4], $assignments->pluck('seat_number')->all());
    }

    #[Test]
    public function it_distributes_students_alphabetically_across_halls_when_enabled(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithNamedStudents($context, 'توزيع أبجدي عام', [
            ['001', 'مروان'],
            ['002', 'باسل'],
            ['003', 'ٱدم'],
            ['004', 'تامر'],
            ['005', 'أحمد'],
            ['006', 'إياد'],
        ]);

        foreach (['م10', 'م2', 'م3'] as $hallName) {
            ExamHall::query()->create([
                'college_id' => $context['college']->id,
                'name' => $hallName,
                'location' => 'المبنى الأول',
                'capacity' => 2,
                'hall_type' => ExamHallType::Small->value,
                'priority' => ExamHallPriority::High->value,
                'is_active' => true,
            ]);
        }

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $offering,
            distributeStudentsAlphabeticallyAcrossHalls: true,
        );

        $assignmentsByHall = HallAssignment::query()
            ->with(['examHall', 'studentAssignments.examStudent'])
            ->get()
            ->mapWithKeys(fn (HallAssignment $assignment): array => [
                $assignment->examHall?->name => $assignment->studentAssignments
                    ->sortBy('seat_number')
                    ->map(fn (ExamStudentHallAssignment $studentAssignment): string => $studentAssignment->examStudent->full_name)
                    ->values()
                    ->all(),
            ])
            ->all();

        $this->assertSame('success', $result['status']);
        $this->assertSame(6, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertSame(['أحمد', 'ٱدم'], $assignmentsByHall['م2']);
        $this->assertSame(['إياد', 'باسل'], $assignmentsByHall['م3']);
        $this->assertSame(['تامر', 'مروان'], $assignmentsByHall['م10']);
        $this->assertTrue(HallAssignment::query()->get()->every(fn (HallAssignment $assignment): bool => $assignment->assigned_students_count <= $assignment->total_capacity));

        $hallAssignments = app(HallAttendanceSheetService::class)->hallAssignmentsForSlot(
            collegeId: $context['college']->id,
            examDate: '2026-06-01',
            examStartTime: '09:00:00',
        );
        $sheets = app(HallAttendanceSheetService::class)->viewData($hallAssignments)['sheets'];

        $this->assertStringContainsString('م2', $sheets[0]['hall_name']);
        $this->assertSame(['أحمد', 'ٱدم'], collect($sheets[0]['students'])->pluck('full_name')->all());
    }

    #[Test]
    public function it_keeps_sort_inside_each_hall_behavior_separate_from_global_alphabetical_distribution(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithNamedStudents($context, 'ترتيب داخل القاعة فقط', [
            ['001', 'مروان'],
            ['002', 'باسل'],
            ['003', 'أحمد'],
            ['004', 'تامر'],
        ]);

        foreach (['م1', 'م2'] as $hallName) {
            ExamHall::query()->create([
                'college_id' => $context['college']->id,
                'name' => $hallName,
                'location' => 'المبنى الأول',
                'capacity' => 2,
                'hall_type' => ExamHallType::Small->value,
                'priority' => ExamHallPriority::High->value,
                'is_active' => true,
            ]);
        }

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $offering,
            sortStudentsAlphabeticallyPerHall: true,
        );

        $assignmentsByHall = HallAssignment::query()
            ->with(['examHall', 'studentAssignments.examStudent'])
            ->get()
            ->mapWithKeys(fn (HallAssignment $assignment): array => [
                $assignment->examHall?->name => $assignment->studentAssignments
                    ->sortBy('seat_number')
                    ->map(fn (ExamStudentHallAssignment $studentAssignment): string => $studentAssignment->examStudent->full_name)
                    ->values()
                    ->all(),
            ])
            ->all();

        $this->assertSame('success', $result['status']);
        $this->assertSame(['باسل', 'مروان'], $assignmentsByHall['م1']);
        $this->assertSame(['أحمد', 'تامر'], $assignmentsByHall['م2']);
    }

    #[Test]
    public function it_sorts_students_across_multiple_subjects_inside_the_same_hall_when_enabled(): void
    {
        $context = $this->createAcademicContext();
        $firstOffering = $this->createOfferingWithNamedStudents($context, 'مادة أولى', [
            ['004', 'محمد'],
            ['001', 'أحمد'],
        ]);
        $this->createOfferingWithNamedStudents($context, 'مادة ثانية', [
            ['003', 'خالد'],
            ['002', 'باسل'],
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة مشتركة',
            'location' => 'المبنى الأول',
            'capacity' => 4,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $firstOffering,
            allowMultipleSubjectsPerHall: true,
            sortStudentsAlphabeticallyPerHall: true,
        );

        $hallAssignment = HallAssignment::query()
            ->with(['assignmentSubjects', 'studentAssignments.examStudent'])
            ->firstOrFail();
        $assignments = $hallAssignment->studentAssignments->sortBy('seat_number')->values();

        $this->assertSame('success', $result['status']);
        $this->assertCount(2, $hallAssignment->assignmentSubjects);
        $this->assertSame(['أحمد', 'باسل', 'خالد', 'محمد'], $assignments->map(fn (ExamStudentHallAssignment $assignment): string => $assignment->examStudent->full_name)->all());
        $this->assertSame([1, 2, 3, 4], $assignments->pluck('seat_number')->all());
    }

    #[Test]
    public function it_stores_the_alphabetical_hall_sort_option_in_global_distribution_summary(): void
    {
        $context = $this->createAcademicContext();
        $this->createOfferingWithNamedStudents($context, 'تخزين الإعداد', [
            ['004', 'محمد'],
            ['001', 'خالد'],
            ['002', 'أحمد'],
            ['003', 'باسل'],
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة D1',
            'location' => 'المبنى الأول',
            'capacity' => 4,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            sortStudentsAlphabeticallyPerHall: true,
            distributeStudentsAlphabeticallyAcrossHalls: true,
        );

        $run = StudentDistributionRun::query()->latest('id')->firstOrFail();

        $this->assertSame('success', $result['status']);
        $this->assertTrue($run->summary_json['settings']['sort_students_alphabetically_per_hall'] ?? false);
        $this->assertTrue($run->summary_json['sort_students_alphabetically_per_hall'] ?? false);
        $this->assertTrue($run->summary_json['settings']['distribute_students_alphabetically_across_halls'] ?? false);
        $this->assertTrue($run->summary_json['distribute_students_alphabetically_across_halls'] ?? false);
    }

    #[Test]
    public function it_uses_remaining_hall_capacity_for_more_than_three_normal_subjects_when_enabled(): void
    {
        $context = $this->createAcademicContext();

        $offerings = collect([
            $this->createOfferingWithStudents($context, 'تحليل', 1),
            $this->createOfferingWithStudents($context, 'فيزياء', 1),
            $this->createOfferingWithStudents($context, 'جبر', 1),
            $this->createOfferingWithStudents($context, 'برمجة', 1),
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة تسع كل المواد',
            'location' => 'المبنى الأول',
            'capacity' => 4,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة لا يجب استخدامها',
            'location' => 'المبنى الثاني',
            'capacity' => 4,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::Medium->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $offerings->first(),
            allowMultipleSubjectsPerHall: true,
        );

        $assignment = HallAssignment::query()
            ->with(['examHall', 'assignmentSubjects'])
            ->firstOrFail();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['used_halls_count']);
        $this->assertSame(4, $assignment->assignmentSubjects->count());
        $this->assertSame(0, $assignment->remaining_capacity);
        $this->assertSame('قاعة تسع كل المواد', $assignment->examHall?->name);
        $this->assertSame(1, HallAssignment::query()->count());
    }

    #[Test]
    public function global_distribution_rebuilds_existing_single_subject_halls_when_mixing_is_enabled(): void
    {
        $context = $this->createAcademicContext();

        $firstOffering = $this->createOfferingWithStudents($context, 'تحليل', 40);
        $secondOffering = $this->createOfferingWithStudents($context, 'فيزياء', 30);

        foreach (['القاعة ذات الأولوية', 'قاعة احتياطية'] as $index => $hallName) {
            ExamHall::query()->create([
                'college_id' => $context['college']->id,
                'name' => $hallName,
                'location' => 'المبنى الأول',
                'capacity' => 100,
                'hall_type' => ExamHallType::Large->value,
                'priority' => $index === 0 ? ExamHallPriority::High->value : ExamHallPriority::Medium->value,
                'is_active' => true,
            ]);
        }

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
            allowMultipleSubjectsPerHall: false,
        );

        $this->assertSame(2, HallAssignment::query()->count());
        $this->assertTrue(HallAssignment::query()
            ->with('assignmentSubjects')
            ->get()
            ->every(fn (HallAssignment $assignment): bool => $assignment->assignmentSubjects->count() === 1));

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: false,
            allowMultipleSubjectsPerHall: true,
        );

        $assignment = HallAssignment::query()
            ->with(['examHall', 'assignmentSubjects'])
            ->firstOrFail();

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['skipped_slots_count']);
        $this->assertSame(1, $result['distributed_slots_count']);
        $this->assertSame(1, HallAssignment::query()->count());
        $this->assertSame('القاعة ذات الأولوية', $assignment->examHall?->name);
        $this->assertCount(2, $assignment->assignmentSubjects);
        $this->assertSame(30, $assignment->remaining_capacity);
        $this->assertSame(40, $firstOffering->studentHallAssignments()->count());
        $this->assertSame(30, $secondOffering->studentHallAssignments()->count());
    }

    #[Test]
    public function it_reuses_high_priority_halls_for_different_exam_periods(): void
    {
        $context = $this->createAcademicContext();

        $morningOffering = $this->createOfferingWithStudents($context, 'تحليل صباحي', 8, startTime: '09:00:00');
        $eveningOffering = $this->createOfferingWithStudents($context, 'تحليل مسائي', 8, startTime: '12:00:00');

        $highPriorityHall = ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة أولوية عالية',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة أولوية منخفضة',
            'location' => 'المبنى الثاني',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::Low->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
        );

        $usedHallIdsByTime = HallAssignment::query()
            ->orderBy('exam_start_time')
            ->pluck('exam_hall_id', 'exam_start_time')
            ->all();

        $this->assertSame('success', $result['status']);
        $this->assertCount(2, $usedHallIdsByTime);
        $this->assertSame($highPriorityHall->id, $usedHallIdsByTime['09:00:00']);
        $this->assertSame($highPriorityHall->id, $usedHallIdsByTime['12:00:00']);
        $this->assertSame(8, $morningOffering->studentHallAssignments()->count());
        $this->assertSame(8, $eveningOffering->studentHallAssignments()->count());
    }

    #[Test]
    public function it_restricts_drawing_subjects_to_drawing_studios_without_mixing_with_regular_subjects(): void
    {
        $context = $this->createAcademicContext();

        $drawingOffering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'رسم معماري',
            studentsCount: 6,
            isDrawingSubject: true,
        );
        $regularOffering = $this->createOfferingWithStudents($context, 'تحليل إنشائي', 6);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم 1',
            'location' => 'مبنى العمارة',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($drawingOffering);

        $this->assertSame('success', $result['status']);
        $this->assertSame(6, $drawingOffering->studentHallAssignments()->count());
        $this->assertSame(6, $regularOffering->studentHallAssignments()->count());

        $summary = app(ExamHallDistributionService::class)->getSlotSummary($drawingOffering);
        $drawingHall = collect($summary['hall_assignments'])->firstWhere('is_drawing_studio', true);
        $regularHall = collect($summary['hall_assignments'])->firstWhere('is_drawing_studio', false);

        $this->assertNotNull($drawingHall);
        $this->assertNotNull($regularHall);
        $this->assertTrue(collect($drawingHall['subjects'])->every(fn (array $subject): bool => (bool) $subject['is_drawing_subject']));
        $this->assertTrue(collect($regularHall['subjects'])->every(fn (array $subject): bool => ! (bool) $subject['is_drawing_subject']));
    }

    #[Test]
    public function drawing_subject_uses_only_drawing_studios(): void
    {
        $context = $this->createAcademicContext();
        $drawingOffering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'رسم حر',
            studentsCount: 8,
            isDrawingSubject: true,
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية واسعة',
            'location' => 'المبنى الأول',
            'capacity' => 20,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم الرسم',
            'location' => 'مبنى الفنون',
            'capacity' => 8,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::Medium->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($drawingOffering);

        $this->assertSame('success', $result['status']);
        $this->assertSame(8, $drawingOffering->studentHallAssignments()->count());
        $this->assertTrue(HallAssignment::query()
            ->with('examHall')
            ->get()
            ->every(fn (HallAssignment $assignment): bool => (bool) $assignment->examHall?->is_drawing_studio));
    }

    #[Test]
    public function normal_subject_does_not_use_drawing_studios_by_default(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents($context, 'تحليل عادي', 8);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية محدودة',
            'location' => 'المبنى الأول',
            'capacity' => 5,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم احتياطي',
            'location' => 'مبنى الفنون',
            'capacity' => 10,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($offering);

        $this->assertSame('warning', $result['status']);
        $this->assertSame(5, $result['assigned_students_count']);
        $this->assertSame(3, $result['unassigned_students_count']);
        $this->assertTrue(HallAssignment::query()
            ->with('examHall')
            ->get()
            ->every(fn (HallAssignment $assignment): bool => ! (bool) $assignment->examHall?->is_drawing_studio));
    }

    #[Test]
    public function normal_subject_can_use_drawing_studios_only_when_enabled(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents($context, 'تحليل يحتاج سعة', 8);
        $context['college']->update([
            'allow_normal_subjects_in_drawing_studios' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية محدودة',
            'location' => 'المبنى الأول',
            'capacity' => 5,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::Medium->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم احتياطي',
            'location' => 'مبنى الفنون',
            'capacity' => 10,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($offering);

        $this->assertSame('success', $result['status']);
        $this->assertSame(8, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertTrue(HallAssignment::query()
            ->with('examHall')
            ->get()
            ->contains(fn (HallAssignment $assignment): bool => (bool) $assignment->examHall?->is_drawing_studio));
    }

    #[Test]
    public function drawing_subject_fails_clearly_when_no_drawing_studio_capacity_is_available(): void
    {
        $context = $this->createAcademicContext();
        $drawingOffering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'رسم بلا مرسم',
            studentsCount: 4,
            isDrawingSubject: true,
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية فقط',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($drawingOffering);

        $this->assertSame('danger', $result['status']);
        $this->assertSame('لا توجد مراسم - مخابر كافية لتوزيع مادة الرسم.', $result['message']);
        $this->assertSame(0, $result['assigned_students_count']);
        $this->assertSame(4, $result['unassigned_students_count']);
        $this->assertSame(0, HallAssignment::query()->count());
    }

    #[Test]
    public function drawing_subject_leaves_students_unassigned_when_drawing_studio_capacity_is_insufficient(): void
    {
        $context = $this->createAcademicContext();
        $drawingOffering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'رسم بسعة ناقصة',
            studentsCount: 6,
            isDrawingSubject: true,
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم صغير',
            'location' => 'مبنى الفنون',
            'capacity' => 2,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية واسعة',
            'location' => 'المبنى الأول',
            'capacity' => 20,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
        );

        $this->assertSame('partial', $result['status']);
        $this->assertSame(2, $result['assigned_students_count']);
        $this->assertSame(4, $result['unassigned_students_count']);
        $this->assertSame('drawing_studio_capacity_insufficient', $result['failure_details'][0]['reason_code']);
        $this->assertSame(6, $result['failure_details'][0]['required_capacity']);
        $this->assertSame(2, $result['failure_details'][0]['nominal_capacity']);
        $this->assertSame(0, $result['failure_details'][0]['available_capacity']);
        $this->assertSame(0, $result['failure_details'][0]['usable_remaining_capacity']);
        $this->assertSame(4, $result['failure_details'][0]['capacity_shortage']);
        $this->assertSame([
            $result['failure_details'][0]['candidate_halls'][0]['hall_id'] => 0,
        ], $result['failure_details'][0]['remaining_capacity_by_hall']);
        $this->assertStringContainsString('مرسم', $result['failure_details'][0]['reason_message']);
        $this->assertSame(2, $drawingOffering->studentHallAssignments()->count());
        $this->assertTrue(HallAssignment::query()
            ->with('examHall')
            ->get()
            ->every(fn (HallAssignment $assignment): bool => (bool) $assignment->examHall?->is_drawing_studio));
        $this->assertDatabaseHas('student_distribution_run_issues', [
            'issue_type' => 'drawing_studio_capacity_insufficient',
            'affected_students_count' => 4,
        ]);
    }

    #[Test]
    public function drawing_subjects_can_share_the_same_drawing_studio_when_capacity_remains(): void
    {
        $context = $this->createAcademicContext();
        $firstDrawing = $this->createOfferingWithStudents($context, 'رسم A', 60, isDrawingSubject: true);
        $secondDrawing = $this->createOfferingWithStudents($context, 'رسم B', 30, isDrawingSubject: true);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم مشترك',
            'location' => 'مبنى الفنون',
            'capacity' => 100,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($firstDrawing);

        $assignment = HallAssignment::query()->with(['examHall', 'assignmentSubjects.subjectExamOffering.subject'])->firstOrFail();

        $this->assertSame('success', $result['status']);
        $this->assertSame(90, $result['assigned_students_count']);
        $this->assertSame(10, $assignment->remaining_capacity);
        $this->assertTrue((bool) $assignment->examHall?->is_drawing_studio);
        $expectedOfferingIds = [$firstDrawing->id, $secondDrawing->id];
        $actualOfferingIds = $assignment->assignmentSubjects->pluck('subject_exam_offering_id')->all();
        sort($expectedOfferingIds);
        sort($actualOfferingIds);

        $this->assertSame($expectedOfferingIds, $actualOfferingIds);
        $this->assertTrue($assignment->assignmentSubjects->every(
            fn (HallAssignmentSubject $subject): bool => (bool) $subject->subjectExamOffering?->subject?->is_drawing_subject,
        ));
    }

    #[Test]
    public function period_distribution_report_reflects_real_drawing_studio_sharing(): void
    {
        $context = $this->createAcademicContext();
        $this->actingAs(User::factory()->create(['college_id' => $context['college']->id]));

        $this->createOfferingWithStudents($context, 'رسم تقرير A', 4, isDrawingSubject: true);
        $this->createOfferingWithStudents($context, 'رسم تقرير B', 3, isDrawingSubject: true);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم التقرير',
            'location' => 'مبنى الفنون',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
        );

        $report = new HallDistributionByPeriodReport();
        $report->college_id = $context['college']->id;
        $report->academic_year_id = $context['academic_year']->id;
        $report->semester_id = $context['semester']->id;
        $report->date_from = '2026-06-01';
        $report->date_to = '2026-06-01';

        $reportData = $report->reportData();
        $period = $reportData['periods'][0];
        $hall = collect($period['halls'])->firstWhere('name', 'مرسم التقرير');
        $subjects = collect($period['subjects']);

        $this->assertNotNull($hall);
        $this->assertSame(4, $subjects->firstWhere('name', 'رسم تقرير A')['hall_counts'][$hall['id']]);
        $this->assertSame(3, $subjects->firstWhere('name', 'رسم تقرير B')['hall_counts'][$hall['id']]);

        $exportRows = collect((new HallDistributionByPeriodExport($reportData))->array());

        $this->assertTrue($exportRows->contains(fn (array $row): bool => in_array('مرسم التقرير', $row, true)));
        $this->assertTrue($exportRows->contains(fn (array $row): bool => ($row[0] ?? null) === 'رسم تقرير A'
            && ($row[1] ?? null) === 'قسم المعلوماتية'
            && ($row[3] ?? null) === 4
            && in_array(4, $row, true)));
        $this->assertNotEmpty(\Maatwebsite\Excel\Facades\Excel::raw(
            new HallDistributionByPeriodExport($reportData),
            \Maatwebsite\Excel\Excel::XLSX,
        ));
    }

    #[Test]
    public function drawing_subjects_do_not_share_halls_with_normal_subjects(): void
    {
        $context = $this->createAcademicContext();
        $this->createOfferingWithStudents($context, 'تحليل عادي', 80);
        $this->createOfferingWithStudents($context, 'رسم منفصل', 30, isDrawingSubject: true);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية',
            'location' => 'المبنى الأول',
            'capacity' => 80,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم منفصل',
            'location' => 'مبنى الفنون',
            'capacity' => 100,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
        );

        $this->assertTrue(HallAssignment::query()
            ->with('assignmentSubjects.subjectExamOffering.subject')
            ->get()
            ->every(function (HallAssignment $assignment): bool {
                $hasDrawing = $assignment->assignmentSubjects->contains(
                    fn (HallAssignmentSubject $subject): bool => (bool) $subject->subjectExamOffering?->subject?->is_drawing_subject,
                );
                $hasNormal = $assignment->assignmentSubjects->contains(
                    fn (HallAssignmentSubject $subject): bool => ! (bool) $subject->subjectExamOffering?->subject?->is_drawing_subject,
                );

                return ! ($hasDrawing && $hasNormal);
            }));
    }

    #[Test]
    public function invalid_mixed_drawing_and_normal_hall_is_reported_with_clear_reason_code(): void
    {
        $context = $this->createAcademicContext();
        $normalOffering = $this->createOfferingWithStudents($context, 'عادي داخل خلط خاطئ', 2);
        $drawingOffering = $this->createOfferingWithStudents($context, 'رسم داخل خلط خاطئ', 2, isDrawingSubject: true);

        $studio = ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم مختلط بشكل خاطئ',
            'location' => 'مبنى الفنون',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $assignment = HallAssignment::query()->create([
            'exam_hall_id' => $studio->id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'college_id' => $context['college']->id,
            'total_capacity' => 10,
            'assigned_students_count' => 4,
            'remaining_capacity' => 6,
        ]);
        HallAssignmentSubject::query()->create([
            'hall_assignment_id' => $assignment->id,
            'subject_exam_offering_id' => $normalOffering->id,
            'assigned_students_count' => 2,
        ]);
        HallAssignmentSubject::query()->create([
            'hall_assignment_id' => $assignment->id,
            'subject_exam_offering_id' => $drawingOffering->id,
            'assigned_students_count' => 2,
        ]);

        $method = new \ReflectionMethod(ExamHallDistributionService::class, 'invalidMixedDrawingAndNormalIssue');
        $method->setAccessible(true);
        $issue = $method->invoke(app(ExamHallDistributionService::class), [
            'college_id' => $context['college']->id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
        ]);

        $this->assertSame('invalid_mixed_drawing_and_normal_subjects', $issue['reason_code']);
        $this->assertSame('لا يجوز دمج مادة رسم مع مادة عادية داخل نفس القاعة. يمكن دمج مواد الرسم فقط مع مواد رسم أخرى ضمن المراسم/المخابر عند وجود سعة متبقية.', $issue['message']);
    }

    #[Test]
    public function separate_carry_distribution_reserves_drawing_studios_for_drawing_subjects_before_normal_students(): void
    {
        $context = $this->createAcademicContext();
        $context['college']->update(['allow_normal_subjects_in_drawing_studios' => true]);

        $drawingOffering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'رسم مستجد يحتاج المرسم',
            studentsCount: 4,
            studentTypes: array_fill(0, 4, ExamStudentType::Regular->value),
            isDrawingSubject: true,
        );
        $normalOffering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'حملة عاديون لا يسبقون الرسم',
            studentsCount: 4,
            studentTypes: array_fill(0, 4, ExamStudentType::Carry->value),
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم وحيد',
            'location' => 'مبنى الفنون',
            'capacity' => 4,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $drawingOffering,
            separateCarryStudents: true,
        );

        $this->assertSame('warning', $result['status']);
        $this->assertSame(4, $drawingOffering->studentHallAssignments()->count());
        $this->assertSame(0, $normalOffering->studentHallAssignments()->count());
        $this->assertTrue(HallAssignment::query()
            ->with('assignmentSubjects.subjectExamOffering.subject')
            ->get()
            ->every(fn (HallAssignment $assignment): bool => $assignment->assignmentSubjects->every(
                fn (HallAssignmentSubject $subject): bool => (bool) $subject->subjectExamOffering?->subject?->is_drawing_subject,
            )));
    }

    #[Test]
    public function normal_subject_cannot_use_remaining_capacity_in_drawing_studio_occupied_by_drawing_subjects(): void
    {
        $context = $this->createAcademicContext();
        $context['college']->update(['allow_normal_subjects_in_drawing_studios' => true]);
        $normalOffering = $this->createOfferingWithStudents($context, 'نظرية كبيرة', 70);
        $this->createOfferingWithStudents($context, 'رسم يشغل المرسم', 60, isDrawingSubject: true);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية محدودة',
            'location' => 'المبنى الأول',
            'capacity' => 50,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم فيه سعة متبقية',
            'location' => 'مبنى الفنون',
            'capacity' => 100,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
        );

        $normalStudentsInDrawingStudio = ExamStudentHallAssignment::query()
            ->where('subject_exam_offering_id', $normalOffering->id)
            ->whereHas('hallAssignment.examHall', fn ($query) => $query->where('is_drawing_studio', true))
            ->count();

        $this->assertSame('partial', $result['status']);
        $this->assertSame(0, $normalStudentsInDrawingStudio);
        $this->assertSame(50, $normalOffering->studentHallAssignments()->count());
    }

    #[Test]
    public function drawing_subject_never_uses_a_normal_hall(): void
    {
        $context = $this->createAcademicContext();
        $drawingOffering = $this->createOfferingWithStudents($context, 'رسم بلا بديل', 5, isDrawingSubject: true);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية واسعة',
            'location' => 'المبنى الأول',
            'capacity' => 100,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($drawingOffering);

        $this->assertSame('danger', $result['status']);
        $this->assertSame(0, $drawingOffering->studentHallAssignments()->count());
        $this->assertSame(0, HallAssignment::query()->count());
    }

    #[Test]
    public function drawing_remaining_capacity_ignores_studios_occupied_by_normal_subjects(): void
    {
        $context = $this->createAcademicContext();
        $drawingOffering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'رسم يحتاج مرسم متوافق',
            studentsCount: 50,
            isDrawingSubject: true,
        )->loadCount('examStudents');
        $normalOffering = $this->createOfferingWithStudents($context, 'مادة عادية في مرسم', 40)->loadCount('examStudents');

        $studio = ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم مشغول بمادة عادية',
            'location' => 'مبنى الفنون',
            'capacity' => 100,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $assignment = HallAssignment::query()->create([
            'exam_hall_id' => $studio->id,
            'exam_date' => '2026-06-01',
            'exam_start_time' => '09:00:00',
            'college_id' => $context['college']->id,
            'total_capacity' => 100,
            'assigned_students_count' => 40,
            'remaining_capacity' => 60,
        ]);
        HallAssignmentSubject::query()->create([
            'hall_assignment_id' => $assignment->id,
            'subject_exam_offering_id' => $normalOffering->id,
            'assigned_students_count' => 40,
        ]);

        $detail = $this->failureDetailForTest($drawingOffering, collect([$drawingOffering, $normalOffering]), collect([$studio]));
        $candidate = $detail['candidate_halls'][0];

        $this->assertSame(100, $detail['drawing_studio_capacity']);
        $this->assertSame(40, $detail['non_drawing_subject_used_capacity']);
        $this->assertSame(0, $detail['drawing_subject_usable_remaining_capacity']);
        $this->assertSame(50, $detail['capacity_shortage']);
        $this->assertSame(0, $candidate['remaining_capacity']);
        $this->assertSame(60, $candidate['raw_remaining_capacity']);
        $this->assertSame('normal_only', $candidate['hall_subject_mix_key']);
        $this->assertStringContainsString('مواد عادية', $candidate['exclusion_reason']);
    }

    #[Test]
    public function reports_explain_that_drawing_studio_remaining_capacity_is_for_drawing_subjects_only(): void
    {
        $context = $this->createAcademicContext();
        $this->createOfferingWithStudents($context, 'رسم تقرير', 70, isDrawingSubject: true);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم تقرير',
            'location' => 'مبنى الفنون',
            'capacity' => 40,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
        );

        $run = StudentDistributionRun::query()->latest('id')->firstOrFail();
        $html = view('pdf.student-distribution-run-summary', [
            'run' => $run,
            'summary' => $run->summary_json ?? [],
            'systemSetting' => (object) ['university_name' => 'جامعة الاختبار'],
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('يمكن دمجها فقط مع مواد رسم أخرى', $html);
        $this->assertStringContainsString('سعة قاعات المرسم/المخبر', $html);
        $this->assertStringContainsString('السعة المتبقية المتاحة لمواد الرسم فقط', $html);
        $this->assertStringContainsString('تحتوي مواد رسم فقط', $html);
    }

    #[Test]
    public function drawing_subject_with_carry_separation_uses_only_drawing_studios(): void
    {
        $context = $this->createAcademicContext();
        $drawingOffering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'رسم مع حملة',
            studentsCount: 4,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
            isDrawingSubject: true,
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم الرسم',
            'location' => 'مبنى الفنون',
            'capacity' => 4,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering(
            $drawingOffering,
            separateCarryStudents: true,
        );

        $this->assertSame('warning', $result['status']);
        $this->assertSame(4, $result['assigned_students_count']);
        $this->assertSame(0, $result['unassigned_students_count']);
        $this->assertTrue(HallAssignment::query()
            ->with('examHall')
            ->get()
            ->every(fn (HallAssignment $assignment): bool => (bool) $assignment->examHall?->is_drawing_studio));
    }

    #[Test]
    public function global_distribution_rebuilds_stale_normal_hall_assignments_after_subject_becomes_drawing(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents($context, 'تحول إلى رسم', 3);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة عادية',
            'location' => 'المبنى الأول',
            'capacity' => 3,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForOffering($offering);

        $this->assertTrue(HallAssignment::query()
            ->with('examHall')
            ->get()
            ->contains(fn (HallAssignment $assignment): bool => ! (bool) $assignment->examHall?->is_drawing_studio));

        $offering->subject()->update(['is_drawing_subject' => true]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم بديل',
            'location' => 'مبنى الفنون',
            'capacity' => 3,
            'hall_type' => ExamHallType::Small->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: false,
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['skipped_slots_count']);
        $this->assertSame(1, $result['distributed_slots_count']);
        $this->assertSame(3, $offering->studentHallAssignments()->count());
        $this->assertTrue(HallAssignment::query()
            ->with('examHall')
            ->get()
            ->every(fn (HallAssignment $assignment): bool => (bool) $assignment->examHall?->is_drawing_studio));
    }

    #[Test]
    public function it_keeps_existing_mixed_hall_behavior_when_carry_separation_is_disabled(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'مادة مختلطة',
            studentsCount: 6,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة واحدة',
            'location' => 'المبنى الأول',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($offering);
        $summary = app(ExamHallDistributionService::class)->getSlotSummary($offering);

        $this->assertSame('success', $result['status']);
        $this->assertSame('mixed', $summary['hall_assignments'][0]['hall_student_type_key']);
    }

    #[Test]
    public function it_separates_carry_students_from_regular_students_when_capacity_allows(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'مادة فصل الحملة',
            studentsCount: 8,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة حملة صغيرة',
            'location' => 'المبنى الأول',
            'capacity' => 2,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة مستجدين',
            'location' => 'المبنى الثاني',
            'capacity' => 10,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForOffering($offering, separateCarryStudents: true);
        $summary = app(ExamHallDistributionService::class)->getSlotSummary($offering);
        $hallTypes = collect($summary['hall_assignments'])->pluck('hall_student_type_key')->sort()->values()->all();

        $this->assertSame('success', $result['status']);
        $this->assertSame(['carry_only', 'regular_only'], $hallTypes);
        $this->assertSame(0, collect($summary['hall_assignments'])->where('hall_student_type_key', 'mixed')->count());
    }

    #[Test]
    public function it_records_a_warning_when_carry_separation_requires_fallback_mixing(): void
    {
        $context = $this->createAcademicContext();
        $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'مادة سعة غير كافية للفصل',
            studentsCount: 8,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة وحيدة',
            'location' => 'المبنى الأول',
            'capacity' => 8,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
            separateCarryStudents: true,
        );

        $this->assertSame('success_with_warnings', $result['status']);
        $this->assertSame(0, $result['unassigned_students']);
        $this->assertSame(0, $result['issue_slots_count']);
        $this->assertSame(1, $result['warning_slots_count']);
        $this->assertSame(1, $result['warnings_count']);
        $this->assertTrue($result['separate_carry_students']);
        $this->assertSame(2, $result['carry_students_count']);
        $this->assertSame(6, $result['regular_students_count']);
        $this->assertSame(1, $result['mixed_halls_count']);
        $this->assertSame(1, $result['carry_regular_mixing_cases_count']);
        $this->assertStringContainsString('لم يتم فصل جميع طلاب الحملة', $result['separation_status_message']);
        $this->assertDatabaseHas('student_distribution_runs', [
            'status' => 'success_with_warnings',
            'unassigned_students' => 0,
            'capacity_shortage' => 0,
        ]);
        $this->assertDatabaseMissing('student_distribution_run_issues', [
            'issue_type' => 'carry_regular_mixed_due_to_capacity',
        ]);
    }

    #[Test]
    public function distribution_reports_include_student_type_and_hall_classification(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'مادة تقرير الفصل',
            studentsCount: 4,
            studentTypes: [
                ExamStudentType::Regular->value,
                ExamStudentType::Regular->value,
                ExamStudentType::Carry->value,
                ExamStudentType::Carry->value,
            ],
        );

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة حملة',
            'location' => 'المبنى الأول',
            'capacity' => 2,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة مستجدين',
            'location' => 'المبنى الثاني',
            'capacity' => 2,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForOffering($offering, separateCarryStudents: true);
        $summary = app(ExamHallDistributionService::class)->getSlotSummary($offering);
        $html = view('pdf.hall-distribution-slot', [
            'summary' => $summary,
            'systemSetting' => (object) ['university_name' => 'جامعة الاختبار'],
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('نوع الطالب', $html);
        $this->assertStringContainsString('قاعة طلاب حملة', $html);
        $this->assertStringContainsString('قاعة طلاب مستجدين', $html);
        $this->assertContains('نوع الطالب', (new StudentDistributionUnassignedExport(new StudentDistributionRun))->headings());
    }

    #[Test]
    public function global_distribution_run_keeps_unassigned_snapshot_for_reports_even_if_live_data_changes(): void
    {
        $context = $this->createAcademicContext();

        $offering = $this->createOfferingWithStudents($context, 'هياكل', 5);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة أولى',
            'location' => 'المبنى الأول',
            'capacity' => 3,
            'hall_type' => ExamHallType::Small->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        /** @var StudentDistributionRun $firstRun */
        $firstRun = StudentDistributionRun::query()->oldest('id')->firstOrFail();
        $this->assertSame('partial', $firstRun->status);
        $this->assertSame(2, $firstRun->unassigned_students);
        $this->assertCount(2, $firstRun->summary_json['validation']['unassigned_students_list'] ?? []);

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة ثانية',
            'location' => 'المبنى الثاني',
            'capacity' => 5,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
            redistribute: true,
        );

        $this->assertSame(0, ExamStudent::query()->whereDoesntHave('hallAssignment')->count());
        $this->assertCount(2, app(ExamHallDistributionService::class)->unassignedStudentsForRun($firstRun->fresh('issues')));
        $this->assertSame(2, count($firstRun->fresh()->summary_json['validation']['unassigned_students_list'] ?? []));
        $this->assertSame($offering->id, $firstRun->fresh()->summary_json['validation']['unassigned_students_list'][0]['subject_exam_offering_id'] ?? null);
    }

    #[Test]
    public function global_distribution_run_stores_final_validation_based_on_real_assignments(): void
    {
        $context = $this->createAcademicContext();

        foreach (['تحليل', 'جبر', 'فيزياء', 'برمجة'] as $subjectName) {
            $this->createOfferingWithStudents($context, $subjectName, 1);
        }

        ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة واحدة',
            'location' => 'المبنى الأول',
            'capacity' => 4,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
            collegeId: $context['college']->id,
            fromDate: '2026-06-01',
            toDate: '2026-06-01',
        );

        $run = StudentDistributionRun::query()->latest('id')->firstOrFail();

        $this->assertSame('success', $result['status']);
        $this->assertSame('success', $run->status);
        $this->assertSame(0, $result['capacity_shortage']);
        $this->assertSame(4, $run->summary_json['validation']['expected_students'] ?? null);
        $this->assertSame(4, $run->summary_json['validation']['assigned_students'] ?? null);
        $this->assertSame(0, $run->summary_json['validation']['unassigned_students'] ?? null);
        $this->assertSame(4, $run->summary_json['validation']['used_hall_capacity'] ?? null);
        $this->assertSame(0, $run->summary_json['validation']['remaining_capacity'] ?? null);
    }

    #[Test]
    public function failure_detail_calculates_shortage_from_remaining_usable_capacity_not_nominal_minus_remaining(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'رسم هندسي 1',
            studentsCount: 258,
            date: '2026-07-08',
            startTime: '09:00:00',
            isDrawingSubject: true,
        )->loadCount('examStudents');

        $hall = ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'مرسم بحري',
            'location' => 'المبنى البحري',
            'capacity' => 559,
            'hall_type' => ExamHallType::Large->value,
            'is_drawing_studio' => true,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        HallAssignment::query()->create([
            'exam_hall_id' => $hall->id,
            'exam_date' => '2026-07-08',
            'exam_start_time' => '09:00:00',
            'college_id' => $context['college']->id,
            'total_capacity' => 559,
            'assigned_students_count' => 159,
            'remaining_capacity' => 400,
        ]);

        $detail = $this->failureDetailForTest($offering, collect([$offering]), collect([$hall]));

        $this->assertSame(258, $detail['students_count']);
        $this->assertSame(559, $detail['nominal_capacity']);
        $this->assertSame(159, $detail['reserved_or_used_capacity']);
        $this->assertSame(400, $detail['available_capacity']);
        $this->assertSame(400, $detail['usable_remaining_capacity']);
        $this->assertSame(0, $detail['capacity_shortage']);
        $this->assertSame(0, $detail['actual_shortage']);
        $this->assertSame(142, $detail['surplus_capacity']);
        $this->assertNotSame('drawing_studio_capacity_insufficient', $detail['reason_code']);
        $this->assertSame('remaining_capacity_calculation_mismatch', $detail['reason_code']);
    }

    #[Test]
    public function pinned_failure_detail_does_not_report_no_capacity_when_usable_capacity_covers_students(): void
    {
        $context = $this->createAcademicContext();
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: 'الاقتصاد إدارة المشاريع البرمجية',
            studentsCount: 207,
            date: '2026-07-27',
            startTime: '12:00:00',
        );
        $offering->update(['is_pinned' => true]);
        $offering = $offering->fresh()->loadCount('examStudents');

        $hall = ExamHall::query()->create([
            'college_id' => $context['college']->id,
            'name' => 'قاعة كبيرة',
            'location' => 'المبنى الرئيسي',
            'capacity' => 2963,
            'hall_type' => ExamHallType::Amphitheater->value,
            'is_drawing_studio' => false,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        $detail = $this->failureDetailForTest($offering, collect([$offering]), collect([$hall]));

        $this->assertSame(207, $detail['students_count']);
        $this->assertSame(2963, $detail['nominal_capacity']);
        $this->assertSame(2963, $detail['available_capacity']);
        $this->assertSame(2963, $detail['usable_remaining_capacity']);
        $this->assertSame(0, $detail['capacity_shortage']);
        $this->assertSame(0, $detail['actual_shortage']);
        $this->assertSame(2756, $detail['surplus_capacity']);
        $this->assertNotContains($detail['reason_code'], [
            'pinned_exam_no_capacity',
            'insufficient_capacity',
            'drawing_studio_capacity_insufficient',
        ]);
        $this->assertSame('remaining_capacity_calculation_mismatch', $detail['reason_code']);
        $this->assertStringContainsString('ليس بسبب نقص السعة', $detail['reason_message']);
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

    protected function createOfferingWithStudents(
        array $context,
        string $subjectName,
        int $studentsCount,
        string $date = '2026-06-01',
        string $startTime = '09:00:00',
        array|string|ExamStudentType $studentTypes = ExamStudentType::Regular,
        bool $isDrawingSubject = false,
    ): SubjectExamOffering {
        $subject = Subject::query()->create([
            'college_id' => $context['college']->id,
            'department_id' => $context['department']->id,
            'study_level_id' => $context['study_level']->id,
            'name' => $subjectName,
            'is_active' => true,
            'is_drawing_subject' => $isDrawingSubject,
        ]);

        $offering = SubjectExamOffering::query()->create([
            'subject_id' => $subject->id,
            'academic_year_id' => $context['academic_year']->id,
            'semester_id' => $context['semester']->id,
            'exam_date' => $date,
            'exam_start_time' => $startTime,
            'status' => ExamOfferingStatus::Draft->value,
        ]);

        for ($index = 1; $index <= $studentsCount; $index++) {
            $studentType = is_array($studentTypes)
                ? ($studentTypes[$index - 1] ?? ExamStudentType::Regular->value)
                : $studentTypes;
            $studentType = $studentType instanceof ExamStudentType ? $studentType->value : $studentType;

            ExamStudent::query()->create([
                'subject_exam_offering_id' => $offering->id,
                'student_number' => sprintf('%s-%03d', $offering->id, $index),
                'full_name' => sprintf('طالب %s %03d', $subjectName, $index),
                'student_type' => $studentType,
            ]);
        }

        return $offering;
    }

    /**
     * @param  array<int, array{0:string,1:string,2?:string|ExamStudentType}>  $students
     */
    protected function createOfferingWithNamedStudents(
        array $context,
        string $subjectName,
        array $students,
        string $date = '2026-06-01',
        string $startTime = '09:00:00',
    ): SubjectExamOffering {
        $offering = $this->createOfferingWithStudents(
            context: $context,
            subjectName: $subjectName,
            studentsCount: 0,
            date: $date,
            startTime: $startTime,
        );

        foreach ($students as $student) {
            $studentType = $student[2] ?? ExamStudentType::Regular->value;
            $studentType = $studentType instanceof ExamStudentType ? $studentType->value : $studentType;

            ExamStudent::query()->create([
                'subject_exam_offering_id' => $offering->id,
                'student_number' => $student[0],
                'full_name' => $student[1],
                'student_type' => $studentType,
            ]);
        }

        return $offering;
    }

    protected function failureDetailForTest(SubjectExamOffering $offering, mixed $slotOfferings, mixed $activeHalls): array
    {
        $method = new \ReflectionMethod(ExamHallDistributionService::class, 'distributionFailureDetail');
        $method->setAccessible(true);

        return $method->invoke(
            app(ExamHallDistributionService::class),
            $offering,
            $slotOfferings,
            $activeHalls,
            0,
            (int) $offering->exam_students_count,
            null,
        );
    }
}
