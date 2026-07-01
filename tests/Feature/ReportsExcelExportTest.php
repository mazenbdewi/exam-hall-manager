<?php

namespace Tests\Feature;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Enums\StaffCategory;
use App\Exports\HallAttendanceByHallExport;
use App\Exports\InvigilatorDistributionByInvigilatorExport;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportsExcelExportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function hall_inspection_by_hall_excel_contains_halls_students_and_seat_numbers_for_selected_college(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة المعلوماتية', 'is_active' => true]);
        $otherCollege = College::query()->create(['name' => 'كلية أخرى', 'is_active' => true]);

        $this->createHallAttendanceData($college, 'قاعة 40', 'طالب داخل الكلية', '202640', 7);
        $this->createHallAttendanceData($otherCollege, 'قاعة خارجية', 'طالب خارج الكلية', '202699', 9);

        $contents = Excel::raw(
            new HallAttendanceByHallExport($college->id, '2026-01-28', '12:00:00'),
            ExcelFormat::XLSX,
        );
        $workbookText = $this->workbookText($contents);

        $this->assertStringStartsWith('PK', $contents);
        $this->assertStringContainsString('قاعة 40', $workbookText);
        $this->assertStringContainsString('طالب داخل الكلية', $workbookText);
        $this->assertStringContainsString('202640', $workbookText);
        $this->assertStringContainsString('7', $workbookText);
        $this->assertStringNotContainsString('قاعة خارجية', $workbookText);
        $this->assertStringNotContainsString('طالب خارج الكلية', $workbookText);
    }

    #[Test]
    public function hall_inspection_by_hall_excel_respects_selected_subject_filter(): void
    {
        $context = $this->createExcelHallWithTwoSubjects();

        $contents = Excel::raw(
            new HallAttendanceByHallExport(
                $context['college']->id,
                '2026-08-13',
                '12:00:00',
                $context['hall_assignment']->id,
                $context['offering_b']->id,
            ),
            ExcelFormat::XLSX,
        );
        $workbookText = $this->workbookText($contents);
        $rows = $this->firstWorksheetRows($contents);
        $studentHeaderRowIndex = collect($rows)->search(fn (array $row): bool => in_array(__('exam.fields.seat_number'), $row, true));
        $this->assertIsInt($studentHeaderRowIndex);
        $studentHeaders = array_values($rows[$studentHeaderRowIndex]);

        $this->assertStringStartsWith('PK', $contents);
        $this->assertStringContainsString('تفقد القاعة - المادة: المادة ب', $workbookText);
        $this->assertStringContainsString('طالب المادة ب', $workbookText);
        $this->assertStringContainsString('B-001', $workbookText);
        $this->assertStringContainsString(__('exam.fields.attendance'), $workbookText);
        $this->assertNotContains(__('exam.fields.subject'), $studentHeaders);
        $this->assertNotContains(__('exam.fields.signature'), $studentHeaders);
        $this->assertNotContains(__('exam.fields.notes'), $studentHeaders);
        $this->assertStringNotContainsString(__('exam.fields.signature'), $workbookText);
        $this->assertStringNotContainsString(__('exam.fields.notes'), $workbookText);
        $this->assertStringNotContainsString('طالب المادة أ', $workbookText);
        $this->assertStringNotContainsString('A-001', $workbookText);
    }

    #[Test]
    public function invigilator_distribution_by_invigilator_excel_contains_invigilators_and_halls_for_selected_college(): void
    {
        $college = College::query()->create(['name' => 'كلية الهندسة المعلوماتية', 'is_active' => true]);
        $otherCollege = College::query()->create(['name' => 'كلية أخرى', 'is_active' => true]);

        $this->createInvigilatorAssignmentData($college, 'قاعة مراقبة 1', 'د. سامر حسن');
        $this->createInvigilatorAssignmentData($otherCollege, 'قاعة مراقبة خارجية', 'د. خارج الكلية');

        $contents = Excel::raw(
            new InvigilatorDistributionByInvigilatorExport($college, null, null, '2026-01-28', '2026-01-28'),
            ExcelFormat::XLSX,
        );
        $workbookText = $this->workbookText($contents);
        $rows = $this->firstWorksheetRows($contents);
        $headerRowIndex = collect($rows)->search(fn (array $row): bool => in_array(__('exam.fields.exam_date'), $row, true));
        $this->assertIsInt($headerRowIndex);

        $headers = array_values($rows[$headerRowIndex]);
        $assignmentRow = array_values($rows[$headerRowIndex + 1]);
        $hallNameColumn = array_search(__('exam.fields.hall_name'), $headers, true);
        $hallLocationColumn = array_search(__('exam.fields.hall_location'), $headers, true);

        $this->assertStringStartsWith('PK', $contents);
        $this->assertStringContainsString('د. سامر حسن', $workbookText);
        $this->assertStringNotContainsString(__('exam.fields.status'), $workbookText);
        $this->assertStringContainsString(__('exam.fields.hall_name'), $workbookText);
        $this->assertStringContainsString(__('exam.fields.hall_location'), $workbookText);
        $this->assertIsInt($hallNameColumn);
        $this->assertIsInt($hallLocationColumn);
        $this->assertSame('', (string) ($assignmentRow[$hallNameColumn] ?? ''));
        $this->assertSame('', (string) ($assignmentRow[$hallLocationColumn] ?? ''));
        $this->assertStringContainsString('2026-01-28', $workbookText);
        $this->assertStringContainsString('12:00', $workbookText);
        $this->assertStringContainsString(__('exam.invigilation_roles.hall_head'), $workbookText);
        $this->assertStringContainsString('ملاحظة اختبار', $workbookText);
        $this->assertStringNotContainsString('قاعة مراقبة 1', $workbookText);
        $this->assertStringNotContainsString('د. خارج الكلية', $workbookText);
        $this->assertStringNotContainsString('قاعة مراقبة خارجية', $workbookText);
    }

    protected function createHallAttendanceData(College $college, string $hallName, string $studentName, string $studentNumber, int $seatNumber): void
    {
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم '.$college->id, 'is_active' => true]);
        $studyLevel = StudyLevel::query()->firstOrCreate(['name' => 'الأولى'], ['sort_order' => 1, 'is_active' => true]);
        $academicYear = AcademicYear::query()->firstOrCreate(['name' => '2025-2026'], ['is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->firstOrCreate(['name' => 'الفصل الأول'], ['sort_order' => 1, 'is_active' => true]);
        $subject = Subject::query()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'study_level_id' => $studyLevel->id,
            'name' => 'تحليل '.$college->id,
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
        $hallAssignment = $this->createUsedHall($college, $hallName);

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
    }

    protected function createInvigilatorAssignmentData(College $college, string $hallName, string $invigilatorName): void
    {
        $hallAssignment = $this->createUsedHall($college, $hallName);
        $invigilator = Invigilator::query()->create([
            'college_id' => $college->id,
            'name' => $invigilatorName,
            'phone' => '09'.str_pad((string) $college->id, 8, '0', STR_PAD_LEFT),
            'staff_category' => StaffCategory::Doctor->value,
            'invigilation_role' => InvigilationRole::HallHead->value,
            'max_assignments' => 10,
            'is_active' => true,
        ]);

        InvigilatorAssignment::query()->create([
            'college_id' => $college->id,
            'exam_date' => '2026-01-28',
            'start_time' => '12:00:00',
            'end_time' => '14:00:00',
            'exam_hall_id' => $hallAssignment->exam_hall_id,
            'invigilator_id' => $invigilator->id,
            'invigilation_role' => InvigilationRole::HallHead->value,
            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
            'notes' => 'ملاحظة اختبار',
        ]);
    }

    protected function createExcelHallWithTwoSubjects(): array
    {
        $college = College::query()->create(['name' => 'كلية الهندسة المعلوماتية', 'is_active' => true]);
        $department = Department::query()->create(['college_id' => $college->id, 'name' => 'قسم الاختبار', 'is_active' => true]);
        $studyLevel = StudyLevel::query()->firstOrCreate(['name' => 'الأولى'], ['sort_order' => 1, 'is_active' => true]);
        $academicYear = AcademicYear::query()->firstOrCreate(['name' => '2025-2026'], ['is_active' => true, 'is_current' => true]);
        $semester = Semester::query()->firstOrCreate(['name' => 'الفصل الأول'], ['sort_order' => 1, 'is_active' => true]);
        $hallAssignment = $this->createUsedHall($college, 'م 21', '2026-08-13');
        $offeringA = $this->createExcelOfferingStudent($college, $department, $studyLevel, $academicYear, $semester, $hallAssignment, 'المادة أ', 'طالب المادة أ', 'A-001', 1);
        $offeringB = $this->createExcelOfferingStudent($college, $department, $studyLevel, $academicYear, $semester, $hallAssignment, 'المادة ب', 'طالب المادة ب', 'B-001', 2);

        $hallAssignment->update(['assigned_students_count' => 2, 'remaining_capacity' => 38]);

        return [
            'college' => $college,
            'hall_assignment' => $hallAssignment,
            'offering_a' => $offeringA,
            'offering_b' => $offeringB,
        ];
    }

    protected function createExcelOfferingStudent(
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
            'exam_date' => '2026-08-13',
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

    protected function createUsedHall(College $college, string $hallName, string $examDate = '2026-01-28'): HallAssignment
    {
        $hall = ExamHall::query()->create([
            'college_id' => $college->id,
            'name' => $hallName,
            'location' => 'A',
            'capacity' => 40,
            'hall_type' => ExamHallType::Large->value,
            'priority' => ExamHallPriority::High->value,
            'is_active' => true,
        ]);

        return HallAssignment::query()->create([
            'exam_hall_id' => $hall->id,
            'exam_date' => $examDate,
            'exam_start_time' => '12:00:00',
            'college_id' => $college->id,
            'total_capacity' => 40,
            'assigned_students_count' => 1,
            'remaining_capacity' => 39,
        ]);
    }

    protected function workbookText(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-report-');
        file_put_contents($path, $contents);

        try {
            $spreadsheet = IOFactory::load($path);
            $parts = [];

            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $parts[] = $worksheet->getTitle();

                foreach ($worksheet->toArray(null, true, true, true) as $row) {
                    $parts[] = implode(' ', array_filter(array_map(
                        fn ($value): string => is_scalar($value) ? (string) $value : '',
                        $row,
                    )));
                }
            }

            return implode("\n", $parts);
        } finally {
            @unlink($path);
        }
    }

    protected function firstWorksheetRows(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-report-');
        file_put_contents($path, $contents);

        try {
            $spreadsheet = IOFactory::load($path);
            $worksheet = $spreadsheet->getSheet(0);

            return array_values(array_map(
                fn (array $row): array => array_values(array_map(
                    fn ($value): string => is_scalar($value) ? (string) $value : '',
                    $row,
                )),
                $worksheet->toArray('', true, true, false),
            ));
        } finally {
            @unlink($path);
        }
    }
}
