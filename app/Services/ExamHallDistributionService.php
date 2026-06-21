<?php

namespace App\Services;

use App\Enums\ExamHallPriority;
use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Models\ExamHall;
use App\Models\ExamStudent;
use App\Models\ExamStudentHallAssignment;
use App\Models\HallAssignment;
use App\Models\HallAssignmentSubject;
use App\Models\StudentDistributionRun;
use App\Models\StudentDistributionRunIssue;
use App\Models\SubjectExamOffering;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExamHallDistributionService
{
    public function distributeForFacultyDateRange(
        int $collegeId,
        string $fromDate,
        string $toDate,
        bool $redistribute = false,
        bool $separateCarryStudents = false,
        bool $allowMultipleSubjectsPerHall = true,
    ): array {
        $fromDate = substr($fromDate, 0, 10);
        $toDate = substr($toDate, 0, 10);
        $settings = [
            'separate_carry_students' => $separateCarryStudents,
            'allow_multiple_subjects_per_hall' => $allowMultipleSubjectsPerHall,
            'allow_normal_subjects_in_drawing_studios' => $this->allowNormalSubjectsInDrawingStudios(),
        ];

        $offerings = SubjectExamOffering::query()
            ->with(['subject'])
            ->withCount('examStudents')
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate)
            ->whereHas('subject', fn ($query) => $query->where('college_id', $collegeId))
            ->orderBy('exam_date')
            ->orderBy('exam_start_time')
            ->orderBy('id')
            ->get();

        if ($offerings->isEmpty()) {
            return $this->persistGlobalDistributionResult($this->globalDistributionFailure(
                collegeId: $collegeId,
                fromDate: $fromDate,
                toDate: $toDate,
                reason: __('exam.global_hall_distribution.reasons.no_offerings'),
                issueType: 'no_offerings',
                settings: $settings,
            ));
        }

        $slots = $offerings
            ->groupBy(fn (SubjectExamOffering $offering): string => $offering->exam_date->format('Y-m-d').'|'.$this->normalizeExamStartTime($offering->exam_start_time))
            ->values();

        $activeHalls = ExamHall::query()
            ->where('college_id', $collegeId)
            ->where('is_active', true)
            ->get();

        if ($activeHalls->isEmpty()) {
            $failureIssues = $this->globalFailureIssueSummaries(
                slots: $slots,
                reason: __('exam.global_hall_distribution.reasons.no_active_halls'),
                issueType: 'no_available_halls',
            );

            return $this->persistGlobalDistributionResult($this->globalDistributionFailure(
                collegeId: $collegeId,
                fromDate: $fromDate,
                toDate: $toDate,
                reason: __('exam.global_hall_distribution.reasons.no_active_halls'),
                issueType: 'no_available_halls',
                totalOfferings: $offerings->count(),
                totalSlots: $slots->count(),
                totalStudents: (int) $offerings->sum('exam_students_count'),
                capacityShortage: (int) $offerings->sum('exam_students_count'),
                issues: $failureIssues['issues'],
                unassignedBySubject: $failureIssues['unassigned_by_subject'],
                unassignedBySlot: $failureIssues['unassigned_by_slot'],
                settings: $settings,
            ));
        }

        $totalStudents = (int) $offerings->sum('exam_students_count');

        if ($totalStudents === 0) {
            return $this->persistGlobalDistributionResult($this->globalDistributionFailure(
                collegeId: $collegeId,
                fromDate: $fromDate,
                toDate: $toDate,
                reason: __('exam.global_hall_distribution.reasons.no_students'),
                issueType: 'no_students',
                totalOfferings: $offerings->count(),
                totalSlots: $slots->count(),
                settings: $settings,
            ));
        }

        $summary = [
            'status' => 'success',
            'faculty_id' => $collegeId,
            'college_id' => $collegeId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'message' => __('exam.notifications.global_hall_distribution_completed'),
            'total_offerings' => $offerings->count(),
            'total_slots' => $slots->count(),
            'total_students' => $totalStudents,
            'distributed_students' => 0,
            'unassigned_students' => 0,
            'used_halls' => 0,
            'total_capacity' => 0,
            'capacity_shortage' => 0,
            'distributed_slots_count' => 0,
            'skipped_slots_count' => 0,
            'issue_slots_count' => 0,
            'slots' => [],
            'issues' => [],
            'unassigned_by_subject' => [],
            'unassigned_by_slot' => [],
            'settings' => $settings,
            'separate_carry_students' => $separateCarryStudents,
            'regular_students_count' => 0,
            'carry_students_count' => 0,
            'regular_halls_count' => 0,
            'carry_halls_count' => 0,
            'mixed_halls_count' => 0,
            'carry_regular_mixing_cases_count' => 0,
        ];

        foreach ($slots as $slotOfferings) {
            /** @var SubjectExamOffering $firstOffering */
            $firstOffering = $slotOfferings->first();
            $examDate = $firstOffering->exam_date->format('Y-m-d');
            $examStartTime = $this->normalizeExamStartTime($firstOffering->exam_start_time);
            $slotStudentsCount = (int) $slotOfferings->sum('exam_students_count');
            $slotCapacityProfile = $this->slotCapacityProfile($slotOfferings, $activeHalls);
            $slotCapacity = $slotCapacityProfile['usable_capacity'];
            $slotCapacityShortage = $slotCapacityProfile['capacity_shortage'];
            $summary['total_capacity'] += $slotCapacity;
            $summary['capacity_shortage'] += $slotCapacityShortage;

            if (! $redistribute && $this->slotHasDistribution($collegeId, $examDate, $examStartTime)) {
                $slotStats = $this->slotDistributionStats($slotOfferings, $collegeId, $examDate, $examStartTime, $slotCapacity, $slotCapacityShortage, $separateCarryStudents);
                $summary['skipped_slots_count']++;
                $summary['distributed_students'] += $slotStats['distributed_students'];
                $summary['unassigned_students'] += $slotStats['unassigned_students'];
                $summary['used_halls'] += $slotStats['used_halls'];
                $summary['issues'] = array_merge($summary['issues'], $slotStats['issues']);
                $summary['unassigned_by_subject'] = array_merge($summary['unassigned_by_subject'], $slotStats['unassigned_by_subject']);
                $this->addSeparationStatsToGlobalSummary($summary, $slotStats);

                if ($slotStats['unassigned_students'] > 0 || $slotCapacityShortage > 0 || ($separateCarryStudents && $slotStats['mixed_halls_count'] > 0)) {
                    $summary['issue_slots_count']++;
                    $summary['unassigned_by_slot'][] = $slotStats['slot_issue'];
                }

                $summary['slots'][] = [
                    'status' => 'skipped',
                    'exam_date' => $examDate,
                    'exam_start_time' => $examStartTime,
                    'offerings_count' => $slotOfferings->count(),
                    'students_count' => $slotStudentsCount,
                    'assigned_students_count' => $slotStats['distributed_students'],
                    'unassigned_students_count' => $slotStats['unassigned_students'],
                    'used_halls_count' => $slotStats['used_halls'],
                    'capacity' => $slotCapacity,
                    'capacity_shortage' => $slotCapacityShortage,
                    'regular_students_count' => $slotStats['regular_students_count'],
                    'carry_students_count' => $slotStats['carry_students_count'],
                    'regular_halls_count' => $slotStats['regular_halls_count'],
                    'carry_halls_count' => $slotStats['carry_halls_count'],
                    'mixed_halls_count' => $slotStats['mixed_halls_count'],
                    'message' => __('exam.global_hall_distribution.slot_skipped'),
                ];

                continue;
            }

            $result = $this->distributeForOffering(
                $firstOffering,
                separateCarryStudents: $separateCarryStudents,
                allowMultipleSubjectsPerHall: $allowMultipleSubjectsPerHall,
            );
            $slotStats = $this->slotDistributionStats($slotOfferings, $collegeId, $examDate, $examStartTime, $slotCapacity, $slotCapacityShortage, $separateCarryStudents);
            $assignedStudentsCount = $slotStats['distributed_students'];
            $unassignedStudentsCount = $slotStats['unassigned_students'];

            $summary['distributed_students'] += $assignedStudentsCount;
            $summary['unassigned_students'] += $unassignedStudentsCount;
            $summary['used_halls'] += $slotStats['used_halls'];
            $summary['issues'] = array_merge($summary['issues'], $slotStats['issues']);
            $summary['unassigned_by_subject'] = array_merge($summary['unassigned_by_subject'], $slotStats['unassigned_by_subject']);
            $summary['distributed_slots_count']++;
            $this->addSeparationStatsToGlobalSummary($summary, $slotStats);

            if ($unassignedStudentsCount > 0 || $slotCapacityShortage > 0 || ($separateCarryStudents && $slotStats['mixed_halls_count'] > 0)) {
                $summary['issue_slots_count']++;
                $summary['unassigned_by_slot'][] = $slotStats['slot_issue'];
            }

            $summary['slots'][] = [
                'status' => $result['status'] ?? 'warning',
                'exam_date' => $examDate,
                'exam_start_time' => $examStartTime,
                'offerings_count' => $slotOfferings->count(),
                'students_count' => $slotStudentsCount,
                'assigned_students_count' => $assignedStudentsCount,
                'unassigned_students_count' => $unassignedStudentsCount,
                'used_halls_count' => (int) ($result['used_halls_count'] ?? 0),
                'capacity' => $slotCapacity,
                'capacity_shortage' => $slotCapacityShortage,
                'regular_students_count' => $slotStats['regular_students_count'],
                'carry_students_count' => $slotStats['carry_students_count'],
                'regular_halls_count' => $slotStats['regular_halls_count'],
                'carry_halls_count' => $slotStats['carry_halls_count'],
                'mixed_halls_count' => $slotStats['mixed_halls_count'],
                'message' => $result['message'] ?? null,
            ];
        }

        $summary['separation_status_message'] = $this->carrySeparationStatusMessage($summary);

        if ($summary['unassigned_students'] > 0 || $summary['capacity_shortage'] > 0 || $summary['carry_regular_mixing_cases_count'] > 0) {
            $summary['status'] = 'partial';
            $summary['message'] = __('exam.notifications.global_hall_distribution_completed_with_issues');
        }

        return $this->persistGlobalDistributionResult($this->withLegacyGlobalDistributionKeys($summary));
    }

    public function distributeForOffering(
        SubjectExamOffering $offering,
        bool $separateCarryStudents = false,
        bool $allowMultipleSubjectsPerHall = true,
    ): array {
        $slot = $this->getSlotContext($offering);
        $slotOfferings = $slot['offerings'];
        $availableHalls = $slot['halls'];
        $totalStudents = $slotOfferings->sum('exam_students_count');

        if ($totalStudents === 0) {
            return [
                'status' => 'warning',
                'message' => __('exam.notifications.distribution_no_students'),
                'assigned_students_count' => 0,
                'unassigned_students_count' => 0,
                'used_halls_count' => 0,
            ];
        }

        if ($availableHalls->isEmpty()) {
            return [
                'status' => 'danger',
                'message' => __('exam.notifications.distribution_no_halls'),
                'assigned_students_count' => 0,
                'unassigned_students_count' => $totalStudents,
                'used_halls_count' => 0,
            ];
        }

        return DB::transaction(function () use ($slot, $slotOfferings, $availableHalls, $totalStudents, $separateCarryStudents, $allowMultipleSubjectsPerHall): array {
            $this->clearSlotDistribution(
                collegeId: $slot['college_id'],
                examDate: $slot['exam_date'],
                examStartTime: $slot['exam_start_time'],
            );

            if ($separateCarryStudents) {
                return $this->distributeSlotWithCarrySeparation(
                    slot: $slot,
                    slotOfferings: $slotOfferings,
                    availableHalls: $availableHalls,
                    totalStudents: $totalStudents,
                    allowMultipleSubjectsPerHall: $allowMultipleSubjectsPerHall,
                );
            }

            $studentQueues = [];
            $remainingCounts = [];

            foreach ($slotOfferings as $slotOffering) {
                $students = $slotOffering->examStudents()
                    ->orderBy('student_number')
                    ->orderBy('full_name')
                    ->get();

                $studentQueues[$slotOffering->getKey()] = $students->values();
                $remainingCounts[$slotOffering->getKey()] = $students->count();
            }

            $assignedStudentsCount = 0;
            $usedHallsCount = 0;
            $maxSubjectsPerHall = $this->maxSubjectsPerHall($allowMultipleSubjectsPerHall);
            $slotHasDrawingSubjects = $this->slotHasDrawingSubjects($slotOfferings);
            $allowNormalSubjectsInDrawingStudios = $this->allowNormalSubjectsInDrawingStudios();

            foreach ($availableHalls as $hall) {
                if (array_sum($remainingCounts) === 0) {
                    break;
                }

                $remainingCapacity = (int) $hall->capacity;
                $subjectCounts = [];
                $studentAssignmentRows = [];

                while (($remainingCapacity > 0) && (count($subjectCounts) < $maxSubjectsPerHall)) {
                    $nextOffering = $this->nextOfferingToAssign(
                        slotOfferings: $slotOfferings,
                        remainingCounts: $remainingCounts,
                        usedOfferingIds: array_keys($subjectCounts),
                        hall: $hall,
                        subjectCounts: $subjectCounts,
                        maxSubjectsPerHall: $maxSubjectsPerHall,
                        slotHasDrawingSubjects: $slotHasDrawingSubjects,
                        allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
                    );

                    if (! $nextOffering) {
                        break;
                    }

                    $offeringId = $nextOffering->getKey();
                    $take = min($remainingCapacity, $remainingCounts[$offeringId] ?? 0);

                    if ($take <= 0) {
                        break;
                    }

                    /** @var Collection<int, ExamStudent> $students */
                    $students = $studentQueues[$offeringId]->splice(0, $take);
                    $count = $students->count();

                    if ($count === 0) {
                        $remainingCounts[$offeringId] = 0;

                        continue;
                    }

                    $subjectCounts[$offeringId] = $count;
                    $remainingCounts[$offeringId] -= $count;
                    $remainingCapacity -= $count;

                    foreach ($students as $student) {
                        $studentAssignmentRows[] = [
                            'exam_student_id' => $student->getKey(),
                            'subject_exam_offering_id' => $offeringId,
                            'seat_number' => count($studentAssignmentRows) + 1,
                        ];
                    }
                }

                $hallAssignedStudentsCount = array_sum($subjectCounts);

                if ($hallAssignedStudentsCount === 0) {
                    continue;
                }

                $hallAssignment = HallAssignment::query()->create([
                    'exam_hall_id' => $hall->getKey(),
                    'exam_date' => $slot['exam_date'],
                    'exam_start_time' => $slot['exam_start_time'],
                    'college_id' => $slot['college_id'],
                    'total_capacity' => $hall->capacity,
                    'assigned_students_count' => $hallAssignedStudentsCount,
                    'remaining_capacity' => $hall->capacity - $hallAssignedStudentsCount,
                ]);

                foreach ($subjectCounts as $offeringId => $count) {
                    HallAssignmentSubject::query()->create([
                        'hall_assignment_id' => $hallAssignment->getKey(),
                        'subject_exam_offering_id' => $offeringId,
                        'assigned_students_count' => $count,
                    ]);
                }

                ExamStudentHallAssignment::query()->insert(
                    collect($studentAssignmentRows)
                        ->map(fn (array $row): array => [
                            ...$row,
                            'hall_assignment_id' => $hallAssignment->getKey(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])
                        ->all(),
                );

                $assignedStudentsCount += $hallAssignedStudentsCount;
                $usedHallsCount++;
            }

            $unassignedStudentsCount = max(0, $totalStudents - $assignedStudentsCount);
            $hasUnassignedDrawingStudents = $this->hasUnassignedDrawingStudents($slotOfferings, $remainingCounts);

            SubjectExamOffering::query()
                ->whereKey($slotOfferings->modelKeys())
                ->update([
                    'status' => $unassignedStudentsCount === 0
                        ? ExamOfferingStatus::Distributed->value
                        : ExamOfferingStatus::Ready->value,
                ]);

            return [
                'status' => $hasUnassignedDrawingStudents ? 'danger' : ($unassignedStudentsCount === 0 ? 'success' : 'warning'),
                'message' => $hasUnassignedDrawingStudents
                    ? __('exam.notifications.drawing_studio_capacity_shortage')
                    : ($unassignedStudentsCount === 0
                        ? __('exam.notifications.distribution_completed')
                        : __('exam.notifications.distribution_completed_with_unassigned', [
                            'count' => $unassignedStudentsCount,
                        ])),
                'assigned_students_count' => $assignedStudentsCount,
                'unassigned_students_count' => $unassignedStudentsCount,
                'used_halls_count' => $usedHallsCount,
            ];
        });
    }

    protected function distributeSlotWithCarrySeparation(
        array $slot,
        Collection $slotOfferings,
        Collection $availableHalls,
        int $totalStudents,
        bool $allowMultipleSubjectsPerHall = true,
    ): array {
        [$studentQueues, $remainingCounts] = $this->buildStudentTypeQueues($slotOfferings);
        $plans = [];
        $maxSubjectsPerHall = $this->maxSubjectsPerHall($allowMultipleSubjectsPerHall);
        $slotHasDrawingSubjects = $this->slotHasDrawingSubjects($slotOfferings);
        $allowNormalSubjectsInDrawingStudios = $this->allowNormalSubjectsInDrawingStudios();

        $regularStudentsCount = array_sum($remainingCounts[ExamStudentType::Regular->value]);
        $carryStudentsCount = array_sum($remainingCounts[ExamStudentType::Carry->value]);

        foreach ($this->hallsForCarryStudents($availableHalls) as $hall) {
            if (array_sum($remainingCounts[ExamStudentType::Carry->value]) === 0) {
                break;
            }

            $hallId = $hall->getKey();
            $plans[$hallId] ??= $this->emptyHallPlan($hall);

            $this->assignStudentTypeToHallPlan(
                studentType: ExamStudentType::Carry->value,
                plan: $plans[$hallId],
                slotOfferings: $slotOfferings,
                studentQueues: $studentQueues,
                remainingCounts: $remainingCounts,
                maxSubjectsPerHall: $maxSubjectsPerHall,
                slotHasDrawingSubjects: $slotHasDrawingSubjects,
                allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
            );
        }

        foreach ($availableHalls as $hall) {
            if (array_sum($remainingCounts[ExamStudentType::Regular->value]) === 0) {
                break;
            }

            $hallId = $hall->getKey();

            if (isset($plans[$hallId]) && $this->planAssignedStudentsCount($plans[$hallId]) > 0) {
                continue;
            }

            $plans[$hallId] ??= $this->emptyHallPlan($hall);

            $this->assignStudentTypeToHallPlan(
                studentType: ExamStudentType::Regular->value,
                plan: $plans[$hallId],
                slotOfferings: $slotOfferings,
                studentQueues: $studentQueues,
                remainingCounts: $remainingCounts,
                maxSubjectsPerHall: $maxSubjectsPerHall,
                slotHasDrawingSubjects: $slotHasDrawingSubjects,
                allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
            );
        }

        $fallbackMixedStudents = 0;
        $fallbackMixedStudents += $this->mixRemainingStudentTypeIntoExistingPlans(
            studentType: ExamStudentType::Regular->value,
            plans: $plans,
            slotOfferings: $slotOfferings,
            studentQueues: $studentQueues,
            remainingCounts: $remainingCounts,
            maxSubjectsPerHall: $maxSubjectsPerHall,
            slotHasDrawingSubjects: $slotHasDrawingSubjects,
            allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
        );
        $fallbackMixedStudents += $this->mixRemainingStudentTypeIntoExistingPlans(
            studentType: ExamStudentType::Carry->value,
            plans: $plans,
            slotOfferings: $slotOfferings,
            studentQueues: $studentQueues,
            remainingCounts: $remainingCounts,
            maxSubjectsPerHall: $maxSubjectsPerHall,
            slotHasDrawingSubjects: $slotHasDrawingSubjects,
            allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
        );

        [$assignedStudentsCount, $usedHallsCount] = $this->persistHallPlans($plans, $slot);
        $unassignedStudentsCount = max(0, $totalStudents - $assignedStudentsCount);
        $hasUnassignedDrawingStudents = $this->hasUnassignedDrawingStudents(
            $slotOfferings,
            $this->combinedRemainingCounts($remainingCounts),
        );

        SubjectExamOffering::query()
            ->whereKey($slotOfferings->modelKeys())
            ->update([
                'status' => $unassignedStudentsCount === 0
                    ? ExamOfferingStatus::Distributed->value
                    : ExamOfferingStatus::Ready->value,
            ]);

        $mixedHallsCount = collect($plans)
            ->filter(fn (array $plan): bool => $this->planIsMixed($plan))
            ->count();

        $hasMixing = $mixedHallsCount > 0 || $fallbackMixedStudents > 0;

        return [
            'status' => $hasUnassignedDrawingStudents ? 'danger' : ($unassignedStudentsCount === 0 && ! $hasMixing ? 'success' : 'warning'),
            'message' => match (true) {
                $hasUnassignedDrawingStudents => __('exam.notifications.drawing_studio_capacity_shortage'),
                $unassignedStudentsCount > 0 => __('exam.notifications.distribution_completed_with_unassigned', [
                    'count' => $unassignedStudentsCount,
                ]),
                $hasMixing => __('exam.global_hall_distribution.carry_regular_mixed_warning'),
                default => __('exam.notifications.distribution_completed'),
            },
            'assigned_students_count' => $assignedStudentsCount,
            'unassigned_students_count' => $unassignedStudentsCount,
            'used_halls_count' => $usedHallsCount,
            'regular_students_count' => $regularStudentsCount,
            'carry_students_count' => $carryStudentsCount,
            'mixed_halls_count' => $mixedHallsCount,
        ];
    }

    protected function buildStudentTypeQueues(Collection $slotOfferings): array
    {
        $queues = [
            ExamStudentType::Regular->value => [],
            ExamStudentType::Carry->value => [],
        ];
        $remainingCounts = [
            ExamStudentType::Regular->value => [],
            ExamStudentType::Carry->value => [],
        ];

        foreach ($slotOfferings as $slotOffering) {
            $students = $slotOffering->examStudents()
                ->orderBy('student_number')
                ->orderBy('full_name')
                ->get();

            foreach ([ExamStudentType::Carry->value, ExamStudentType::Regular->value] as $studentType) {
                $typedStudents = $students
                    ->filter(fn (ExamStudent $student): bool => (string) $student->getRawOriginal('student_type') === $studentType)
                    ->values();

                $queues[$studentType][$slotOffering->getKey()] = $typedStudents;
                $remainingCounts[$studentType][$slotOffering->getKey()] = $typedStudents->count();
            }
        }

        return [$queues, $remainingCounts];
    }

    protected function hallsForCarryStudents(Collection $availableHalls): Collection
    {
        return $availableHalls
            ->sort(function (ExamHall $first, ExamHall $second): int {
                $drawingStudioComparison = ((int) $this->isDrawingStudio($first)) <=> ((int) $this->isDrawingStudio($second));

                if ($drawingStudioComparison !== 0) {
                    return $drawingStudioComparison;
                }

                $capacityComparison = $first->capacity <=> $second->capacity;

                if ($capacityComparison !== 0) {
                    return $capacityComparison;
                }

                $priorityComparison = $this->priorityRank($first->priority?->value)
                    <=> $this->priorityRank($second->priority?->value);

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return strcmp($first->name, $second->name);
            })
            ->values();
    }

    protected function emptyHallPlan(ExamHall $hall): array
    {
        return [
            'hall' => $hall,
            'subject_counts' => [],
            'student_assignment_rows' => [],
            'student_type_counts' => [
                ExamStudentType::Regular->value => 0,
                ExamStudentType::Carry->value => 0,
            ],
        ];
    }

    protected function assignStudentTypeToHallPlan(
        string $studentType,
        array &$plan,
        Collection $slotOfferings,
        array &$studentQueues,
        array &$remainingCounts,
        int $maxSubjectsPerHall,
        bool $slotHasDrawingSubjects,
        bool $allowNormalSubjectsInDrawingStudios,
    ): int {
        $assignedCount = 0;
        $visitedOfferingIds = [];

        while ($this->planRemainingCapacity($plan) > 0) {
            $nextOffering = $this->nextOfferingForHallPlan(
                slotOfferings: $slotOfferings,
                remainingCounts: $remainingCounts[$studentType],
                subjectCounts: $plan['subject_counts'],
                visitedOfferingIds: $visitedOfferingIds,
                hall: $plan['hall'],
                maxSubjectsPerHall: $maxSubjectsPerHall,
                slotHasDrawingSubjects: $slotHasDrawingSubjects,
                allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
            );

            if (! $nextOffering) {
                break;
            }

            $offeringId = $nextOffering->getKey();
            $take = min($this->planRemainingCapacity($plan), $remainingCounts[$studentType][$offeringId] ?? 0);

            if ($take <= 0) {
                $visitedOfferingIds[] = $offeringId;

                continue;
            }

            /** @var Collection<int, ExamStudent> $students */
            $students = $studentQueues[$studentType][$offeringId]->splice(0, $take);
            $count = $students->count();

            if ($count === 0) {
                $remainingCounts[$studentType][$offeringId] = 0;
                $visitedOfferingIds[] = $offeringId;

                continue;
            }

            $plan['subject_counts'][$offeringId] = ($plan['subject_counts'][$offeringId] ?? 0) + $count;
            $plan['student_type_counts'][$studentType] += $count;
            $remainingCounts[$studentType][$offeringId] -= $count;
            $assignedCount += $count;
            $visitedOfferingIds[] = $offeringId;

            foreach ($students as $student) {
                $plan['student_assignment_rows'][] = [
                    'exam_student_id' => $student->getKey(),
                    'subject_exam_offering_id' => $offeringId,
                    'seat_number' => count($plan['student_assignment_rows']) + 1,
                    'student_type' => $studentType,
                ];
            }
        }

        return $assignedCount;
    }

    protected function nextOfferingForHallPlan(
        Collection $slotOfferings,
        array $remainingCounts,
        array $subjectCounts,
        array $visitedOfferingIds,
        ExamHall $hall,
        int $maxSubjectsPerHall,
        bool $slotHasDrawingSubjects,
        bool $allowNormalSubjectsInDrawingStudios,
    ): ?SubjectExamOffering {
        return $slotOfferings
            ->first(function (SubjectExamOffering $slotOffering) use ($remainingCounts, $subjectCounts, $visitedOfferingIds, $hall, $maxSubjectsPerHall, $slotHasDrawingSubjects, $slotOfferings, $allowNormalSubjectsInDrawingStudios): bool {
                $offeringId = $slotOffering->getKey();

                if (in_array($offeringId, $visitedOfferingIds, true)) {
                    return false;
                }

                if (($remainingCounts[$offeringId] ?? 0) <= 0) {
                    return false;
                }

                return $this->canAddOfferingToHall(
                    offering: $slotOffering,
                    hall: $hall,
                    subjectCounts: $subjectCounts,
                    slotOfferings: $slotOfferings,
                    maxSubjectsPerHall: $maxSubjectsPerHall,
                    slotHasDrawingSubjects: $slotHasDrawingSubjects,
                    allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
                );
            });
    }

    protected function mixRemainingStudentTypeIntoExistingPlans(
        string $studentType,
        array &$plans,
        Collection $slotOfferings,
        array &$studentQueues,
        array &$remainingCounts,
        int $maxSubjectsPerHall,
        bool $slotHasDrawingSubjects,
        bool $allowNormalSubjectsInDrawingStudios,
    ): int {
        if (array_sum($remainingCounts[$studentType]) === 0) {
            return 0;
        }

        $oppositeStudentType = $studentType === ExamStudentType::Carry->value
            ? ExamStudentType::Regular->value
            : ExamStudentType::Carry->value;
        $mixedAssignedCount = 0;

        foreach ($plans as &$plan) {
            if (array_sum($remainingCounts[$studentType]) === 0) {
                break;
            }

            if ($this->planRemainingCapacity($plan) <= 0) {
                continue;
            }

            $hadOppositeStudents = ($plan['student_type_counts'][$oppositeStudentType] ?? 0) > 0;
            $assigned = $this->assignStudentTypeToHallPlan(
                studentType: $studentType,
                plan: $plan,
                slotOfferings: $slotOfferings,
                studentQueues: $studentQueues,
                remainingCounts: $remainingCounts,
                maxSubjectsPerHall: $maxSubjectsPerHall,
                slotHasDrawingSubjects: $slotHasDrawingSubjects,
                allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
            );

            if ($hadOppositeStudents) {
                $mixedAssignedCount += $assigned;
            }
        }

        return $mixedAssignedCount;
    }

    protected function persistHallPlans(array $plans, array $slot): array
    {
        $assignedStudentsCount = 0;
        $usedHallsCount = 0;

        foreach ($plans as $plan) {
            $hallAssignedStudentsCount = $this->planAssignedStudentsCount($plan);

            if ($hallAssignedStudentsCount === 0) {
                continue;
            }

            /** @var ExamHall $hall */
            $hall = $plan['hall'];
            $hallAssignment = HallAssignment::query()->create([
                'exam_hall_id' => $hall->getKey(),
                'exam_date' => $slot['exam_date'],
                'exam_start_time' => $slot['exam_start_time'],
                'college_id' => $slot['college_id'],
                'total_capacity' => $hall->capacity,
                'assigned_students_count' => $hallAssignedStudentsCount,
                'remaining_capacity' => $hall->capacity - $hallAssignedStudentsCount,
            ]);

            foreach ($plan['subject_counts'] as $offeringId => $count) {
                HallAssignmentSubject::query()->create([
                    'hall_assignment_id' => $hallAssignment->getKey(),
                    'subject_exam_offering_id' => $offeringId,
                    'assigned_students_count' => $count,
                ]);
            }

            ExamStudentHallAssignment::query()->insert(
                collect($plan['student_assignment_rows'])
                    ->map(fn (array $row): array => [
                        'exam_student_id' => $row['exam_student_id'],
                        'subject_exam_offering_id' => $row['subject_exam_offering_id'],
                        'seat_number' => $row['seat_number'],
                        'hall_assignment_id' => $hallAssignment->getKey(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all(),
            );

            $assignedStudentsCount += $hallAssignedStudentsCount;
            $usedHallsCount++;
        }

        return [$assignedStudentsCount, $usedHallsCount];
    }

    protected function planAssignedStudentsCount(array $plan): int
    {
        return array_sum($plan['student_type_counts']);
    }

    protected function planRemainingCapacity(array $plan): int
    {
        return max(0, (int) $plan['hall']->capacity - $this->planAssignedStudentsCount($plan));
    }

    protected function planIsMixed(array $plan): bool
    {
        return ($plan['student_type_counts'][ExamStudentType::Regular->value] ?? 0) > 0
            && ($plan['student_type_counts'][ExamStudentType::Carry->value] ?? 0) > 0;
    }

    public function getSlotSummary(SubjectExamOffering $offering): array
    {
        $slot = $this->getSlotContext($offering);
        $slotOfferings = $slot['offerings'];
        $availableHalls = $slot['halls'];
        $hallAssignments = $this->getCurrentHallAssignments($offering);
        $hasDistribution = $hallAssignments->isNotEmpty();

        $assignedByOffering = $hallAssignments
            ->flatMap(fn (HallAssignment $assignment) => $assignment->assignmentSubjects)
            ->groupBy('subject_exam_offering_id')
            ->map(fn (Collection $rows): int => $rows->sum('assigned_students_count'));

        $totalStudents = $slotOfferings->sum('exam_students_count');
        $assignedStudents = (int) $assignedByOffering->sum();
        $unassignedStudentsCount = max(0, $totalStudents - $assignedStudents);
        $availableHallsCount = $availableHalls->count();
        $usedHallsCount = $hallAssignments->count();
        $slotCapacityProfile = $this->slotCapacityProfile($slotOfferings, $availableHalls);
        $totalCapacity = $slotCapacityProfile['usable_capacity'];
        $usedCapacity = (int) $hallAssignments->sum('assigned_students_count');
        $remainingCapacity = max(0, $totalCapacity - $usedCapacity);
        $capacityShortage = $slotCapacityProfile['capacity_shortage'];
        $distributionPercentage = $totalStudents > 0
            ? (int) round(($assignedStudents / $totalStudents) * 100)
            : 0;

        $distributionStatus = $this->resolveDistributionStatus(
            totalStudents: $totalStudents,
            availableHallsCount: $availableHallsCount,
            totalCapacity: $totalCapacity,
            hasDistribution: $hasDistribution,
            unassignedStudentsCount: $unassignedStudentsCount,
        );

        $offeringsWithSummary = $slotOfferings->map(function (SubjectExamOffering $slotOffering) use ($assignedByOffering, $hasDistribution): array {
            $assignedCount = (int) ($assignedByOffering[$slotOffering->getKey()] ?? 0);
            $totalCount = (int) $slotOffering->exam_students_count;
            $unassignedCount = max(0, $totalCount - $assignedCount);
            $offeringDistributionPercentage = $totalCount > 0
                ? (int) round(($assignedCount / $totalCount) * 100)
                : 0;

            $statusKey = match (true) {
                $totalCount === 0 => 'empty',
                $unassignedCount === 0 && $assignedCount > 0 => 'complete',
                $assignedCount === 0 && ! $hasDistribution => 'pending',
                $unassignedCount > 0 => 'issue',
                default => 'pending',
            };

            return [
                'offering_id' => $slotOffering->getKey(),
                'subject_name' => $this->sanitizeString($slotOffering->subject?->name ?? ''),
                'college_name' => $this->sanitizeString($slotOffering->subject?->college?->name ?? ''),
                'department_name' => $this->sanitizeString($slotOffering->subject?->department?->name ?? ''),
                'academic_year_name' => $this->sanitizeString($slotOffering->academicYear?->name ?? ''),
                'semester_name' => $this->sanitizeString($slotOffering->semester?->name ?? ''),
                'is_drawing_subject' => (bool) ($slotOffering->subject?->is_drawing_subject ?? false),
                'students_count' => $totalCount,
                'assigned_students_count' => $assignedCount,
                'unassigned_students_count' => $unassignedCount,
                'distribution_percentage' => $offeringDistributionPercentage,
                'status_key' => $statusKey,
                'status_label' => match ($statusKey) {
                    'empty' => __('exam.distribution_statuses.no_students'),
                    'complete' => __('exam.distribution_statuses.distributed'),
                    'issue' => __('exam.distribution_statuses.has_issues'),
                    default => __('exam.distribution_statuses.not_run'),
                },
                'status_tone' => match ($statusKey) {
                    'complete' => 'success',
                    'issue' => 'danger',
                    default => 'gray',
                },
            ];
        })->values();

        $hallAssignmentsByHallId = $hallAssignments
            ->filter(fn (HallAssignment $assignment): bool => filled($assignment->examHall?->getKey()))
            ->keyBy(fn (HallAssignment $assignment): int|string => $assignment->examHall->getKey());

        $availableHallsSummary = $availableHalls
            ->map(fn (ExamHall $hall): array => $this->toAvailableHallSummary(
                hall: $hall,
                assignment: $hallAssignmentsByHallId->get($hall->getKey()),
                collegeName: $slotOfferings->first()?->subject?->college?->name ?? $offering->subject?->college?->name ?? '',
            ))
            ->values();

        $allStudents = $this->getSlotStudents($slotOfferings);
        $assignedStudentIds = $hallAssignments
            ->flatMap(fn (HallAssignment $assignment) => $assignment->studentAssignments->pluck('exam_student_id'))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $assignedStudentLookup = array_fill_keys($assignedStudentIds->all(), true);

        $unassignedStudents = $allStudents
            ->filter(fn (ExamStudent $student): bool => ! isset($assignedStudentLookup[$student->getKey()]))
            ->values();

        $unassignedStudentsSummary = $this->buildUnassignedStudentsSummary(
            students: $unassignedStudents,
            availableHallsCount: $availableHallsCount,
            capacityShortage: $capacityShortage,
            remainingCapacity: $remainingCapacity,
            hasDistribution: $hasDistribution,
        );
        $unassignedSummaryBySubject = $unassignedStudentsSummary
            ->groupBy('subject_name')
            ->map(fn (Collection $rows, string $subjectName): array => [
                'subject_name' => $subjectName,
                'students_count' => $rows->count(),
            ])
            ->sortByDesc('students_count')
            ->values();

        $diagnosis = $this->buildDiagnosisSummary(
            totalStudents: $totalStudents,
            availableHallsCount: $availableHallsCount,
            totalCapacity: $totalCapacity,
            remainingCapacity: $remainingCapacity,
            usedCapacity: $usedCapacity,
            unassignedStudentsCount: $unassignedStudentsCount,
            capacityShortage: $capacityShortage,
            hasDistribution: $hasDistribution,
            distributionStatus: $distributionStatus,
        );

        $summary = [
            'context' => [
                'college_name' => $this->sanitizeString($slotOfferings->first()?->subject?->college?->name ?? $offering->subject?->college?->name ?? ''),
                'offerings_count' => $slotOfferings->count(),
            ],
            'exam_date' => $slot['exam_date'],
            'exam_start_time' => $slot['exam_start_time'],
            'college_id' => $slot['college_id'],
            'offerings_summary' => $offeringsWithSummary,
            'available_halls' => $availableHalls
                ->map(fn (ExamHall $hall): array => [
                    'hall_id' => $hall->getKey(),
                    'name' => $this->sanitizeString($hall->name),
                    'location' => $this->sanitizeString($hall->location),
                    'capacity' => (int) $hall->capacity,
                    'priority' => $hall->priority?->value,
                    'priority_label' => $this->sanitizeString($hall->priority?->label() ?? ''),
                    'is_drawing_studio' => (bool) $hall->is_drawing_studio,
                    'is_active' => (bool) $hall->is_active,
                    'status_label' => $hall->is_active
                        ? __('exam.statuses.active')
                        : __('exam.statuses.inactive'),
                ])
                ->values()
                ->all(),
            'available_halls_summary' => $availableHallsSummary->all(),
            'hall_assignments' => $hallAssignments
                ->map(fn (HallAssignment $assignment): array => $this->toHallAssignmentSummary($assignment))
                ->values()
                ->all(),
            'distribution_results_summaries' => $hallAssignments
                ->map(function (HallAssignment $assignment): array {
                    $summary = $this->toHallAssignmentSummary($assignment);
                    unset($summary['students']);

                    return $summary;
                })
                ->values()
                ->all(),
            'summary_cards' => [
                [
                    'label' => 'إجمالي الطلاب',
                    'value' => $totalStudents,
                    'tone' => 'gray',
                    'icon' => 'heroicon-o-users',
                ],
                [
                    'label' => 'الطلاب الموزعون',
                    'value' => $assignedStudents,
                    'tone' => $assignedStudents > 0 ? 'success' : 'gray',
                    'icon' => 'heroicon-o-check-circle',
                ],
                [
                    'label' => 'الطلاب غير الموزعين',
                    'value' => $unassignedStudentsCount,
                    'tone' => $unassignedStudentsCount > 0 ? 'danger' : 'success',
                    'icon' => 'heroicon-o-user-minus',
                ],
                [
                    'label' => 'عدد المواد',
                    'value' => $slotOfferings->count(),
                    'tone' => 'info',
                    'icon' => 'heroicon-o-rectangle-stack',
                ],
                [
                    'label' => 'عدد القاعات المتاحة',
                    'value' => $availableHallsCount,
                    'tone' => $availableHallsCount > 0 ? 'info' : 'danger',
                    'icon' => 'heroicon-o-building-office-2',
                ],
                [
                    'label' => 'عدد القاعات المستخدمة',
                    'value' => $usedHallsCount,
                    'tone' => $usedHallsCount > 0 ? 'warning' : 'gray',
                    'icon' => 'heroicon-o-home-modern',
                ],
                [
                    'label' => 'السعة الإجمالية',
                    'value' => $totalCapacity,
                    'tone' => 'info',
                    'icon' => 'heroicon-o-chart-bar-square',
                ],
                [
                    'label' => 'المقاعد المستخدمة',
                    'value' => $usedCapacity,
                    'tone' => $usedCapacity > 0 ? 'primary' : 'gray',
                    'icon' => 'heroicon-o-chart-pie',
                ],
                [
                    'label' => 'المقاعد المتبقية',
                    'value' => $remainingCapacity,
                    'tone' => $remainingCapacity > 0 ? 'success' : 'gray',
                    'icon' => 'heroicon-o-arrow-path-rounded-square',
                ],
                [
                    'label' => 'العجز في المقاعد',
                    'value' => $capacityShortage,
                    'tone' => $capacityShortage > 0 ? 'danger' : 'gray',
                    'icon' => 'heroicon-o-no-symbol',
                ],
                [
                    'label' => 'نسبة التوزيع',
                    'value' => $distributionPercentage.'%',
                    'tone' => $distributionPercentage === 100 ? 'success' : ($distributionPercentage > 0 ? 'warning' : 'gray'),
                    'icon' => 'heroicon-o-presentation-chart-line',
                ],
            ],
            'subject_summaries' => $offeringsWithSummary->all(),
            'hall_summaries' => $availableHallsSummary->all(),
            'unassigned_summary_by_subject' => $unassignedSummaryBySubject->all(),
            'total_students_count' => $totalStudents,
            'assigned_students_count' => $assignedStudents,
            'unassigned_students_count' => $unassignedStudentsCount,
            'used_halls_count' => $usedHallsCount,
            'available_halls_count' => $availableHallsCount,
            'available_capacity' => $totalCapacity,
            'total_capacity' => $totalCapacity,
            'used_capacity' => $usedCapacity,
            'remaining_capacity' => $remainingCapacity,
            'capacity_shortage' => $capacityShortage,
            'distribution_percentage' => $distributionPercentage,
            'distribution_status' => $distributionStatus,
            'diagnosis' => $diagnosis,
            'recommended_actions' => $diagnosis['recommended_actions'] ?? [],
            'unassigned_students' => $unassignedStudentsSummary->all(),
            'show_unassigned_students_section' => ($availableHallsCount === 0 && $totalStudents > 0)
                || ($hasDistribution && $unassignedStudentsCount > 0),
            'has_distribution' => $hasDistribution,
            'total_students' => $totalStudents,
            'distributed_students' => $assignedStudents,
            'unassigned_students_count_value' => $unassignedStudentsCount,
            'unassigned_students_total' => $unassignedStudentsCount,
            'total_available_halls' => $availableHallsCount,
            'used_halls' => $usedHallsCount,
            'subjects' => $offeringsWithSummary->all(),
            'subjects_summary' => $offeringsWithSummary->all(),
            'halls' => $availableHallsSummary->all(),
            'halls_summary' => $availableHallsSummary->all(),
            'assignments_by_hall' => $hallAssignments
                ->map(fn (HallAssignment $assignment): array => $this->toHallAssignmentSummary($assignment))
                ->values()
                ->all(),
            'metrics' => [
                'totalStudents' => $totalStudents,
                'distributedStudents' => $assignedStudents,
                'unassignedStudents' => $unassignedStudentsCount,
                'totalAvailableHalls' => $availableHallsCount,
                'usedHalls' => $usedHallsCount,
                'totalCapacity' => $totalCapacity,
                'usedCapacity' => $usedCapacity,
                'remainingCapacity' => $remainingCapacity,
                'capacityShortage' => $capacityShortage,
                'distributionPercentage' => $distributionPercentage,
            ],
            'per_subject_assigned_count' => $offeringsWithSummary
                ->mapWithKeys(fn (array $offeringSummary): array => [
                    $offeringSummary['offering_id'] => $offeringSummary['assigned_students_count'],
                ])
                ->all(),
            'per_subject_unassigned_count' => $offeringsWithSummary
                ->mapWithKeys(fn (array $offeringSummary): array => [
                    $offeringSummary['offering_id'] => $offeringSummary['unassigned_students_count'],
                ])
                ->all(),
            'per_hall_used_seats' => $availableHallsSummary
                ->mapWithKeys(fn (array $hallSummary): array => [$hallSummary['hall_id'] => $hallSummary['used_seats']])
                ->all(),
            'per_hall_remaining_seats' => $availableHallsSummary
                ->mapWithKeys(fn (array $hallSummary): array => [$hallSummary['hall_id'] => $hallSummary['remaining_seats']])
                ->all(),
        ];

        $this->logInvalidUtf8InSummary($summary, context: [
            'offering_id' => $offering->getKey(),
            'exam_date' => $slot['exam_date'],
            'exam_start_time' => $slot['exam_start_time'],
            'college_id' => $slot['college_id'],
        ]);

        return $summary;
    }

    public function getCurrentHallAssignments(SubjectExamOffering $offering): Collection
    {
        $slot = $this->getSlotContext($offering);

        return HallAssignment::query()
            ->where('college_id', $slot['college_id'])
            ->whereDate('exam_date', $slot['exam_date'])
            ->whereTime('exam_start_time', $slot['exam_start_time'])
            ->with([
                'examHall',
                'assignmentSubjects.subjectExamOffering.subject',
                'studentAssignments.subjectExamOffering.subject',
                'studentAssignments.examStudent.subjectExamOffering.subject',
            ])
            ->get()
            ->sort(function (HallAssignment $first, HallAssignment $second): int {
                $priorityComparison = $this->priorityRank($first->examHall?->priority?->value)
                    <=> $this->priorityRank($second->examHall?->priority?->value);

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                $capacityComparison = ($second->examHall?->capacity ?? 0) <=> ($first->examHall?->capacity ?? 0);

                if ($capacityComparison !== 0) {
                    return $capacityComparison;
                }

                return strcmp($first->examHall?->name ?? '', $second->examHall?->name ?? '');
            })
            ->map(fn (HallAssignment $assignment): HallAssignment => $this->sanitizeHallAssignment($assignment))
            ->values();
    }

    public function getSlotContext(SubjectExamOffering $offering): array
    {
        $offering->loadMissing('subject.college');

        $collegeId = (int) $offering->subject->college_id;
        $examDate = $offering->exam_date?->toDateString();
        $examStartTime = $this->normalizeExamStartTime($offering->exam_start_time);

        $slotOfferings = SubjectExamOffering::query()
            ->with(['subject.college', 'subject.department', 'academicYear', 'semester'])
            ->withCount('examStudents')
            ->whereDate('exam_date', $examDate)
            ->whereTime('exam_start_time', $examStartTime)
            ->whereHas('subject', fn ($query) => $query->where('college_id', $collegeId))
            ->get()
            ->sort(function (SubjectExamOffering $first, SubjectExamOffering $second): int {
                $studentsComparison = ($second->exam_students_count ?? 0) <=> ($first->exam_students_count ?? 0);

                if ($studentsComparison !== 0) {
                    return $studentsComparison;
                }

                return strcmp($first->subject?->name ?? '', $second->subject?->name ?? '');
            })
            ->map(fn (SubjectExamOffering $slotOffering): SubjectExamOffering => $this->sanitizeSubjectExamOffering($slotOffering))
            ->values();

        $availableHalls = ExamHall::query()
            ->where('college_id', $collegeId)
            ->where('is_active', true)
            ->get()
            ->sort(function (ExamHall $first, ExamHall $second): int {
                $drawingStudioComparison = ((int) $this->isDrawingStudio($first)) <=> ((int) $this->isDrawingStudio($second));

                if ($drawingStudioComparison !== 0) {
                    return $drawingStudioComparison;
                }

                $priorityComparison = $this->priorityRank($first->priority?->value)
                    <=> $this->priorityRank($second->priority?->value);

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                $capacityComparison = $second->capacity <=> $first->capacity;

                if ($capacityComparison !== 0) {
                    return $capacityComparison;
                }

                return strcmp($first->name, $second->name);
            })
            ->map(fn (ExamHall $hall): ExamHall => $this->sanitizeExamHall($hall))
            ->values();

        return [
            'college_id' => $collegeId,
            'exam_date' => $examDate,
            'exam_start_time' => $examStartTime,
            'offerings' => $slotOfferings,
            'halls' => $availableHalls,
        ];
    }

    protected function clearSlotDistribution(int $collegeId, string $examDate, string $examStartTime): void
    {
        HallAssignment::query()
            ->where('college_id', $collegeId)
            ->whereDate('exam_date', $examDate)
            ->whereTime('exam_start_time', $examStartTime)
            ->delete();
    }

    protected function slotHasDistribution(int $collegeId, string $examDate, string $examStartTime): bool
    {
        return HallAssignment::query()
            ->where('college_id', $collegeId)
            ->whereDate('exam_date', $examDate)
            ->whereTime('exam_start_time', $examStartTime)
            ->exists();
    }

    protected function slotDistributionStats(
        Collection $slotOfferings,
        int $collegeId,
        string $examDate,
        string $examStartTime,
        int $slotCapacity,
        int $slotCapacityShortage,
        bool $separateCarryStudents = false,
    ): array {
        $assignmentCounts = ExamStudentHallAssignment::query()
            ->whereIn('subject_exam_offering_id', $slotOfferings->pluck('id'))
            ->selectRaw('subject_exam_offering_id, count(*) as assigned_count')
            ->groupBy('subject_exam_offering_id')
            ->pluck('assigned_count', 'subject_exam_offering_id');
        $usedHalls = HallAssignment::query()
            ->where('college_id', $collegeId)
            ->whereDate('exam_date', $examDate)
            ->whereTime('exam_start_time', $examStartTime)
            ->where('assigned_students_count', '>', 0)
            ->count();
        $hallTypeStats = $this->slotHallStudentTypeStats($collegeId, $examDate, $examStartTime);
        $studentTypeTotals = $this->slotStudentTypeTotals($slotOfferings);
        $distributedStudents = (int) $assignmentCounts->sum();
        $totalStudents = (int) $slotOfferings->sum('exam_students_count');
        $unassignedStudents = max(0, $totalStudents - $distributedStudents);
        $hasSlotProblem = $unassignedStudents > 0 || $slotCapacityShortage > 0;
        $reason = $hasSlotProblem ? $this->globalDistributionIssueReason($slotCapacityShortage, $usedHalls) : null;
        $issues = [];
        $unassignedBySubject = [];

        foreach ($slotOfferings as $offering) {
            $assigned = (int) ($assignmentCounts[$offering->id] ?? 0);
            $unassigned = max(0, (int) $offering->exam_students_count - $assigned);

            if ($unassigned === 0) {
                continue;
            }

            $isDrawingSubject = $this->isDrawingSubjectOffering($offering);
            $issue = [
                'exam_date' => $examDate,
                'start_time' => $examStartTime,
                'subject_exam_offering_id' => $offering->id,
                'subject_name' => $offering->subject?->name,
                'unassigned_count' => $unassigned,
                'reason' => $isDrawingSubject
                    ? __('exam.notifications.drawing_studio_capacity_shortage')
                    : ($reason ?? __('exam.global_hall_distribution.issue_reasons.unassigned_students')),
                'issue_type' => $isDrawingSubject
                    ? 'drawing_studio_capacity_shortage'
                    : ($slotCapacityShortage > 0 ? 'capacity_shortage' : 'unassigned_students'),
            ];

            $issues[] = $issue;
            $unassignedBySubject[] = $issue;
        }

        if ($separateCarryStudents && $hallTypeStats['mixed_halls_count'] > 0) {
            $issues[] = [
                'exam_date' => $examDate,
                'start_time' => $examStartTime,
                'subject_exam_offering_id' => null,
                'subject_name' => null,
                'affected_students_count' => $hallTypeStats['mixed_students_count'],
                'reason' => __('exam.global_hall_distribution.carry_regular_mixed_issue'),
                'issue_type' => 'carry_regular_mixed_due_to_capacity',
            ];
        }

        $slotReason = $reason ?? ($separateCarryStudents && $hallTypeStats['mixed_halls_count'] > 0
            ? __('exam.global_hall_distribution.carry_regular_mixed_issue')
            : null);

        return [
            'distributed_students' => $distributedStudents,
            'unassigned_students' => $unassignedStudents,
            'used_halls' => $usedHalls,
            'issues' => $issues,
            'unassigned_by_subject' => $unassignedBySubject,
            ...$hallTypeStats,
            'regular_students_count' => $studentTypeTotals[ExamStudentType::Regular->value],
            'carry_students_count' => $studentTypeTotals[ExamStudentType::Carry->value],
            'slot_issue' => [
                'exam_date' => $examDate,
                'start_time' => $examStartTime,
                'unassigned_count' => $unassignedStudents,
                'reason' => $slotReason,
                'capacity_shortage' => $slotCapacityShortage,
                'total_capacity' => $slotCapacity,
                'used_halls' => $usedHalls,
                'mixed_halls_count' => $hallTypeStats['mixed_halls_count'],
                'mixed_students_count' => $hallTypeStats['mixed_students_count'],
            ],
        ];
    }

    protected function slotHallStudentTypeStats(int $collegeId, string $examDate, string $examStartTime): array
    {
        $assignments = HallAssignment::query()
            ->where('college_id', $collegeId)
            ->whereDate('exam_date', $examDate)
            ->whereTime('exam_start_time', $examStartTime)
            ->with(['studentAssignments.examStudent'])
            ->get();

        $regularStudentsCount = 0;
        $carryStudentsCount = 0;
        $regularHallsCount = 0;
        $carryHallsCount = 0;
        $mixedHallsCount = 0;
        $mixedStudentsCount = 0;

        foreach ($assignments as $assignment) {
            $typeCounts = $this->studentTypeCountsForAssignment($assignment);
            $regularStudentsCount += $typeCounts[ExamStudentType::Regular->value];
            $carryStudentsCount += $typeCounts[ExamStudentType::Carry->value];
            $classification = $this->hallStudentTypeClassification($typeCounts);

            if ($classification['key'] === 'regular_only') {
                $regularHallsCount++;
            } elseif ($classification['key'] === 'carry_only') {
                $carryHallsCount++;
            } elseif ($classification['key'] === 'mixed') {
                $mixedHallsCount++;
                $mixedStudentsCount += $typeCounts[ExamStudentType::Regular->value] + $typeCounts[ExamStudentType::Carry->value];
            }
        }

        return [
            'assigned_regular_students_count' => $regularStudentsCount,
            'assigned_carry_students_count' => $carryStudentsCount,
            'regular_halls_count' => $regularHallsCount,
            'carry_halls_count' => $carryHallsCount,
            'mixed_halls_count' => $mixedHallsCount,
            'mixed_students_count' => $mixedStudentsCount,
        ];
    }

    protected function slotStudentTypeTotals(Collection $slotOfferings): array
    {
        $counts = ExamStudent::query()
            ->whereIn('subject_exam_offering_id', $slotOfferings->pluck('id'))
            ->selectRaw('student_type, count(*) as students_count')
            ->groupBy('student_type')
            ->pluck('students_count', 'student_type');

        return [
            ExamStudentType::Regular->value => (int) ($counts[ExamStudentType::Regular->value] ?? 0),
            ExamStudentType::Carry->value => (int) ($counts[ExamStudentType::Carry->value] ?? 0),
        ];
    }

    protected function addSeparationStatsToGlobalSummary(array &$summary, array $slotStats): void
    {
        foreach ([
            'regular_students_count',
            'carry_students_count',
            'regular_halls_count',
            'carry_halls_count',
            'mixed_halls_count',
        ] as $key) {
            $summary[$key] += (int) ($slotStats[$key] ?? 0);
        }

        if ((bool) ($summary['separate_carry_students'] ?? false)) {
            $summary['carry_regular_mixing_cases_count'] += (int) ($slotStats['mixed_halls_count'] ?? 0);
        }
    }

    protected function carrySeparationStatusMessage(array $summary): ?string
    {
        if (! (bool) ($summary['separate_carry_students'] ?? false)) {
            return null;
        }

        if ((int) ($summary['carry_students_count'] ?? 0) === 0) {
            return __('exam.global_hall_distribution.no_carry_students');
        }

        if ((int) ($summary['carry_regular_mixing_cases_count'] ?? 0) > 0) {
            return __('exam.global_hall_distribution.carry_regular_mixed_warning');
        }

        return __('exam.global_hall_distribution.carry_regular_separated_success');
    }

    protected function globalDistributionIssueReason(int $capacityShortage, int $usedHalls): string
    {
        if ($usedHalls === 0) {
            return __('exam.global_hall_distribution.issue_reasons.no_available_halls');
        }

        if ($capacityShortage > 0) {
            return __('exam.global_hall_distribution.issue_reasons.capacity_shortage');
        }

        return __('exam.global_hall_distribution.issue_reasons.unassigned_students');
    }

    protected function globalDistributionFailure(
        int $collegeId,
        string $fromDate,
        string $toDate,
        string $reason,
        string $issueType,
        int $totalOfferings = 0,
        int $totalSlots = 0,
        int $totalStudents = 0,
        int $capacityShortage = 0,
        array $issues = [],
        array $unassignedBySubject = [],
        array $unassignedBySlot = [],
        array $settings = [],
    ): array {
        $separateCarryStudents = (bool) ($settings['separate_carry_students'] ?? false);

        return $this->withLegacyGlobalDistributionKeys([
            'status' => 'failed',
            'faculty_id' => $collegeId,
            'college_id' => $collegeId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'message' => __('exam.notifications.global_hall_distribution_failed'),
            'reason' => $reason,
            'total_offerings' => $totalOfferings,
            'total_slots' => $totalSlots,
            'total_students' => $totalStudents,
            'distributed_students' => 0,
            'unassigned_students' => $totalStudents,
            'used_halls' => 0,
            'total_capacity' => 0,
            'capacity_shortage' => $capacityShortage,
            'distributed_slots_count' => 0,
            'skipped_slots_count' => 0,
            'issue_slots_count' => count($unassignedBySlot),
            'slots' => [],
            'issues' => $issues !== [] ? $issues : ($totalStudents > 0 ? [[
                'exam_date' => null,
                'start_time' => null,
                'subject_exam_offering_id' => null,
                'subject_name' => null,
                'unassigned_count' => $totalStudents,
                'reason' => $reason,
                'issue_type' => $issueType,
            ]] : []),
            'unassigned_by_subject' => $unassignedBySubject,
            'unassigned_by_slot' => $unassignedBySlot,
            'settings' => $settings,
            'separate_carry_students' => $separateCarryStudents,
            'regular_students_count' => 0,
            'carry_students_count' => 0,
            'regular_halls_count' => 0,
            'carry_halls_count' => 0,
            'mixed_halls_count' => 0,
            'carry_regular_mixing_cases_count' => 0,
            'separation_status_message' => $separateCarryStudents
                ? __('exam.global_hall_distribution.no_carry_students')
                : null,
        ]);
    }

    protected function globalFailureIssueSummaries(Collection $slots, string $reason, string $issueType): array
    {
        $issues = [];
        $unassignedBySubject = [];
        $unassignedBySlot = [];

        foreach ($slots as $slotOfferings) {
            /** @var SubjectExamOffering|null $firstOffering */
            $firstOffering = $slotOfferings->first();

            if (! $firstOffering) {
                continue;
            }

            $examDate = $firstOffering->exam_date->format('Y-m-d');
            $startTime = $this->normalizeExamStartTime($firstOffering->exam_start_time);
            $unassignedCount = (int) $slotOfferings->sum('exam_students_count');

            if ($unassignedCount > 0) {
                $unassignedBySlot[] = [
                    'exam_date' => $examDate,
                    'start_time' => $startTime,
                    'unassigned_count' => $unassignedCount,
                    'reason' => $reason,
                    'capacity_shortage' => $unassignedCount,
                    'total_capacity' => 0,
                    'used_halls' => 0,
                ];
            }

            foreach ($slotOfferings as $offering) {
                $subjectUnassignedCount = (int) $offering->exam_students_count;

                if ($subjectUnassignedCount === 0) {
                    continue;
                }

                $issue = [
                    'exam_date' => $examDate,
                    'start_time' => $startTime,
                    'subject_exam_offering_id' => $offering->id,
                    'subject_name' => $offering->subject?->name,
                    'unassigned_count' => $subjectUnassignedCount,
                    'reason' => $reason,
                    'issue_type' => $issueType,
                ];

                $issues[] = $issue;
                $unassignedBySubject[] = $issue;
            }
        }

        return [
            'issues' => $issues,
            'unassigned_by_subject' => $unassignedBySubject,
            'unassigned_by_slot' => $unassignedBySlot,
        ];
    }

    protected function withLegacyGlobalDistributionKeys(array $summary): array
    {
        return [
            ...$summary,
            'offerings_count' => $summary['total_offerings'] ?? 0,
            'slots_count' => $summary['total_slots'] ?? 0,
            'students_count' => $summary['total_students'] ?? 0,
            'assigned_students_count' => $summary['distributed_students'] ?? 0,
            'unassigned_students_count' => $summary['unassigned_students'] ?? 0,
            'used_halls_count' => $summary['used_halls'] ?? 0,
        ];
    }

    protected function persistGlobalDistributionResult(array $summary): array
    {
        $summary = $this->filterGlobalDistributionProblemSummaries($summary);
        $summary = $this->applyFinalValidationToGlobalSummary($summary);

        $run = StudentDistributionRun::query()->create([
            'college_id' => $summary['college_id'],
            'from_date' => $summary['from_date'],
            'to_date' => $summary['to_date'],
            'status' => $summary['status'],
            'total_offerings' => $summary['total_offerings'],
            'total_slots' => $summary['total_slots'],
            'total_students' => $summary['total_students'],
            'distributed_students' => $summary['distributed_students'],
            'unassigned_students' => $summary['unassigned_students'],
            'total_capacity' => $summary['total_capacity'],
            'used_halls' => $summary['used_halls'],
            'capacity_shortage' => $summary['capacity_shortage'],
            'executed_by' => auth()->id(),
            'executed_at' => now(),
            'summary_json' => $summary,
            'notes' => $summary['reason'] ?? null,
        ]);

        foreach ($summary['issues'] ?? [] as $issue) {
            $affectedStudentsCount = (int) ($issue['unassigned_count'] ?? $issue['affected_students_count'] ?? 0);

            if ($affectedStudentsCount <= 0) {
                continue;
            }

            StudentDistributionRunIssue::query()->create([
                'student_distribution_run_id' => $run->id,
                'exam_date' => $issue['exam_date'] ?? null,
                'start_time' => $issue['start_time'] ?? null,
                'subject_exam_offering_id' => $issue['subject_exam_offering_id'] ?? null,
                'issue_type' => $issue['issue_type'] ?? 'unknown',
                'message' => $issue['reason'] ?? ($summary['reason'] ?? $summary['message']),
                'affected_students_count' => $affectedStudentsCount,
                'payload_json' => $issue,
            ]);
        }

        $run->load('issues');
        $summary['validation']['unassigned_students_list'] = $this->buildUnassignedStudentsSnapshot($run);

        $summary['run_id'] = $run->id;
        $summary['result_url'] = route('filament.adminpanel.resources.subject-exam-offerings.global-distribution-results', ['run' => $run]);

        $run->update(['summary_json' => $summary]);

        return $summary;
    }

    protected function filterGlobalDistributionProblemSummaries(array $summary): array
    {
        $summary['issues'] = collect($summary['issues'] ?? [])
            ->filter(fn (array $issue): bool => (int) ($issue['unassigned_count'] ?? $issue['affected_students_count'] ?? 0) > 0)
            ->values()
            ->all();

        $summary['unassigned_by_subject'] = collect($summary['unassigned_by_subject'] ?? [])
            ->filter(fn (array $subject): bool => (int) ($subject['unassigned_count'] ?? 0) > 0)
            ->values()
            ->all();

        $summary['unassigned_by_slot'] = collect($summary['unassigned_by_slot'] ?? [])
            ->filter(fn (array $slot): bool => (int) ($slot['unassigned_count'] ?? 0) > 0
                || (int) ($slot['capacity_shortage'] ?? $slot['shortage_count'] ?? 0) > 0
                || (int) ($slot['mixed_halls_count'] ?? 0) > 0)
            ->values()
            ->all();

        $summary['issue_slots_count'] = count($summary['unassigned_by_slot']);

        return $summary;
    }

    public function unassignedStudentsForRun(StudentDistributionRun $run): array
    {
        $snapshot = $run->summary_json['validation']['unassigned_students_list'] ?? null;

        if (is_array($snapshot)) {
            return $snapshot;
        }

        if ((int) $run->unassigned_students === 0) {
            return [];
        }

        $issuesByOffering = $run->issues
            ->filter(fn (StudentDistributionRunIssue $issue): bool => filled($issue->subject_exam_offering_id))
            ->keyBy('subject_exam_offering_id');
        $issuesBySlot = $run->issues
            ->groupBy(fn (StudentDistributionRunIssue $issue): string => ($issue->exam_date?->format('Y-m-d') ?? '-').'|'.substr((string) $issue->start_time, 0, 8));

        return ExamStudent::query()
            ->with(['subjectExamOffering.subject'])
            ->whereDoesntHave('hallAssignment')
            ->whereHas('subjectExamOffering', fn ($query) => $query
                ->whereDate('exam_date', '>=', $run->from_date)
                ->whereDate('exam_date', '<=', $run->to_date)
                ->whereHas('subject', fn ($subjectQuery) => $subjectQuery->where('college_id', $run->college_id)))
            ->orderBy('student_number')
            ->get()
            ->map(function (ExamStudent $student) use ($issuesByOffering, $issuesBySlot): array {
                $offering = $student->subjectExamOffering;
                $slotKey = ($offering?->exam_date?->format('Y-m-d') ?? '-').'|'.substr((string) $offering?->exam_start_time, 0, 8);
                $issue = $issuesByOffering->get($offering?->id) ?? $issuesBySlot->get($slotKey)?->first();

                return [
                    'student_number' => $student->student_number,
                    'full_name' => $student->full_name,
                    'subject_name' => $offering?->subject?->name,
                    'exam_date' => $offering?->exam_date?->format('Y-m-d'),
                    'start_time' => substr((string) $offering?->exam_start_time, 0, 5),
                    'student_type' => (string) $student->getRawOriginal('student_type'),
                    'student_type_label' => $this->studentTypeLabel((string) $student->getRawOriginal('student_type')),
                    'reason' => $issue?->message ?? __('exam.global_hall_distribution.issue_reasons.unassigned_students'),
                ];
            })
            ->all();
    }

    protected function applyFinalValidationToGlobalSummary(array $summary): array
    {
        $validation = $this->buildGlobalDistributionValidationSummary(
            collegeId: (int) $summary['college_id'],
            fromDate: (string) $summary['from_date'],
            toDate: (string) $summary['to_date'],
            expectedStudents: (int) ($summary['total_students'] ?? 0),
        );

        $summary['distributed_students'] = $validation['assigned_students'];
        $summary['unassigned_students'] = $validation['unassigned_students'];
        $summary['used_halls'] = $validation['used_halls_count'];
        $summary['assigned_students_count'] = $validation['assigned_students'];
        $summary['unassigned_students_count'] = $validation['unassigned_students'];
        $summary['used_halls_count'] = $validation['used_halls_count'];
        $summary['validation'] = $validation;

        $hasValidationProblem = $validation['unassigned_students'] > 0;
        $hasMixingProblem = (int) ($summary['carry_regular_mixing_cases_count'] ?? 0) > 0;
        $hasCapacityProblem = (int) ($summary['capacity_shortage'] ?? 0) > 0;

        if (($summary['status'] ?? null) !== 'failed') {
            $summary['status'] = ($hasValidationProblem || $hasMixingProblem || $hasCapacityProblem) ? 'partial' : 'success';
            $summary['message'] = $summary['status'] === 'success'
                ? __('exam.notifications.global_hall_distribution_completed')
                : __('exam.notifications.global_hall_distribution_completed_with_issues');
        }

        return $this->withLegacyGlobalDistributionKeys($summary);
    }

    protected function buildGlobalDistributionValidationSummary(
        int $collegeId,
        string $fromDate,
        string $toDate,
        int $expectedStudents,
    ): array {
        $hallAssignments = HallAssignment::query()
            ->where('college_id', $collegeId)
            ->whereDate('exam_date', '>=', $fromDate)
            ->whereDate('exam_date', '<=', $toDate);

        $assignedStudents = ExamStudentHallAssignment::query()
            ->whereHas('subjectExamOffering', fn ($query) => $query
                ->whereDate('exam_date', '>=', $fromDate)
                ->whereDate('exam_date', '<=', $toDate)
                ->whereHas('subject', fn ($subjectQuery) => $subjectQuery->where('college_id', $collegeId)))
            ->distinct('exam_student_id')
            ->count('exam_student_id');

        $usedHallCapacity = (int) (clone $hallAssignments)->sum('total_capacity');
        $remainingCapacity = (int) (clone $hallAssignments)->sum('remaining_capacity');
        $usedHallsCount = (int) (clone $hallAssignments)->where('assigned_students_count', '>', 0)->count();
        $unassignedStudents = max(0, $expectedStudents - $assignedStudents);

        return [
            'expected_students' => $expectedStudents,
            'assigned_students' => $assignedStudents,
            'unassigned_students' => $unassignedStudents,
            'used_halls_count' => $usedHallsCount,
            'used_hall_capacity' => $usedHallCapacity,
            'remaining_capacity' => $remainingCapacity,
            'status' => $unassignedStudents > 0 ? 'partial' : 'success',
            'data_source' => 'exam_student_hall_assignments + hall_assignments',
        ];
    }

    protected function buildUnassignedStudentsSnapshot(StudentDistributionRun $run): array
    {
        $issuesByOffering = $run->issues
            ->filter(fn (StudentDistributionRunIssue $issue): bool => filled($issue->subject_exam_offering_id))
            ->keyBy('subject_exam_offering_id');
        $issuesBySlot = $run->issues
            ->groupBy(fn (StudentDistributionRunIssue $issue): string => ($issue->exam_date?->format('Y-m-d') ?? '-').'|'.substr((string) $issue->start_time, 0, 8));

        return ExamStudent::query()
            ->with(['subjectExamOffering.subject'])
            ->whereDoesntHave('hallAssignment')
            ->whereHas('subjectExamOffering', fn ($query) => $query
                ->whereDate('exam_date', '>=', $run->from_date)
                ->whereDate('exam_date', '<=', $run->to_date)
                ->whereHas('subject', fn ($subjectQuery) => $subjectQuery->where('college_id', $run->college_id)))
            ->orderBy('student_number')
            ->get()
            ->map(function (ExamStudent $student) use ($issuesByOffering, $issuesBySlot): array {
                $offering = $student->subjectExamOffering;
                $slotKey = ($offering?->exam_date?->format('Y-m-d') ?? '-').'|'.substr((string) $offering?->exam_start_time, 0, 8);
                $issue = $issuesByOffering->get($offering?->id) ?? $issuesBySlot->get($slotKey)?->first();

                return [
                    'student_number' => $student->student_number,
                    'full_name' => $student->full_name,
                    'subject_name' => $offering?->subject?->name,
                    'subject_exam_offering_id' => $offering?->id,
                    'exam_date' => $offering?->exam_date?->format('Y-m-d'),
                    'start_time' => substr((string) $offering?->exam_start_time, 0, 5),
                    'student_type' => (string) $student->getRawOriginal('student_type'),
                    'student_type_label' => $this->studentTypeLabel((string) $student->getRawOriginal('student_type')),
                    'reason' => $issue?->message ?? __('exam.global_hall_distribution.issue_reasons.unassigned_students'),
                ];
            })
            ->all();
    }

    protected function nextOfferingToAssign(
        Collection $slotOfferings,
        array $remainingCounts,
        array $usedOfferingIds,
        ExamHall $hall,
        array $subjectCounts,
        int $maxSubjectsPerHall,
        bool $slotHasDrawingSubjects,
        bool $allowNormalSubjectsInDrawingStudios,
    ): ?SubjectExamOffering {
        return $slotOfferings
            ->first(function (SubjectExamOffering $slotOffering) use ($remainingCounts, $usedOfferingIds, $hall, $subjectCounts, $maxSubjectsPerHall, $slotHasDrawingSubjects, $slotOfferings, $allowNormalSubjectsInDrawingStudios): bool {
                if (in_array($slotOffering->getKey(), $usedOfferingIds, true)) {
                    return false;
                }

                if (($remainingCounts[$slotOffering->getKey()] ?? 0) <= 0) {
                    return false;
                }

                return $this->canAddOfferingToHall(
                    offering: $slotOffering,
                    hall: $hall,
                    subjectCounts: $subjectCounts,
                    slotOfferings: $slotOfferings,
                    maxSubjectsPerHall: $maxSubjectsPerHall,
                    slotHasDrawingSubjects: $slotHasDrawingSubjects,
                    allowNormalSubjectsInDrawingStudios: $allowNormalSubjectsInDrawingStudios,
                );
            });
    }

    protected function maxSubjectsPerHall(bool $allowMultipleSubjectsPerHall): int
    {
        return $allowMultipleSubjectsPerHall ? 3 : 1;
    }

    protected function slotCapacityProfile(Collection $slotOfferings, Collection $availableHalls): array
    {
        $drawingStudents = (int) $slotOfferings
            ->filter(fn (SubjectExamOffering $offering): bool => $this->isDrawingSubjectOffering($offering))
            ->sum('exam_students_count');
        $nonDrawingStudents = (int) $slotOfferings
            ->reject(fn (SubjectExamOffering $offering): bool => $this->isDrawingSubjectOffering($offering))
            ->sum('exam_students_count');
        $drawingStudioCapacity = (int) $availableHalls
            ->filter(fn (ExamHall $hall): bool => $this->isDrawingStudio($hall))
            ->sum('capacity');
        $nonDrawingHallCapacity = (int) $availableHalls
            ->reject(fn (ExamHall $hall): bool => $this->isDrawingStudio($hall))
            ->sum('capacity');
        $allCapacity = (int) $availableHalls->sum('capacity');

        $allowNormalSubjectsInDrawingStudios = $this->allowNormalSubjectsInDrawingStudios();

        if ($drawingStudents === 0) {
            $usableCapacity = $allowNormalSubjectsInDrawingStudios ? $allCapacity : $nonDrawingHallCapacity;

            return [
                'usable_capacity' => $usableCapacity,
                'capacity_shortage' => max(0, $nonDrawingStudents - $usableCapacity),
                'drawing_students_count' => 0,
                'drawing_studio_capacity' => $drawingStudioCapacity,
                'non_drawing_students_count' => $nonDrawingStudents,
                'non_drawing_hall_capacity' => $usableCapacity,
            ];
        }

        $nonDrawingCapacity = $nonDrawingStudents > 0 ? $nonDrawingHallCapacity : 0;

        return [
            'usable_capacity' => $drawingStudioCapacity + $nonDrawingCapacity,
            'capacity_shortage' => max(0, $drawingStudents - $drawingStudioCapacity)
                + max(0, $nonDrawingStudents - $nonDrawingCapacity),
            'drawing_students_count' => $drawingStudents,
            'drawing_studio_capacity' => $drawingStudioCapacity,
            'non_drawing_students_count' => $nonDrawingStudents,
            'non_drawing_hall_capacity' => $nonDrawingCapacity,
        ];
    }

    protected function slotHasDrawingSubjects(Collection $slotOfferings): bool
    {
        return $slotOfferings->contains(fn (SubjectExamOffering $offering): bool => $this->isDrawingSubjectOffering($offering));
    }

    protected function canAddOfferingToHall(
        SubjectExamOffering $offering,
        ExamHall $hall,
        array $subjectCounts,
        Collection $slotOfferings,
        int $maxSubjectsPerHall,
        bool $slotHasDrawingSubjects,
        bool $allowNormalSubjectsInDrawingStudios,
    ): bool {
        $offeringId = $offering->getKey();

        if (! isset($subjectCounts[$offeringId]) && count($subjectCounts) >= $maxSubjectsPerHall) {
            return false;
        }

        $isDrawingSubject = $this->isDrawingSubjectOffering($offering);
        $isDrawingStudio = $this->isDrawingStudio($hall);

        if ($isDrawingSubject && ! $isDrawingStudio) {
            return false;
        }

        if (! $isDrawingSubject && $slotHasDrawingSubjects && $isDrawingStudio) {
            return false;
        }

        if (! $isDrawingSubject && $isDrawingStudio && ! $allowNormalSubjectsInDrawingStudios) {
            return false;
        }

        $existingOfferings = $slotOfferings
            ->filter(fn (SubjectExamOffering $slotOffering): bool => array_key_exists($slotOffering->getKey(), $subjectCounts));

        $hasDrawingSubject = $existingOfferings->contains(fn (SubjectExamOffering $slotOffering): bool => $this->isDrawingSubjectOffering($slotOffering));
        $hasNonDrawingSubject = $existingOfferings->contains(fn (SubjectExamOffering $slotOffering): bool => ! $this->isDrawingSubjectOffering($slotOffering));

        if ($isDrawingSubject && $hasNonDrawingSubject) {
            return false;
        }

        if (! $isDrawingSubject && $hasDrawingSubject) {
            return false;
        }

        return true;
    }

    protected function isDrawingSubjectOffering(SubjectExamOffering $offering): bool
    {
        return (bool) ($offering->subject?->is_drawing_subject ?? false);
    }

    protected function isDrawingStudio(ExamHall $hall): bool
    {
        return (bool) ($hall->is_drawing_studio ?? false);
    }

    protected function allowNormalSubjectsInDrawingStudios(): bool
    {
        return (bool) app(AppSettingsService::class)->get(
            AppSettingsService::ALLOW_NORMAL_SUBJECTS_IN_DRAWING_STUDIOS,
            false,
        );
    }

    protected function hasUnassignedDrawingStudents(Collection $slotOfferings, array $remainingCounts): bool
    {
        return $slotOfferings->contains(function (SubjectExamOffering $offering) use ($remainingCounts): bool {
            return $this->isDrawingSubjectOffering($offering)
                && (int) ($remainingCounts[$offering->getKey()] ?? 0) > 0;
        });
    }

    protected function combinedRemainingCounts(array $remainingCounts): array
    {
        $combined = [];

        foreach ($remainingCounts as $typeCounts) {
            foreach ($typeCounts as $offeringId => $count) {
                $combined[$offeringId] = ($combined[$offeringId] ?? 0) + (int) $count;
            }
        }

        return $combined;
    }

    protected function priorityRank(?string $priority): int
    {
        return match ($priority) {
            ExamHallPriority::High->value => 0,
            ExamHallPriority::Medium->value => 1,
            default => 2,
        };
    }

    protected function normalizeExamStartTime(mixed $value): string
    {
        return date('H:i:s', strtotime((string) $value));
    }

    protected function sanitizeHallAssignment(HallAssignment $assignment): HallAssignment
    {
        if ($assignment->relationLoaded('examHall') && $assignment->examHall) {
            $this->sanitizeExamHall($assignment->examHall);
        }

        if ($assignment->relationLoaded('assignmentSubjects')) {
            $assignment->assignmentSubjects->each(function (HallAssignmentSubject $assignmentSubject): void {
                if ($assignmentSubject->relationLoaded('subjectExamOffering') && $assignmentSubject->subjectExamOffering) {
                    $this->sanitizeSubjectExamOffering($assignmentSubject->subjectExamOffering);
                }
            });
        }

        if ($assignment->relationLoaded('studentAssignments')) {
            $assignment->studentAssignments->each(function (ExamStudentHallAssignment $studentAssignment): void {
                if ($studentAssignment->relationLoaded('examStudent') && $studentAssignment->examStudent) {
                    $studentAssignment->examStudent->student_number = $this->sanitizeString($studentAssignment->examStudent->student_number);
                    $studentAssignment->examStudent->full_name = $this->sanitizeString($studentAssignment->examStudent->full_name);
                    $studentAssignment->examStudent->notes = $this->sanitizeNullableString($studentAssignment->examStudent->notes);
                }

                if ($studentAssignment->relationLoaded('subjectExamOffering') && $studentAssignment->subjectExamOffering) {
                    $this->sanitizeSubjectExamOffering($studentAssignment->subjectExamOffering);
                }
            });
        }

        return $assignment;
    }

    protected function toHallAssignmentSummary(HallAssignment $assignment): array
    {
        $studentTypeCounts = $this->studentTypeCountsForAssignment($assignment);
        $hallStudentTypeClassification = $this->hallStudentTypeClassification($studentTypeCounts);
        $subjects = $assignment->assignmentSubjects
            ->map(fn (HallAssignmentSubject $assignmentSubject): array => [
                'subject_exam_offering_id' => $assignmentSubject->subject_exam_offering_id,
                'subject_name' => $this->sanitizeString($assignmentSubject->subjectExamOffering?->subject?->name ?? ''),
                'is_drawing_subject' => (bool) ($assignmentSubject->subjectExamOffering?->subject?->is_drawing_subject ?? false),
                'assigned_students_count' => (int) $assignmentSubject->assigned_students_count,
            ])
            ->values()
            ->all();

        $students = $assignment->studentAssignments
            ->sortBy(fn (ExamStudentHallAssignment $studentAssignment) => [
                $studentAssignment->examStudent?->student_number,
                $studentAssignment->examStudent?->full_name,
            ])
            ->values()
            ->map(fn (ExamStudentHallAssignment $studentAssignment): array => [
                'student_number' => $this->sanitizeString($studentAssignment->examStudent?->student_number ?? ''),
                'full_name' => $this->sanitizeString($studentAssignment->examStudent?->full_name ?? ''),
                'subject_name' => $this->sanitizeString($studentAssignment->subjectExamOffering?->subject?->name ?? ''),
                'student_type' => (string) $studentAssignment->examStudent?->getRawOriginal('student_type'),
                'student_type_label' => $this->studentTypeLabel((string) $studentAssignment->examStudent?->getRawOriginal('student_type')),
            ])
            ->all();

        return [
            'id' => $assignment->getKey(),
            'hall_id' => $assignment->examHall?->getKey(),
            'hall_name' => $this->sanitizeString($assignment->examHall?->name ?? ''),
            'hall_location' => $this->sanitizeString($assignment->examHall?->location ?? ''),
            'priority' => $assignment->examHall?->priority?->value,
            'priority_label' => $this->sanitizeString($assignment->examHall?->priority?->label() ?? ''),
            'is_drawing_studio' => (bool) ($assignment->examHall?->is_drawing_studio ?? false),
            'total_capacity' => (int) $assignment->total_capacity,
            'assigned_students_count' => (int) $assignment->assigned_students_count,
            'remaining_capacity' => (int) $assignment->remaining_capacity,
            'usage_percentage' => (int) ($assignment->total_capacity > 0
                ? round(($assignment->assigned_students_count / $assignment->total_capacity) * 100)
                : 0),
            'subjects_count' => count($subjects),
            'subjects' => $subjects,
            'students' => $students,
            'student_type_counts' => $studentTypeCounts,
            'regular_students_count' => $studentTypeCounts[ExamStudentType::Regular->value],
            'carry_students_count' => $studentTypeCounts[ExamStudentType::Carry->value],
            'hall_student_type_key' => $hallStudentTypeClassification['key'],
            'hall_student_type_label' => $hallStudentTypeClassification['label'],
            'status_key' => $assignment->remaining_capacity === 0 ? 'full' : 'available',
            'status_label' => $assignment->remaining_capacity === 0
                ? __('exam.distribution_statuses.full')
                : __('exam.distribution_statuses.available'),
        ];
    }

    protected function studentTypeCountsForAssignment(HallAssignment $assignment): array
    {
        $counts = [
            ExamStudentType::Regular->value => 0,
            ExamStudentType::Carry->value => 0,
        ];

        foreach ($assignment->studentAssignments as $studentAssignment) {
            $studentType = (string) $studentAssignment->examStudent?->getRawOriginal('student_type');

            if (array_key_exists($studentType, $counts)) {
                $counts[$studentType]++;
            }
        }

        return $counts;
    }

    protected function hallStudentTypeClassification(array $studentTypeCounts): array
    {
        $regularCount = (int) ($studentTypeCounts[ExamStudentType::Regular->value] ?? 0);
        $carryCount = (int) ($studentTypeCounts[ExamStudentType::Carry->value] ?? 0);

        $key = match (true) {
            $carryCount > 0 && $regularCount === 0 => 'carry_only',
            $regularCount > 0 && $carryCount === 0 => 'regular_only',
            $regularCount > 0 && $carryCount > 0 => 'mixed',
            default => 'empty',
        };

        return [
            'key' => $key,
            'label' => match ($key) {
                'carry_only' => __('exam.global_hall_distribution.hall_classifications.carry_only'),
                'regular_only' => __('exam.global_hall_distribution.hall_classifications.regular_only'),
                'mixed' => __('exam.global_hall_distribution.hall_classifications.mixed'),
                default => __('exam.distribution_statuses.unused'),
            },
        ];
    }

    protected function studentTypeLabel(string $studentType): string
    {
        return match ($studentType) {
            ExamStudentType::Regular->value => __('exam.student_types.regular'),
            ExamStudentType::Carry->value => __('exam.student_types.carry'),
            default => __('exam.student_types.unknown'),
        };
    }

    protected function sanitizeSubjectExamOffering(SubjectExamOffering $offering): SubjectExamOffering
    {
        $offering->notes = $this->sanitizeNullableString($offering->notes);

        if ($offering->relationLoaded('subject') && $offering->subject) {
            $offering->subject->name = $this->sanitizeString($offering->subject->name);
            $offering->subject->code = $this->sanitizeNullableString($offering->subject->code);

            if ($offering->subject->relationLoaded('college') && $offering->subject->college) {
                $offering->subject->college->name = $this->sanitizeString($offering->subject->college->name);
                $offering->subject->college->code = $this->sanitizeNullableString($offering->subject->college->code);
            }

            if ($offering->subject->relationLoaded('department') && $offering->subject->department) {
                $offering->subject->department->name = $this->sanitizeString($offering->subject->department->name);
                $offering->subject->department->code = $this->sanitizeNullableString($offering->subject->department->code);
            }
        }

        return $offering;
    }

    protected function sanitizeExamStudent(ExamStudent $student): ExamStudent
    {
        $student->student_number = $this->sanitizeString($student->student_number);
        $student->full_name = $this->sanitizeString($student->full_name);
        $student->notes = $this->sanitizeNullableString($student->notes);

        if ($student->relationLoaded('subjectExamOffering') && $student->subjectExamOffering) {
            $this->sanitizeSubjectExamOffering($student->subjectExamOffering);
        }

        return $student;
    }

    protected function sanitizeExamHall(ExamHall $hall): ExamHall
    {
        $hall->name = $this->sanitizeString($hall->name);
        $hall->location = $this->sanitizeString($hall->location);

        return $hall;
    }

    protected function sanitizeNullableString(?string $value): ?string
    {
        return $value === null ? null : $this->sanitizeString($value);
    }

    protected function sanitizeString(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($sanitized !== false && $sanitized !== '') {
            return $sanitized;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    protected function getSlotStudents(Collection $slotOfferings): Collection
    {
        if ($slotOfferings->isEmpty()) {
            return collect();
        }

        return ExamStudent::query()
            ->with(['subjectExamOffering.subject'])
            ->whereIn('subject_exam_offering_id', $slotOfferings->modelKeys())
            ->orderBy('student_number')
            ->orderBy('full_name')
            ->get()
            ->map(fn (ExamStudent $student): ExamStudent => $this->sanitizeExamStudent($student))
            ->values();
    }

    protected function resolveDistributionStatus(
        int $totalStudents,
        int $availableHallsCount,
        int $totalCapacity,
        bool $hasDistribution,
        int $unassignedStudentsCount,
    ): array {
        $key = match (true) {
            $totalStudents === 0 => 'no_students',
            $availableHallsCount === 0 || $totalCapacity < $totalStudents => 'shortage',
            ! $hasDistribution => 'not_run',
            $unassignedStudentsCount === 0 => 'success',
            default => 'partial',
        };

        return [
            'key' => $key,
            'label' => match ($key) {
                'success' => __('exam.distribution_statuses.distribution_success'),
                'partial' => __('exam.distribution_statuses.partial_distribution'),
                'shortage' => __('exam.distribution_statuses.capacity_shortage'),
                'no_students' => __('exam.distribution_statuses.no_students'),
                default => __('exam.distribution_statuses.not_run'),
            },
            'tone' => match ($key) {
                'success' => 'success',
                'partial' => 'warning',
                'shortage' => 'danger',
                default => 'gray',
            },
            'icon' => match ($key) {
                'success' => 'heroicon-o-check-badge',
                'partial' => 'heroicon-o-exclamation-triangle',
                'shortage' => 'heroicon-o-no-symbol',
                'no_students' => 'heroicon-o-user-group',
                default => 'heroicon-o-clock',
            },
        ];
    }

    protected function toAvailableHallSummary(
        ExamHall $hall,
        ?HallAssignment $assignment,
        string $collegeName,
    ): array {
        $usedSeats = (int) ($assignment?->assigned_students_count ?? 0);
        $remainingSeats = max(0, (int) $hall->capacity - $usedSeats);
        $usagePercentage = $hall->capacity > 0
            ? (int) round(($usedSeats / $hall->capacity) * 100)
            : 0;

        $statusKey = match (true) {
            $usedSeats === 0 => 'unused',
            $remainingSeats === 0 => 'full',
            default => 'available',
        };

        return [
            'hall_id' => $hall->getKey(),
            'name' => $this->sanitizeString($hall->name),
            'location' => $this->sanitizeString($hall->location),
            'capacity' => (int) $hall->capacity,
            'used_seats' => $usedSeats,
            'remaining_seats' => $remainingSeats,
            'usage_percentage' => $usagePercentage,
            'priority' => $hall->priority?->value,
            'priority_label' => $this->sanitizeString($hall->priority?->label() ?? ''),
            'hall_type' => $hall->hall_type?->value,
            'hall_type_label' => $this->sanitizeString($hall->hall_type?->label() ?? ''),
            'is_drawing_studio' => (bool) $hall->is_drawing_studio,
            'college_name' => $this->sanitizeString($collegeName),
            'status_key' => $statusKey,
            'status_label' => match ($statusKey) {
                'unused' => __('exam.distribution_statuses.unused'),
                'full' => __('exam.distribution_statuses.full'),
                default => __('exam.distribution_statuses.available'),
            },
            'status_tone' => match ($statusKey) {
                'unused' => 'gray',
                'full' => 'danger',
                default => 'success',
            },
            'is_used' => $usedSeats > 0,
            'is_full' => $remainingSeats === 0,
            'has_available_seats' => $remainingSeats > 0,
        ];
    }

    protected function buildDiagnosisSummary(
        int $totalStudents,
        int $availableHallsCount,
        int $totalCapacity,
        int $remainingCapacity,
        int $usedCapacity,
        int $unassignedStudentsCount,
        int $capacityShortage,
        bool $hasDistribution,
        array $distributionStatus,
    ): array {
        $items = [];
        $recommendedActions = [];
        $occupancyPercentage = $totalCapacity > 0
            ? (int) round(($usedCapacity / $totalCapacity) * 100)
            : 0;
        $isNearCapacity = $totalCapacity > 0 && $occupancyPercentage >= 85 && $capacityShortage === 0;

        if ($totalStudents === 0) {
            $items[] = [
                'tone' => 'gray',
                'icon' => 'heroicon-o-user-group',
                'text' => __('exam.diagnosis.no_students'),
            ];
            $recommendedActions[] = 'العودة إلى البرامج الامتحانية وإضافة طلاب إلى هذه الجلسة.';
        } elseif ($availableHallsCount === 0) {
            $items[] = [
                'tone' => 'danger',
                'icon' => 'heroicon-o-building-office-2',
                'text' => __('exam.diagnosis.no_active_halls'),
            ];
            $recommendedActions[] = 'أضف قاعة امتحانية فعالة لهذه الكلية.';
            $recommendedActions[] = 'أعد تنفيذ التوزيع بعد إضافة القاعات.';
        } elseif ($capacityShortage > 0) {
            $items[] = [
                'tone' => 'danger',
                'icon' => 'heroicon-o-exclamation-circle',
                'text' => __('exam.diagnosis.capacity_not_enough', ['count' => $totalCapacity]),
            ];
            $items[] = [
                'tone' => 'danger',
                'icon' => 'heroicon-o-no-symbol',
                'text' => __('exam.diagnosis.capacity_shortage', ['count' => $capacityShortage]),
            ];
            $items[] = [
                'tone' => 'warning',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'text' => __('exam.diagnosis.add_hall_with_capacity', ['count' => $capacityShortage]),
            ];

            if ($hasDistribution && $unassignedStudentsCount > 0) {
                $items[] = [
                    'tone' => 'danger',
                    'icon' => 'heroicon-o-user-minus',
                    'text' => __('exam.diagnosis.unassigned_students', ['count' => $unassignedStudentsCount]),
                ];
            }
            $recommendedActions[] = 'أضف قاعة بسعة لا تقل عن '.$capacityShortage.' مقعد.';
            $recommendedActions[] = 'أعد تنفيذ التوزيع بعد تعديل القاعات.';
        } elseif (! $hasDistribution) {
            $items[] = [
                'tone' => 'gray',
                'icon' => 'heroicon-o-clock',
                'text' => __('exam.diagnosis.not_run_yet'),
            ];
            $recommendedActions[] = 'نفّذ التوزيع الآلي لبدء توزيع الطلاب على القاعات.';
        } elseif ($unassignedStudentsCount > 0) {
            $items[] = [
                'tone' => 'danger',
                'icon' => 'heroicon-o-user-minus',
                'text' => __('exam.diagnosis.unassigned_students', ['count' => $unassignedStudentsCount]),
            ];

            if ($remainingCapacity > 0) {
                $items[] = [
                    'tone' => 'warning',
                    'icon' => 'heroicon-o-arrow-trending-up',
                    'text' => __('exam.diagnosis.remaining_seats_but_unassigned', ['count' => $remainingCapacity]),
                ];
                $items[] = [
                    'tone' => 'warning',
                    'icon' => 'heroicon-o-wrench-screwdriver',
                    'text' => __('exam.diagnosis.review_distribution_constraints'),
                ];
            }

            $recommendedActions[] = 'راجع الطلاب غير الموزعين أدناه.';
            $recommendedActions[] = 'أعد تنفيذ التوزيع بعد تعديل القاعات أو البيانات.';
        } else {
            $items[] = [
                'tone' => 'success',
                'icon' => 'heroicon-o-check-circle',
                'text' => __('exam.diagnosis.all_distributed'),
            ];

            if ($remainingCapacity > 0) {
                $items[] = [
                    'tone' => 'success',
                    'icon' => 'heroicon-o-chart-bar',
                    'text' => __('exam.diagnosis.remaining_capacity', ['count' => $remainingCapacity]),
                ];
            }
        }

        if ($isNearCapacity) {
            $items[] = [
                'tone' => 'warning',
                'icon' => 'heroicon-o-fire',
                'text' => 'القاعات قريبة من الامتلاء.',
            ];
            $recommendedActions[] = 'يفضل إضافة قاعة إضافية احتياطية.';
        }

        $tone = match (true) {
            $totalStudents === 0 => 'gray',
            $availableHallsCount === 0 || $capacityShortage > 0 || $unassignedStudentsCount > 0 => 'danger',
            $isNearCapacity => 'warning',
            default => 'success',
        };

        $headline = match ($tone) {
            'success' => 'تم توزيع جميع الطلاب بنجاح.',
            'warning' => 'القاعات قريبة من الامتلاء.',
            'danger' => $availableHallsCount === 0
                ? 'لا توجد قاعات فعالة متاحة لهذه الكلية.'
                : ($capacityShortage > 0
                    ? 'يوجد عجز في السعة بمقدار '.$capacityShortage.' مقعد.'
                    : 'يوجد '.$unassignedStudentsCount.' طالب غير موزع.'),
            default => 'لم يتم تنفيذ التوزيع بعد.',
        };

        $summaryText = match (true) {
            $totalStudents === 0 => 'لا يوجد طلاب ضمن هذه الجلسة حالياً، لذلك لن تظهر نتائج توزيع أو جداول تشغيلية.',
            $availableHallsCount === 0 => 'أضف قاعة امتحانية فعالة لهذه الكلية قبل تنفيذ التوزيع.',
            $capacityShortage > 0 => 'السعة الحالية أقل من عدد الطلاب المسجلين، لذلك لن يكتمل التوزيع بدون قاعات إضافية.',
            $unassignedStudentsCount > 0 => 'بعض الطلاب لم يحصلوا على قاعة بعد، ويجب مراجعتهم قبل اعتماد التوزيع.',
            $isNearCapacity => 'التوزيع ناجح حالياً، لكن السعة المتبقية محدودة جداً ويُفضّل إضافة قاعة احتياطية.',
            default => 'البيانات الحالية تشير إلى أن التوزيع مكتمل ويمكن الاعتماد عليه والتصدير منه.',
        };

        return [
            'title' => __('exam.sections.problem_diagnosis'),
            'status' => $distributionStatus,
            'tone' => $tone,
            'headline' => $headline,
            'summary' => $summaryText,
            'items' => $items,
            'recommended_actions' => array_values(array_unique($recommendedActions)),
            'used_capacity' => $usedCapacity,
            'remaining_capacity' => $remainingCapacity,
            'occupancy_percentage' => $occupancyPercentage,
        ];
    }

    protected function buildUnassignedStudentsSummary(
        Collection $students,
        int $availableHallsCount,
        int $capacityShortage,
        int $remainingCapacity,
        bool $hasDistribution,
    ): Collection {
        return $students->map(function (ExamStudent $student) use (
            $availableHallsCount,
            $capacityShortage,
            $remainingCapacity,
            $hasDistribution,
        ): array {
            $subjectName = $this->sanitizeString($student->subjectExamOffering?->subject?->name ?? '');
            $studentType = (string) $student->getRawOriginal('student_type');
            $studentTypeLabel = match ($studentType) {
                ExamStudentType::Regular->value => __('exam.student_types.regular'),
                ExamStudentType::Carry->value => __('exam.student_types.carry'),
                default => __('exam.student_types.unknown'),
            };

            return [
                'student_id' => $student->getKey(),
                'student_number' => $this->sanitizeString($student->student_number ?? ''),
                'full_name' => $this->sanitizeString($student->full_name ?? ''),
                'subject_name' => $subjectName,
                'student_type' => $studentType,
                'student_type_label' => $studentTypeLabel,
                'reason' => $this->resolveUnassignedReason(
                    student: $student,
                    availableHallsCount: $availableHallsCount,
                    capacityShortage: $capacityShortage,
                    remainingCapacity: $remainingCapacity,
                    hasDistribution: $hasDistribution,
                ),
            ];
        })->values();
    }

    protected function resolveUnassignedReason(
        ExamStudent $student,
        int $availableHallsCount,
        int $capacityShortage,
        int $remainingCapacity,
        bool $hasDistribution,
    ): string {
        $studentType = (string) $student->getRawOriginal('student_type');

        if (blank($student->student_number) || blank($student->full_name)) {
            return __('exam.unassigned_reasons.missing_student_data');
        }

        if (! in_array($studentType, ExamStudentType::values(), true)) {
            return __('exam.unassigned_reasons.unknown_student_type');
        }

        if (! $student->subjectExamOffering?->subject) {
            return __('exam.unassigned_reasons.invalid_subject_session');
        }

        if ($availableHallsCount === 0) {
            return __('exam.unassigned_reasons.no_available_hall');
        }

        if ($capacityShortage > 0) {
            return __('exam.unassigned_reasons.insufficient_capacity');
        }

        if (! $hasDistribution) {
            return __('exam.unassigned_reasons.distribution_not_run');
        }

        if ($remainingCapacity > 0) {
            return __('exam.unassigned_reasons.review_constraints');
        }

        return __('exam.unassigned_reasons.unknown');
    }

    protected function logInvalidUtf8InSummary(array $summary, array $context = []): void
    {
        $offending = $this->findInvalidUtf8Value($summary);

        if (! $offending) {
            return;
        }

        Log::error('Invalid UTF-8 detected in hall distribution summary.', [
            ...$context,
            'summary_path' => $offending['path'],
            'summary_value_preview' => $offending['preview'],
            'summary_value_hex' => $offending['hex'],
        ]);
    }

    protected function findInvalidUtf8Value(mixed $value, string $path = 'summary'): ?array
    {
        if (is_string($value)) {
            if (mb_check_encoding($value, 'UTF-8')) {
                return null;
            }

            return [
                'path' => $path,
                'preview' => substr($value, 0, 120),
                'hex' => bin2hex(substr($value, 0, 60)),
            ];
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $offending = $this->findInvalidUtf8Value($item, $path.'.'.$key);

                if ($offending) {
                    return $offending;
                }
            }
        }

        return null;
    }
}
