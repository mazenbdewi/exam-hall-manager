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
use App\Models\InvigilatorDistributionDraft;
use App\Models\InvigilatorDistributionDraftAssignment;
use App\Models\InvigilatorDistributionSetting;
use App\Models\InvigilatorHallRequirement;
use App\Models\InvigilatorUnassignedRequirement;
use App\Models\StudentDistributionRun;
use App\Models\StudentDistributionRunIssue;
use App\Models\SubjectExamOffering;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InvigilatorDistributionService
{
    public function distributeForFaculty(College $college, CarbonInterface $fromDate, CarbonInterface $toDate, bool $overwriteManual = false): array
    {
        $startedAt = hrtime(true);
        $fromDateString = $fromDate->toDateString();
        $toDateString = $toDate->toDateString();
        $context = [
            'college_id' => $college->getKey(),
            'from_date' => $fromDateString,
            'to_date' => $toDateString,
        ];

        $readiness = $this->lightweightStudentDistributionReadiness($college, $fromDateString, $toDateString);

        if (! ($readiness['is_ready'] ?? false) && ! ($readiness['has_student_distribution_run'] ?? false)) {
            $readiness = $this->studentDistributionReadiness($college, $fromDateString, $toDateString);
        }

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

        $result = $this->distributeForFacultyOptimized($college, $fromDateString, $toDateString, $overwriteManual, $context);

        $this->logDistributionTiming('total distribution', $startedAt, [
            ...$context,
            'assigned_count' => $result['assigned_count'],
            'shortage_count' => $result['shortage_count'],
            'slots_count' => $result['slots_count'],
        ]);

        return $result;
    }

    protected function distributeForFacultyOptimized(College $college, string $fromDate, string $toDate, bool $overwriteManual, array $context): array
    {
        $stageStartedAt = hrtime(true);
        $setting = $this->settingsForCollege($college);
        $requirementsByHallType = $this->requirementsByHallType($college);
        $usedHalls = $this->usedHallsForRange($college, $fromDate, $toDate);
        $firstOfferingIdBySlot = $this->firstOfferingIdBySlot($college, $fromDate, $toDate);
        $this->logDistributionTiming('loading reservations and requirements', $stageStartedAt, [
            ...$context,
            'used_halls_count' => $usedHalls->count(),
            'requirements_count' => $requirementsByHallType->count(),
        ]);

        if ($usedHalls->isEmpty()) {
            return [
                'status' => 'danger',
                'slots_count' => 0,
                'assigned_count' => 0,
                'shortage_count' => 0,
                'message' => __('exam.notifications.invigilator_distribution_no_used_halls'),
                'results' => [],
            ];
        }

        $slots = $usedHalls
            ->groupBy(fn (HallAssignment $assignment): string => $this->slotKey($assignment->exam_date->format('Y-m-d'), $this->normalizeTime((string) $assignment->exam_start_time)))
            ->sortKeys();

        $stageStartedAt = hrtime(true);
        $invigilators = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
        $inactiveInvigilators = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', false)
            ->get();
        $rolePools = $this->rolePools($invigilators);
        $inactiveRoleCounts = $this->roleCounts($inactiveInvigilators);
        $this->logDistributionTiming('loading invigilators', $stageStartedAt, [
            ...$context,
            'active_invigilators_count' => $invigilators->count(),
            'inactive_invigilators_count' => $inactiveInvigilators->count(),
        ]);

        $stageStartedAt = hrtime(true);
        DB::transaction(function () use ($college, $fromDate, $toDate, $overwriteManual): void {
            $this->clearDistributionRange($college, $fromDate, $toDate, $overwriteManual);
        });
        $this->logDistributionTiming('clearing old assignments', $stageStartedAt, $context);

        $stageStartedAt = hrtime(true);
        $invigilatorIds = $invigilators->pluck('id')->all();
        $existingAssignments = $invigilatorIds === []
            ? collect()
            : InvigilatorAssignment::query()
                ->whereIn('invigilator_id', $invigilatorIds)
                ->select(['id', 'college_id', 'exam_date', 'start_time', 'exam_hall_id', 'invigilator_id', 'invigilation_role', 'assignment_status'])
                ->get();
        $manualAssignmentsInRange = $existingAssignments
            ->where('college_id', $college->getKey())
            ->filter(fn (InvigilatorAssignment $assignment): bool => $assignment->exam_date->format('Y-m-d') >= $fromDate && $assignment->exam_date->format('Y-m-d') <= $toDate);
        $this->logDistributionTiming('loading existing assignments', $stageStartedAt, [
            ...$context,
            'existing_assignments_count' => $existingAssignments->count(),
            'manual_assignments_in_range_count' => $manualAssignmentsInRange->count(),
        ]);

        $totalCounts = [];
        $dayCounts = [];
        $slotAssigned = [];
        $assignedByHallRole = [];

        foreach ($existingAssignments as $assignment) {
            $invigilatorId = (int) $assignment->invigilator_id;
            $examDate = $assignment->exam_date->format('Y-m-d');
            $slotKey = $this->slotKey($examDate, $this->normalizeTime((string) $assignment->start_time));
            $hallRoleKey = $this->hallRoleKey($examDate, $this->normalizeTime((string) $assignment->start_time), (int) $assignment->exam_hall_id, $this->assignmentRoleValue($assignment));

            $totalCounts[$invigilatorId] = (int) ($totalCounts[$invigilatorId] ?? 0) + 1;
            $dayCounts[$invigilatorId][$examDate] = (int) ($dayCounts[$invigilatorId][$examDate] ?? 0) + 1;
            $slotAssigned[$slotKey][$invigilatorId] = true;
            $assignedByHallRole[$hallRoleKey] = (int) ($assignedByHallRole[$hallRoleKey] ?? 0) + 1;
        }

        $stageStartedAt = hrtime(true);
        $assignmentRows = [];
        $shortageRows = [];
        $slotResults = [];
        $now = now();

        foreach ($slots as $slotKey => $slotHalls) {
            [$examDate, $startTime] = explode('|', (string) $slotKey);
            $slotResults[$slotKey] = [
                'status' => 'success',
                'exam_date' => $examDate,
                'start_time' => $startTime,
                'halls_count' => $slotHalls->count(),
                'assigned_count' => 0,
                'shortage_count' => 0,
                'message' => __('exam.notifications.invigilator_distribution_completed'),
            ];

            foreach ($slotHalls as $hallAssignment) {
                $hall = $hallAssignment->examHall;
                $hallType = $hall?->hall_type?->value ?? (string) $hall?->hall_type;
                $requirement = $requirementsByHallType->get($hallType);

                if (! $hall || ! $requirement) {
                    $shortageRows[] = $this->shortageRow($college, $examDate, $startTime, (int) ($hall?->id ?? 0), InvigilationRole::Regular, 1, 0, __('exam.invigilator_shortage_reasons.missing_hall_requirement'), $now);
                    $slotResults[$slotKey]['shortage_count']++;

                    continue;
                }

                foreach ($this->roleRequirements($requirement) as $roleValue => $requiredCount) {
                    $requiredRole = InvigilationRole::from($roleValue);
                    $requiredCount = (int) $requiredCount;

                    if ($requiredCount <= 0) {
                        continue;
                    }

                    $hallRoleKey = $this->hallRoleKey($examDate, $startTime, (int) $hall->id, $requiredRole->value);
                    $assignedCount = (int) ($assignedByHallRole[$hallRoleKey] ?? 0);

                    if ($requiredRole === InvigilationRole::Reserve) {
                        if ($assignedCount < $requiredCount) {
                            $shortageCount = $requiredCount - $assignedCount;
                            $shortageRows[] = $this->shortageRow(
                                $college,
                                $examDate,
                                $startTime,
                                (int) $hall->id,
                                $requiredRole,
                                $requiredCount,
                                $assignedCount,
                                'لا يتم ربط مراقبي الاحتياط بالقاعات في التوزيع الآلي. يجب تحويل المراقب إلى دور فعال قبل استخدامه لتغطية نقص.',
                                $now,
                            );
                            $slotResults[$slotKey]['shortage_count'] += $shortageCount;
                        }

                        continue;
                    }

                    for ($index = $assignedCount; $index < $requiredCount; $index++) {
                        $selection = $this->selectInvigilatorFromMaps(
                            requiredRole: $requiredRole,
                            examDate: $examDate,
                            startTime: $startTime,
                            setting: $setting,
                            rolePools: $rolePools,
                            slotAssigned: $slotAssigned,
                            totalCounts: $totalCounts,
                            dayCounts: $dayCounts,
                        );

                        $invigilator = $selection['invigilator'];

                        if (! $invigilator) {
                            $diagnostics = $this->candidateDiagnosticsFromMaps(
                                role: $requiredRole,
                                setting: $setting,
                                examDate: $examDate,
                                startTime: $startTime,
                                rolePools: $rolePools,
                                slotAssigned: $slotAssigned,
                                totalCounts: $totalCounts,
                                dayCounts: $dayCounts,
                                inactiveRoleCounts: $inactiveRoleCounts,
                                activeInvigilatorsCount: $invigilators->count(),
                            );
                            $reason = $this->shortageReasonFromDiagnostics($requiredRole, $diagnostics);

                            $this->logUnfilledRequiredRole(
                                examDate: $examDate,
                                startTime: $startTime,
                                hallId: (int) $hall->id,
                                hallName: (string) $hall->name,
                                role: $requiredRole,
                                requiredCount: $requiredCount,
                                assignedCount: $index,
                                diagnostics: $diagnostics,
                            );

                            $shortageRows[] = $this->shortageRow($college, $examDate, $startTime, (int) $hall->id, $requiredRole, $requiredCount, $index, $reason, $now);
                            $slotResults[$slotKey]['shortage_count'] += $requiredCount - $index;

                            break;
                        }

                        $invigilatorId = (int) $invigilator->getKey();
                        $assignmentRows[] = [
                            'college_id' => $college->getKey(),
                            'subject_exam_offering_id' => $firstOfferingIdBySlot[$slotKey] ?? null,
                            'exam_date' => $examDate,
                            'start_time' => $startTime,
                            'end_time' => null,
                            'exam_hall_id' => (int) $hall->id,
                            'invigilator_id' => $invigilatorId,
                            'invigilation_role' => $requiredRole->value,
                            'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
                            'assigned_by' => auth()->id(),
                            'notes' => $selection['notes'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $totalCounts[$invigilatorId] = (int) ($totalCounts[$invigilatorId] ?? 0) + 1;
                        $dayCounts[$invigilatorId][$examDate] = (int) ($dayCounts[$invigilatorId][$examDate] ?? 0) + 1;
                        $slotAssigned[$slotKey][$invigilatorId] = true;
                        $assignedByHallRole[$hallRoleKey] = (int) ($assignedByHallRole[$hallRoleKey] ?? 0) + 1;
                        $slotResults[$slotKey]['assigned_count']++;
                    }
                }
            }

            if ($slotResults[$slotKey]['shortage_count'] > 0) {
                $slotResults[$slotKey]['status'] = 'partial';
                $slotResults[$slotKey]['message'] = __('exam.notifications.invigilator_distribution_completed_with_shortage', ['count' => $slotResults[$slotKey]['shortage_count']]);
            }
        }

        $this->logDistributionTiming('assignment loop', $stageStartedAt, [
            ...$context,
            'new_assignments_count' => count($assignmentRows),
            'shortage_rows_count' => count($shortageRows),
        ]);

        $stageStartedAt = hrtime(true);
        DB::transaction(function () use ($assignmentRows, $shortageRows): void {
            foreach (array_chunk($assignmentRows, 1000) as $chunk) {
                InvigilatorAssignment::query()->insert($chunk);
            }

            foreach (array_chunk($shortageRows, 1000) as $chunk) {
                InvigilatorUnassignedRequirement::query()->insert($chunk);
            }
        });
        $this->logDistributionTiming('saving assignments and shortages', $stageStartedAt, [
            ...$context,
            'new_assignments_count' => count($assignmentRows),
            'shortage_rows_count' => count($shortageRows),
        ]);

        $results = collect($slotResults)->values();
        $shortageCount = (int) $results->sum('shortage_count');

        return [
            'status' => $results->isEmpty() || $shortageCount > 0 ? 'partial' : 'success',
            'slots_count' => $results->count(),
            'assigned_count' => count($assignmentRows),
            'shortage_count' => $shortageCount,
            'message' => $results->isEmpty()
                ? __('exam.notifications.invigilator_distribution_no_used_halls')
                : ($shortageCount > 0
                    ? __('exam.notifications.invigilator_distribution_completed_with_shortage', ['count' => $shortageCount])
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

        try {
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

                        if ($requiredRole === InvigilationRole::Reserve) {
                            continue;
                        }

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

                            $this->assertInvigilatorAssignmentIsValid($invigilator, $requiredRole, $setting);

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

                $validationErrors = $this->validateSlotAssignments($college, $examDate, $startTime, $usedHalls, $requirementsByHallType, $setting);

                if ($validationErrors !== []) {
                    throw new RuntimeException(implode(' ', $validationErrors));
                }

                $shortageCount = InvigilatorUnassignedRequirement::query()
                    ->where('college_id', $college->getKey())
                    ->whereDate('exam_date', $examDate)
                    ->whereTime('start_time', $startTime)
                    ->sum('shortage_count');
            });
        } catch (RuntimeException $exception) {
            Log::error('Invigilator distribution failed final validation.', [
                'college_id' => $college->getKey(),
                'exam_date' => $examDate,
                'start_time' => $startTime,
                'message' => $exception->getMessage(),
            ]);

            return [
                'status' => 'danger',
                'exam_date' => $examDate,
                'start_time' => $startTime,
                'halls_count' => $usedHalls->count(),
                'assigned_count' => 0,
                'shortage_count' => 0,
                'message' => $exception->getMessage(),
            ];
        }

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

    protected function usedHallsForRange(College $college, string $fromDate, string $toDate): Collection
    {
        return HallAssignment::query()
            ->with('examHall')
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate)
            ->where('assigned_students_count', '>', 0)
            ->whereHas('examHall', fn (Builder $query) => $query
                ->where('college_id', $college->getKey())
                ->where('is_active', true))
            ->orderBy('exam_date')
            ->orderBy('exam_start_time')
            ->orderBy('exam_hall_id')
            ->get()
            ->filter(fn (HallAssignment $assignment): bool => $assignment->examHall !== null)
            ->values();
    }

    protected function firstOfferingIdBySlot(College $college, string $fromDate, string $toDate): array
    {
        return SubjectExamOffering::query()
            ->select(['id', 'exam_date', 'exam_start_time'])
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate)
            ->whereHas('subject', fn (Builder $query) => $query->where('college_id', $college->getKey()))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SubjectExamOffering $offering): string => $this->slotKey($offering->exam_date->format('Y-m-d'), (string) $offering->exam_start_time))
            ->map(fn (Collection $offerings): int => (int) $offerings->first()->getKey())
            ->all();
    }

    protected function rolePools(Collection $invigilators): array
    {
        $pools = collect(InvigilationRole::cases())
            ->mapWithKeys(fn (InvigilationRole $role): array => [$role->value => collect()])
            ->all();

        foreach ($invigilators as $invigilator) {
            foreach (InvigilationRole::cases() as $role) {
                if ($this->invigilatorCanServeRequiredRole($invigilator, $role)) {
                    $pools[$role->value]->push($invigilator);
                }
            }
        }

        return $pools;
    }

    protected function roleCounts(Collection $invigilators): array
    {
        $counts = collect(InvigilationRole::cases())
            ->mapWithKeys(fn (InvigilationRole $role): array => [$role->value => 0])
            ->all();

        foreach ($invigilators as $invigilator) {
            foreach (InvigilationRole::cases() as $role) {
                if ($this->invigilatorCanServeRequiredRole($invigilator, $role)) {
                    $counts[$role->value]++;
                }
            }
        }

        return $counts;
    }

    protected function selectInvigilatorFromMaps(
        InvigilationRole $requiredRole,
        string $examDate,
        string $startTime,
        InvigilatorDistributionSetting $setting,
        array $rolePools,
        array $slotAssigned,
        array $totalCounts,
        array $dayCounts,
    ): array {
        $strict = $this->selectCandidateFromPool($rolePools[$requiredRole->value] ?? collect(), $requiredRole, $examDate, $startTime, $setting, $slotAssigned, $totalCounts, $dayCounts);

        if ($strict) {
            return [
                'invigilator' => $strict,
                'notes' => null,
            ];
        }

        if (! (bool) $setting->allow_role_fallback) {
            return [
                'invigilator' => null,
                'notes' => null,
            ];
        }

        foreach ($this->fallbackRolesFor($requiredRole) as $fallbackRole) {
            $fallback = $this->selectCandidateFromPool($rolePools[$fallbackRole->value] ?? collect(), $requiredRole, $examDate, $startTime, $setting, $slotAssigned, $totalCounts, $dayCounts);

            if (! $fallback) {
                continue;
            }

            return [
                'invigilator' => $fallback,
                'notes' => __('exam.invigilator_shortage_reasons.fallback_used', [
                    'required_role' => $requiredRole->label(),
                    'fallback_role' => $fallbackRole->label(),
                ]),
            ];
        }

        return [
            'invigilator' => null,
            'notes' => null,
        ];
    }

    protected function selectCandidateFromPool(
        Collection $pool,
        InvigilationRole $assignmentRole,
        string $examDate,
        string $startTime,
        InvigilatorDistributionSetting $setting,
        array $slotAssigned,
        array $totalCounts,
        array $dayCounts,
    ): ?Invigilator {
        $eligible = $pool
            ->filter(fn (Invigilator $invigilator): bool => $this->candidateRejectionReasonsFromMaps($invigilator, $examDate, $startTime, $setting, $slotAssigned, $totalCounts, $dayCounts) === [])
            ->values();

        if ($eligible->isEmpty()) {
            return null;
        }

        $primaryEligible = $eligible
            ->filter(fn (Invigilator $invigilator): bool => $invigilator->hasPrimaryRole($assignmentRole))
            ->values();
        $selectionPool = $primaryEligible->isNotEmpty() ? $primaryEligible : $eligible;

        return (($setting->distribution_pattern?->value ?? $setting->distribution_pattern) === InvigilatorDistributionPattern::Random->value)
            ? $selectionPool->shuffle()->first()
            : $selectionPool->sortBy(fn (Invigilator $invigilator): array => $this->scoreFromMaps($invigilator, $examDate, $setting, $totalCounts, $dayCounts))->first();
    }

    protected function candidateDiagnosticsFromMaps(
        InvigilationRole $role,
        InvigilatorDistributionSetting $setting,
        string $examDate,
        string $startTime,
        array $rolePools,
        array $slotAssigned,
        array $totalCounts,
        array $dayCounts,
        array $inactiveRoleCounts,
        int $activeInvigilatorsCount,
    ): array {
        /** @var Collection<int, Invigilator> $candidates */
        $candidates = $rolePools[$role->value] ?? collect();
        $rejections = [];
        $eligible = collect();

        foreach ($candidates as $candidate) {
            $reasons = $this->candidateRejectionReasonsFromMaps($candidate, $examDate, $startTime, $setting, $slotAssigned, $totalCounts, $dayCounts);

            if ($reasons === []) {
                $eligible->push($candidate);

                continue;
            }

            foreach ($reasons as $reason) {
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;
            }
        }

        return [
            'role' => $role->value,
            'role_label' => $role->label(),
            'inactive_count' => (int) ($inactiveRoleCounts[$role->value] ?? 0),
            'wrong_faculty_count' => 0,
            'wrong_role_count' => max(0, $activeInvigilatorsCount - $candidates->count()),
            'candidates_found' => $candidates->count(),
            'eligible_count' => $eligible->count(),
            'rejected_count' => $candidates->count() - $eligible->count(),
            'rejected_counts' => $rejections,
            'eligible' => $eligible->values(),
        ];
    }

    protected function candidateRejectionReasonsFromMaps(
        Invigilator $invigilator,
        string $examDate,
        string $startTime,
        InvigilatorDistributionSetting $setting,
        array $slotAssigned,
        array $totalCounts,
        array $dayCounts,
    ): array {
        $reasons = [];
        $invigilatorId = (int) $invigilator->getKey();
        $slotKey = $this->slotKey($examDate, $startTime);
        $maxAssignments = $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator);
        $totalAssignments = (int) ($totalCounts[$invigilatorId] ?? 0);

        if (isset($slotAssigned[$slotKey][$invigilatorId])) {
            $reasons[] = 'same_slot_conflict';
        }

        if ((int) $invigilator->workload_reduction_percentage >= 100) {
            $reasons[] = 'workload_reduction_100';
        } elseif ($maxAssignments <= 0 || $totalAssignments >= $maxAssignments) {
            $reasons[] = 'max_assignments_reached';
        }

        $dayAssignments = (int) ($dayCounts[$invigilatorId][$examDate] ?? 0);
        $allowMultiplePerDay = $this->allowsMultipleAssignmentsPerDay($invigilator, $setting);
        $dayLimit = $this->maxAssignmentsPerDay($invigilator, $setting);

        if (! $allowMultiplePerDay && $dayAssignments > 0) {
            $reasons[] = $invigilator->allow_multiple_assignments_per_day !== null
                ? 'personal_same_day_limit'
                : 'same_day_limit';
        }

        if ($dayLimit !== null && $dayAssignments >= $dayLimit) {
            $reasons[] = $invigilator->max_assignments_per_day !== null
                ? 'personal_daily_limit_reached'
                : 'daily_limit_reached';
        }

        return array_values(array_unique($reasons));
    }

    protected function scoreFromMaps(Invigilator $invigilator, string $examDate, InvigilatorDistributionSetting $setting, array $totalCounts, array $dayCounts): array
    {
        $invigilatorId = (int) $invigilator->getKey();
        $total = (int) ($totalCounts[$invigilatorId] ?? 0);
        $week = $this->assignmentCountInWeekFromMap($dayCounts[$invigilatorId] ?? [], $examDate);
        $nearby = $this->nearbyAssignmentCountFromMap($dayCounts[$invigilatorId] ?? [], $examDate);
        $pattern = $setting->distribution_pattern?->value ?? $setting->distribution_pattern;
        $dayPreference = $this->dayPreference($invigilator, $setting);

        $patternScore = match ($pattern) {
            InvigilatorDistributionPattern::Consecutive->value => -$nearby,
            InvigilatorDistributionPattern::Distributed->value => $nearby,
            default => 0,
        };

        $dayScore = match ($dayPreference) {
            InvigilatorDayPreference::Early->value => $this->assignmentCountBeforeFromMap($dayCounts[$invigilatorId] ?? [], $examDate),
            InvigilatorDayPreference::Late->value => -$this->assignmentCountBeforeFromMap($dayCounts[$invigilatorId] ?? [], $examDate),
            default => 0,
        };

        return [$total, $week, $patternScore, $dayScore, $invigilatorId];
    }

    protected function assignmentCountInWeekFromMap(array $dateCounts, string $examDate): int
    {
        $date = Carbon::parse($examDate);
        $from = $date->copy()->startOfWeek()->toDateString();
        $to = $date->copy()->endOfWeek()->toDateString();

        return collect($dateCounts)
            ->filter(fn (int $count, string $date): bool => $date >= $from && $date <= $to)
            ->sum();
    }

    protected function nearbyAssignmentCountFromMap(array $dateCounts, string $examDate): int
    {
        $date = Carbon::parse($examDate);
        $from = $date->copy()->subDay()->toDateString();
        $to = $date->copy()->addDay()->toDateString();

        return collect($dateCounts)
            ->filter(fn (int $count, string $date): bool => $date >= $from && $date <= $to)
            ->sum();
    }

    protected function assignmentCountBeforeFromMap(array $dateCounts, string $examDate): int
    {
        return collect($dateCounts)
            ->filter(fn (int $count, string $date): bool => $date < $examDate)
            ->sum();
    }

    protected function clearDistributionRange(College $college, string $fromDate, string $toDate, bool $overwriteManual = false): void
    {
        $assignmentQuery = InvigilatorAssignment::withTrashed()
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate);

        if (! $overwriteManual) {
            $assignmentQuery->where('assignment_status', '!=', InvigilatorAssignmentStatus::Manual->value);
        }

        $assignmentQuery->forceDelete();

        InvigilatorUnassignedRequirement::query()
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate)
            ->delete();
    }

    protected function shortageRow(College $college, string $examDate, string $startTime, int $hallId, InvigilationRole $role, int $required, int $assigned, string $reason, CarbonInterface $timestamp): array
    {
        return [
            'college_id' => $college->getKey(),
            'exam_date' => $examDate,
            'start_time' => $startTime,
            'exam_hall_id' => $hallId,
            'invigilation_role' => $role->value,
            'required_count' => $required,
            'assigned_count' => $assigned,
            'shortage_count' => max(0, $required - $assigned),
            'reason' => $reason,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    protected function hallRoleKey(string $examDate, string $startTime, int $hallId, string $role): string
    {
        return $this->slotKey($examDate, $startTime).'|'.$hallId.'|'.$role;
    }

    protected function logDistributionTiming(string $stage, int $startedAt, array $context = []): void
    {
        Log::info('Invigilator distribution performance: '.$stage, [
            ...$context,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }

    public function getSummary(College $college, ?string $examDate = null, ?string $startTime = null, ?string $fromDate = null, ?string $toDate = null, bool $includeDutyIncreaseRecommendationDetails = false, bool $includeShortageDetails = true, bool $includeReportDetails = true): array
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
        $assignments = $includeReportDetails ? $this->flattenAssignments($slotSummaries) : collect();
        $shortages = $slotSummaries->flatMap(fn (array $slot): array => $slot['shortages'])->values();
        $setting = $this->settingsForCollege($college);
        $shortageByRole = $this->shortageByRole($slotSummaries, $setting);
        $dutyIncreaseRecommendations = $this->dutyIncreaseRecommendations($college, $slotSummaries, $setting, $includeDutyIncreaseRecommendationDetails);

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
            'slots' => $includeReportDetails ? $slotSummaries->all() : [],
            'shortages' => $includeShortageDetails ? $shortages->all() : [],
            'shortage_by_role' => $shortageByRole,
            'shortage_by_slot' => $this->shortageBySlot($slotSummaries),
            'duty_increase_recommendations' => $dutyIncreaseRecommendations,
            'diagnosis' => $this->diagnosis($slotSummaries, $shortageByRole),
            'by_invigilator' => $includeReportDetails ? $this->groupByInvigilator($assignments) : [],
            'by_day' => $includeReportDetails ? $this->groupByDay($slotSummaries) : [],
        ];
    }

    public function getShortagePage(College $college, ?string $examDate = null, ?string $startTime = null, ?string $fromDate = null, ?string $toDate = null, int $page = 1, int $perPage = 10): array
    {
        $allowedPerPage = [10, 25, 50, 100];
        $perPage = in_array($perPage, $allowedPerPage, true) ? $perPage : 10;

        $slots = filled($examDate) && filled($startTime)
            ? collect([['exam_date' => substr((string) $examDate, 0, 10), 'start_time' => $this->normalizeTime((string) $startTime)]])
            : $this->buildSlots($college, $fromDate, $toDate);

        $rows = $slots
            ->flatMap(fn (array $slot): array => $this->slotSummary($college, $slot['exam_date'], $slot['start_time'])['shortages'] ?? [])
            ->sortBy([
                ['exam_date', 'asc'],
                ['start_time', 'asc'],
                ['hall_name', 'asc'],
                ['role_key', 'asc'],
            ])
            ->values();

        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = min($total, $page * $perPage);

        return [
            'data' => $rows
                ->slice(($page - 1) * $perPage, $perPage)
                ->map(fn (array $row): array => [
                    'college_name' => $college->name,
                    ...$row,
                ])
                ->values()
                ->all(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
            'has_pages' => $lastPage > 1,
            'per_page_options' => $allowedPerPage,
        ];
    }

    public function createFairBalancedDraft(College $college, ?string $fromDate = null, ?string $toDate = null, ?int $createdBy = null): InvigilatorDistributionDraft
    {
        $setting = $this->settingsForCollege($college);
        $slots = $this->buildSlots($college, $fromDate, $toDate);
        $slotSummaries = $slots
            ->map(fn (array $slot): array => $this->slotSummary($college, $slot['exam_date'], $slot['start_time']))
            ->values();
        $dutyUnits = $this->fairDraftDutyUnits($slotSummaries);
        $invigilators = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', true)
            ->where('workload_reduction_percentage', '<', 100)
            ->orderBy('id')
            ->get();
        $invigilatorIds = $invigilators->pluck('id')->all();
        $currentCounts = InvigilatorAssignment::query()
            ->whereIn('invigilator_id', $invigilatorIds)
            ->when($fromDate, fn (Builder $query) => $query->whereDate('exam_date', '>=', substr((string) $fromDate, 0, 10)))
            ->when($toDate, fn (Builder $query) => $query->whereDate('exam_date', '<=', substr((string) $toDate, 0, 10)))
            ->select('invigilator_id', DB::raw('count(*) as aggregate'))
            ->groupBy('invigilator_id')
            ->pluck('aggregate', 'invigilator_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $outsideCounts = InvigilatorAssignment::query()
            ->whereIn('invigilator_id', $invigilatorIds)
            ->where(function (Builder $query) use ($college, $fromDate, $toDate): void {
                $query->where('college_id', '!=', $college->getKey());

                if ($fromDate) {
                    $query->orWhereDate('exam_date', '<', substr((string) $fromDate, 0, 10));
                }

                if ($toDate) {
                    $query->orWhereDate('exam_date', '>', substr((string) $toDate, 0, 10));
                }
            })
            ->select('invigilator_id', DB::raw('count(*) as aggregate'))
            ->groupBy('invigilator_id')
            ->pluck('aggregate', 'invigilator_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $blockedSlots = $this->officialSlotsOutsideFairDraftScope($college, $fromDate, $toDate, $invigilatorIds);

        $proposedCounts = [];
        $proposedDayCounts = [];
        $proposedSlots = [];
        $draftRows = [];
        $uncovered = [];

        foreach ($dutyUnits as $unit) {
            $role = InvigilationRole::tryFrom($unit['role']);

            if (! $role) {
                continue;
            }

            $selected = $this->selectFairDraftInvigilator(
                $invigilators,
                $role,
                $setting,
                $unit,
                $currentCounts,
                $outsideCounts,
                $proposedCounts,
                $proposedDayCounts,
                $proposedSlots,
                $blockedSlots,
                false,
            );

            $relaxedConstraints = [];

            if (! $selected) {
                $selected = $this->selectFairDraftInvigilator(
                    $invigilators,
                    $role,
                    $setting,
                    $unit,
                    $currentCounts,
                    $outsideCounts,
                    $proposedCounts,
                    $proposedDayCounts,
                    $proposedSlots,
                    $blockedSlots,
                    true,
                );
                $relaxedConstraints = $selected
                    ? $this->relaxedConstraintsForFairDraft($selected, $setting, $unit, $outsideCounts, $proposedCounts, $proposedDayCounts)
                    : [];
            }

            if (! $selected) {
                $uncovered[] = $unit;

                continue;
            }

            $selectedId = (int) $selected->getKey();
            $date = $unit['exam_date'];
            $slotKey = $this->slotKey($date, $unit['start_time']);

            $proposedCounts[$selectedId] = (int) ($proposedCounts[$selectedId] ?? 0) + 1;
            $proposedDayCounts[$selectedId][$date] = (int) ($proposedDayCounts[$selectedId][$date] ?? 0) + 1;
            $proposedSlots[$selectedId][$slotKey] = true;

            $draftRows[] = [
                'college_id' => $college->getKey(),
                'invigilator_id' => $selectedId,
                'exam_hall_id' => $unit['exam_hall_id'],
                'exam_date' => $date,
                'start_time' => $unit['start_time'],
                'invigilation_role' => $role->value,
                'relaxed_constraints_json' => $relaxedConstraints ?: null,
                'reason' => $relaxedConstraints
                    ? __('exam.fair_draft.reasons.soft_constraints_relaxed')
                    : __('exam.fair_draft.reasons.least_loaded_eligible'),
            ];
        }

        $summary = $this->fairDraftSummary($college, $invigilators, $currentCounts, $proposedCounts, $draftRows, $uncovered);

        return DB::transaction(function () use ($college, $fromDate, $toDate, $createdBy, $setting, $summary, $draftRows, $currentCounts, $proposedCounts): InvigilatorDistributionDraft {
            $draft = InvigilatorDistributionDraft::query()->create([
                'college_id' => $college->getKey(),
                'exam_date_from' => $fromDate ? substr((string) $fromDate, 0, 10) : null,
                'exam_date_to' => $toDate ? substr((string) $toDate, 0, 10) : null,
                'status' => 'draft',
                'created_by' => $createdBy,
                'summary_json' => $summary,
                'settings_json' => [
                    'default_max_assignments_per_invigilator' => $setting->default_max_assignments_per_invigilator,
                    'max_assignments_per_day' => $setting->max_assignments_per_day,
                    'allow_multiple_assignments_per_day' => $setting->allow_multiple_assignments_per_day,
                    'allow_role_fallback' => $setting->allow_role_fallback,
                ],
            ]);

            foreach ($draftRows as $row) {
                $current = (int) ($currentCounts[$row['invigilator_id']] ?? 0);
                $proposed = (int) ($proposedCounts[$row['invigilator_id']] ?? 0);

                InvigilatorDistributionDraftAssignment::query()->create([
                    'draft_id' => $draft->getKey(),
                    ...$row,
                    'current_duties_count' => $current,
                    'proposed_duties_count' => $proposed,
                    'difference' => $proposed - $current,
                ]);
            }

            return $draft->refresh();
        });
    }

    public function approveFairBalancedDraft(InvigilatorDistributionDraft $draft, ?int $approvedBy = null): InvigilatorDistributionDraft
    {
        return DB::transaction(function () use ($draft, $approvedBy): InvigilatorDistributionDraft {
            $draft = InvigilatorDistributionDraft::query()
                ->whereKey($draft->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->status !== 'draft') {
                throw new RuntimeException(__('exam.fair_draft.errors.already_finalized'));
            }

            $draft->load(['college', 'assignments.invigilator', 'assignments.examHall']);
            $errors = $this->validateFairDraft($draft);

            if ($errors !== []) {
                throw new RuntimeException(implode(' ', $errors));
            }

            InvigilatorAssignment::query()
                ->where('college_id', $draft->college_id)
                ->when($draft->exam_date_from, fn (Builder $query) => $query->whereDate('exam_date', '>=', $draft->exam_date_from))
                ->when($draft->exam_date_to, fn (Builder $query) => $query->whereDate('exam_date', '<=', $draft->exam_date_to))
                ->delete();

            foreach ($draft->assignments as $assignment) {
                InvigilatorAssignment::query()->create([
                    'college_id' => $draft->college_id,
                    'exam_date' => $assignment->exam_date,
                    'start_time' => $assignment->start_time,
                    'exam_hall_id' => $assignment->exam_hall_id,
                    'invigilator_id' => $assignment->invigilator_id,
                    'invigilation_role' => $assignment->invigilation_role?->value ?? (string) $assignment->invigilation_role,
                    'assignment_status' => InvigilatorAssignmentStatus::Assigned->value,
                    'assigned_by' => $approvedBy,
                    'notes' => __('exam.fair_draft.approved_assignment_note', ['draft' => $draft->getKey()]),
                ]);
            }

            $draft->forceFill([
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ])->save();

            return $draft->refresh();
        });
    }

    public function cancelFairBalancedDraft(InvigilatorDistributionDraft $draft): InvigilatorDistributionDraft
    {
        if ($draft->status !== 'draft') {
            throw new RuntimeException(__('exam.fair_draft.errors.already_finalized'));
        }

        $draft->forceFill(['status' => 'cancelled'])->save();

        return $draft->refresh();
    }

    protected function fairDraftDutyUnits(Collection $slotSummaries): array
    {
        return $slotSummaries
            ->flatMap(function (array $slot): array {
                return collect($slot['halls'] ?? [])
                    ->flatMap(function (array $hall) use ($slot): array {
                        return collect($hall['required_roles'] ?? [])
                            ->flatMap(function (int $requiredCount, string $role) use ($slot, $hall): array {
                                $roleEnum = InvigilationRole::tryFrom($role);

                                if (! $roleEnum || $roleEnum === InvigilationRole::Reserve || $requiredCount <= 0) {
                                    return [];
                                }

                                return collect(range(1, $requiredCount))
                                    ->map(fn (): array => [
                                        'exam_date' => substr((string) $slot['exam_date'], 0, 10),
                                        'start_time' => $this->normalizeTime((string) $slot['start_time']),
                                        'exam_hall_id' => $hall['id'],
                                        'hall_name' => $hall['name'],
                                        'role' => $role,
                                    ])
                                    ->all();
                            })
                            ->all();
                    })
                    ->all();
            })
            ->values()
            ->all();
    }

    protected function selectFairDraftInvigilator(Collection $invigilators, InvigilationRole $role, InvigilatorDistributionSetting $setting, array $unit, array $currentCounts, array $outsideCounts, array $proposedCounts, array $proposedDayCounts, array $proposedSlots, array $blockedSlots, bool $allowSoftRelaxation): ?Invigilator
    {
        $slotKey = $this->slotKey($unit['exam_date'], $unit['start_time']);

        return $invigilators
            ->filter(function (Invigilator $invigilator) use ($role, $setting, $unit, $outsideCounts, $proposedCounts, $proposedDayCounts, $proposedSlots, $blockedSlots, $slotKey, $allowSoftRelaxation): bool {
                $invigilatorId = (int) $invigilator->getKey();

                if (! $this->assignmentRoleIsCompatible($invigilator, $role, $setting)) {
                    return false;
                }

                if (($blockedSlots[$invigilatorId][$slotKey] ?? false) || ($proposedSlots[$invigilatorId][$slotKey] ?? false)) {
                    return false;
                }

                if ($allowSoftRelaxation) {
                    return true;
                }

                $outsideCount = (int) ($outsideCounts[$invigilatorId] ?? 0);
                $proposedCount = (int) ($proposedCounts[$invigilatorId] ?? 0);
                $maxAssignments = $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator);

                if ($maxAssignments <= 0 || ($outsideCount + $proposedCount + 1) > $maxAssignments) {
                    return false;
                }

                $date = $unit['exam_date'];
                $projectedDayCount = (int) ($proposedDayCounts[$invigilatorId][$date] ?? 0) + 1;

                if (! $this->allowsMultipleAssignmentsPerDay($invigilator, $setting) && $projectedDayCount > 1) {
                    return false;
                }

                return $projectedDayCount <= $this->maxAssignmentsPerDay($invigilator, $setting);
            })
            ->sortBy(function (Invigilator $invigilator) use ($currentCounts, $outsideCounts, $proposedCounts): array {
                $invigilatorId = (int) $invigilator->getKey();

                return [
                    (int) ($proposedCounts[$invigilatorId] ?? 0),
                    (int) ($currentCounts[$invigilatorId] ?? 0),
                    (int) ($outsideCounts[$invigilatorId] ?? 0),
                    $invigilatorId,
                ];
            })
            ->first();
    }

    protected function relaxedConstraintsForFairDraft(Invigilator $invigilator, InvigilatorDistributionSetting $setting, array $unit, array $outsideCounts, array $proposedCounts, array $proposedDayCounts): array
    {
        $invigilatorId = (int) $invigilator->getKey();
        $constraints = [];
        $maxAssignments = $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator);
        $projectedTotal = (int) ($outsideCounts[$invigilatorId] ?? 0) + (int) ($proposedCounts[$invigilatorId] ?? 0) + 1;

        if ($maxAssignments <= 0 || $projectedTotal > $maxAssignments) {
            $constraints[] = __('exam.fair_draft.relaxed_constraints.max_assignments');
        }

        $date = $unit['exam_date'];
        $projectedDayCount = (int) ($proposedDayCounts[$invigilatorId][$date] ?? 0) + 1;

        if (! $this->allowsMultipleAssignmentsPerDay($invigilator, $setting) && $projectedDayCount > 1) {
            $constraints[] = __('exam.fair_draft.relaxed_constraints.multiple_per_day');
        }

        if ($projectedDayCount > $this->maxAssignmentsPerDay($invigilator, $setting)) {
            $constraints[] = __('exam.fair_draft.relaxed_constraints.daily_limit');
        }

        return array_values(array_unique($constraints));
    }

    protected function fairDraftSummary(College $college, Collection $invigilators, array $currentCounts, array $proposedCounts, array $draftRows, array $uncovered): array
    {
        $allObserverIds = $invigilators->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $proposedValues = collect($allObserverIds)->map(fn (int $id): int => (int) ($proposedCounts[$id] ?? 0));
        $changedIds = collect($allObserverIds)
            ->filter(fn (int $id): bool => (int) ($currentCounts[$id] ?? 0) !== (int) ($proposedCounts[$id] ?? 0));

        return [
            'college_name' => $college->name,
            'total_observers' => count($allObserverIds),
            'total_duties' => count($draftRows) + count($uncovered),
            'proposed_duties' => count($draftRows),
            'uncovered_duties' => count($uncovered),
            'min_duties' => (int) ($proposedValues->min() ?? 0),
            'max_duties' => (int) ($proposedValues->max() ?? 0),
            'average_duties' => count($allObserverIds) > 0 ? round(count($draftRows) / count($allObserverIds), 2) : 0,
            'increased_observers_count' => collect($allObserverIds)->filter(fn (int $id): bool => (int) ($proposedCounts[$id] ?? 0) > (int) ($currentCounts[$id] ?? 0))->count(),
            'decreased_observers_count' => collect($allObserverIds)->filter(fn (int $id): bool => (int) ($proposedCounts[$id] ?? 0) < (int) ($currentCounts[$id] ?? 0))->count(),
            'changed_observers_count' => $changedIds->count(),
            'relaxed_constraints_count' => collect($draftRows)->filter(fn (array $row): bool => ! empty($row['relaxed_constraints_json'] ?? []))->count(),
        ];
    }

    protected function officialSlotsOutsideFairDraftScope(College $college, ?string $fromDate, ?string $toDate, array $invigilatorIds): array
    {
        return InvigilatorAssignment::query()
            ->whereIn('invigilator_id', $invigilatorIds)
            ->where(function (Builder $query) use ($college, $fromDate, $toDate): void {
                $query->where('college_id', '!=', $college->getKey());

                if ($fromDate) {
                    $query->orWhereDate('exam_date', '<', substr((string) $fromDate, 0, 10));
                }

                if ($toDate) {
                    $query->orWhereDate('exam_date', '>', substr((string) $toDate, 0, 10));
                }
            })
            ->get(['invigilator_id', 'exam_date', 'start_time'])
            ->groupBy('invigilator_id')
            ->map(fn (Collection $assignments): array => $assignments
                ->mapWithKeys(fn (InvigilatorAssignment $assignment): array => [
                    $this->slotKey($assignment->exam_date->format('Y-m-d'), (string) $assignment->start_time) => true,
                ])
                ->all())
            ->all();
    }

    protected function validateFairDraft(InvigilatorDistributionDraft $draft): array
    {
        $setting = $this->settingsForCollege($draft->college);
        $errors = [];
        $duplicateSlots = $draft->assignments
            ->groupBy(fn (InvigilatorDistributionDraftAssignment $assignment): string => implode('|', [
                $assignment->invigilator_id,
                $assignment->exam_date->format('Y-m-d'),
                $this->normalizeTime((string) $assignment->start_time),
            ]))
            ->filter(fn (Collection $items): bool => $items->count() > 1);

        if ($duplicateSlots->isNotEmpty()) {
            $errors[] = __('exam.fair_draft.errors.duplicate_slot_assignment');
        }

        foreach ($draft->assignments as $assignment) {
            $invigilator = $assignment->invigilator;
            $role = $assignment->invigilation_role instanceof InvigilationRole
                ? $assignment->invigilation_role
                : InvigilationRole::tryFrom((string) $assignment->invigilation_role);

            if (! $invigilator || ! $invigilator->is_active || (int) $invigilator->workload_reduction_percentage >= 100) {
                $errors[] = __('exam.fair_draft.errors.invalid_invigilator');
                continue;
            }

            if (! $role || ! $this->assignmentRoleIsCompatible($invigilator, $role, $setting)) {
                $errors[] = __('exam.fair_draft.errors.invalid_role_assignment');
            }

            $hallIsUsed = HallAssignment::query()
                ->where('college_id', $draft->college_id)
                ->where('exam_hall_id', $assignment->exam_hall_id)
                ->whereDate('exam_date', $assignment->exam_date)
                ->whereTime('exam_start_time', $assignment->start_time)
                ->exists();

            if (! $hallIsUsed) {
                $errors[] = __('exam.fair_draft.errors.invalid_hall_assignment');
            }
        }

        return array_values(array_unique($errors));
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
        $warnings = $this->studentDistributionNonBlockingWarnings($college, $fromDate, $toDate);

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
            'has_non_blocking_warnings' => $warnings !== [],
            'warnings' => $warnings,
            'warning_message' => $warnings === [] ? null : __('exam.global_hall_distribution.success_with_warnings_body'),
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
        $computedShortages = $this->computedShortagesForSlot($examDate, $startTime, $hallSummaries);

        return [
            'exam_date' => $examDate,
            'start_time' => $startTime,
            'halls_count' => $hallSummaries->count(),
            'required_count' => $hallSummaries->sum('required_count'),
            'assigned_count' => $hallSummaries->sum('assigned_count'),
            'shortage_count' => $computedShortages->sum('shortage_count'),
            'halls' => $hallSummaries->all(),
            'shortages' => $computedShortages->all(),
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
            'has_non_blocking_warnings' => false,
            'warnings' => [],
            'warning_message' => null,
            'slots' => [],
            'incomplete_slots' => [],
        ];
    }

    public function lightweightStudentDistributionReadiness(College $college, ?string $fromDate, ?string $toDate): array
    {
        $fromDate = filled($fromDate) ? substr((string) $fromDate, 0, 10) : null;
        $toDate = filled($toDate) ? substr((string) $toDate, 0, 10) : null;

        if (! $fromDate || ! $toDate) {
            return $this->emptyReadiness(
                isReady: false,
                blockingMessage: __('exam.readiness.reasons.period_missing'),
            );
        }

        $hasOfferings = SubjectExamOffering::query()
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate)
            ->whereHas('subject', fn (Builder $query) => $query->where('college_id', $college->getKey()))
            ->exists();

        if (! $hasOfferings) {
            return array_merge($this->emptyReadiness(
                isReady: false,
                blockingMessage: __('exam.readiness.reasons.no_offerings'),
            ), [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'has_student_distribution_run' => false,
                'has_hall_assignments' => false,
            ]);
        }

        $run = StudentDistributionRun::query()
            ->where('college_id', $college->getKey())
            ->whereDate('from_date', '<=', $fromDate)
            ->whereDate('to_date', '>=', $toDate)
            ->latest('executed_at')
            ->latest('id')
            ->first();

        $hasHallAssignments = HallAssignment::query()
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate)
            ->where('assigned_students_count', '>', 0)
            ->exists();

        $unassignedStudentsCount = (int) ($run?->unassigned_students ?? 0);
        $usedHallsCount = $hasHallAssignments ? max(1, (int) ($run?->used_halls ?? 1)) : 0;
        $runStatusIsSuccessful = $run && in_array((string) $run->status, ['success', 'success_with_warnings'], true);
        $isReady = $run !== null
            && $runStatusIsSuccessful
            && $unassignedStudentsCount === 0
            && (int) ($run->used_halls ?? 0) > 0
            && $hasHallAssignments;

        $blockingMessage = match (true) {
            $run === null => __('exam.readiness.reasons.student_distribution_missing'),
            ! $runStatusIsSuccessful => __('exam.readiness.reasons.student_distribution_missing'),
            $unassignedStudentsCount > 0 => __('exam.readiness.reasons.unassigned_students_block_invigilators'),
            ! $hasHallAssignments || (int) ($run->used_halls ?? 0) <= 0 => __('exam.readiness.reasons.no_used_halls'),
            default => __('exam.readiness.ready_message'),
        };

        $hasNonBlockingWarnings = $run !== null && (
            (string) $run->status === 'success_with_warnings'
            || (int) (($run->summary_json ?? [])['carry_regular_mixing_cases_count'] ?? 0) > 0
            || StudentDistributionRunIssue::query()
                ->where('student_distribution_run_id', $run->getKey())
                ->where('issue_type', 'carry_regular_mixed_due_to_capacity')
                ->exists()
        );

        return [
            'is_ready' => $isReady,
            'blocking_message' => $blockingMessage,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'offerings_count' => 1,
            'slots_count' => 0,
            'distributed_slots_count' => 0,
            'used_halls_count' => $usedHallsCount,
            'halls_needing_invigilators_count' => $usedHallsCount,
            'assigned_students_count' => 0,
            'unassigned_students_count' => $unassignedStudentsCount,
            'incomplete_slots_count' => $isReady ? 0 : 1,
            'has_student_distribution_run' => $run !== null,
            'has_hall_assignments' => $hasHallAssignments,
            'has_non_blocking_warnings' => $hasNonBlockingWarnings,
            'warnings' => $hasNonBlockingWarnings ? [[
                'type' => 'carry_regular_mixed_due_to_capacity',
                'message' => __('exam.global_hall_distribution.success_with_warnings_body'),
                'blocks_invigilator_distribution' => false,
            ]] : [],
            'warning_message' => $hasNonBlockingWarnings ? __('exam.global_hall_distribution.success_with_warnings_body') : null,
            'slots' => [],
            'incomplete_slots' => [],
        ];
    }

    protected function studentDistributionNonBlockingWarnings(College $college, ?string $fromDate, ?string $toDate): array
    {
        if (! $fromDate || ! $toDate) {
            return [];
        }

        $run = StudentDistributionRun::query()
            ->where('college_id', $college->getKey())
            ->whereDate('from_date', '<=', $fromDate)
            ->whereDate('to_date', '>=', $toDate)
            ->latest('executed_at')
            ->latest('id')
            ->first();

        if (! $run || (int) $run->unassigned_students > 0 || (int) $run->capacity_shortage > 0 || (int) $run->used_halls <= 0) {
            return [];
        }

        $summary = $run->summary_json ?? [];
        $warnings = collect($summary['warnings'] ?? [])
            ->merge($summary['warnings_by_slot'] ?? [])
            ->filter(function (array $warning) use ($fromDate, $toDate): bool {
                $date = substr((string) ($warning['exam_date'] ?? ''), 0, 10);

                return filled($date) && $date >= $fromDate && $date <= $toDate;
            })
            ->values();

        if ($warnings->isEmpty() && (int) ($summary['carry_regular_mixing_cases_count'] ?? 0) <= 0) {
            return [];
        }

        return [[
            'type' => 'carry_regular_mixed_due_to_capacity',
            'message' => __('exam.global_hall_distribution.success_with_warnings_body'),
            'blocks_invigilator_distribution' => false,
        ]];
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

        $primaryEligible = $eligible
            ->filter(fn (Invigilator $invigilator): bool => $invigilator->hasPrimaryRole($role))
            ->values();
        $selectionPool = $primaryEligible->isNotEmpty() ? $primaryEligible : $eligible;

        $invigilator = (($setting->distribution_pattern?->value ?? $setting->distribution_pattern) === InvigilatorDistributionPattern::Random->value)
            ? $selectionPool->shuffle()->first()
            : $selectionPool->sortBy(fn (Invigilator $invigilator): array => $this->score($invigilator, $examDate, $setting))->first();

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
        $activeInvigilators = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', true)
            ->get();
        $candidates = $activeInvigilators
            ->filter(fn (Invigilator $invigilator): bool => $this->invigilatorCanServeRequiredRole($invigilator, $role))
            ->values();
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
                ->where('is_active', false)
                ->get()
                ->filter(fn (Invigilator $invigilator): bool => $this->invigilatorCanServeRequiredRole($invigilator, $role))
                ->count(),
            'wrong_faculty_count' => $all
                ->filter(fn (Invigilator $invigilator): bool => $this->invigilatorCanServeRequiredRole($invigilator, $role))
                ->where('college_id', '!=', $college->getKey())
                ->count(),
            'wrong_role_count' => $activeInvigilators
                ->reject(fn (Invigilator $invigilator): bool => $this->invigilatorCanServeRequiredRole($invigilator, $role))
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
        $allowMultiplePerDay = $this->allowsMultipleAssignmentsPerDay($invigilator, $setting);
        $dayLimit = $this->maxAssignmentsPerDay($invigilator, $setting);

        if (! $allowMultiplePerDay && $dayAssignments > 0) {
            $reasons[] = $invigilator->allow_multiple_assignments_per_day !== null
                ? 'personal_same_day_limit'
                : 'same_day_limit';
        }

        if ($dayLimit !== null && $dayAssignments >= $dayLimit) {
            $reasons[] = $invigilator->max_assignments_per_day !== null
                ? 'personal_daily_limit_reached'
                : 'daily_limit_reached';
        }

        return array_values(array_unique($reasons));
    }

    protected function invigilatorCanServeRequiredRole(Invigilator $invigilator, InvigilationRole $role): bool
    {
        if ($this->invigilatorHasPrimaryReserveRole($invigilator)) {
            return $role === InvigilationRole::Reserve;
        }

        return $role !== InvigilationRole::Reserve
            && $invigilator->canServeAs($role);
    }

    protected function invigilatorHasPrimaryReserveRole(Invigilator $invigilator): bool
    {
        $primaryRole = $invigilator->invigilation_role instanceof InvigilationRole
            ? $invigilator->invigilation_role->value
            : (string) $invigilator->invigilation_role;

        return $primaryRole === InvigilationRole::Reserve->value;
    }

    protected function assignmentRoleIsCompatible(Invigilator $invigilator, InvigilationRole $assignmentRole, InvigilatorDistributionSetting $setting): bool
    {
        if ($assignmentRole === InvigilationRole::Reserve || $this->invigilatorHasPrimaryReserveRole($invigilator)) {
            return false;
        }

        if ($this->invigilatorCanServeRequiredRole($invigilator, $assignmentRole)) {
            return true;
        }

        if (! (bool) $setting->allow_role_fallback) {
            return false;
        }

        foreach ($this->fallbackRolesFor($assignmentRole) as $fallbackRole) {
            if ($this->invigilatorCanServeRequiredRole($invigilator, $fallbackRole)) {
                return true;
            }
        }

        return false;
    }

    protected function assertInvigilatorAssignmentIsValid(Invigilator $invigilator, InvigilationRole $assignmentRole, InvigilatorDistributionSetting $setting): void
    {
        if ($this->assignmentRoleIsCompatible($invigilator, $assignmentRole, $setting)) {
            return;
        }

        throw new RuntimeException($this->invalidAssignmentMessage($invigilator, $assignmentRole));
    }

    protected function invalidAssignmentMessage(Invigilator $invigilator, InvigilationRole $assignmentRole): string
    {
        if ($this->invigilatorHasPrimaryReserveRole($invigilator)) {
            return sprintf(
                'لا يمكن تكليف مراقب الاحتياط "%s" بدور "%s" أو ربطه بقاعة. يجب تحويله أولاً إلى دور مراقبة فعال.',
                $invigilator->name,
                $assignmentRole->label(),
            );
        }

        if ($assignmentRole === InvigilationRole::Reserve) {
            return sprintf(
                'لا يمكن إنشاء تكليف احتياط مرتبط بقاعة للمراقب "%s". الاحتياط يبقى خارج أدوار القاعة الفعلية.',
                $invigilator->name,
            );
        }

        return sprintf(
            'نوع المراقب "%s" غير متوافق مع الدور المطلوب "%s".',
            $invigilator->name,
            $assignmentRole->label(),
        );
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

            if ($requiredRole === InvigilationRole::Reserve) {
                $this->recordShortage(
                    college: $college,
                    examDate: $examDate,
                    startTime: $startTime,
                    hallId: (int) $hall->id,
                    role: $requiredRole,
                    required: (int) $requiredCount,
                    assigned: (int) $assignedCount,
                    reason: 'لا يتم ربط مراقبي الاحتياط بالقاعات في التوزيع الآلي. يجب تحويل المراقب إلى دور فعال قبل استخدامه لتغطية نقص.',
                );

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

    protected function validateSlotAssignments(
        College $college,
        string $examDate,
        string $startTime,
        Collection $usedHalls,
        Collection $requirementsByHallType,
        InvigilatorDistributionSetting $setting,
    ): array {
        $errors = [];
        $assignments = InvigilatorAssignment::query()
            ->with(['examHall', 'invigilator'])
            ->where('college_id', $college->getKey())
            ->whereDate('exam_date', $examDate)
            ->whereTime('start_time', $startTime)
            ->get();

        foreach ($assignments as $assignment) {
            $invigilator = $assignment->invigilator;
            $assignmentRole = InvigilationRole::tryFrom($this->assignmentRoleValue($assignment));

            if (! $invigilator || ! $assignmentRole) {
                $errors[] = 'يوجد تكليف مراقبة غير مكتمل أو بدور غير صحيح.';

                continue;
            }

            if (! $this->assignmentRoleIsCompatible($invigilator, $assignmentRole, $setting)) {
                $errors[] = $this->invalidAssignmentMessage($invigilator, $assignmentRole);
            }
        }

        $duplicateNames = $assignments
            ->groupBy('invigilator_id')
            ->filter(fn (Collection $items): bool => $items->count() > 1)
            ->map(fn (Collection $items): string => (string) ($items->first()->invigilator?->name ?? ''))
            ->filter()
            ->values()
            ->all();

        if ($duplicateNames !== []) {
            $errors[] = 'لا يمكن تكليف المراقب نفسه أكثر من مرة في نفس الموعد: '.implode('، ', $duplicateNames).'.';
        }

        foreach ($assignments->pluck('invigilator')->filter()->unique('id') as $invigilator) {
            $maxAssignments = $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator);
            $totalAssignments = $this->assignmentCount($invigilator);

            if ($maxAssignments <= 0 || $totalAssignments > $maxAssignments) {
                $errors[] = sprintf('تجاوز المراقب "%s" الحد الأقصى للمراقبات.', $invigilator->name);
            }

            $dayAssignments = $this->assignmentCount($invigilator, $examDate);
            $allowMultiplePerDay = $this->allowsMultipleAssignmentsPerDay($invigilator, $setting);
            $dayLimit = $this->maxAssignmentsPerDay($invigilator, $setting);

            if (! $allowMultiplePerDay && $dayAssignments > 1) {
                $errors[] = sprintf('لا يسمح للمراقب "%s" بأكثر من مراقبة في اليوم نفسه.', $invigilator->name);
            }

            if ($dayLimit !== null && $dayAssignments > $dayLimit) {
                $errors[] = sprintf('تجاوز المراقب "%s" الحد الأقصى اليومي للمراقبات.', $invigilator->name);
            }
        }

        foreach ($usedHalls as $hallAssignment) {
            $hall = $hallAssignment->examHall;
            $hallType = $hall?->hall_type?->value ?? (string) $hall?->hall_type;
            $requirement = $requirementsByHallType->get($hallType);

            if (! $hall || ! $requirement || (int) $requirement->hall_head_count <= 0) {
                continue;
            }

            $validHallHeads = $assignments
                ->where('exam_hall_id', $hall->getKey())
                ->filter(function (InvigilatorAssignment $assignment) use ($setting): bool {
                    $invigilator = $assignment->invigilator;
                    $assignmentRole = InvigilationRole::tryFrom($this->assignmentRoleValue($assignment));

                    return $invigilator
                        && $assignmentRole === InvigilationRole::HallHead
                        && $this->assignmentRoleIsCompatible($invigilator, $assignmentRole, $setting);
                })
                ->count();

            $assignedHallHeads = $assignments
                ->where('exam_hall_id', $hall->getKey())
                ->filter(fn (InvigilatorAssignment $assignment): bool => $this->assignmentRoleValue($assignment) === InvigilationRole::HallHead->value)
                ->count();

            if ($assignedHallHeads > 0 && $validHallHeads < min((int) $requirement->hall_head_count, $assignedHallHeads)) {
                $errors[] = sprintf('القاعة "%s" تحتوي على رئيس قاعة غير صالح.', $hall->name);
            }
        }

        return array_values(array_unique($errors));
    }

    protected function score(Invigilator $invigilator, string $examDate, InvigilatorDistributionSetting $setting): array
    {
        $total = $this->assignmentCount($invigilator);
        $week = $this->assignmentCountInWeek($invigilator, $examDate);
        $nearby = $this->nearbyAssignmentCount($invigilator, $examDate);
        $pattern = $setting->distribution_pattern?->value ?? $setting->distribution_pattern;
        $dayPreference = $this->dayPreference($invigilator, $setting);

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

    protected function allowsMultipleAssignmentsPerDay(Invigilator $invigilator, InvigilatorDistributionSetting $setting): bool
    {
        if ($invigilator->allow_multiple_assignments_per_day !== null) {
            return (bool) $invigilator->allow_multiple_assignments_per_day;
        }

        return (bool) ($setting->allow_multiple_assignments_per_day ?? false);
    }

    protected function maxAssignmentsPerDay(Invigilator $invigilator, InvigilatorDistributionSetting $setting): int
    {
        return (int) ($invigilator->max_assignments_per_day ?? $setting->max_assignments_per_day ?? 1);
    }

    protected function dayPreference(Invigilator $invigilator, InvigilatorDistributionSetting $setting): string
    {
        $preference = $invigilator->day_preference ?? $setting->day_preference ?? InvigilatorDayPreference::Balanced;

        return $preference instanceof InvigilatorDayPreference ? $preference->value : (string) $preference;
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

        if (($rejections['personal_same_day_limit'] ?? 0) >= $found) {
            return $this->roleShortageReason($role, 'personal_multiple_per_day_not_allowed');
        }

        if (($rejections['personal_daily_limit_reached'] ?? 0) >= $found) {
            return $this->roleShortageReason($role, 'personal_daily_limit_reached');
        }

        if (
            (($rejections['personal_same_day_limit'] ?? 0) > 0 || ($rejections['personal_daily_limit_reached'] ?? 0) > 0)
            && (int) ($diagnostics['eligible_count'] ?? 0) === 0
        ) {
            return $this->roleShortageReason($role, 'personal_settings_shortage');
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
            'personal_multiple_per_day_not_allowed' => 'لا يسمح لهذا المراقب بأكثر من مراقبة في اليوم.',
            'personal_daily_limit_reached' => 'تجاوز هذا المراقب الحد الأقصى اليومي المحدد له.',
            'personal_settings_shortage' => 'لم يتوفر عدد كافٍ من المراقبين ضمن الإعدادات الشخصية المحددة.',
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

    protected function computedShortagesForSlot(string $examDate, string $startTime, Collection $hallSummaries): Collection
    {
        return $hallSummaries
            ->flatMap(function (array $hall) use ($examDate, $startTime): array {
                return collect($hall['required_roles'] ?? [])
                    ->map(function (int $requiredCount, string $role) use ($examDate, $startTime, $hall): ?array {
                        $assignedCount = count($hall['assignments_by_role'][$role] ?? []);
                        $shortageCount = max(0, (int) $requiredCount - $assignedCount);

                        if ($shortageCount === 0) {
                            return null;
                        }

                        $recordedShortage = $hall['shortages_by_role'][$role] ?? [];

                        return [
                            'exam_date' => $examDate,
                            'start_time' => substr($startTime, 0, 5),
                            'hall_id' => $hall['id'] ?? null,
                            'hall_name' => $hall['name'] ?? null,
                            'hall_location' => $hall['location'] ?? null,
                            'hall_type' => $hall['hall_type'] ?? null,
                            'hall_type_label' => $hall['hall_type_label'] ?? '-',
                            'role_key' => $role,
                            'invigilation_role' => __("exam.invigilation_roles.{$role}"),
                            'required_count' => (int) $requiredCount,
                            'assigned_count' => $assignedCount,
                            'shortage_count' => $shortageCount,
                            'reason' => $recordedShortage['reason'] ?? __('exam.reports.required_role_shortage_reason'),
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            })
            ->values();
    }

    protected function shortageByRole(Collection $slotSummaries, InvigilatorDistributionSetting $setting): array
    {
        $shortages = $slotSummaries->flatMap(fn (array $slot): array => $slot['shortages'] ?? []);

        return collect(InvigilationRole::cases())
            ->mapWithKeys(function (InvigilationRole $role) use ($slotSummaries, $shortages, $setting): array {
                $halls = $slotSummaries->flatMap(fn (array $slot): array => $slot['halls'] ?? []);
                $requiredCount = $halls->sum(fn (array $hall): int => (int) ($hall['required_roles'][$role->value] ?? 0));
                $assignedCount = $halls->sum(fn (array $hall): int => count($hall['assignments_by_role'][$role->value] ?? []));
                $roleShortages = $shortages
                    ->filter(fn (array $shortage): bool => ($shortage['role_key'] ?? null) === $role->value)
                    ->values();
                $shortageCount = (int) $roleShortages->sum('shortage_count');

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
                    'shortage_count' => $shortageCount,
                    'missing_assignments_count' => $shortageCount,
                    'recommended_additional_observers_count' => $this->recommendedAdditionalObserversForRole($roleShortages, $setting),
                    'reason_counts' => $roleShortages
                        ->groupBy(fn (array $shortage): string => (string) ($shortage['reason'] ?? __('exam.reports.required_role_shortage_reason')))
                        ->map(fn (Collection $items): int => (int) $items->sum('shortage_count'))
                        ->sortDesc()
                        ->all(),
                ]];
            })
            ->all();
    }

    protected function shortageBySlot(Collection $slotSummaries): array
    {
        return $slotSummaries
            ->flatMap(function (array $slot): array {
                return collect($slot['shortages'] ?? [])
                    ->groupBy('role_key')
                    ->map(fn (Collection $items, string $role): array => [
                        'exam_date' => $slot['exam_date'],
                        'start_time' => substr((string) $slot['start_time'], 0, 5),
                        'role_key' => $role,
                        'role_label' => __("exam.invigilation_roles.{$role}"),
                        'required_count' => (int) $items->sum('required_count'),
                        'assigned_count' => (int) $items->sum('assigned_count'),
                        'shortage_count' => (int) $items->sum('shortage_count'),
                    ])
                    ->values()
                    ->all();
            })
            ->sortBy([['exam_date', 'asc'], ['start_time', 'asc'], ['role_key', 'asc']])
            ->values()
            ->all();
    }

    protected function recommendedAdditionalObserversForRole(Collection $roleShortages, InvigilatorDistributionSetting $setting): int
    {
        $missingAssignments = (int) $roleShortages->sum('shortage_count');

        if ($missingAssignments === 0) {
            return 0;
        }

        $maxAssignments = max(1, (int) ($setting->default_max_assignments_per_invigilator ?? 1));
        $dailyLimit = (bool) ($setting->allow_multiple_assignments_per_day ?? false)
            ? max(1, (int) ($setting->max_assignments_per_day ?? 1))
            : 1;

        $maxSameSlotShortage = (int) $roleShortages
            ->groupBy(fn (array $shortage): string => ($shortage['exam_date'] ?? '').'|'.($shortage['start_time'] ?? ''))
            ->map(fn (Collection $items): int => (int) $items->sum('shortage_count'))
            ->max();
        $maxSameDayShortage = (int) $roleShortages
            ->groupBy(fn (array $shortage): string => (string) ($shortage['exam_date'] ?? ''))
            ->map(fn (Collection $items): int => (int) $items->sum('shortage_count'))
            ->max();

        return max(
            $maxSameSlotShortage,
            (int) ceil($missingAssignments / $maxAssignments),
            (int) ceil($maxSameDayShortage / $dailyLimit),
        );
    }

    protected function dutyIncreaseRecommendations(College $college, Collection $slotSummaries, InvigilatorDistributionSetting $setting, bool $includeDetails = true): array
    {
        $shortageUnits = $slotSummaries
            ->flatMap(fn (array $slot): array => $slot['shortages'] ?? [])
            ->flatMap(function (array $shortage): array {
                $role = InvigilationRole::tryFrom((string) ($shortage['role_key'] ?? ''));

                if (! $role) {
                    return [];
                }

                $shortageCount = max(0, (int) ($shortage['shortage_count'] ?? 0));

                if ($shortageCount === 0) {
                    return [];
                }

                return collect(range(1, $shortageCount))
                    ->map(fn (): array => [
                        'exam_date' => substr((string) ($shortage['exam_date'] ?? ''), 0, 10),
                        'start_time' => $this->normalizeTime((string) ($shortage['start_time'] ?? '')),
                        'hall_name' => (string) ($shortage['hall_name'] ?? ''),
                        'role' => $role,
                        'role_label' => $role->label(),
                    ])
                    ->all();
            })
            ->sortBy([['exam_date', 'asc'], ['start_time', 'asc'], ['role_label', 'asc'], ['hall_name', 'asc']])
            ->values();

        $totalUncoveredDuties = (int) $slotSummaries
            ->flatMap(fn (array $slot): array => $slot['shortages'] ?? [])
            ->sum('shortage_count');

        if ($totalUncoveredDuties === 0) {
            return $this->emptyDutyIncreaseRecommendations();
        }

        $invigilators = Invigilator::query()
            ->where('college_id', $college->getKey())
            ->where('is_active', true)
            ->where('workload_reduction_percentage', '<', 100)
            ->orderBy('id')
            ->get();

        $assignmentRows = InvigilatorAssignment::query()
            ->where('college_id', $college->getKey())
            ->get(['invigilator_id', 'exam_date', 'start_time']);

        $assignmentCounts = $assignmentRows
            ->groupBy('invigilator_id')
            ->map(fn (Collection $items): int => $items->count())
            ->all();
        $assignedSlots = $assignmentRows
            ->groupBy('invigilator_id')
            ->map(fn (Collection $items): array => $items
                ->mapWithKeys(fn (InvigilatorAssignment $assignment): array => [
                    $this->slotKey($assignment->exam_date->format('Y-m-d'), (string) $assignment->start_time) => true,
                ])
                ->all())
            ->all();
        $assignedDayCounts = $assignmentRows
            ->groupBy('invigilator_id')
            ->map(fn (Collection $items): array => $items
                ->groupBy(fn (InvigilatorAssignment $assignment): string => $assignment->exam_date->format('Y-m-d'))
                ->map(fn (Collection $dayItems): int => $dayItems->count())
                ->all())
            ->all();

        $recommended = [];
        $recommendedSlotKeys = [];
        $recommendedDayCounts = [];
        $unresolved = [];

        foreach ($shortageUnits as $unit) {
            /** @var InvigilationRole $role */
            $role = $unit['role'];
            $slotKey = $this->slotKey($unit['exam_date'], $unit['start_time']);

            $compatibleCandidates = $invigilators
                ->filter(fn (Invigilator $invigilator): bool => $this->assignmentRoleIsCompatible($invigilator, $role, $setting))
                ->values();

            $candidates = $compatibleCandidates
                ->filter(function (Invigilator $invigilator) use ($setting, $assignmentCounts, $assignedSlots, $assignedDayCounts, $recommended, $recommendedSlotKeys, $recommendedDayCounts, $unit, $slotKey): bool {
                    $invigilatorId = (int) $invigilator->getKey();
                    $currentAssigned = (int) ($assignmentCounts[$invigilatorId] ?? 0);
                    $currentMax = $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator);

                    if ($currentMax <= 0 || $currentAssigned < $currentMax) {
                        return false;
                    }

                    if (($assignedSlots[$invigilatorId][$slotKey] ?? false) || ($recommendedSlotKeys[$invigilatorId][$slotKey] ?? false)) {
                        return false;
                    }

                    $date = $unit['exam_date'];
                    $existingDayCount = (int) ($assignedDayCounts[$invigilatorId][$date] ?? 0);
                    $recommendedDayCount = (int) ($recommendedDayCounts[$invigilatorId][$date] ?? 0);
                    $projectedDayCount = $existingDayCount + $recommendedDayCount + 1;

                    if (! $this->allowsMultipleAssignmentsPerDay($invigilator, $setting) && $projectedDayCount > 1) {
                        return false;
                    }

                    $dayLimit = $this->maxAssignmentsPerDay($invigilator, $setting);

                    if ($dayLimit !== null && $projectedDayCount > $dayLimit) {
                        return false;
                    }

                    $suggestedAdditional = (int) ($recommended[$invigilatorId]['suggested_additional_duties'] ?? 0);

                    return $currentAssigned + $suggestedAdditional >= $currentMax;
                })
                ->sortBy(function (Invigilator $invigilator) use ($assignmentCounts, $recommended, $setting): array {
                    $invigilatorId = (int) $invigilator->getKey();

                    return [
                        (int) ($recommended[$invigilatorId]['suggested_additional_duties'] ?? 0),
                        (int) ($assignmentCounts[$invigilatorId] ?? 0),
                        $invigilator->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator),
                        $invigilatorId,
                    ];
                })
                ->values();

            /** @var Invigilator|null $selected */
            $selected = $candidates->first();

            if (! $selected) {
                $unresolved[] = [
                    'exam_date' => $unit['exam_date'],
                    'start_time' => substr((string) $unit['start_time'], 0, 5),
                    'role' => $role->value,
                    'role_label' => $role->label(),
                    'hall_name' => $unit['hall_name'],
                    'reason' => $compatibleCandidates->isEmpty()
                        ? __('exam.reports.duty_increase_no_compatible_observers')
                        : __('exam.reports.duty_increase_blocked_by_conflicts_or_daily_limits'),
                ];

                continue;
            }

            $selectedId = (int) $selected->getKey();
            $currentAssigned = (int) ($assignmentCounts[$selectedId] ?? 0);
            $currentMax = $selected->effectiveMaxAssignments($setting->default_max_assignments_per_invigilator);
            $period = trim($unit['exam_date'].' '.substr((string) $unit['start_time'], 0, 5).' - '.$unit['role_label'].' - '.$unit['hall_name'], ' -');

            $recommended[$selectedId] ??= [
                'invigilator_id' => $selectedId,
                'current_assigned_duties' => $currentAssigned,
                'current_max_duties' => $currentMax,
                'suggested_new_max_duties' => $currentMax,
                'suggested_additional_duties' => 0,
            ];

            if ($includeDetails && ! isset($recommended[$selectedId]['name'])) {
                $recommended[$selectedId] += [
                    'name' => $selected->name,
                    'observer_type' => $selected->invigilation_role?->label(),
                    'eligible_roles' => collect($selected->eligibleRoleValues())
                        ->reject(fn (string $role): bool => $role === InvigilationRole::Reserve->value)
                        ->map(fn (string $role): string => __("exam.invigilation_roles.{$role}"))
                        ->implode('، '),
                    'reason' => __('exam.reports.duty_increase_recommendation_reason'),
                    'related_slots' => [],
                    'related_roles' => [],
                ];
            }

            $recommended[$selectedId]['suggested_additional_duties']++;
            $recommended[$selectedId]['suggested_new_max_duties'] = $currentMax + $recommended[$selectedId]['suggested_additional_duties'];

            if ($includeDetails) {
                $recommended[$selectedId]['related_slots'][] = $period;
                $recommended[$selectedId]['related_roles'][] = $unit['role_label'];
            }

            $recommendedSlotKeys[$selectedId][$slotKey] = true;
            $recommendedDayCounts[$selectedId][$unit['exam_date']] = (int) ($recommendedDayCounts[$selectedId][$unit['exam_date']] ?? 0) + 1;
        }

        $recommendedCollection = collect($recommended);
        $recommendations = $includeDetails
            ? $recommendedCollection
                ->map(function (array $item): array {
                    $item['related_slots'] = array_values(array_unique($item['related_slots'] ?? []));
                    $item['related_roles'] = array_values(array_unique($item['related_roles'] ?? []));

                    return $item;
                })
                ->sortBy([['suggested_additional_duties', 'desc'], ['current_assigned_duties', 'asc'], ['name', 'asc']])
                ->values()
                ->all()
            : [];
        $coverable = (int) $recommendedCollection->sum('suggested_additional_duties');

        return [
            'total_uncovered_duties' => $totalUncoveredDuties,
            'coverable_by_limit_increase' => $coverable,
            'requires_new_observers' => max(0, $totalUncoveredDuties - $coverable),
            'recommended_observers_count' => $recommendedCollection->count(),
            'max_suggested_increase_per_observer' => (int) $recommendedCollection->max('suggested_additional_duties'),
            'recommendations' => $recommendations,
            'unresolved' => $includeDetails
                ? collect($unresolved)
                    ->groupBy(fn (array $item): string => implode('|', [
                        $item['exam_date'],
                        $item['start_time'],
                        $item['role'],
                        $item['reason'],
                    ]))
                    ->map(function (Collection $items): array {
                        $first = $items->first();

                        return [
                            'exam_date' => $first['exam_date'],
                            'start_time' => $first['start_time'],
                            'role' => $first['role'],
                            'role_label' => $first['role_label'],
                            'shortage_count' => $items->count(),
                            'reason' => $first['reason'],
                        ];
                    })
                    ->values()
                    ->all()
                : [],
        ];
    }

    protected function emptyDutyIncreaseRecommendations(): array
    {
        return [
            'total_uncovered_duties' => 0,
            'coverable_by_limit_increase' => 0,
            'requires_new_observers' => 0,
            'recommended_observers_count' => 0,
            'max_suggested_increase_per_observer' => 0,
            'recommendations' => [],
            'unresolved' => [],
        ];
    }

    protected function slotKey(string $examDate, string $startTime): string
    {
        return substr($examDate, 0, 10).'|'.$this->normalizeTime($startTime);
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

    protected function diagnosis(Collection $slotSummaries, array $shortageByRole): array
    {
        if ($slotSummaries->isEmpty()) {
            return [[
                'tone' => 'gray',
                'message' => __('exam.diagnosis.no_hall_distribution_results'),
            ]];
        }

        $roleShortages = collect($shortageByRole)
            ->filter(fn (array $roleShortage): bool => (int) ($roleShortage['shortage_count'] ?? 0) > 0)
            ->values();

        if ($roleShortages->isEmpty()) {
            return [[
                'tone' => 'success',
                'message' => __('exam.diagnosis.invigilators_all_distributed'),
            ]];
        }

        $items = $roleShortages
            ->map(fn (array $roleShortage): array => [
                'tone' => 'danger',
                'message' => __('exam.diagnosis.invigilator_role_shortage', [
                    'role' => $roleShortage['role_label'] ?? $roleShortage['role'],
                    'assignments' => (int) ($roleShortage['shortage_count'] ?? 0),
                    'observers' => (int) ($roleShortage['recommended_additional_observers_count'] ?? 0),
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
