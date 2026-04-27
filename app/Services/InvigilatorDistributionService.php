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
use Illuminate\Support\Facades\Log;

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
            'status' => $results->isEmpty() || $results->contains(fn (array $result): bool => $result['shortage_count'] > 0) ? 'partial' : 'success',
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
                    $this->recordShortage($college, $examDate, $startTime, $hall->id, InvigilationRole::Regular, 1, 0, __('exam.invigilator_shortage_reasons.missing_hall_requirement'));

                    continue;
                }

                foreach ($this->roleRequirements($requirement) as $role => $count) {
                    $requiredRole = InvigilationRole::from($role);
                    $assignedForRole = InvigilatorAssignment::query()
                        ->where('college_id', $college->getKey())
                        ->whereDate('exam_date', $examDate)
                        ->whereTime('start_time', $startTime)
                        ->where('exam_hall_id', $hall->getKey())
                        ->where('invigilation_role', $role)
                        ->count();

                    for ($index = $assignedForRole; $index < $count; $index++) {
                        $selection = $this->selectInvigilatorForRequiredRole($college, $requiredRole, $examDate, $startTime, $setting, $slotAssignedIds);
                        $invigilator = $selection['invigilator'];

                        if (! $invigilator) {
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
                            'invigilation_role' => $requiredRole->value,
                            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
                            'assigned_by' => auth()->id(),
                            'notes' => $selection['notes'] ?? null,
                        ]);

                        $slotAssignedIds[] = $invigilator->getKey();
                        $assignedCount++;
                        $assignedForRole++;
                    }
                }

                $this->recordHallShortagesFromFinalCounts(
                    college: $college,
                    examDate: $examDate,
                    startTime: $startTime,
                    hallAssignment: $hallAssignment,
                    requirement: $requirement,
                    setting: $setting,
                    slotAssignedIds: $slotAssignedIds,
                );
            }

            $shortageCount = InvigilatorUnassignedRequirement::query()
                ->where('college_id', $college->getKey())
                ->whereDate('exam_date', $examDate)
                ->whereTime('start_time', $startTime)
                ->sum('shortage_count');
        });

        return [
            'status' => $shortageCount > 0 ? 'partial' : 'success',
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
        $shortages = $slotSummaries->flatMap(fn (array $slot): array => $slot['shortages'])->values();

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
            'shortages' => $shortages->all(),
            'shortage_by_role' => $this->shortageByRole($slotSummaries),
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
                unassignedStudentsCount: $unassignedStudentsCount,
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

        $hallSummaries = $usedHalls->map(function (HallAssignment $hallAssignment) use ($requirementsByHallType, $assignments, $shortages): array {
            $hall = $hallAssignment->examHall;
            $hallType = $hall->hall_type?->value ?? (string) $hall->hall_type;
            $requirement = $requirementsByHallType->get($hallType);
            $hallAssignments = $assignments->get($hall->getKey(), collect());
            $hallShortages = $shortages
                ->where('exam_hall_id', $hall->getKey())
                ->values();
            $shortagesByRole = $hallShortages
                ->keyBy(fn (InvigilatorUnassignedRequirement $shortage): string => $shortage->invigilation_role?->value ?? (string) $shortage->invigilation_role)
                ->map(fn (InvigilatorUnassignedRequirement $shortage): array => [
                    'role' => $shortage->invigilation_role?->value ?? (string) $shortage->invigilation_role,
                    'role_label' => $shortage->invigilation_role?->label() ?? (string) $shortage->invigilation_role,
                    'required_count' => $shortage->required_count,
                    'assigned_count' => $shortage->assigned_count,
                    'shortage_count' => $shortage->shortage_count,
                    'reason' => $shortage->reason,
                ])
                ->all();

            return [
                'id' => $hall->getKey(),
                'name' => $hall->name,
                'location' => $hall->location,
                'hall_type' => $hallType,
                'hall_type_label' => filled($hallType) ? __("exam.hall_types.{$hallType}") : '-',
                'required_roles' => $requirement ? $this->roleRequirements($requirement) : [],
                'required_count' => $requirement ? array_sum($this->roleRequirements($requirement)) : 0,
                'assigned_count' => $hallAssignments->count(),
                'shortages_by_role' => $shortagesByRole,
                'assignments_by_role' => collect(InvigilationRole::cases())
                    ->mapWithKeys(fn (InvigilationRole $role): array => [
                        $role->value => $hallAssignments
                            ->filter(fn (InvigilatorAssignment $assignment): bool => $this->assignmentRoleValue($assignment) === $role->value)
                            ->map(fn (InvigilatorAssignment $assignment): array => [
                                'assignment_id' => $assignment->getKey(),
                                'invigilator_id' => $assignment->invigilator?->getKey(),
                                'name' => $assignment->invigilator?->name,
                                'phone' => $assignment->invigilator?->phone,
                                'staff_category' => $assignment->invigilator?->staff_category?->label(),
                                'invigilation_role' => $assignment->invigilator?->invigilation_role?->label(),
                                'workload_reduction_percentage' => (int) ($assignment->invigilator?->workload_reduction_percentage ?? 0),
                                'assignment_status' => $assignment->assignment_status?->value ?? (string) $assignment->assignment_status,
                                'notes' => $assignment->notes,
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
                'hall_type' => $shortage->examHall?->hall_type?->value ?? (string) $shortage->examHall?->hall_type,
                'hall_type_label' => $shortage->examHall?->hall_type?->label() ?? (filled($shortage->examHall?->hall_type) ? (string) $shortage->examHall?->hall_type : '-'),
                'role_key' => $shortage->invigilation_role?->value ?? (string) $shortage->invigilation_role,
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

    protected function readinessBlockingMessage(bool $hasOfferings, int $incompleteSlotsCount, int $unassignedStudentsCount, int $usedHallsCount, int $hallsNeedingInvigilatorsCount): string
    {
        if (! $hasOfferings) {
            return __('exam.readiness.reasons.no_offerings');
        }

        if ($unassignedStudentsCount > 0) {
            return __('exam.readiness.reasons.unassigned_students_block_invigilators');
        }

        if ($incompleteSlotsCount > 0 && $usedHallsCount === 0) {
            return __('exam.readiness.reasons.student_distribution_missing');
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

    protected function selectInvigilatorForRequiredRole(
        College $college,
        InvigilationRole $requiredRole,
        string $examDate,
        string $startTime,
        InvigilatorDistributionSetting $setting,
        array $slotAssignedIds,
    ): array {
        $strict = $this->selectCandidateForRole($college, $requiredRole, $examDate, $startTime, $setting, $slotAssignedIds);

        if ($strict['invigilator']) {
            return [
                'invigilator' => $strict['invigilator'],
                'notes' => null,
                'diagnostics' => $strict['diagnostics'],
            ];
        }

        if (! (bool) $setting->allow_role_fallback) {
            return [
                'invigilator' => null,
                'notes' => null,
                'diagnostics' => $strict['diagnostics'],
            ];
        }

        foreach ($this->fallbackRolesFor($requiredRole) as $fallbackRole) {
            $fallback = $this->selectCandidateForRole($college, $fallbackRole, $examDate, $startTime, $setting, $slotAssignedIds);

            if (! $fallback['invigilator']) {
                continue;
            }

            return [
                'invigilator' => $fallback['invigilator'],
                'notes' => __('exam.invigilator_shortage_reasons.fallback_used', [
                    'required_role' => $requiredRole->label(),
                    'fallback_role' => $fallbackRole->label(),
                ]),
                'diagnostics' => $strict['diagnostics'],
            ];
        }

        return [
            'invigilator' => null,
            'notes' => null,
            'diagnostics' => $strict['diagnostics'],
        ];
    }

    protected function selectCandidateForRole(
        College $college,
        InvigilationRole $role,
        string $examDate,
        string $startTime,
        InvigilatorDistributionSetting $setting,
        array $slotAssignedIds,
    ): array {
        $diagnostics = $this->candidateDiagnostics($college, $role, $examDate, $startTime, $setting, $slotAssignedIds);
        /** @var Collection<int, Invigilator> $eligible */
        $eligible = $diagnostics['eligible'];

        if ($eligible->isEmpty()) {
            return [
                'invigilator' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        $invigilator = (($setting->distribution_pattern?->value ?? $setting->distribution_pattern) === InvigilatorDistributionPattern::Random->value)
            ? $eligible->shuffle()->first()
            : $eligible->sortBy(fn (Invigilator $invigilator): array => $this->score($invigilator, $examDate, $setting))->first();

        return [
            'invigilator' => $invigilator,
            'diagnostics' => $diagnostics,
        ];
    }

    protected function candidateDiagnostics(
        College $college,
        InvigilationRole $role,
        string $examDate,
        string $startTime,
        InvigilatorDistributionSetting $setting,
        array $slotAssignedIds,
    ): array {
        $all = Invigilator::query()->get();
        $candidates = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', true)
            ->where('invigilation_role', $role->value)
            ->get();
        $rejections = [];
        $rejectedIds = [];

        foreach ($candidates as $candidate) {
            $reasons = $this->candidateRejectionReasons($candidate, $examDate, $startTime, $setting, $slotAssignedIds);

            if ($reasons === []) {
                continue;
            }

            $rejectedIds[$candidate->getKey()] = true;

            foreach ($reasons as $reason) {
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;
            }
        }

        $eligible = $candidates
            ->reject(fn (Invigilator $candidate): bool => isset($rejectedIds[$candidate->getKey()]))
            ->values();

        return [
            'role' => $role->value,
            'role_label' => $role->label(),
            'inactive_count' => Invigilator::query()
                ->where('college_id', $college->getKey())
                ->where('invigilation_role', $role->value)
                ->where('is_active', false)
                ->count(),
            'wrong_faculty_count' => $all
                ->filter(fn (Invigilator $invigilator): bool => ($invigilator->invigilation_role?->value ?? (string) $invigilator->invigilation_role) === $role->value)
                ->where('college_id', '!=', $college->getKey())
                ->count(),
            'wrong_role_count' => Invigilator::query()
                ->where('college_id', $college->getKey())
                ->where('is_active', true)
                ->where('invigilation_role', '!=', $role->value)
                ->count(),
            'candidates_found' => $candidates->count(),
            'eligible_count' => $eligible->count(),
            'rejected_count' => count($rejectedIds),
            'rejected_counts' => $rejections,
            'eligible' => $eligible,
        ];
    }

    protected function candidateRejectionReasons(
        Invigilator $invigilator,
        string $examDate,
        string $startTime,
        InvigilatorDistributionSetting $setting,
        array $slotAssignedIds,
    ): array {
        $reasons = [];
        $maxAssignments = $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator);
        $totalAssignments = $this->assignmentCount($invigilator);

        if (in_array($invigilator->getKey(), $slotAssignedIds, true)) {
            $reasons[] = 'same_slot_conflict';
        }

        if ((int) $invigilator->workload_reduction_percentage >= 100) {
            $reasons[] = 'workload_reduction_100';
        } elseif ($maxAssignments <= 0 || $totalAssignments >= $maxAssignments) {
            $reasons[] = 'max_assignments_reached';
        }

        if ($this->hasTimeConflict($invigilator, $examDate, $startTime)) {
            $reasons[] = 'same_slot_conflict';
        }

        $dayAssignments = $this->assignmentCount($invigilator, $examDate);
        $dayLimit = $invigilator->max_assignments_per_day ?? $setting->max_assignments_per_day;

        if (! $setting->allow_multiple_assignments_per_day && $dayAssignments > 0) {
            $reasons[] = 'same_day_limit';
        }

        if ($dayLimit !== null && $dayAssignments >= $dayLimit) {
            $reasons[] = 'daily_limit_reached';
        }

        return array_values(array_unique($reasons));
    }

    protected function fallbackRolesFor(InvigilationRole $role): array
    {
        return match ($role) {
            InvigilationRole::Secretary => [InvigilationRole::HallHead],
            InvigilationRole::Regular => [InvigilationRole::Secretary, InvigilationRole::HallHead],
            InvigilationRole::Reserve => [InvigilationRole::HallHead, InvigilationRole::Secretary, InvigilationRole::Regular],
            default => [],
        };
    }

    protected function recordHallShortagesFromFinalCounts(
        College $college,
        string $examDate,
        string $startTime,
        HallAssignment $hallAssignment,
        InvigilatorHallRequirement $requirement,
        InvigilatorDistributionSetting $setting,
        array $slotAssignedIds,
    ): void {
        $hall = $hallAssignment->examHall;

        foreach ($this->roleRequirements($requirement) as $role => $requiredCount) {
            $requiredRole = InvigilationRole::from($role);
            $assignedCount = InvigilatorAssignment::query()
                ->where('college_id', $college->getKey())
                ->whereDate('exam_date', $examDate)
                ->whereTime('start_time', $startTime)
                ->where('exam_hall_id', $hall->getKey())
                ->where('invigilation_role', $requiredRole->value)
                ->count();

            $shortageCount = max(0, (int) $requiredCount - $assignedCount);

            if ($shortageCount === 0) {
                continue;
            }

            $diagnostics = $this->candidateDiagnostics($college, $requiredRole, $examDate, $startTime, $setting, $slotAssignedIds);
            $reason = $this->shortageReasonFromDiagnostics($requiredRole, $diagnostics);

            $this->logUnfilledRequiredRole(
                examDate: $examDate,
                startTime: $startTime,
                hallId: (int) $hall->id,
                hallName: (string) $hall->name,
                role: $requiredRole,
                requiredCount: (int) $requiredCount,
                assignedCount: (int) $assignedCount,
                diagnostics: $diagnostics,
            );

            $this->recordShortage(
                college: $college,
                examDate: $examDate,
                startTime: $startTime,
                hallId: (int) $hall->id,
                role: $requiredRole,
                required: (int) $requiredCount,
                assigned: (int) $assignedCount,
                reason: $reason,
            );
        }
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

    protected function shortageReasonFromDiagnostics(InvigilationRole $role, array $diagnostics): string
    {
        $found = (int) ($diagnostics['candidates_found'] ?? 0);
        $rejections = $diagnostics['rejected_counts'] ?? [];

        if ($found === 0) {
            return $this->roleShortageReason($role, 'no_active_role');
        }

        if (($rejections['same_slot_conflict'] ?? 0) >= $found) {
            return $this->roleShortageReason($role, 'time_conflict');
        }

        if (($rejections['max_assignments_reached'] ?? 0) >= $found) {
            return $this->roleShortageReason($role, 'max_assignments_exceeded');
        }

        if (($rejections['same_day_limit'] ?? 0) >= $found || ($rejections['daily_limit_reached'] ?? 0) >= $found) {
            return $this->roleShortageReason($role, 'multiple_per_day_not_allowed');
        }

        if (($rejections['workload_reduction_100'] ?? 0) > 0 && (int) ($diagnostics['eligible_count'] ?? 0) === 0) {
            return $this->roleShortageReason($role, 'workload_reduction_exemptions');
        }

        if (($rejections['same_slot_conflict'] ?? 0) > 0) {
            return $this->roleShortageReason($role, 'insufficient_role_count_for_slot');
        }

        return $this->roleShortageReason($role, 'no_eligible_invigilator');
    }

    protected function roleShortageReason(InvigilationRole $role, string $reason): string
    {
        return match ($reason) {
            'no_active_role' => match ($role) {
                InvigilationRole::HallHead => 'لا يوجد رئيس قاعة فعال لهذه الكلية.',
                InvigilationRole::Secretary => 'لا يوجد أمين سر فعال لهذه الكلية.',
                InvigilationRole::Regular => 'لا يوجد مراقب عادي فعال لهذه الكلية.',
                InvigilationRole::Reserve => 'لا يوجد مراقب احتياط فعال لهذه الكلية.',
            },
            'time_conflict' => match ($role) {
                InvigilationRole::HallHead => 'جميع رؤساء القاعات لديهم مراقبة في نفس الموعد.',
                InvigilationRole::Secretary => 'جميع أمناء السر لديهم مراقبة في نفس الموعد.',
                InvigilationRole::Regular => 'جميع المراقبين العاديين لديهم مراقبة في نفس الموعد.',
                InvigilationRole::Reserve => 'جميع مراقبي الاحتياط لديهم مراقبة في نفس الموعد.',
            },
            'multiple_per_day_not_allowed' => match ($role) {
                InvigilationRole::HallHead => 'لا يسمح لرئيس القاعة بأكثر من مراقبة في نفس اليوم.',
                InvigilationRole::Secretary => 'لا يسمح لأمين السر بأكثر من مراقبة في نفس اليوم.',
                InvigilationRole::Regular => 'لا يسمح للمراقب العادي بأكثر من مراقبة في نفس اليوم.',
                InvigilationRole::Reserve => 'لا يسمح لمراقب الاحتياط بأكثر من مراقبة في نفس اليوم.',
            },
            'max_assignments_exceeded' => match ($role) {
                InvigilationRole::HallHead => 'جميع رؤساء القاعات تجاوزوا الحد الأقصى للمراقبات.',
                InvigilationRole::Secretary => 'جميع أمناء السر تجاوزوا الحد الأقصى للمراقبات.',
                InvigilationRole::Regular => 'جميع المراقبين العاديين تجاوزوا الحد الأقصى للمراقبات.',
                InvigilationRole::Reserve => 'جميع مراقبي الاحتياط تجاوزوا الحد الأقصى للمراقبات.',
            },
            'workload_reduction_exemptions' => match ($role) {
                InvigilationRole::HallHead => 'بعض رؤساء القاعات لديهم تخفيض أو إعفاء من التوزيع الآلي.',
                InvigilationRole::Secretary => 'بعض أمناء السر لديهم تخفيض أو إعفاء من التوزيع الآلي.',
                InvigilationRole::Regular => 'بعض المراقبين العاديين لديهم تخفيض أو إعفاء من التوزيع الآلي.',
                InvigilationRole::Reserve => 'بعض مراقبي الاحتياط لديهم تخفيض أو إعفاء من التوزيع الآلي.',
            },
            'insufficient_role_count_for_slot' => match ($role) {
                InvigilationRole::HallHead => 'عدد رؤساء القاعات غير كافٍ لتغطية جميع القاعات في هذا الموعد.',
                InvigilationRole::Secretary => 'عدد أمناء السر غير كافٍ لتغطية جميع القاعات في هذا الموعد.',
                InvigilationRole::Regular => 'عدد المراقبين العاديين غير كافٍ لتغطية جميع القاعات في هذا الموعد.',
                InvigilationRole::Reserve => 'عدد مراقبي الاحتياط غير كافٍ لتغطية جميع القاعات في هذا الموعد.',
            },
            default => 'تعذر توفير العدد المطلوب من هذا النوع من المراقبين ضمن الشروط المحددة.',
        };
    }

    protected function logUnfilledRequiredRole(
        string $examDate,
        string $startTime,
        int $hallId,
        string $hallName,
        InvigilationRole $role,
        int $requiredCount,
        int $assignedCount,
        array $diagnostics,
    ): void {
        $context = [
            'exam_date' => $examDate,
            'start_time' => $startTime,
            'hall_id' => $hallId,
            'hall_name' => $hallName,
            'required_role' => $role->value,
            'required_role_label' => $role->label(),
            'required_count' => $requiredCount,
            'assigned_count' => $assignedCount,
            'candidates_found' => $diagnostics['candidates_found'] ?? 0,
            'inactive_count' => $diagnostics['inactive_count'] ?? 0,
            'wrong_faculty_count' => $diagnostics['wrong_faculty_count'] ?? 0,
            'wrong_role_count' => $diagnostics['wrong_role_count'] ?? 0,
            'eligible_count' => $diagnostics['eligible_count'] ?? 0,
            'candidates_rejected' => $diagnostics['rejected_count'] ?? 0,
            'rejection_reasons' => $diagnostics['rejected_counts'] ?? [],
        ];

        if (($context['candidates_found'] ?? 0) > 0 && ($context['eligible_count'] ?? 0) === 0 && ($context['candidates_rejected'] ?? 0) === 0) {
            Log::error('Invigilator distribution algorithm could not assign role despite unrejected candidates.', $context);

            return;
        }

        Log::warning('Invigilator distribution required role shortage.', $context);
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

    protected function assignmentRoleValue(InvigilatorAssignment $assignment): string
    {
        return $assignment->invigilation_role instanceof InvigilationRole
            ? $assignment->invigilation_role->value
            : (string) $assignment->invigilation_role;
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

    protected function shortageByRole(Collection $slotSummaries): array
    {
        return collect(InvigilationRole::cases())
            ->mapWithKeys(function (InvigilationRole $role) use ($slotSummaries): array {
                $halls = $slotSummaries->flatMap(fn (array $slot): array => $slot['halls'] ?? []);
                $requiredCount = $halls->sum(fn (array $hall): int => (int) ($hall['required_roles'][$role->value] ?? 0));
                $assignedCount = $halls->sum(fn (array $hall): int => count($hall['assignments_by_role'][$role->value] ?? []));

                return [$role->value => [
                    'role' => $role->value,
                    'role_label' => $role->label(),
                    'label' => match ($role) {
                        InvigilationRole::HallHead => __('exam.fields.hall_head_shortage'),
                        InvigilationRole::Secretary => __('exam.fields.secretary_shortage'),
                        InvigilationRole::Regular => __('exam.fields.regular_shortage'),
                        InvigilationRole::Reserve => __('exam.fields.reserve_shortage'),
                    },
                    'required_count' => $requiredCount,
                    'assigned_count' => $assignedCount,
                    'shortage_count' => max(0, $requiredCount - $assignedCount),
                ]];
            })
            ->all();
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
