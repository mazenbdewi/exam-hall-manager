<?php

namespace App\Services;

use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Models\HallAssignment;
use App\Models\InvigilatorAssignment;
use App\Support\InstitutionSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class HallAttendanceSheetService
{
    /**
     * @return array<int, string>
     */
    public function hallAssignmentRelations(): array
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

    /**
     * @return Collection<int, HallAssignment>
     */
    public function hallAssignmentsForSlot(int $collegeId, string $examDate, string $examStartTime, ?int $hallAssignmentId = null): Collection
    {
        return HallAssignment::query()
            ->where('college_id', $collegeId)
            ->whereDate('exam_date', Carbon::parse($examDate)->toDateString())
            ->whereTime('exam_start_time', $this->normalizeTime($examStartTime))
            ->when($hallAssignmentId, fn ($query) => $query->whereKey($hallAssignmentId))
            ->with($this->hallAssignmentRelations())
            ->get()
            ->sort(function (HallAssignment $first, HallAssignment $second): int {
                $nameComparison = strnatcasecmp($first->examHall?->name ?? '', $second->examHall?->name ?? '');

                return $nameComparison !== 0
                    ? $nameComparison
                    : $first->getKey() <=> $second->getKey();
            })
            ->values();
    }

    /**
     * @param  Collection<int, HallAssignment>  $hallAssignments
     * @return array<string, mixed>
     */
    public function viewData(Collection $hallAssignments, bool $allowWithoutSupervisors = false, ?int $subjectExamOfferingId = null): array
    {
        $hallAssignments = $this->filterHallAssignmentsBySubject($hallAssignments, $subjectExamOfferingId);
        $firstAssignment = $hallAssignments->first();
        $invigilatorAssignments = $this->invigilatorAssignmentsFor($hallAssignments);
        $institution = InstitutionSettings::make();
        $systemSetting = $institution->reportContext($firstAssignment?->college?->name);
        $hasMissingSupervisorDistribution = $this->hasMissingSupervisorDistribution($hallAssignments, $invigilatorAssignments);

        return [
            'systemSetting' => $systemSetting,
            'logoDataUri' => $institution->logoDataUri(),
            'sheets' => $hallAssignments
                ->map(fn (HallAssignment $assignment): array => $this->sheetData(
                    assignment: $assignment,
                    invigilatorAssignments: $invigilatorAssignments->get($assignment->exam_hall_id, collect()),
                    universityName: $systemSetting->university_name,
                    subjectExamOfferingId: $subjectExamOfferingId,
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
     */
    public function hasAnyAssignedSubjects(Collection $hallAssignments): bool
    {
        return $hallAssignments->contains(fn (HallAssignment $assignment): bool => $assignment->assignmentSubjects->isNotEmpty());
    }

    /**
     * @param  Collection<int, HallAssignment>  $hallAssignments
     */
    public function hasSubjectInAssignments(Collection $hallAssignments, int $subjectExamOfferingId): bool
    {
        return $hallAssignments->contains(fn (HallAssignment $assignment): bool => $assignment->assignmentSubjects
            ->contains('subject_exam_offering_id', $subjectExamOfferingId));
    }

    /**
     * @param  Collection<int, HallAssignment>  $hallAssignments
     * @return Collection<int, Collection<int, InvigilatorAssignment>>
     */
    public function invigilatorAssignmentsFor(Collection $hallAssignments): Collection
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
     * @param  Collection<int, HallAssignment>  $hallAssignments
     * @param  Collection<int, Collection<int, InvigilatorAssignment>>  $invigilatorAssignments
     */
    public function hasMissingSupervisorDistribution(Collection $hallAssignments, Collection $invigilatorAssignments): bool
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
     * @param  Collection<int, InvigilatorAssignment>  $invigilatorAssignments
     * @return array<string, mixed>
     */
    public function sheetData(HallAssignment $assignment, Collection $invigilatorAssignments, string $universityName, ?int $subjectExamOfferingId = null): array
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
                'subject_name' => (string) ($studentAssignment->subjectExamOffering?->subject?->name ?? ''),
            ])
            ->all();
        $selectedSubjectName = $subjects->count() === 1 ? (string) ($subjects->first()['name'] ?? '') : null;

        return [
            'university_name' => $universityName,
            'college_name' => $assignment->college?->name ?? $subjects->pluck('college_name')->filter()->first() ?? '—',
            'department_name' => $this->departmentSummary($subjects),
            'report_title' => filled($subjectExamOfferingId) && filled($selectedSubjectName)
                ? 'تفقد القاعة - المادة: '.$selectedSubjectName
                : 'كشف تفقد القاعة الامتحانية',
            'day_date' => $this->dayDateLabel($assignment->exam_date),
            'exam_date' => $assignment->exam_date?->toDateString(),
            'exam_start_time' => $this->displayTime($assignment->exam_start_time),
            'period' => $this->timeRange($assignment->exam_start_time, $endTime),
            'subject_summary' => $this->subjectSummary($subjects),
            'hall_name' => trim(($assignment->examHall?->name ?? '—').(filled($assignment->examHall?->location) ? ' / '.$assignment->examHall->location : '')),
            'students_count' => count($students),
            'supervisors' => $this->supervisorRows($invigilatorAssignments),
            'students' => $students,
        ];
    }

    /**
     * @param  Collection<int, HallAssignment>  $hallAssignments
     * @return Collection<int, HallAssignment>
     */
    protected function filterHallAssignmentsBySubject(Collection $hallAssignments, ?int $subjectExamOfferingId): Collection
    {
        if (! $subjectExamOfferingId) {
            return $hallAssignments;
        }

        return $hallAssignments
            ->map(function (HallAssignment $assignment) use ($subjectExamOfferingId): HallAssignment {
                $studentAssignments = $assignment->studentAssignments
                    ->filter(fn ($studentAssignment): bool => (int) $studentAssignment->subject_exam_offering_id === $subjectExamOfferingId)
                    ->values();

                $assignmentSubjects = $assignment->assignmentSubjects
                    ->filter(fn ($assignmentSubject): bool => (int) $assignmentSubject->subject_exam_offering_id === $subjectExamOfferingId)
                    ->values()
                    ->each(function ($assignmentSubject) use ($studentAssignments): void {
                        $assignmentSubject->assigned_students_count = $studentAssignments->count();
                    });

                $assignment->setRelation('studentAssignments', $studentAssignments);
                $assignment->setRelation('assignmentSubjects', $assignmentSubjects);
                $assignment->assigned_students_count = $studentAssignments->count();

                return $assignment;
            })
            ->filter(fn (HallAssignment $assignment): bool => $assignment->assignmentSubjects->isNotEmpty())
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function subjectsForAssignment(HallAssignment $assignment): Collection
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
    public function departmentSummary(Collection $subjects): string
    {
        $departments = $subjects->pluck('department_name')->filter()->unique()->values();

        return $departments->isEmpty() ? '—' : $departments->implode('، ');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $subjects
     */
    public function subjectSummary(Collection $subjects): string
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
    public function resolveEndTime(Collection $subjects, Collection $invigilatorAssignments): ?string
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
    public function supervisorRows(Collection $assignments): array
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

    public function roleValue(InvigilatorAssignment $assignment): string
    {
        return $assignment->invigilation_role instanceof InvigilationRole
            ? $assignment->invigilation_role->value
            : (string) $assignment->invigilation_role;
    }

    public function roleLabel(string $role): string
    {
        return match ($role) {
            InvigilationRole::HallHead->value => 'رئيس القاعة',
            InvigilationRole::Secretary->value => 'أمين السر',
            InvigilationRole::Reserve->value => 'مراقب احتياط',
            default => 'مراقب',
        };
    }

    public function dayDateLabel(mixed $date): string
    {
        $carbonDate = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $this->arabicDayName($carbonDate).' '.$carbonDate->format('d-m-Y');
    }

    public function arabicDayName(CarbonInterface $date): string
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

    public function timeRange(mixed $startTime, mixed $endTime): string
    {
        $start = $this->displayTime($startTime);
        $end = $this->displayTime($endTime);

        return filled($end) ? $start.' - '.$end : $start;
    }

    public function displayTime(mixed $time): string
    {
        $normalized = $this->normalizeTime($time);

        return $normalized ? substr($normalized, 0, 5) : '—';
    }

    public function normalizeTime(mixed $time): ?string
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

    public function fontDataUri(string $filename): ?string
    {
        $path = resource_path('fonts/'.$filename);

        if (! File::exists($path)) {
            return null;
        }

        return 'data:font/ttf;base64,'.base64_encode((string) File::get($path));
    }
}
