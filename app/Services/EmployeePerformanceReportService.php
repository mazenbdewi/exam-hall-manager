<?php

namespace App\Services;

use App\Enums\InvigilatorAssignmentStatus;
use App\Models\College;
use App\Models\HallAssignment;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorDistributionSetting;
use App\Models\InvigilatorHallRequirement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EmployeePerformanceReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(College $college, ?string $fromDate = null, ?string $toDate = null): array
    {
        $fromDate = $this->normalizeDate($fromDate) ?? $this->firstReportDate($college);
        $toDate = $this->normalizeDate($toDate) ?? $this->lastReportDate($college);

        $requiredCount = $this->requiredAssignmentsCount($college, $fromDate, $toDate);
        $assignments = $this->assignments($college, $fromDate, $toDate);
        $activeAssignments = $assignments->filter(fn (InvigilatorAssignment $assignment): bool => $this->isActiveAssignment($assignment));
        $completedCount = $activeAssignments->count();
        $shortageCount = max(0, $requiredCount - $completedCount);
        $completionPercentage = $requiredCount > 0
            ? min(100, round(($completedCount / $requiredCount) * 100, 1))
            : 0.0;

        $employees = $this->employees($college, $assignments, $activeAssignments, $completedCount);

        return [
            'college' => $college,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_employees' => Invigilator::query()->where('college_id', $college->getKey())->count(),
            'active_employees' => Invigilator::query()
                ->where('college_id', $college->getKey())
                ->where('is_active', true)
                ->where('workload_reduction_percentage', '<', 100)
                ->count(),
            'assigned_tasks_count' => $completedCount,
            'required_tasks_count' => $requiredCount,
            'shortage_count' => $shortageCount,
            'completion_percentage' => $completionPercentage,
            'days_count' => $activeAssignments
                ->map(fn (InvigilatorAssignment $assignment): ?string => $assignment->exam_date?->format('Y-m-d'))
                ->filter()
                ->unique()
                ->count(),
            'halls_count' => $activeAssignments->pluck('exam_hall_id')->filter()->unique()->count(),
            'employees' => $employees,
            'top_employees' => $employees->take(5)->values()->all(),
            'status_counts' => $this->statusCounts($assignments),
        ];
    }

    public function firstReportDate(College $college): ?string
    {
        return $this->dateBounds($college)['first'];
    }

    public function lastReportDate(College $college): ?string
    {
        return $this->dateBounds($college)['last'];
    }

    /**
     * @return array{first:?string,last:?string}
     */
    protected function dateBounds(College $college): array
    {
        $assignmentFirst = InvigilatorAssignment::query()
            ->where('college_id', $college->getKey())
            ->min('exam_date');
        $assignmentLast = InvigilatorAssignment::query()
            ->where('college_id', $college->getKey())
            ->max('exam_date');
        $hallFirst = HallAssignment::query()
            ->where('college_id', $college->getKey())
            ->where('assigned_students_count', '>', 0)
            ->min('exam_date');
        $hallLast = HallAssignment::query()
            ->where('college_id', $college->getKey())
            ->where('assigned_students_count', '>', 0)
            ->max('exam_date');

        $first = collect([$assignmentFirst, $hallFirst])->filter()->min();
        $last = collect([$assignmentLast, $hallLast])->filter()->max();

        return [
            'first' => $first ? substr((string) $first, 0, 10) : null,
            'last' => $last ? substr((string) $last, 0, 10) : null,
        ];
    }

    protected function requiredAssignmentsCount(College $college, ?string $fromDate, ?string $toDate): int
    {
        $requirements = InvigilatorHallRequirement::query()
            ->where('college_id', $college->getKey())
            ->get()
            ->keyBy(fn (InvigilatorHallRequirement $requirement): string => $requirement->hall_type?->value ?? (string) $requirement->hall_type);

        return HallAssignment::query()
            ->with('examHall')
            ->where('college_id', $college->getKey())
            ->where('assigned_students_count', '>', 0)
            ->when($fromDate, fn (Builder $query) => $query->whereDate('exam_date', '>=', $fromDate))
            ->when($toDate, fn (Builder $query) => $query->whereDate('exam_date', '<=', $toDate))
            ->whereHas('examHall', fn (Builder $query) => $query->where('is_active', true))
            ->get()
            ->sum(function (HallAssignment $assignment) use ($requirements): int {
                $hallType = $assignment->examHall?->hall_type?->value ?? (string) $assignment->examHall?->hall_type;
                $requirement = $requirements->get($hallType);

                if (! $requirement) {
                    return 0;
                }

                return (int) $requirement->hall_head_count
                    + (int) $requirement->secretary_count
                    + (int) $requirement->regular_count
                    + (int) $requirement->reserve_count;
            });
    }

    protected function assignments(College $college, ?string $fromDate, ?string $toDate): Collection
    {
        return InvigilatorAssignment::query()
            ->with(['invigilator', 'examHall'])
            ->where('college_id', $college->getKey())
            ->when($fromDate, fn (Builder $query) => $query->whereDate('exam_date', '>=', $fromDate))
            ->when($toDate, fn (Builder $query) => $query->whereDate('exam_date', '<=', $toDate))
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->get();
    }

    protected function isActiveAssignment(InvigilatorAssignment $assignment): bool
    {
        $status = $assignment->assignment_status instanceof InvigilatorAssignmentStatus
            ? $assignment->assignment_status->value
            : (string) $assignment->assignment_status;

        return in_array($status, [
            InvigilatorAssignmentStatus::Assigned->value,
            InvigilatorAssignmentStatus::Manual->value,
        ], true);
    }

    protected function employees(College $college, Collection $assignments, Collection $activeAssignments, int $completedCount): Collection
    {
        $setting = InvigilatorDistributionSetting::query()
            ->where('college_id', $college->getKey())
            ->first()
            ?? InvigilatorDistributionSetting::defaultsForCollege($college);
        $assignmentsByInvigilator = $assignments->groupBy('invigilator_id');
        $activeByInvigilator = $activeAssignments->groupBy('invigilator_id');

        return Invigilator::query()
            ->where('college_id', $college->getKey())
            ->with('college')
            ->get()
            ->map(function (Invigilator $invigilator) use ($assignmentsByInvigilator, $activeByInvigilator, $completedCount, $setting): array {
                $items = $assignmentsByInvigilator->get($invigilator->getKey(), collect());
                $activeItems = $activeByInvigilator->get($invigilator->getKey(), collect());
                $effectiveMax = $invigilator->effectiveMaxAssignments((int) $setting->default_max_assignments_per_invigilator);
                $tasksCount = $activeItems->count();

                return [
                    'id' => $invigilator->getKey(),
                    'name' => $invigilator->name,
                    'phone' => $invigilator->phone,
                    'college' => $invigilator->college?->name,
                    'staff_category' => $invigilator->staff_category?->label(),
                    'invigilation_role' => $invigilator->invigilation_role?->label(),
                    'is_active' => (bool) $invigilator->is_active,
                    'workload_reduction_percentage' => (int) $invigilator->workload_reduction_percentage,
                    'effective_max_assignments' => $effectiveMax,
                    'tasks_count' => $tasksCount,
                    'assigned_count' => $this->countStatus($items, InvigilatorAssignmentStatus::Assigned),
                    'manual_count' => $this->countStatus($items, InvigilatorAssignmentStatus::Manual),
                    'conflict_count' => $this->countStatus($items, InvigilatorAssignmentStatus::Conflict),
                    'cancelled_count' => $this->countStatus($items, InvigilatorAssignmentStatus::Cancelled),
                    'days_count' => $activeItems
                        ->map(fn (InvigilatorAssignment $assignment): ?string => $assignment->exam_date?->format('Y-m-d'))
                        ->filter()
                        ->unique()
                        ->count(),
                    'halls_count' => $activeItems->pluck('exam_hall_id')->filter()->unique()->count(),
                    'contribution_percentage' => $completedCount > 0 ? round(($tasksCount / $completedCount) * 100, 1) : 0.0,
                    'capacity_usage_percentage' => $effectiveMax > 0 ? min(100, round(($tasksCount / $effectiveMax) * 100, 1)) : 0.0,
                    'latest_assignment_date' => $activeItems
                        ->sortByDesc(fn (InvigilatorAssignment $assignment): string => (string) $assignment->exam_date?->format('Y-m-d'))
                        ->first()?->exam_date?->format('Y-m-d'),
                    'assignments' => $activeItems
                        ->sortBy([['exam_date', 'asc'], ['start_time', 'asc']])
                        ->map(fn (InvigilatorAssignment $assignment): array => [
                            'exam_date' => $assignment->exam_date?->format('Y-m-d'),
                            'start_time' => substr((string) $assignment->start_time, 0, 5),
                            'hall_name' => $assignment->examHall?->name,
                            'role' => $assignment->invigilation_role?->label(),
                            'status' => $assignment->assignment_status?->label(),
                            'notes' => $assignment->notes,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy([
                ['tasks_count', 'desc'],
                ['days_count', 'desc'],
                ['name', 'asc'],
            ])
            ->values()
            ->map(function (array $employee, int $index): array {
                $employee['rank'] = $index + 1;

                return $employee;
            });
    }

    protected function countStatus(Collection $assignments, InvigilatorAssignmentStatus $status): int
    {
        return $assignments
            ->filter(function (InvigilatorAssignment $assignment) use ($status): bool {
                $assignmentStatus = $assignment->assignment_status instanceof InvigilatorAssignmentStatus
                    ? $assignment->assignment_status->value
                    : (string) $assignment->assignment_status;

                return $assignmentStatus === $status->value;
            })
            ->count();
    }

    protected function statusCounts(Collection $assignments): array
    {
        return collect(InvigilatorAssignmentStatus::cases())
            ->mapWithKeys(fn (InvigilatorAssignmentStatus $status): array => [
                $status->value => [
                    'label' => $status->label(),
                    'count' => $this->countStatus($assignments, $status),
                ],
            ])
            ->all();
    }

    protected function normalizeDate(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
