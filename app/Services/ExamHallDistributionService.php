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
use App\Models\SubjectExamOffering;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExamHallDistributionService
{
    public function distributeForOffering(SubjectExamOffering $offering): array
    {
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

        return DB::transaction(function () use ($slot, $slotOfferings, $availableHalls, $totalStudents): array {
            $this->clearSlotDistribution(
                collegeId: $slot['college_id'],
                examDate: $slot['exam_date'],
                examStartTime: $slot['exam_start_time'],
            );

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

            foreach ($availableHalls as $hall) {
                if (array_sum($remainingCounts) === 0) {
                    break;
                }

                $remainingCapacity = (int) $hall->capacity;
                $subjectCounts = [];
                $studentAssignmentRows = [];

                while (($remainingCapacity > 0) && (count($subjectCounts) < 3)) {
                    $nextOffering = $this->nextOfferingToAssign($slotOfferings, $remainingCounts, array_keys($subjectCounts));

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
                            'seat_number' => null,
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

            SubjectExamOffering::query()
                ->whereKey($slotOfferings->modelKeys())
                ->update([
                    'status' => $unassignedStudentsCount === 0
                        ? ExamOfferingStatus::Distributed->value
                        : ExamOfferingStatus::Ready->value,
                ]);

            return [
                'status' => $unassignedStudentsCount === 0 ? 'success' : 'warning',
                'message' => $unassignedStudentsCount === 0
                    ? __('exam.notifications.distribution_completed')
                    : __('exam.notifications.distribution_completed_with_unassigned', [
                        'count' => $unassignedStudentsCount,
                    ]),
                'assigned_students_count' => $assignedStudentsCount,
                'unassigned_students_count' => $unassignedStudentsCount,
                'used_halls_count' => $usedHallsCount,
            ];
        });
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
        $totalCapacity = (int) $availableHalls->sum('capacity');
        $usedCapacity = (int) $hallAssignments->sum('assigned_students_count');
        $remainingCapacity = max(0, $totalCapacity - $usedCapacity);
        $capacityShortage = max(0, $totalStudents - $totalCapacity);
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

    protected function nextOfferingToAssign(
        Collection $slotOfferings,
        array $remainingCounts,
        array $usedOfferingIds,
    ): ?SubjectExamOffering {
        return $slotOfferings
            ->first(function (SubjectExamOffering $slotOffering) use ($remainingCounts, $usedOfferingIds): bool {
                if (in_array($slotOffering->getKey(), $usedOfferingIds, true)) {
                    return false;
                }

                return ($remainingCounts[$slotOffering->getKey()] ?? 0) > 0;
            });
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
        $subjects = $assignment->assignmentSubjects
            ->map(fn (HallAssignmentSubject $assignmentSubject): array => [
                'subject_exam_offering_id' => $assignmentSubject->subject_exam_offering_id,
                'subject_name' => $this->sanitizeString($assignmentSubject->subjectExamOffering?->subject?->name ?? ''),
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
            ])
            ->all();

        return [
            'id' => $assignment->getKey(),
            'hall_id' => $assignment->examHall?->getKey(),
            'hall_name' => $this->sanitizeString($assignment->examHall?->name ?? ''),
            'hall_location' => $this->sanitizeString($assignment->examHall?->location ?? ''),
            'priority' => $assignment->examHall?->priority?->value,
            'priority_label' => $this->sanitizeString($assignment->examHall?->priority?->label() ?? ''),
            'total_capacity' => (int) $assignment->total_capacity,
            'assigned_students_count' => (int) $assignment->assigned_students_count,
            'remaining_capacity' => (int) $assignment->remaining_capacity,
            'usage_percentage' => (int) ($assignment->total_capacity > 0
                ? round(($assignment->assigned_students_count / $assignment->total_capacity) * 100)
                : 0),
            'subjects_count' => count($subjects),
            'subjects' => $subjects,
            'students' => $students,
            'status_key' => $assignment->remaining_capacity === 0 ? 'full' : 'available',
            'status_label' => $assignment->remaining_capacity === 0
                ? __('exam.distribution_statuses.full')
                : __('exam.distribution_statuses.available'),
        ];
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
