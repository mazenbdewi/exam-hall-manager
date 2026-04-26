<?php

namespace App\Services;

use App\Enums\InvigilationRole;
use App\Enums\InvigilatorAssignmentStatus;
use App\Enums\InvigilatorDayPreference;
use App\Enums\InvigilatorDistributionPattern;
use App\Models\College;
use App\Models\HallAssignment;
use App\Models\Invigilator;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorDistributionSetting;
use App\Models\InvigilatorHallRequirement;
use App\Models\InvigilatorUnassignedRequirement;
use App\Models\SubjectExamOffering;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvigilatorDistributionService
{
    public function distributeForFaculty(College $college, CarbonInterface $fromDate, CarbonInterface $toDate, bool $overwriteManual = false): array
    {
        $readiness = $this->studentDistributionReadiness($college, $fromDate->toDateString(), $toDate->toDateString());

        if (! $readiness['is_ready']) {
            return [
                'status' => 'danger',
                'slots_count' => 0,
                'assigned_count' => 0,
                'shortage_count' => 0,
                'message' => $readiness['blocking_message'],
                'readiness' => $readiness,
                'results' => [],
            ];
        }

        $slots = $this->buildSlots($college, $fromDate->toDateString(), $toDate->toDateString());
        $results = $slots->map(fn (array $slot): array => $this->distributeForSlot($college, $slot['exam_date'], $slot['start_time'], $overwriteManual));

        return [
            'status' => $results->isEmpty() || $results->contains(fn (array $result): bool => $result['shortage_count'] > 0) ? 'warning' : 'success',
            'slots_count' => $results->count(),
            'assigned_count' => $results->sum('assigned_count'),
            'shortage_count' => $results->sum('shortage_count'),
            'message' => $results->isEmpty()
                ? __('exam.notifications.invigilator_distribution_no_used_halls')
                : ($results->sum('shortage_count') > 0
                    ? __('exam.notifications.invigilator_distribution_completed_with_shortage', ['count' => $results->sum('shortage_count')])
                    : __('exam.notifications.invigilator_distribution_completed')),
            'results' => $results->all(),
        ];
    }

    public function distributeForSlot(College $college, string $examDate, string $startTime, bool $overwriteManual = false): array
    {
        $examDate = substr($examDate, 0, 10);
        $startTime = $this->normalizeTime($startTime);
        $readiness = $this->studentDistributionReadiness($college, $examDate, $examDate);
        $slotReadiness = collect($readiness['slots'])
            ->first(fn (array $slot): bool => $slot['exam_date'] === $examDate && $this->normalizeTime($slot['start_time']) === $startTime);

        if (! $slotReadiness || ! ($slotReadiness['is_ready'] ?? false)) {
            return [
                'status' => 'danger',
                'exam_date' => $examDate,
                'start_time' => $startTime,
                'halls_count' => 0,
                'assigned_count' => 0,
                'shortage_count' => 0,
                'message' => __('exam.warnings.student_distribution_incomplete'),
                'readiness' => $readiness,
            ];
        }

        $setting = $this->settingsForCollege($college);
        $slotOfferings = $this->slotOfferings($college, $examDate, $startTime);
        $firstOffering = $slotOfferings->first();
        $usedHalls = $this->usedHalls($college, $examDate, $startTime);
        $requirementsByHallType = $this->requirementsByHallType($college);
        $assignedCount = 0;
        $shortageCount = 0;

        DB::transaction(function () use (
            $college,
            $examDate,
            $startTime,
            $setting,
            $firstOffering,
            $usedHalls,
            $requirementsByHallType,
            $overwriteManual,
            &$assignedCount,
            &$shortageCount,
        ): void {
            $this->clearSlot($college, $examDate, $startTime, $overwriteManual);

            $slotAssignedIds = InvigilatorAssignment::query()
                ->where('college_id', $college->getKey())
                ->whereDate('exam_date', $examDate)
                ->whereTime('start_time', $startTime)
                ->pluck('invigilator_id')
                ->all();

            foreach ($usedHalls as $hallAssignment) {
                $hall = $hallAssignment->examHall;
                $hallType = $hall->hall_type?->value ?? (string) $hall->hall_type;
                $requirement = $requirementsByHallType->get($hallType);

                if (! $requirement) {
                    $shortageCount++;
                    $this->recordShortage($college, $examDate, $startTime, $hall->id, InvigilationRole::Regular, 1, 0, __('exam.invigilator_shortage_reasons.missing_hall_requirement'));

                    continue;
                }

                foreach ($this->roleRequirements($requirement) as $role => $count) {
                    $assignedForRole = InvigilatorAssignment::query()
                        ->where('college_id', $college->getKey())
                        ->whereDate('exam_date', $examDate)
                        ->whereTime('start_time', $startTime)
                        ->where('exam_hall_id', $hall->getKey())
                        ->where('invigilation_role', $role)
                        ->count();

                    for ($index = $assignedForRole; $index < $count; $index++) {
                        $invigilator = $this->selectInvigilator($college, InvigilationRole::from($role), $examDate, $startTime, $setting, $slotAssignedIds);

                        if (! $invigilator) {
                            $shortageCount++;

                            continue;
                        }

                        InvigilatorAssignment::query()->create([
                            'college_id' => $college->getKey(),
                            'subject_exam_offering_id' => $firstOffering?->getKey(),
                            'exam_date' => $examDate,
                            'start_time' => $startTime,
                            'end_time' => null,
                            'exam_hall_id' => $hall->getKey(),
                            'invigilator_id' => $invigilator->getKey(),
                            'invigilation_role' => $role,
                            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
                            'assigned_by' => auth()->id(),
                        ]);

                        $slotAssignedIds[] = $invigilator->getKey();
                        $assignedCount++;
                        $assignedForRole++;
                    }

                    if ($assignedForRole < $count) {
                        $this->recordShortage(
                            $college,
                            $examDate,
                            $startTime,
                            $hall->id,
                            InvigilationRole::from($role),
                            $count,
                            $assignedForRole,
                            $this->shortageReason($college, InvigilationRole::from($role), $examDate, $startTime, $setting),
                        );
                    }
                }
            }
        });

        return [
            'status' => $shortageCount > 0 ? 'warning' : 'success',
            'exam_date' => $examDate,
            'start_time' => $startTime,
            'halls_count' => $usedHalls->count(),
            'assigned_count' => $assignedCount,
            'shortage_count' => $shortageCount,
            'message' => $shortageCount > 0
                ? __('exam.notifications.invigilator_distribution_completed_with_shortage', ['count' => $shortageCount])
                : __('exam.notifications.invigilator_distribution_completed'),
        ];
    }

    public function getSummary(College $college, ?string $examDate = null, ?string $startTime = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        $slots = filled($examDate) && filled($startTime)
            ? collect([['exam_date' => substr((string) $examDate, 0, 10), 'start_time' => $this->normalizeTime((string) $startTime)]])
            : $this->buildSlots($college, $fromDate, $toDate);

        $slotSummaries = $slots
            ->map(fn (array $slot): array => $this->slotSummary($college, $slot['exam_date'], $slot['start_time']))
            ->values();

        $totalInvigilators = Invigilator::query()->where('college_id', $college->getKey())->count();
        $activeInvigilators = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', true)
            ->where('workload_reduction_percentage', '<', 100)
            ->count();
        $reducedInvigilators = Invigilator::query()->where('college_id', $college->getKey())->where('workload_reduction_percentage', '>', 0)->count();
        $exemptInvigilators = Invigilator::query()->where('college_id', $college->getKey())->where('workload_reduction_percentage', 100)->count();
        $assignments = $this->flattenAssignments($slotSummaries);

        return [
            'college' => $college,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_invigilators' => $totalInvigilators,
            'available_invigilators' => $activeInvigilators,
            'reduced_invigilators_count' => $reducedInvigilators,
            'exempt_invigilators_count' => $exemptInvigilators,
            'required_count' => $slotSummaries->sum('required_count'),
            'assigned_count' => $slotSummaries->sum('assigned_count'),
            'shortage_count' => $slotSummaries->sum('shortage_count'),
            'halls_count' => $slotSummaries->sum('halls_count'),
            'days_count' => $slotSummaries->pluck('exam_date')->unique()->count(),
            'slots_count' => $slotSummaries->count(),
            'has_assignments' => $slotSummaries->sum('assigned_count') > 0,
            'slots' => $slotSummaries->all(),
            'shortages' => $slotSummaries->flatMap(fn (array $slot): array => $slot['shortages'])->values()->all(),
            'diagnosis' => $this->diagnosis($slotSummaries),
            'by_invigilator' => $this->groupByInvigilator($assignments),
            'by_day' => $this->groupByDay($slotSummaries),
        ];
    }

    public function studentDistributionReadiness(College $college, ?string $fromDate, ?string $toDate): array
    {
        $fromDate = filled($fromDate) ? substr((string) $fromDate, 0, 10) : null;
        $toDate = filled($toDate) ? substr((string) $toDate, 0, 10) : null;

        if (! $fromDate || ! $toDate) {
            return $this->emptyReadiness(
                isReady: false,
                blockingMessage: __('exam.readiness.reasons.period_missing'),
            );
        }

        $offerings = SubjectExamOffering::query()
            ->with(['subject'])
            ->withCount(['examStudents', 'studentHallAssignments'])
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate)
            ->whereHas('subject', fn (Builder $query) => $query->where('college_id', $college->getKey()))
            ->orderBy('exam_date')
            ->orderBy('exam_start_time')
            ->orderBy('id')
            ->get();

        if ($offerings->isEmpty()) {
            return $this->emptyReadiness(
                isReady: false,
                blockingMessage: __('exam.readiness.reasons.no_offerings'),
            );
        }

        $slots = $offerings
            ->groupBy(fn (SubjectExamOffering $offering): string => $offering->exam_date->format('Y-m-d').'|'.$this->normalizeTime((string) $offering->exam_start_time))
            ->map(function (Collection $slotOfferings) use ($college): array {
                /** @var SubjectExamOffering $first */
                $first = $slotOfferings->first();
                $examDate = $first->exam_date->format('Y-m-d');
                $startTime = $this->normalizeTime((string) $first->exam_start_time);

                $usedHalls = $this->usedHalls($college, $examDate, $startTime);
                $studentsCount = (int) $slotOfferings->sum('exam_students_count');
                $assignedStudentsCount = (int) $slotOfferings->sum('student_hall_assignments_count');
                $unassignedStudentsCount = max(0, $studentsCount - $assignedStudentsCount);
                $isReady = $studentsCount === 0 || ($unassignedStudentsCount === 0 && $usedHalls->isNotEmpty());

                return [
                    'exam_date' => $examDate,
                    'start_time' => $startTime,
                    'offerings_count' => $slotOfferings->count(),
                    'students_count' => $studentsCount,
                    'assigned_students_count' => $assignedStudentsCount,
                    'unassigned_students_count' => $unassignedStudentsCount,
                    'used_halls_count' => $usedHalls->count(),
                    'halls_needing_invigilators_count' => $usedHalls->count(),
                    'is_ready' => $isReady,
                    'incomplete_offerings' => $slotOfferings
                        ->filter(function (SubjectExamOffering $offering): bool {
                            $studentsCount = (int) $offering->exam_students_count;

                            return $studentsCount > 0 && (int) $offering->student_hall_assignments_count < $studentsCount;
                        })
                        ->map(fn (SubjectExamOffering $offering): array => [
                            'id' => $offering->getKey(),
                            'subject_name' => $offering->subject?->name,
                            'students_count' => (int) $offering->exam_students_count,
                            'assigned_students_count' => (int) $offering->student_hall_assignments_count,
                            'unassigned_students_count' => max(0, (int) $offering->exam_students_count - (int) $offering->student_hall_assignments_count),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        $usedHallsCount = (int) $slots->sum('used_halls_count');
        $hallsNeedingInvigilatorsCount = (int) $slots->sum('halls_needing_invigilators_count');
        $unassignedStudentsCount = (int) $slots->sum('unassigned_students_count');
        $incompleteSlots = $slots
            ->filter(fn (array $slot): bool => ! $slot['is_ready'])
            ->values();
        $isReady = $incompleteSlots->isEmpty()
            && $usedHallsCount > 0
            && $hallsNeedingInvigilatorsCount > 0;

        return [
            'is_ready' => $isReady,
            'blocking_message' => $this->readinessBlockingMessage(
                hasOfferings: $offerings->isNotEmpty(),
                incompleteSlotsCount: $incompleteSlots->count(),
                usedHallsCount: $usedHallsCount,
                hallsNeedingInvigilatorsCount: $hallsNeedingInvigilatorsCount,
            ),
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'offerings_count' => $offerings->count(),
            'slots_count' => $slots->count(),
            'distributed_slots_count' => $slots->where('is_ready', true)->where('used_halls_count', '>', 0)->count(),
            'used_halls_count' => $usedHallsCount,
            'halls_needing_invigilators_count' => $hallsNeedingInvigilatorsCount,
            'assigned_students_count' => (int) $slots->sum('assigned_students_count'),
            'unassigned_students_count' => $unassignedStudentsCount,
            'incomplete_slots_count' => $incompleteSlots->count(),
            'slots' => $slots->all(),
            'incomplete_slots' => $incompleteSlots->all(),
        ];
    }

    public function slotSummary(College $college, string $examDate, string $startTime): array
    {
        $examDate = substr($examDate, 0, 10);
        $startTime = $this->normalizeTime($startTime);
        $usedHalls = $this->usedHalls($college, $examDate, $startTime);
        $requirementsByHallType = $this->requirementsByHallType($college);
        $assignments = InvigilatorAssignment::query()
            ->with(['examHall', 'invigilator'])
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', $examDate)
            ->whereTime('start_time', $startTime)
            ->get()
            ->groupBy('exam_hall_id');
        $shortages = InvigilatorUnassignedRequirement::query()
            ->with('examHall')
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', $examDate)
            ->whereTime('start_time', $startTime)
            ->get();

        $hallSummaries = $usedHalls->map(function (HallAssignment $hallAssignment) use ($requirementsByHallType, $assignments): array {
            $hall = $hallAssignment->examHall;
            $hallType = $hall->hall_type?->value ?? (string) $hall->hall_type;
            $requirement = $requirementsByHallType->get($hallType);
            $hallAssignments = $assignments->get($hall->getKey(), collect());

            return [
                'id' => $hall->getKey(),
                'name' => $hall->name,
                'location' => $hall->location,
                'hall_type' => $hallType,
                'hall_type_label' => filled($hallType) ? __("exam.hall_types.{$hallType}") : '-',
                'required_roles' => $requirement ? $this->roleRequirements($requirement) : [],
                'required_count' => $requirement ? array_sum($this->roleRequirements($requirement)) : 0,
                'assigned_count' => $hallAssignments->count(),
                'assignments_by_role' => collect(InvigilationRole::cases())
                    ->mapWithKeys(fn (InvigilationRole $role): array => [
                        $role->value => $hallAssignments
                            ->where('invigilation_role', $role)
                            ->map(fn (InvigilatorAssignment $assignment): array => [
                                'assignment_id' => $assignment->getKey(),
                                'invigilator_id' => $assignment->invigilator?->getKey(),
                                'name' => $assignment->invigilator?->name,
                                'phone' => $assignment->invigilator?->phone,
                                'staff_category' => $assignment->invigilator?->staff_category?->label(),
                                'invigilation_role' => $assignment->invigilator?->invigilation_role?->label(),
                                'workload_reduction_percentage' => (int) ($assignment->invigilator?->workload_reduction_percentage ?? 0),
                                'assignment_status' => $assignment->assignment_status?->value ?? (string) $assignment->assignment_status,
                                'role' => $role->value,
                                'role_label' => $role->label(),
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->all(),
            ];
        })->values();

        return [
            'exam_date' => $examDate,
            'start_time' => $startTime,
            'halls_count' => $hallSummaries->count(),
            'required_count' => $hallSummaries->sum('required_count'),
            'assigned_count' => $hallSummaries->sum('assigned_count'),
            'shortage_count' => $shortages->sum('shortage_count'),
            'halls' => $hallSummaries->all(),
            'shortages' => $shortages->map(fn (InvigilatorUnassignedRequirement $shortage): array => [
                'exam_date' => $shortage->exam_date?->format('Y-m-d'),
                'start_time' => substr((string) $shortage->start_time, 0, 5),
                'hall_name' => $shortage->examHall?->name,
                'hall_location' => $shortage->examHall?->location,
                'invigilation_role' => $shortage->invigilation_role?->label(),
                'required_count' => $shortage->required_count,
                'assigned_count' => $shortage->assigned_count,
                'shortage_count' => $shortage->shortage_count,
                'reason' => $shortage->reason,
            ])->values()->all(),
        ];
    }

    protected function buildSlots(College $college, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        return HallAssignment::query()
            ->where('college_id', $college->getKey())
            ->when($fromDate, fn (Builder $query) => $query->whereDate('exam_date', '>=', substr($fromDate, 0, 10)))
            ->when($toDate, fn (Builder $query) => $query->whereDate('exam_date', '<=', substr($toDate, 0, 10)))
            ->select(['exam_date', 'exam_start_time'])
            ->distinct()
            ->orderBy('exam_date')
            ->orderBy('exam_start_time')
            ->get()
            ->map(fn (HallAssignment $assignment): array => [
                'exam_date' => $assignment->exam_date->format('Y-m-d'),
                'start_time' => $this->normalizeTime((string) $assignment->exam_start_time),
            ]);
    }

    protected function usedHalls(College $college, string $examDate, string $startTime): Collection
    {
        return HallAssignment::query()
            ->with('examHall')
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', $examDate)
            ->whereTime('exam_start_time', $startTime)
            ->where('assigned_students_count', '>', 0)
            ->whereHas('examHall', fn (Builder $query) => $query
                ->where('college_id', $college->getKey())
                ->where('is_active', true))
            ->orderBy('id')
            ->get()
            ->filter(fn (HallAssignment $assignment): bool => $assignment->examHall !== null)
            ->values();
    }

    protected function emptyReadiness(bool $isReady, string $blockingMessage): array
    {
        return [
            'is_ready' => $isReady,
            'blocking_message' => $blockingMessage,
            'from_date' => null,
            'to_date' => null,
            'offerings_count' => 0,
            'slots_count' => 0,
            'distributed_slots_count' => 0,
            'used_halls_count' => 0,
            'halls_needing_invigilators_count' => 0,
            'assigned_students_count' => 0,
            'unassigned_students_count' => 0,
            'incomplete_slots_count' => 0,
            'slots' => [],
            'incomplete_slots' => [],
        ];
    }

    protected function readinessBlockingMessage(bool $hasOfferings, int $incompleteSlotsCount, int $usedHallsCount, int $hallsNeedingInvigilatorsCount): string
    {
        if (! $hasOfferings) {
            return __('exam.readiness.reasons.no_offerings');
        }

        if ($incompleteSlotsCount > 0) {
            return __('exam.readiness.reasons.student_distribution_missing');
        }

        if ($usedHallsCount === 0 || $hallsNeedingInvigilatorsCount === 0) {
            return __('exam.readiness.reasons.no_used_halls');
        }

        return __('exam.readiness.ready_message');
    }

    protected function slotOfferings(College $college, string $examDate, string $startTime): Collection
    {
        return SubjectExamOffering::query()
            ->with('subject')
            ->whereDate('exam_date', $examDate)
            ->whereTime('exam_start_time', $startTime)
            ->whereHas('subject', fn (Builder $query) => $query->where('college_id', $college->getKey()))
            ->orderBy('id')
            ->get();
    }

    protected function settingsForCollege(College $college): InvigilatorDistributionSetting
    {
        return InvigilatorDistributionSetting::query()
            ->where('college_id', $college->getKey())
            ->first()
            ?? InvigilatorDistributionSetting::defaultsForCollege($college);
    }

    protected function requirementsByHallType(College $college): Collection
    {
        return InvigilatorHallRequirement::query()
            ->where('college_id', $college->getKey())
            ->get()
            ->keyBy(fn (InvigilatorHallRequirement $requirement): string => $requirement->hall_type?->value ?? (string) $requirement->hall_type);
    }

    protected function roleRequirements(InvigilatorHallRequirement $requirement): array
    {
        return [
            InvigilationRole::HallHead->value => $requirement->hall_head_count,
            InvigilationRole::Secretary->value => $requirement->secretary_count,
            InvigilationRole::Regular->value => $requirement->regular_count,
            InvigilationRole::Reserve->value => $requirement->reserve_count,
        ];
    }

    protected function selectInvigilator(
        College $college,
        InvigilationRole $role,
        string $examDate,
        string $startTime,
        InvigilatorDistributionSetting $setting,
        array $slotAssignedIds,
    ): ?Invigilator {
        $eligible = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', true)
            ->where('invigilation_role', $role->value)
            ->whereNotIn('id', $slotAssignedIds)
            ->get()
            ->filter(fn (Invigilator $invigilator): bool => $this->passesHardConstraints($invigilator, $examDate, $startTime, $setting))
            ->values();

        if ($eligible->isEmpty()) {
            return null;
        }

        if (($setting->distribution_pattern?->value ?? $setting->distribution_pattern) === InvigilatorDistributionPattern::Random->value) {
            return $eligible->shuffle()->first();
        }

        return $eligible
            ->sortBy(fn (Invigilator $invigilator): array => $this->score($invigilator, $examDate, $setting))
            ->first();
    }

    protected function passesHardConstraints(Invigilator $invigilator, string $examDate, string $startTime, InvigilatorDistributionSetting $setting): bool
    {
        $maxAssignments = $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator);
        $totalAssignments = $this->assignmentCount($invigilator);

        if ($maxAssignments <= 0 || $totalAssignments >= $maxAssignments) {
            return false;
        }

        if ($this->hasTimeConflict($invigilator, $examDate, $startTime)) {
            return false;
        }

        $dayAssignments = $this->assignmentCount($invigilator, $examDate);
        $dayLimit = $invigilator->max_assignments_per_day ?? $setting->max_assignments_per_day;

        if (! $setting->allow_multiple_assignments_per_day && $dayAssignments > 0) {
            return false;
        }

        return ! ($dayLimit !== null && $dayAssignments >= $dayLimit);
    }

    protected function score(Invigilator $invigilator, string $examDate, InvigilatorDistributionSetting $setting): array
    {
        $total = $this->assignmentCount($invigilator);
        $week = $this->assignmentCountInWeek($invigilator, $examDate);
        $nearby = $this->nearbyAssignmentCount($invigilator, $examDate);
        $pattern = $setting->distribution_pattern?->value ?? $setting->distribution_pattern;
        $dayPreference = $setting->day_preference?->value ?? $setting->day_preference;

        $patternScore = match ($pattern) {
            InvigilatorDistributionPattern::Consecutive->value => -$nearby,
            InvigilatorDistributionPattern::Distributed->value => $nearby,
            default => 0,
        };

        $dayScore = match ($dayPreference) {
            InvigilatorDayPreference::Early->value => $this->assignmentCountBefore($invigilator, $examDate),
            InvigilatorDayPreference::Late->value => -$this->assignmentCountBefore($invigilator, $examDate),
            default => 0,
        };

        return [$total, $week, $patternScore, $dayScore, $invigilator->id];
    }

    protected function assignmentCount(Invigilator $invigilator, ?string $examDate = null): int
    {
        return InvigilatorAssignment::query()
            ->where('invigilator_id', $invigilator->getKey())
            ->when($examDate, fn (Builder $query) => $query->whereDate('exam_date', $examDate))
            ->count();
    }

    protected function assignmentCountInWeek(Invigilator $invigilator, string $examDate): int
    {
        $date = Carbon::parse($examDate);

        return InvigilatorAssignment::query()
            ->where('invigilator_id', $invigilator->getKey())
            ->whereBetween('exam_date', [$date->copy()->startOfWeek()->toDateString(), $date->copy()->endOfWeek()->toDateString()])
            ->count();
    }

    protected function nearbyAssignmentCount(Invigilator $invigilator, string $examDate): int
    {
        $date = Carbon::parse($examDate);

        return InvigilatorAssignment::query()
            ->where('invigilator_id', $invigilator->getKey())
            ->whereBetween('exam_date', [$date->copy()->subDay()->toDateString(), $date->copy()->addDay()->toDateString()])
            ->count();
    }

    protected function assignmentCountBefore(Invigilator $invigilator, string $examDate): int
    {
        return InvigilatorAssignment::query()
            ->where('invigilator_id', $invigilator->getKey())
            ->whereDate('exam_date', '<', $examDate)
            ->count();
    }

    protected function hasTimeConflict(Invigilator $invigilator, string $examDate, string $startTime): bool
    {
        return InvigilatorAssignment::query()
            ->where('invigilator_id', $invigilator->getKey())
            ->whereDate('exam_date', $examDate)
            ->whereTime('start_time', $startTime)
            ->exists();
    }

    protected function shortageReason(College $college, InvigilationRole $role, string $examDate, string $startTime, InvigilatorDistributionSetting $setting): string
    {
        $active = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', true)
            ->where('invigilation_role', $role->value)
            ->get();

        if ($active->isEmpty()) {
            return __('exam.invigilator_shortage_reasons.no_active_role');
        }

        if ($active->contains(fn (Invigilator $invigilator): bool => (int) $invigilator->workload_reduction_percentage >= 100)) {
            return __('exam.invigilator_shortage_reasons.workload_reduction_exemptions');
        }

        if ($active->every(fn (Invigilator $invigilator): bool => $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator) <= $this->assignmentCount($invigilator))) {
            return __('exam.invigilator_shortage_reasons.max_assignments_exceeded');
        }

        if ($active->every(fn (Invigilator $invigilator): bool => $this->hasTimeConflict($invigilator, $examDate, $startTime))) {
            return __('exam.invigilator_shortage_reasons.time_conflict');
        }

        if (! $setting->allow_multiple_assignments_per_day && $active->every(fn (Invigilator $invigilator): bool => $this->assignmentCount($invigilator, $examDate) > 0)) {
            return __('exam.invigilator_shortage_reasons.multiple_per_day_not_allowed');
        }

        return __('exam.invigilator_shortage_reasons.no_eligible_invigilator');
    }

    protected function recordShortage(College $college, string $examDate, string $startTime, int $hallId, InvigilationRole $role, int $required, int $assigned, string $reason): void
    {
        InvigilatorUnassignedRequirement::query()->create([
            'college_id' => $college->getKey(),
            'exam_date' => $examDate,
            'start_time' => $startTime,
            'exam_hall_id' => $hallId,
            'invigilation_role' => $role->value,
            'required_count' => $required,
            'assigned_count' => $assigned,
            'shortage_count' => max(0, $required - $assigned),
            'reason' => $reason,
        ]);
    }

    protected function clearSlot(College $college, string $examDate, string $startTime, bool $overwriteManual = false): void
    {
        $assignmentQuery = InvigilatorAssignment::withTrashed()
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', $examDate)
            ->whereTime('start_time', $startTime);

        if (! $overwriteManual) {
            $assignmentQuery->where('assignment_status', '!=', InvigilatorAssignmentStatus::Manual->value);
        }

        $assignmentQuery->forceDelete();

        InvigilatorUnassignedRequirement::query()
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', $examDate)
            ->whereTime('start_time', $startTime)
            ->delete();
    }

    protected function flattenAssignments(Collection $slotSummaries): Collection
    {
        return $slotSummaries
            ->flatMap(fn (array $slot): array => collect($slot['halls'])->flatMap(function (array $hall) use ($slot): array {
                return collect($hall['assignments_by_role'])->flatMap(function (array $assignments, string $role) use ($slot, $hall): array {
                    return collect($assignments)->map(fn (array $assignment): array => [
                        ...$assignment,
                        'exam_date' => $slot['exam_date'],
                        'start_time' => $slot['start_time'],
                        'hall_id' => $hall['id'],
                        'hall_name' => $hall['name'],
                        'hall_location' => $hall['location'],
                        'hall_type' => $hall['hall_type'],
                        'hall_type_label' => $hall['hall_type_label'],
                        'role' => $role,
                        'role_label' => __("exam.invigilation_roles.{$role}"),
                    ])->all();
                })->all();
            })->all())
            ->filter(fn (array $assignment): bool => filled($assignment['invigilator_id'] ?? null))
            ->values();
    }

    protected function groupByInvigilator(Collection $assignments): array
    {
        return $assignments
            ->groupBy('invigilator_id')
            ->map(function (Collection $items): array {
                $first = $items->first();

                return [
                    'invigilator_id' => $first['invigilator_id'],
                    'name' => $first['name'],
                    'phone' => $first['phone'],
                    'staff_category' => $first['staff_category'],
                    'invigilation_role' => $first['invigilation_role'],
                    'workload_reduction_percentage' => $first['workload_reduction_percentage'] ?? 0,
                    'assignments_count' => $items->count(),
                    'assignments' => $items
                        ->sortBy([['exam_date', 'asc'], ['start_time', 'asc'], ['hall_name', 'asc']])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    protected function groupByDay(Collection $slotSummaries): array
    {
        return $slotSummaries
            ->groupBy('exam_date')
            ->map(fn (Collection $slots, string $date): array => [
                'exam_date' => $date,
                'slots_count' => $slots->count(),
                'halls_count' => $slots->sum('halls_count'),
                'required_count' => $slots->sum('required_count'),
                'assigned_count' => $slots->sum('assigned_count'),
                'shortage_count' => $slots->sum('shortage_count'),
                'slots' => $slots->values()->all(),
            ])
            ->sortBy('exam_date')
            ->values()
            ->all();
    }

    protected function diagnosis(Collection $slotSummaries): array
    {
        if ($slotSummaries->isEmpty()) {
            return [[
                'tone' => 'gray',
                'message' => __('exam.diagnosis.no_hall_distribution_results'),
            ]];
        }

        $shortages = $slotSummaries->flatMap(fn (array $slot): array => $slot['shortages']);

        if ($shortages->isEmpty()) {
            return [[
                'tone' => 'success',
                'message' => __('exam.diagnosis.invigilators_all_distributed'),
            ]];
        }

        $items = $shortages
            ->groupBy('invigilation_role')
            ->map(fn (Collection $items, string $role): array => [
                'tone' => 'danger',
                'message' => __('exam.diagnosis.invigilator_role_shortage', [
                    'role' => $role,
                    'count' => $items->sum('shortage_count'),
                ]),
            ])
            ->values()
            ->all();

        $items[] = [
            'tone' => 'warning',
            'message' => __('exam.diagnosis.invigilator_reduction_shortage_hint'),
        ];

        return $items;
    }

    protected function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
