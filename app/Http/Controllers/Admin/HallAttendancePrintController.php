<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Http\Controllers\Controller;
use App\Models\HallAssignment;
use App\Models\InvigilatorAssignment;
use App\Models\SystemSetting;
use App\Support\ExamCollegeScope;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class HallAttendancePrintController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'college_id' => ['required', 'integer'],
            'exam_date' => ['required', 'date'],
            'exam_start_time' => ['required', 'string'],
            'allow_without_supervisors' => ['sometimes', 'boolean'],
        ]);

        $collegeId = (int) $filters['college_id'];
        $examDate = Carbon::parse($filters['exam_date'])->toDateString();
        $examStartTime = $this->normalizeTime($filters['exam_start_time']);

        $this->authorizeCollegeAccess($collegeId);

        $hallAssignments = HallAssignment::query()
            ->where('college_id', $collegeId)
            ->whereDate('exam_date', $examDate)
            ->whereTime('exam_start_time', $examStartTime)
            ->with($this->hallAssignmentRelations())
            ->get()
            ->sortBy(fn (HallAssignment $assignment): string => ($assignment->examHall?->name ?? '').'|'.str_pad((string) $assignment->getKey(), 10, '0', STR_PAD_LEFT))
            ->values();

        abort_if($hallAssignments->isEmpty(), 404);

        return response()->view('admin.hall-attendance.print', $this->viewData(
            hallAssignments: $hallAssignments,
            allowWithoutSupervisors: (bool) ($filters['allow_without_supervisors'] ?? false),
        ));
    }

    public function show(Request $request, HallAssignment $hallAssignment): Response
    {
        $this->authorizeCollegeAccess((int) $hallAssignment->college_id);

        $hallAssignment->loadMissing($this->hallAssignmentRelations());

        return response()->view('admin.hall-attendance.print', $this->viewData(
            hallAssignments: collect([$hallAssignment]),
            allowWithoutSupervisors: $request->boolean('allow_without_supervisors'),
        ));
    }

    /**
     * @return array<int, string>
     */
    protected function hallAssignmentRelations(): array
    {
        return [
            'college',
            'examHall',
            'assignmentSubjects.subjectExamOffering.subject.department',
            'assignmentSubjects.subjectExamOffering.subject.college',
            'assignmentSubjects.subjectExamOffering.examScheduleDraftItem',
            'studentAssignments.examStudent',
            'studentAssignments.subjectExamOffering.subject',
        ];
    }

    protected function authorizeCollegeAccess(int $collegeId): void
    {
        abort_unless(SubjectExamOfferingResource::canViewAny(), 403);
        abort_unless(ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $collegeId), 403);
    }

    /**
     * @param  Collection<int, HallAssignment>  $hallAssignments
     * @return array<string, mixed>
     */
    protected function viewData(Collection $hallAssignments, bool $allowWithoutSupervisors = false): array
    {
        $firstAssignment = $hallAssignments->first();
        $invigilatorAssignments = $this->invigilatorAssignmentsFor($hallAssignments);
        $systemSetting = SystemSetting::current();
        $hasMissingSupervisorDistribution = $this->hasMissingSupervisorDistribution($hallAssignments, $invigilatorAssignments);

        return [
            'systemSetting' => $systemSetting,
            'sheets' => $hallAssignments
                ->map(fn (HallAssignment $assignment): array => $this->sheetData(
                    assignment: $assignment,
                    invigilatorAssignments: $invigilatorAssignments->get($assignment->exam_hall_id, collect()),
                    universityName: $systemSetting->university_name,
                ))
                ->values()
                ->all(),
            'printTitle' => $hallAssignments->count() > 1 ? 'طباعة تفقد كل القاعات' : 'طباعة تفقد القاعة',
            'supervisorWarning' => $allowWithoutSupervisors && $hasMissingSupervisorDistribution
                ? 'تنبيه: لم يتم توزيع المراقبين بعد، لذلك لا يحتوي هذا الكشف على أسماء رئيس القاعة وأمين السر والمراقبين.'
                : null,
            'regularFontDataUri' => $this->fontDataUri('NotoSansArabic-Regular.ttf'),
            'boldFontDataUri' => $this->fontDataUri('NotoSansArabic-Bold.ttf'),
            'slotLabel' => $firstAssignment
                ? $this->dayDateLabel($firstAssignment->exam_date).' - '.$this->displayTime($firstAssignment->exam_start_time)
                : null,
        ];
    }

    /**
     * @param  Collection<int, HallAssignment>  $hallAssignments
     * @param  Collection<int, Collection<int, InvigilatorAssignment>>  $invigilatorAssignments
     */
    protected function hasMissingSupervisorDistribution(Collection $hallAssignments, Collection $invigilatorAssignments): bool
    {
        $requiredRoles = collect([
            InvigilationRole::HallHead->value,
            InvigilationRole::Secretary->value,
            InvigilationRole::Regular->value,
        ]);

        return $hallAssignments
            ->pluck('exam_hall_id')
            ->filter()
            ->unique()
            ->contains(function ($hallId) use ($invigilatorAssignments, $requiredRoles): bool {
                $assignedRoles = $invigilatorAssignments
                    ->get($hallId, collect())
                    ->filter(fn (InvigilatorAssignment $assignment): bool => filled($assignment->invigilator_id))
                    ->map(fn (InvigilatorAssignment $assignment): string => $this->roleValue($assignment))
                    ->unique()
                    ->values();

                return $requiredRoles->diff($assignedRoles)->isNotEmpty();
            });
    }

    /**
     * @param  Collection<int, HallAssignment>  $hallAssignments
     * @return Collection<int, Collection<int, InvigilatorAssignment>>
     */
    protected function invigilatorAssignmentsFor(Collection $hallAssignments): Collection
    {
        $firstAssignment = $hallAssignments->first();

        if (! $firstAssignment) {
            return collect();
        }

        return InvigilatorAssignment::query()
            ->with('invigilator')
            ->where('college_id', $firstAssignment->college_id)
            ->whereDate('exam_date', $firstAssignment->exam_date?->toDateString())
            ->whereTime('start_time', $this->normalizeTime($firstAssignment->exam_start_time))
            ->whereIn('exam_hall_id', $hallAssignments->pluck('exam_hall_id')->filter()->unique()->values())
            ->where('assignment_status', '<>', InvigilatorAssignmentStatus::Cancelled->value)
            ->get()
            ->groupBy('exam_hall_id');
    }

    /**
     * @param  Collection<int, InvigilatorAssignment>  $invigilatorAssignments
     * @return array<string, mixed>
     */
    protected function sheetData(HallAssignment $assignment, Collection $invigilatorAssignments, string $universityName): array
    {
        $subjects = $this->subjectsForAssignment($assignment);
        $endTime = $this->resolveEndTime($subjects, $invigilatorAssignments);
        $students = $assignment->studentAssignments
            ->sortBy(fn ($studentAssignment): string => str_pad((string) ($studentAssignment->seat_number ?? PHP_INT_MAX), 10, '0', STR_PAD_LEFT)
                .'|'.($studentAssignment->examStudent?->student_number ?? '')
                .'|'.($studentAssignment->examStudent?->full_name ?? ''))
            ->values()
            ->map(fn ($studentAssignment, int $index): array => [
                'seat_number' => $studentAssignment->seat_number ?: $index + 1,
                'student_number' => (string) ($studentAssignment->examStudent?->student_number ?? ''),
                'full_name' => (string) ($studentAssignment->examStudent?->full_name ?? ''),
            ])
            ->all();

        return [
            'university_name' => $universityName,
            'college_name' => $assignment->college?->name ?? $subjects->pluck('college_name')->filter()->first() ?? '—',
            'department_name' => $this->departmentSummary($subjects),
            'day_date' => $this->dayDateLabel($assignment->exam_date),
            'period' => $this->timeRange($assignment->exam_start_time, $endTime),
            'subject_summary' => $this->subjectSummary($subjects),
            'hall_name' => trim(($assignment->examHall?->name ?? '—').(filled($assignment->examHall?->location) ? ' / '.$assignment->examHall->location : '')),
            'students_count' => (int) $assignment->assigned_students_count,
            'supervisors' => $this->supervisorRows($invigilatorAssignments),
            'students' => $students,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function subjectsForAssignment(HallAssignment $assignment): Collection
    {
        return $assignment->assignmentSubjects
            ->map(function ($assignmentSubject): array {
                $offering = $assignmentSubject->subjectExamOffering;
                $subject = $offering?->subject;

                return [
                    'name' => (string) ($subject?->name ?? '—'),
                    'students_count' => (int) $assignmentSubject->assigned_students_count,
                    'department_name' => (string) ($subject?->department?->name ?? ''),
                    'college_name' => (string) ($subject?->college?->name ?? ''),
                    'end_time' => $this->normalizeTime($offering?->examScheduleDraftItem?->end_time),
                ];
            })
            ->sortBy('name')
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $subjects
     */
    protected function departmentSummary(Collection $subjects): string
    {
        $departments = $subjects->pluck('department_name')->filter()->unique()->values();

        return $departments->isEmpty() ? '—' : $departments->implode('، ');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $subjects
     */
    protected function subjectSummary(Collection $subjects): string
    {
        if ($subjects->isEmpty()) {
            return '—';
        }

        return $subjects
            ->map(fn (array $subject): string => trim($subject['name'].' ('.$subject['students_count'].')'))
            ->implode('، ');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $subjects
     * @param  Collection<int, InvigilatorAssignment>  $invigilatorAssignments
     */
    protected function resolveEndTime(Collection $subjects, Collection $invigilatorAssignments): ?string
    {
        return collect([
            ...$subjects->pluck('end_time')->all(),
            ...$invigilatorAssignments->pluck('end_time')->all(),
        ])
            ->map(fn (mixed $time): ?string => $this->normalizeTime($time))
            ->filter()
            ->sort()
            ->last();
    }

    /**
     * @param  Collection<int, InvigilatorAssignment>  $assignments
     * @return array<int, array{name:string,role:string,notes:string}>
     */
    protected function supervisorRows(Collection $assignments): array
    {
        $rows = collect();

        foreach ([InvigilationRole::HallHead, InvigilationRole::Secretary, InvigilationRole::Regular, InvigilationRole::Reserve] as $role) {
            $roleAssignments = $assignments
                ->filter(fn (InvigilatorAssignment $assignment): bool => $this->roleValue($assignment) === $role->value)
                ->sortBy(fn (InvigilatorAssignment $assignment): string => $assignment->invigilator?->name ?? '')
                ->values();

            if ($roleAssignments->isEmpty() && in_array($role, [InvigilationRole::HallHead, InvigilationRole::Secretary, InvigilationRole::Regular], true)) {
                $rows->push([
                    'name' => '',
                    'role' => $this->roleLabel($role->value),
                    'notes' => '',
                ]);
            }

            foreach ($roleAssignments as $assignment) {
                $rows->push([
                    'name' => (string) ($assignment->invigilator?->name ?? ''),
                    'role' => $this->roleLabel($role->value),
                    'notes' => (string) ($assignment->notes ?? ''),
                ]);
            }
        }

        return $rows->values()->all();
    }

    protected function roleValue(InvigilatorAssignment $assignment): string
    {
        return $assignment->invigilation_role instanceof InvigilationRole
            ? $assignment->invigilation_role->value
            : (string) $assignment->invigilation_role;
    }

    protected function roleLabel(string $role): string
    {
        return match ($role) {
            InvigilationRole::HallHead->value => 'رئيس القاعة',
            InvigilationRole::Secretary->value => 'أمين السر',
            InvigilationRole::Reserve->value => 'مراقب احتياط',
            default => 'مراقب',
        };
    }

    protected function dayDateLabel(mixed $date): string
    {
        $carbonDate = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $this->arabicDayName($carbonDate).' '.$carbonDate->format('d-m-Y');
    }

    protected function arabicDayName(CarbonInterface $date): string
    {
        return match ((int) $date->dayOfWeek) {
            Carbon::SUNDAY => 'الأحد',
            Carbon::MONDAY => 'الإثنين',
            Carbon::TUESDAY => 'الثلاثاء',
            Carbon::WEDNESDAY => 'الأربعاء',
            Carbon::THURSDAY => 'الخميس',
            Carbon::FRIDAY => 'الجمعة',
            default => 'السبت',
        };
    }

    protected function timeRange(mixed $startTime, mixed $endTime): string
    {
        $start = $this->displayTime($startTime);
        $end = $this->displayTime($endTime);

        return filled($end) ? $start.' - '.$end : $start;
    }

    protected function displayTime(mixed $time): string
    {
        $normalized = $this->normalizeTime($time);

        return $normalized ? substr($normalized, 0, 5) : '—';
    }

    protected function normalizeTime(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        if ($time instanceof CarbonInterface) {
            return $time->format('H:i:s');
        }

        $value = trim((string) $time);

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value.':00';
        }

        return strlen($value) >= 8 ? substr($value, 0, 8) : $value;
    }

    protected function fontDataUri(string $filename): ?string
    {
        $path = resource_path('fonts/'.$filename);

        if (! File::exists($path)) {
            return null;
        }

        return 'data:font/ttf;base64,'.base64_encode((string) File::get($path));
    }
}
