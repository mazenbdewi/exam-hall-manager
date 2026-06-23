<?php

namespace App\Services;

use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Exceptions\ExamScheduleGenerationException;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\ExamStudent;
use App\Models\SubjectExamOffering;
use App\Models\SubjectExamRoster;
use App\Models\SubjectExamRosterStudent;
use App\Support\ExamCollegeScope;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamScheduleGeneratorService
{
    protected const STUDENT_NUMBER_METADATA_LIMIT = 500;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function generateDraft(array $settings): ExamScheduleDraft
    {
        $settings = $this->normalizeSettings($settings);
        Log::info('Exam schedule draft generation: settings normalized.', [
            'user_id' => auth()->id(),
            'settings' => $this->loggableSettings($settings),
        ]);

        $collegeId = ExamCollegeScope::enforceCollegeId($settings['faculty_id'] ?? null, 'faculty_id');
        $settings['faculty_id'] = $collegeId;

        if (! $settings['academic_year_id'] || ! $settings['semester_id']) {
            throw ValidationException::withMessages([
                'academic_settings' => 'يجب تحديد العام الدراسي والفصل الدراسي قبل توليد المسودة.',
            ]);
        }

        if (Carbon::parse($settings['end_date'])->lt(Carbon::parse($settings['start_date']))) {
            throw ValidationException::withMessages([
                'end_date' => 'تاريخ نهاية الامتحانات يجب أن يكون بعد تاريخ البداية أو مساوياً له.',
            ]);
        }

        if ($this->availableExamDays($settings) === []) {
            $this->throwGenerationFailure(
                reasonCode: 'missing_exam_days',
                settings: $settings,
                details: [],
                draftId: null,
                technicalDetails: [
                    'start_date' => $settings['start_date'],
                    'end_date' => $settings['end_date'],
                    'excluded_weekdays' => $settings['excluded_weekdays'],
                    'holidays' => $settings['holidays'],
                ],
            );
        }

        $slots = $this->availableSlots($settings);
        Log::info('Exam schedule draft generation: available slots built.', [
            'user_id' => auth()->id(),
            'college_id' => $collegeId,
            'slots_count' => count($slots),
            'first_slot' => $slots[0]['key'] ?? null,
            'last_slot' => $slots !== [] ? $slots[array_key_last($slots)]['key'] : null,
        ]);

        Log::info('Exam schedule draft generation: before building scheduling units.', [
            'user_id' => auth()->id(),
            'college_id' => $collegeId,
            'academic_year_id' => $settings['academic_year_id'],
            'semester_id' => $settings['semester_id'],
        ]);

        $units = $this->buildSchedulingUnits($settings);
        $this->ensureUnitsHaveStudents($units, $settings);
        Log::info('Exam schedule draft generation: scheduling units built.', [
            'user_id' => auth()->id(),
            'college_id' => $collegeId,
            'units_count' => $units->count(),
            'subjects_count' => $units->sum(fn (array $unit): int => count($unit['subjects'] ?? [])),
        ]);

        Log::info('Exam schedule draft generation: before creating draft.', [
            'user_id' => auth()->id(),
            'college_id' => $collegeId,
            'academic_year_id' => $settings['academic_year_id'],
            'semester_id' => $settings['semester_id'],
        ]);

        return DB::transaction(function () use ($settings, $collegeId, $slots, $units): ExamScheduleDraft {
            $draft = ExamScheduleDraft::query()->create([
                'faculty_id' => $collegeId,
                'academic_year_id' => $settings['academic_year_id'],
                'semester_id' => $settings['semester_id'],
                'start_date' => $settings['start_date'],
                'end_date' => $settings['end_date'],
                'status' => ExamScheduleDraft::STATUS_GENERATING,
                'generated_by' => auth()->id(),
                'settings_json' => $settings,
            ]);

            Log::info('Exam schedule draft generation: draft created.', [
                'user_id' => auth()->id(),
                'draft_id' => $draft->id,
                'college_id' => $collegeId,
            ]);

            $slotLoads = collect($slots)->mapWithKeys(fn (array $slot): array => [$slot['key'] => 0])->all();
            $dayLoads = [];
            $academicAssignments = [];
            $studentAssignments = [];
            $pinnedRosterIds = $this->copyPinnedItemsFromPreviousDraft(
                draft: $draft,
                settings: $settings,
                slotLoads: $slotLoads,
                dayLoads: $dayLoads,
                academicAssignments: $academicAssignments,
                studentAssignments: $studentAssignments,
            );
            $pinnedRosterIds = array_values(array_unique(array_merge(
                $pinnedRosterIds,
                $this->copyPinnedItemsFromOfferings(
                    draft: $draft,
                    settings: $settings,
                    slotLoads: $slotLoads,
                    dayLoads: $dayLoads,
                    academicAssignments: $academicAssignments,
                    studentAssignments: $studentAssignments,
                ),
            )));
            $this->ensurePinnedItemsDoNotConflict($draft, $settings);
            $this->deleteReplaceableDraftsForScope($settings, $draft->id);
            $units = $this->withoutPinnedSubjects($units, $pinnedRosterIds);

            foreach ($units->sortByDesc(fn (array $unit): int => count($unit['subjects']))->values() as $unit) {
                $choiceResult = $this->chooseSlot($unit, $slots, $slotLoads, $dayLoads, $academicAssignments, $studentAssignments, $settings);
                $choice = $choiceResult['slot'] ?? null;

                if (! $choice) {
                    Log::info('Exam schedule unit could not be scheduled', [
                        'user_id' => auth()->id(),
                        'draft_id' => $draft->id,
                        'unit_key' => $unit['shared_group_key'] ?? null,
                        'subjects' => collect($unit['subjects'] ?? [])
                            ->map(fn (array $payload): array => [
                                'roster_id' => $payload['roster']->id ?? null,
                                'subject_id' => $payload['subject']->id ?? null,
                                'subject_name' => $payload['subject']->name ?? null,
                                'students_count' => $payload['student_count'] ?? null,
                            ])
                            ->values()
                            ->all(),
                        'reason_code' => $choiceResult['failure_reason_code'] ?? 'unknown',
                        'attempted_slots_count' => $choiceResult['diagnostics']['attempted_slots_count'] ?? null,
                        'diagnostics' => $choiceResult['diagnostics'] ?? [],
                    ]);

                    $this->throwGenerationFailureForUnit($settings, $draft, $unit, $choiceResult);
                }

                foreach ($unit['subjects'] as $subjectPayload) {
                    $draft->items()->create([
                        'source_roster_id' => $subjectPayload['roster']->id,
                        'subject_id' => $subjectPayload['subject']->id,
                        'department_id' => $subjectPayload['department_id'],
                        'exam_date' => $choice['date'],
                        'start_time' => $choice['start_time'],
                        'end_time' => $choice['end_time'],
                        'period_type' => $choice['period_type'],
                        'student_count' => $subjectPayload['student_count'],
                        'regular_count' => $subjectPayload['regular_count'],
                        'carry_count' => $subjectPayload['carry_count'],
                        'is_shared_subject' => $unit['is_shared_subject'],
                        'is_core_subject' => $subjectPayload['is_core_subject'],
                        'shared_group_key' => $unit['shared_group_key'],
                        'status' => 'scheduled',
                        'conflict_notes' => null,
                        'metadata' => [
                            'period_name' => $choice['period_name'],
                            'academic_group_key' => $subjectPayload['academic_group_key'],
                            'shared_subject_scheduling_mode' => $unit['shared_subject_scheduling_mode'],
                            'student_numbers' => $subjectPayload['student_numbers_for_metadata'],
                            'student_numbers_truncated' => $subjectPayload['student_numbers_truncated'],
                            'student_numbers_count' => $subjectPayload['student_count'],
                            'student_examples' => $subjectPayload['student_examples'],
                            'preferred_exam_period' => $subjectPayload['preferred_exam_period'],
                            'core_subject_priority' => $subjectPayload['core_subject_priority'],
                        ],
                    ]);
                }

                $slotLoads[$choice['key']] = ($slotLoads[$choice['key']] ?? 0) + 1;
                $dayLoads[$choice['date']] = ($dayLoads[$choice['date']] ?? 0) + 1;

                foreach ($unit['academic_group_keys'] as $academicGroupKey) {
                    $academicAssignments[$academicGroupKey][] = [
                        'date' => $choice['date'],
                        'start_time' => $choice['start_time'],
                        'shared_group_key' => $unit['shared_group_key'],
                    ];
                }

                foreach ($unit['student_numbers'] as $studentNumber) {
                    $studentAssignments[$studentNumber][] = [
                        'date' => $choice['date'],
                        'start_time' => $choice['start_time'],
                        'shared_group_key' => $unit['shared_group_key'],
                    ];
                }
            }

            $itemsCount = $draft->items()->count();
            $validationStartedAt = microtime(true);

            Log::info('Exam schedule draft generation: before validating draft.', [
                'user_id' => auth()->id(),
                'draft_id' => $draft->id,
                'items_count' => $itemsCount,
            ]);

            try {
                $validation = $this->validateDraft($draft->refresh());

                Log::info('Exam schedule draft generation: after validating draft.', [
                    'user_id' => auth()->id(),
                    'draft_id' => $draft->id,
                    'items_count' => $itemsCount,
                    'duration_ms' => (int) round((microtime(true) - $validationStartedAt) * 1000),
                    'hard_conflicts_count' => $validation['hard_conflicts_count'] ?? null,
                    'warnings_count' => $validation['warnings_count'] ?? null,
                ]);
            } catch (\Throwable $exception) {
                Log::error('Exam schedule draft validation failed.', [
                    'user_id' => auth()->id(),
                    'college_id' => $settings['faculty_id'] ?? null,
                    'academic_year_id' => $settings['academic_year_id'] ?? null,
                    'semester_id' => $settings['semester_id'] ?? null,
                    'draft_id' => $draft->id,
                    'items_count' => $itemsCount,
                    'reason_code' => 'draft_validation_failed',
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'duration_ms' => (int) round((microtime(true) - $validationStartedAt) * 1000),
                ]);

                $this->throwGenerationFailure(
                    reasonCode: 'draft_validation_failed',
                    settings: $settings,
                    details: [],
                    draftId: $draft->id,
                    technicalDetails: [
                        'exception_class' => $exception::class,
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                    ],
                    previous: $exception,
                );
            }

            if (($validation['hard_conflicts_count'] ?? 0) > 0) {
                $this->throwGenerationFailureForValidation($settings, $draft, $validation);
            }

            $this->syncValidationToDraft($draft, $validation);
            Log::info('Exam schedule draft generation: validation synced to draft.', [
                'user_id' => auth()->id(),
                'draft_id' => $draft->id,
                'summary' => $validation['summary'] ?? [],
                'hard_conflicts_count' => $validation['hard_conflicts_count'] ?? null,
                'warnings_count' => $validation['warnings_count'] ?? null,
            ]);

            $draft->update([
                'status' => ExamScheduleDraft::STATUS_COMPLETED,
                'summary_json' => $validation['summary'],
            ]);

            $this->syncDraftToSubjectExamOfferings($draft->refresh());

            $draft = $draft->refresh();
            Log::info('Exam schedule draft generation: before return.', [
                'user_id' => auth()->id(),
                'draft_id' => $draft->id,
                'status' => $draft->status,
                'summary_status' => $draft->summary_json['status'] ?? null,
            ]);

            return $draft;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validateDraft(ExamScheduleDraft $draft): array
    {
        $startedAt = microtime(true);

        Log::info('Draft validation started.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
        ]);

        Log::info('Draft validation: loading items relations.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
        ]);

        $draft->loadMissing(['items.department', 'items.subject.department', 'items.subject.studyLevel', 'college']);
        $items = $draft->items;

        Log::info('Draft validation: items relations loaded.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
            'items_count' => $items->count(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $settings = $this->normalizeSettings($draft->settings_json ?? [], requireExamPeriods: false);
        $settings['faculty_id'] = $draft->faculty_id;
        $settings['academic_year_id'] = $draft->academic_year_id;
        $settings['semester_id'] = $draft->semester_id;
        $settings['start_date'] = $draft->start_date?->toDateString();
        $settings['end_date'] = $draft->end_date?->toDateString();

        Log::info('Draft validation: preloading student numbers.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
            'items_count' => $items->count(),
        ]);

        $studentNumbersByItem = $this->studentNumbersByItemForValidation($items);

        Log::info('Draft validation: student numbers preloaded.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
            'items_with_students_count' => collect($studentNumbersByItem)->filter(fn (array $numbers): bool => $numbers !== [])->count(),
            'student_number_links_count' => collect($studentNumbersByItem)->sum(fn (array $numbers): int => count($numbers)),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $slotAcademicGroups = [];
        $dayAcademicGroups = [];
        $slotStudents = [];
        $dayStudents = [];
        $slotLoads = [];
        $conflicts = [];

        Log::info('Draft validation: indexing items by slot, day, academic group, and students.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
        ]);

        foreach ($items as $item) {
            $date = $item->exam_date?->toDateString();
            $time = $this->timeString($item->start_time);

            if ($item->status === 'unscheduled' || blank($date) || blank($time)) {
                $metadata = $item->metadata ?? [];
                $conflictingStudentNumbers = $this->sampleConflictingStudentNumbersFromMetadata($metadata);

                $conflicts[] = $this->conflictRow(
                    $item,
                    'unscheduled',
                    'مادة لم يتم جدولتها',
                    $metadata['unscheduled_reason'] ?? 'غير مجدولة',
                    $metadata['unscheduled_suggested_action'] ?? $this->unscheduledSuggestedAction((string) ($metadata['unscheduled_reason_code'] ?? 'unknown')),
                    $this->unscheduledDetailsFromMetadata($metadata),
                    true,
                    (int) ($metadata['conflicting_student_numbers_count'] ?? count($conflictingStudentNumbers)),
                    $conflictingStudentNumbers,
                );

                continue;
            }

            if (Carbon::parse($date)->lt(Carbon::parse($settings['start_date'])) || Carbon::parse($date)->gt(Carbon::parse($settings['end_date']))) {
                $conflicts[] = $this->conflictRow($item, 'outside_range', 'خارج الفترة الامتحانية', 'تاريخ غير مسموح', 'انقل المادة إلى تاريخ داخل الفترة الامتحانية.');
            }

            if ($this->isExcludedDate(Carbon::parse($date), $settings)) {
                $conflicts[] = $this->conflictRow($item, 'holiday', 'يوم عطلة', 'تاريخ مستبعد', 'انقل المادة إلى يوم غير مستبعد.');
            }

            $slotKey = $date.'|'.$time;
            $academicGroupKey = $this->academicGroupKeyForItem($item);
            $groupKey = $item->shared_group_key ?: 'item-'.$item->id;

            $slotAcademicGroups[$slotKey][$academicGroupKey][$groupKey][] = $item;
            $dayAcademicGroups[$date][$academicGroupKey][$groupKey][] = $item;
            $slotLoads[$slotKey] = ($slotLoads[$slotKey] ?? 0) + 1;

            foreach ($studentNumbersByItem[$item->id] ?? [] as $studentNumber) {
                $slotStudents[$slotKey][$studentNumber][] = $item;
                $dayStudents[$date][$studentNumber][] = $item;
            }

            if ($item->is_core_subject && $this->coreSubjectIsOutsidePreferredPeriod($item)) {
                $priority = (string) (($item->metadata ?? [])['core_subject_priority'] ?? 'preference');
                $conflicts[] = $this->conflictRow(
                    $item,
                    $priority === 'strict' ? 'core_subject_strict_period' : 'core_subject_not_preferred_period',
                    'مادة أساسية لم توضع صباحًا',
                    'تفضيل الفترة',
                    'راجع توفر فترة صباحية مناسبة أو خفف درجة إلزام الفترة المفضلة.',
                    'تمت جدولة المادة الأساسية خارج الفترة الصباحية بسبب التعارضات أو عدم توفر فترة مناسبة.',
                    $priority === 'strict',
                );
            }
        }

        Log::info('Draft validation: checking student conflicts.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
            'slot_groups_count' => count($slotStudents),
            'day_groups_count' => count($dayStudents),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        foreach ($slotStudents as $students) {
            $conflictsByItem = [];

            foreach ($students as $studentNumber => $conflictItems) {
                if (count($conflictItems) <= 1) {
                    continue;
                }

                foreach ($conflictItems as $item) {
                    $conflictsByItem[$item->id]['item'] = $item;
                    $conflictsByItem[$item->id]['student_numbers'][] = (string) $studentNumber;
                }
            }

            foreach ($conflictsByItem as $conflictData) {
                $studentNumbers = collect($conflictData['student_numbers'] ?? [])
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $conflicts[] = $this->conflictRow(
                    $conflictData['item'],
                    'same_student_time',
                    'طالب لديه مادتان في نفس الوقت',
                    'طلاب متأثرون: '.count($studentNumbers),
                    'غيّر موعد إحدى المواد المتعارضة.',
                    'لا يمكن أن يكون للطالب مادتان في نفس الوقت. أرقام الطلاب المتعارضين: '.$this->formatConflictStudentNumbers($studentNumbers),
                    true,
                    count($studentNumbers),
                    $studentNumbers,
                );
            }
        }

        Log::info('Draft validation: checking academic conflicts.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
            'slot_academic_groups_count' => count($slotAcademicGroups),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        foreach ($slotAcademicGroups as $academicGroups) {
            foreach ($academicGroups as $groupedItems) {
                if (count($groupedItems) <= 1) {
                    continue;
                }

                foreach (collect($groupedItems)->flatten(1) as $item) {
                    $conflicts[] = $this->conflictRow($item, 'same_academic_group_time', 'مادتان لنفس القسم والسنة في نفس الوقت', 'القسم والسنة', 'غيّر موعد إحدى المواد المتعارضة.');
                }
            }
        }

        if ((bool) ($settings['prevent_same_day'] ?? false)) {
            Log::info('Draft validation: checking same-day conflicts.', [
                'user_id' => auth()->id(),
                'draft_id' => $draft->id,
                'day_student_groups_count' => count($dayStudents),
                'day_academic_groups_count' => count($dayAcademicGroups),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            foreach ($dayStudents as $students) {
                $conflictsByItem = [];

                foreach ($students as $studentNumber => $conflictItems) {
                    if (count($conflictItems) <= 1) {
                        continue;
                    }

                    foreach ($conflictItems as $item) {
                        $conflictsByItem[$item->id]['item'] = $item;
                        $conflictsByItem[$item->id]['student_numbers'][] = (string) $studentNumber;
                    }
                }

                foreach ($conflictsByItem as $conflictData) {
                    $studentNumbers = collect($conflictData['student_numbers'] ?? [])
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    $conflicts[] = $this->conflictRow(
                        $conflictData['item'],
                        'same_student_day',
                        'طالب لديه مادتان في نفس اليوم',
                        'طلاب متأثرون: '.count($studentNumbers),
                        'انقل إحدى المواد إلى يوم آخر.',
                        'منع مادتين في نفس اليوم لنفس الطالب مفعل. أرقام الطلاب المتعارضين: '.$this->formatConflictStudentNumbers($studentNumbers),
                        true,
                        count($studentNumbers),
                        $studentNumbers,
                    );
                }
            }

            foreach ($dayAcademicGroups as $academicGroups) {
                foreach ($academicGroups as $groupedItems) {
                    if (count($groupedItems) <= 1) {
                        continue;
                    }

                    foreach (collect($groupedItems)->flatten(1) as $item) {
                        $conflicts[] = $this->conflictRow($item, 'same_academic_group_day', 'مادتان لنفس القسم والسنة في نفس اليوم', 'القسم والسنة', 'انقل إحدى المواد إلى يوم آخر.');
                    }
                }
            }
        }

        Log::info('Draft validation: checking shared subject rules.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        foreach ($items->where('is_shared_subject', true)->groupBy('shared_group_key') as $sharedItems) {
            $requiresSeparateDays = $sharedItems->contains(
                fn (ExamScheduleDraftItem $item): bool => $item->subject?->shared_subject_scheduling_mode === 'separate_departments',
            );

            if (! $requiresSeparateDays) {
                continue;
            }

            foreach ($sharedItems->whereNotNull('exam_date')->groupBy(fn (ExamScheduleDraftItem $item): string => $item->exam_date?->toDateString() ?? '') as $sameDateItems) {
                if ($sameDateItems->count() <= 1) {
                    continue;
                }

                foreach ($sameDateItems as $item) {
                    $conflicts[] = $this->conflictRow(
                        $item,
                        'shared_subject_not_separated',
                        'مادة مشتركة تحتاج مراجعة',
                        'مادة مشتركة',
                        'انقل أحد أقسام المادة المشتركة إلى يوم آخر إذا سمحت الفترة والقاعات.',
                        'تم اختيار جدولة كل قسم في يوم مختلف إن أمكن، لكن بعض الأقسام بقيت في اليوم نفسه.',
                        false,
                    );
                }
            }
        }

        Log::info('Draft validation: building summary.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
            'conflicts_count' => count($conflicts),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $hardConflictTypes = ['unscheduled', 'outside_range', 'holiday', 'same_academic_group_time', 'same_student_time', 'core_subject_strict_period'];

        if ((bool) ($settings['prevent_same_day'] ?? false)) {
            $hardConflictTypes[] = 'same_academic_group_day';
            $hardConflictTypes[] = 'same_student_day';
        }

        $hardConflictsCount = collect($conflicts)->whereIn('type', $hardConflictTypes)->count();
        $warningsCount = count($conflicts) - $hardConflictsCount;
        $scheduledCount = $items->whereIn('status', ['scheduled', 'manually_adjusted', 'conflict'])->whereNotNull('exam_date')->count();
        $unscheduledCount = $items->count() - $scheduledCount;
        $usedDays = $items->pluck('exam_date')->filter()->map(fn ($date) => $date->toDateString())->unique()->count();
        $busiestDay = collect($slotLoads)
            ->mapToGroups(fn (int $count, string $slot): array => [explode('|', $slot)[0] => $count])
            ->map(fn (Collection $counts): int => $counts->sum())
            ->sortDesc()
            ->keys()
            ->first();

        $summary = [
            'status' => $hardConflictsCount > 0 ? 'failed' : ($warningsCount > 0 ? 'warning' : 'success'),
            'subjects_count' => $items->count(),
            'scheduled_subjects_count' => $scheduledCount,
            'unscheduled_subjects_count' => $unscheduledCount,
            'conflicts_count' => $hardConflictsCount,
            'warnings_count' => $warningsCount,
            'used_days_count' => $usedDays,
            'busiest_day' => $busiestDay,
            'shared_subject_notes_count' => collect($conflicts)->where('type', 'shared_subject_not_separated')->count(),
            'core_subject_notes_count' => collect($conflicts)->whereIn('type', ['core_subject_not_preferred_period', 'core_subject_strict_period'])->count(),
        ];

        Log::info('Draft validation completed.', [
            'user_id' => auth()->id(),
            'draft_id' => $draft->id,
            'items_count' => $items->count(),
            'hard_conflicts_count' => $hardConflictsCount,
            'warnings_count' => $warningsCount,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return [
            'summary' => $summary,
            'conflicts' => $conflicts,
            'unscheduled_items' => $items
                ->filter(fn (ExamScheduleDraftItem $item): bool => $item->status === 'unscheduled' || blank($item->exam_date) || blank($item->start_time))
                ->map(fn (ExamScheduleDraftItem $item): array => $this->unscheduledItemSummary($item))
                ->values()
                ->all(),
            'hard_conflicts_count' => $hardConflictsCount,
            'warnings_count' => $warningsCount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function studentConflictDetailRows(ExamScheduleDraft $draft): array
    {
        $draft->loadMissing(['items.department', 'items.subject.department']);

        $settings = $this->normalizeSettings($draft->settings_json ?? [], requireExamPeriods: false);
        $settings['faculty_id'] = $draft->faculty_id;
        $settings['academic_year_id'] = $draft->academic_year_id;
        $settings['semester_id'] = $draft->semester_id;
        $settings['start_date'] = $draft->start_date?->toDateString();
        $settings['end_date'] = $draft->end_date?->toDateString();

        $slotStudents = [];
        $dayStudents = [];

        foreach ($draft->items as $item) {
            $date = $item->exam_date?->toDateString();
            $time = $this->timeString($item->start_time);

            if ($item->status === 'unscheduled' || blank($date) || blank($time)) {
                continue;
            }

            foreach ($this->studentNumbersForItem($item) as $studentNumber) {
                $slotStudents[$date.'|'.$time][$studentNumber][] = $item;
                $dayStudents[$date][$studentNumber][] = $item;
            }
        }

        $rows = [];

        foreach ($slotStudents as $students) {
            foreach ($students as $studentNumber => $items) {
                if (count($items) <= 1) {
                    continue;
                }

                $rows = array_merge(
                    $rows,
                    $this->studentConflictPairRows(
                        draft: $draft,
                        studentNumber: (string) $studentNumber,
                        items: array_values($items),
                        conflictType: 'طالب لديه مادتان في نفس الوقت',
                        details: 'لا يمكن أن يكون للطالب مادتان في نفس الوقت.',
                    ),
                );
            }
        }

        if ((bool) ($settings['prevent_same_day'] ?? false)) {
            foreach ($dayStudents as $students) {
                foreach ($students as $studentNumber => $items) {
                    if (count($items) <= 1) {
                        continue;
                    }

                    $rows = array_merge(
                        $rows,
                        $this->studentConflictPairRows(
                            draft: $draft,
                            studentNumber: (string) $studentNumber,
                            items: array_values($items),
                            conflictType: 'طالب لديه مادتان في نفس اليوم',
                            details: 'منع مادتين في نفس اليوم لنفس الطالب مفعل.',
                            skipSameSlotPairs: true,
                        ),
                    );
                }
            }
        }

        return collect($rows)
            ->sortBy([
                ['conflict_date', 'asc'],
                ['conflict_time', 'asc'],
                ['student_number', 'asc'],
                ['first_subject', 'asc'],
                ['second_subject', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function approveDraft(ExamScheduleDraft $draft, string $existingOfferingStrategy = 'create_missing'): array
    {
        $draft->loadMissing('items.subject', 'items.sourceRoster.rosterStudents');

        if ($draft->status === ExamScheduleDraft::STATUS_APPROVED) {
            $fixedProgram = app(FixedExamProgramSnapshotService::class)->createFromDraft($draft);

            return [
                'status' => 'success',
                'created_count' => 0,
                'updated_count' => 0,
                'fixed_program_id' => $fixedProgram->id,
                'fixed_program_ids' => [$fixedProgram->id],
                'message' => 'تم تثبيت برنامج الامتحان وحفظه بنجاح',
            ];
        }

        $validation = $this->validateDraft($draft);
        $this->syncValidationToDraft($draft, $validation);

        if (($validation['hard_conflicts_count'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'draft' => 'لا يمكن اعتماد المسودة قبل معالجة التعارضات الإلزامية.',
            ]);
        }

        return DB::transaction(function () use ($draft, $validation, $existingOfferingStrategy): array {
            $created = 0;
            $updated = 0;
            $skippedExisting = 0;

            foreach ($draft->items()->with(['subject', 'sourceRoster.rosterStudents'])->whereIn('status', ['scheduled', 'manually_adjusted', 'conflict'])->get() as $item) {
                if (! $item->exam_date || blank($item->start_time)) {
                    continue;
                }

                $existingOffering = $item->subjectExamOffering ?: SubjectExamOffering::query()
                    ->where('subject_id', $item->subject_id)
                    ->where('academic_year_id', $draft->academic_year_id)
                    ->where('semester_id', $draft->semester_id)
                    ->first();

                $isCurrentDraftOffering = $existingOffering
                    && (int) $existingOffering->exam_schedule_draft_id === (int) $draft->id;

                if ($existingOffering) {
                    if (! $isCurrentDraftOffering && $existingOfferingStrategy !== 'update_existing') {
                        $skippedExisting++;

                        continue;
                    }

                    $offering = $existingOffering;
                    $updated++;
                } else {
                    $offering = new SubjectExamOffering([
                        'subject_id' => $item->subject_id,
                        'academic_year_id' => $draft->academic_year_id,
                        'semester_id' => $draft->semester_id,
                    ]);
                    $created++;
                }

                $offering->fill([
                    'exam_schedule_draft_id' => $draft->id,
                    'exam_date' => $item->exam_date->toDateString(),
                    'exam_start_time' => $this->timeString($item->start_time),
                    'status' => ExamOfferingStatus::Ready->value,
                    'notes' => trim(collect([
                        $offering->notes,
                        'تم إنشاؤه/تحديثه من مسودة البرنامج الامتحاني رقم '.$draft->id,
                    ])->filter()->unique()->implode("\n")),
                ]);

                $offering->save();

                $this->copyRosterStudentsToOffering($item, $offering);
                $item->update(['subject_exam_offering_id' => $offering->id]);
                $item->sourceRoster?->update(['status' => 'used']);
            }

            $draft->update([
                'status' => ExamScheduleDraft::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'summary_json' => $validation['summary'],
            ]);

            $fixedProgram = app(FixedExamProgramSnapshotService::class)->createFromDraft($draft);

            return [
                'status' => 'success',
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_existing_count' => $skippedExisting,
                'warnings_count' => $validation['warnings_count'] ?? 0,
                'fixed_program_id' => $fixedProgram->id,
                'fixed_program_ids' => [$fixedProgram->id],
                'message' => 'تم تثبيت برنامج الامتحان وحفظه بنجاح',
            ];
        });
    }

    public function syncDraftToSubjectExamOfferings(ExamScheduleDraft $draft): void
    {
        $draft->loadMissing(['items.subject']);

        foreach ($draft->items as $item) {
            if (! in_array($item->status, ['scheduled', 'manually_adjusted', 'conflict'], true)) {
                continue;
            }

            if (! $item->exam_date || blank($item->start_time)) {
                continue;
            }

            $this->syncDraftItemToOffering($item);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOfferingSchedule(SubjectExamOffering $offering, array $data): SubjectExamOffering
    {
        return DB::transaction(function () use ($offering, $data): SubjectExamOffering {
            $period = $this->periodForOfferingData($offering, $data);
            $examDate = filled($data['exam_date'] ?? null)
                ? Carbon::parse($data['exam_date'])->toDateString()
                : $offering->exam_date?->toDateString();
            $startTime = $period['start_time'] ?? $this->timeString($data['exam_start_time'] ?? $offering->exam_start_time);

            $offering->update([
                'exam_date' => $examDate,
                'exam_start_time' => $startTime,
            ]);

            if ($offering->refresh()->is_pinned) {
                $this->ensureOfferingCanBePinned($offering);
            }

            $this->syncOfferingToDraftItem($offering->refresh(), $period);

            return $offering->refresh();
        });
    }

    public function pinOffering(SubjectExamOffering $offering): SubjectExamOffering
    {
        $offering->loadMissing('subject');

        if (! $offering->exam_date || blank($offering->exam_start_time)) {
            throw ValidationException::withMessages([
                'is_pinned' => 'يجب تحديد تاريخ ووقت الامتحان قبل تثبيت المادة.',
            ]);
        }

        $this->ensureOfferingCanBePinned($offering);

        $offering->update(['is_pinned' => true]);

        return $offering->refresh();
    }

    public function unpinOffering(SubjectExamOffering $offering): SubjectExamOffering
    {
        $offering->update(['is_pinned' => false]);

        return $offering->refresh();
    }

    public function ensureOfferingCanBePinned(SubjectExamOffering $offering): void
    {
        $offering->loadMissing('subject');

        $conflictingOffering = SubjectExamOffering::query()
            ->with('subject')
            ->whereKeyNot($offering->getKey())
            ->where('is_pinned', true)
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('semester_id', $offering->semester_id)
            ->whereDate('exam_date', $offering->exam_date?->toDateString())
            ->whereTime('exam_start_time', $this->timeString($offering->exam_start_time))
            ->whereHas('subject', fn (Builder $query): Builder => $query->where('college_id', $offering->subject?->college_id))
            ->get()
            ->first(fn (SubjectExamOffering $candidate): bool => $this->pinnedOfferingsConflict($offering, $candidate));

        if ($conflictingOffering) {
            throw ValidationException::withMessages([
                'is_pinned' => 'لا يمكن تثبيت هذه المادة في هذا الموعد لوجود تعارض مع مادة مثبتة أخرى.',
            ]);
        }
    }

    protected function pinnedOfferingsConflict(SubjectExamOffering $offering, SubjectExamOffering $candidate): bool
    {
        $sameAcademicGroup = (int) $offering->subject?->department_id === (int) $candidate->subject?->department_id
            && (int) $offering->subject?->study_level_id === (int) $candidate->subject?->study_level_id;

        if ($sameAcademicGroup) {
            return true;
        }

        $offeringStudents = $this->studentNumbersForOffering($offering);
        $candidateStudents = $this->studentNumbersForOffering($candidate);

        return $offeringStudents !== []
            && $candidateStudents !== []
            && array_intersect($offeringStudents, $candidateStudents) !== [];
    }

    /**
     * @return array<int, string>
     */
    protected function studentNumbersForOffering(SubjectExamOffering $offering): array
    {
        $offering->loadMissing('examStudents');

        $examStudentNumbers = $offering->examStudents
            ->pluck('student_number')
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values();

        if ($examStudentNumbers->isNotEmpty()) {
            return $examStudentNumbers->all();
        }

        $roster = $this->matchingReadyRosterForOffering($offering, [
            'faculty_id' => $offering->subject?->college_id,
            'academic_year_id' => $offering->academic_year_id,
            'semester_id' => $offering->semester_id,
            'department_id' => null,
            'study_level_id' => null,
        ]);

        if (! $roster) {
            return [];
        }

        return $roster->eligibleRosterStudents()
            ->pluck('student_number')
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values()
            ->all();
    }

    protected function syncDraftItemToOffering(ExamScheduleDraftItem $item): ?SubjectExamOffering
    {
        $draft = $item->draft;

        if (! $draft || ! $item->exam_date || blank($item->start_time)) {
            return null;
        }

        $offering = $item->subjectExamOffering
            ?: SubjectExamOffering::query()
                ->where('exam_schedule_draft_id', $draft->id)
                ->where('subject_id', $item->subject_id)
                ->where('academic_year_id', $draft->academic_year_id)
                ->where('semester_id', $draft->semester_id)
                ->first()
            ?: new SubjectExamOffering([
                'subject_id' => $item->subject_id,
                'academic_year_id' => $draft->academic_year_id,
                'semester_id' => $draft->semester_id,
                'status' => ExamOfferingStatus::Draft->value,
            ]);

        $offering->fill([
            'exam_schedule_draft_id' => $draft->id,
            'exam_date' => $item->exam_date->toDateString(),
            'exam_start_time' => $this->timeString($item->start_time),
            'status' => $offering->exists ? $offering->status : ExamOfferingStatus::Draft->value,
            'notes' => $offering->notes ?: 'تم توليده من مسودة البرنامج الامتحاني رقم '.$draft->id,
        ]);

        $offering->save();

        if ((int) $item->subject_exam_offering_id !== (int) $offering->id) {
            $item->update(['subject_exam_offering_id' => $offering->id]);
        }

        return $offering;
    }

    /**
     * @param  array<string, mixed>|null  $period
     */
    protected function syncOfferingToDraftItem(SubjectExamOffering $offering, ?array $period = null): void
    {
        $item = $offering->examScheduleDraftItem;

        if (! $item) {
            return;
        }

        $period ??= $this->periodForOfferingData($offering, [
            'exam_start_time' => $offering->exam_start_time,
        ]);

        $metadata = $item->metadata ?? [];

        if ($period) {
            $metadata['period_name'] = $period['name'] ?? $metadata['period_name'] ?? null;
        }

        $item->update([
            'exam_date' => $offering->exam_date?->toDateString(),
            'start_time' => $this->timeString($offering->exam_start_time),
            'end_time' => $period['end_time'] ?? $item->end_time,
            'period_type' => $period['period_type'] ?? $item->period_type,
            'status' => $item->status === 'unscheduled' ? 'scheduled' : 'manually_adjusted',
            'metadata' => array_merge($metadata, [
                'manually_adjusted_at' => now()->toDateTimeString(),
                'manually_adjusted_by' => auth()->id(),
            ]),
        ]);

        $draft = $item->draft;

        if ($draft) {
            $validation = $this->validateDraft($draft->refresh());
            $this->syncValidationToDraft($draft, $validation);
            $draft->update(['summary_json' => $validation['summary']]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function periodForOfferingData(SubjectExamOffering $offering, array $data): ?array
    {
        $draft = $offering->examScheduleDraft ?: $offering->examScheduleDraftItem?->draft;
        $periods = collect($draft?->settings_json['periods'] ?? []);

        if ($periods->isEmpty()) {
            return null;
        }

        if (filled($data['period_key'] ?? null)) {
            $period = $periods->first(fn (array $period, int $index): bool => (string) ($period['key'] ?? $index) === (string) $data['period_key']);

            if ($period) {
                return $period;
            }
        }

        $startTime = $this->timeString($data['exam_start_time'] ?? $offering->exam_start_time);

        return $periods->first(fn (array $period): bool => $this->timeString($period['start_time'] ?? null) === $startTime);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    protected function periodFromSettings(array $settings, mixed $startTime): ?array
    {
        $normalizedStartTime = $this->timeString($startTime);

        return collect($settings['periods'] ?? [])
            ->first(fn (array $period): bool => $this->timeString($period['start_time'] ?? null) === $normalizedStartTime);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function normalizeSettings(array $settings, bool $requireExamPeriods = true): array
    {
        $periods = collect($settings['periods'] ?? [])
            ->filter(fn (array $period): bool => filled($period['start_time'] ?? null) && filled($period['end_time'] ?? null))
            ->map(fn (array $period, int $index): array => [
                'key' => (string) ($period['key'] ?? $index),
                'name' => (string) ($period['name'] ?? 'الفترة '.($index + 1)),
                'start_time' => $this->timeString($period['start_time'] ?? null),
                'end_time' => $this->timeString($period['end_time'] ?? null),
                'period_type' => (string) ($period['period_type'] ?? $period['type'] ?? $this->periodTypeForIndex($index)),
            ])
            ->values()
            ->all();

        if ($periods === []) {
            if (! $requireExamPeriods) {
                $periods = [
                    ['key' => '0', 'name' => 'الفترة الأولى', 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'period_type' => 'morning'],
                ];
            } else {
                $this->throwGenerationFailure(
                    reasonCode: 'missing_exam_periods',
                    settings: $settings,
                );
            }
        }

        $this->validatePeriods($periods);
        $periods = $this->withDerivedPeriodTiming($periods);

        return [
            'faculty_id' => $settings['faculty_id'] ?? $settings['college_id'] ?? null,
            'academic_year_id' => filled($settings['academic_year_id'] ?? null) ? (int) $settings['academic_year_id'] : null,
            'semester_id' => filled($settings['semester_id'] ?? null) ? (int) $settings['semester_id'] : null,
            'study_level_id' => filled($settings['study_level_id'] ?? null) ? (int) $settings['study_level_id'] : null,
            'department_id' => filled($settings['department_id'] ?? null) ? (int) $settings['department_id'] : null,
            'previous_draft_id' => filled($settings['previous_draft_id'] ?? null) ? (int) $settings['previous_draft_id'] : null,
            'start_date' => Carbon::parse($settings['start_date'] ?? now())->toDateString(),
            'end_date' => Carbon::parse($settings['end_date'] ?? $settings['start_date'] ?? now())->toDateString(),
            'excluded_weekdays' => collect($settings['excluded_weekdays'] ?? [5, 6])->map(fn ($day): int => (int) $day)->unique()->values()->all(),
            'holidays' => collect($settings['holidays'] ?? [])
                ->filter(fn (array $holiday): bool => filled($holiday['date'] ?? null))
                ->mapWithKeys(fn (array $holiday): array => [
                    Carbon::parse($holiday['date'])->toDateString() => [
                        'date' => Carbon::parse($holiday['date'])->toDateString(),
                        'reason' => (string) ($holiday['reason'] ?? ''),
                    ],
                ])
                ->values()
                ->all(),
            'periods' => $periods,
            'prevent_same_day' => (bool) ($settings['prevent_same_day'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function loggableSettings(array $settings): array
    {
        return [
            'faculty_id' => $settings['faculty_id'] ?? null,
            'academic_year_id' => $settings['academic_year_id'] ?? null,
            'semester_id' => $settings['semester_id'] ?? null,
            'study_level_id' => $settings['study_level_id'] ?? null,
            'department_id' => $settings['department_id'] ?? null,
            'previous_draft_id' => $settings['previous_draft_id'] ?? null,
            'start_date' => $settings['start_date'] ?? null,
            'end_date' => $settings['end_date'] ?? null,
            'excluded_weekdays' => $settings['excluded_weekdays'] ?? [],
            'holidays_count' => count($settings['holidays'] ?? []),
            'periods_count' => count($settings['periods'] ?? []),
            'prevent_same_day' => (bool) ($settings['prevent_same_day'] ?? false),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $periods
     */
    protected function validatePeriods(array $periods): void
    {
        $periodsByStartTime = collect($periods)
            ->map(function (array $period, int $index): array {
                $start = Carbon::parse($period['start_time']);
                $end = Carbon::parse($period['end_time']);
                $name = filled($period['name'] ?? null) ? (string) $period['name'] : 'الفترة '.($index + 1);

                if ($end->lte($start)) {
                    throw ValidationException::withMessages([
                        "periods.{$index}.end_time" => "وقت نهاية {$name} يجب أن يكون بعد وقت البداية.",
                    ]);
                }

                return [
                    'index' => $index,
                    'name' => $name,
                    'start' => $start,
                    'end' => $end,
                ];
            })
            ->sortBy(fn (array $period): int => $this->timeInSeconds($period['start']))
            ->values();

        $previous = null;

        foreach ($periodsByStartTime as $period) {
            if ($previous && $period['start']->lt($previous['end'])) {
                throw ValidationException::withMessages([
                    "periods.{$period['index']}.start_time" => "{$period['name']} تتداخل مع {$previous['name']}. عدّل أوقات الفترات بحيث لا تتقاطع.",
                ]);
            }

            $previous = $period;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $periods
     * @return array<int, array<string, mixed>>
     */
    protected function withDerivedPeriodTiming(array $periods): array
    {
        $periodsByStartTime = collect($periods)
            ->sortBy(fn (array $period): int => $this->timeInSeconds(Carbon::parse($period['start_time'])))
            ->values();

        return $periodsByStartTime
            ->map(function (array $period, int $index) use ($periodsByStartTime): array {
                $start = Carbon::parse($period['start_time']);
                $end = Carbon::parse($period['end_time']);
                $next = $periodsByStartTime->get($index + 1);

                return $period + [
                    'duration_minutes' => (int) $start->diffInMinutes($end),
                    'break_after_minutes' => $next
                        ? (int) $end->diffInMinutes(Carbon::parse($next['start_time']))
                        : null,
                ];
            })
            ->all();
    }

    protected function timeInSeconds(CarbonInterface $time): int
    {
        return (((int) $time->format('H')) * 3600)
            + (((int) $time->format('i')) * 60)
            + ((int) $time->format('s'));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    protected function availableSlots(array $settings): array
    {
        $slots = [];

        foreach ($this->availableExamDays($settings) as $date) {
            foreach ($settings['periods'] as $period) {
                $slots[] = [
                    'key' => $date.'|'.$period['start_time'],
                    'date' => $date,
                    'start_time' => $period['start_time'],
                    'end_time' => $period['end_time'],
                    'period_type' => $period['period_type'],
                    'period_name' => $period['name'],
                    'date_time' => Carbon::parse($date.' '.$period['start_time']),
                ];
            }
        }

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, string>
     */
    protected function availableExamDays(array $settings): array
    {
        return collect(CarbonPeriod::create($settings['start_date'], $settings['end_date']))
            ->reject(fn (CarbonInterface $date): bool => $this->isExcludedDate($date, $settings))
            ->map(fn (CarbonInterface $date): string => $date->toDateString())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $slotLoads
     * @param  array<string, int>  $dayLoads
     * @param  array<string, array<int, array<string, mixed>>>  $academicAssignments
     * @param  array<string, array<int, array<string, mixed>>>  $studentAssignments
     * @return array<int>
     */
    protected function copyPinnedItemsFromPreviousDraft(
        ExamScheduleDraft $draft,
        array $settings,
        array &$slotLoads,
        array &$dayLoads,
        array &$academicAssignments,
        array &$studentAssignments,
    ): array {
        if (blank($settings['previous_draft_id'] ?? null)) {
            return [];
        }

        $previousDraft = ExamScheduleDraft::query()
            ->with(['items.sourceRoster', 'items.subject.department', 'items.subject.studyLevel'])
            ->whereKey($settings['previous_draft_id'])
            ->where('faculty_id', $settings['faculty_id'])
            ->where('academic_year_id', $settings['academic_year_id'])
            ->where('semester_id', $settings['semester_id'])
            ->first();

        if (! $previousDraft) {
            return [];
        }

        $pinnedRosterIds = [];

        foreach ($previousDraft->items as $item) {
            if (! (bool) (($item->metadata ?? [])['pinned'] ?? false)) {
                continue;
            }

            if (! $item->sourceRoster) {
                continue;
            }

            $metadata = array_merge($item->metadata ?? [], [
                'pinned' => true,
                'carried_from_draft_item_id' => $item->id,
                'carried_from_draft_id' => $previousDraft->id,
            ]);

            $copiedItem = $draft->items()->create([
                'source_roster_id' => $item->source_roster_id,
                'subject_id' => $item->subject_id,
                'department_id' => $item->department_id,
                'exam_date' => $item->exam_date?->toDateString(),
                'start_time' => $this->timeString($item->start_time),
                'end_time' => $this->timeString($item->end_time),
                'period_type' => $item->period_type,
                'student_count' => $item->student_count,
                'regular_count' => $item->regular_count,
                'carry_count' => $item->carry_count,
                'is_shared_subject' => $item->is_shared_subject,
                'is_core_subject' => $item->is_core_subject,
                'shared_group_key' => $item->shared_group_key,
                'status' => $item->status === 'unscheduled' ? 'manually_adjusted' : $item->status,
                'conflict_notes' => $item->conflict_notes,
                'metadata' => $metadata,
            ]);

            if (filled($item->source_roster_id)) {
                $pinnedRosterIds[] = (int) $item->source_roster_id;
            }

            $this->reservePinnedItemSlot($copiedItem, $slotLoads, $dayLoads, $academicAssignments, $studentAssignments);
        }

        return array_values(array_unique($pinnedRosterIds));
    }

    protected function deleteReplaceableDraftsForScope(array $settings, int $currentDraftId): void
    {
        $draftIds = ExamScheduleDraft::query()
            ->where('faculty_id', $settings['faculty_id'])
            ->where('academic_year_id', $settings['academic_year_id'])
            ->where('semester_id', $settings['semester_id'])
            ->whereKeyNot($currentDraftId)
            ->where('status', '<>', ExamScheduleDraft::STATUS_APPROVED)
            ->pluck('id');

        if ($draftIds->isEmpty()) {
            return;
        }

        SubjectExamOffering::query()
            ->whereIn('exam_schedule_draft_id', $draftIds)
            ->where('is_pinned', false)
            ->where('status', ExamOfferingStatus::Draft->value)
            ->delete();

        ExamScheduleDraft::query()
            ->whereKey($draftIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, int>  $slotLoads
     * @param  array<string, int>  $dayLoads
     * @param  array<string, array<int, array<string, mixed>>>  $academicAssignments
     * @param  array<string, array<int, array<string, mixed>>>  $studentAssignments
     * @return array<int>
     */
    protected function copyPinnedItemsFromOfferings(
        ExamScheduleDraft $draft,
        array $settings,
        array &$slotLoads,
        array &$dayLoads,
        array &$academicAssignments,
        array &$studentAssignments,
    ): array {
        $pinnedRosterIds = [];

        foreach ($this->pinnedOfferingsForSettings($settings)->get() as $offering) {
            $roster = $this->matchingReadyRosterForOffering($offering, $settings);
            $payload = $roster ? $this->subjectPayload($roster, $settings) : $this->subjectPayloadFromOffering($offering);
            $period = $this->periodFromSettings($settings, $offering->exam_start_time);
            $metadata = [
                'pinned' => true,
                'pinned_from_offering_id' => $offering->id,
                'period_name' => $period['name'] ?? null,
                'academic_group_key' => $payload['academic_group_key'],
                'shared_subject_scheduling_mode' => 'single',
                'student_numbers' => $payload['student_numbers_for_metadata'],
                'student_numbers_truncated' => $payload['student_numbers_truncated'],
                'student_numbers_count' => $payload['student_count'],
                'student_examples' => $payload['student_examples'],
                'preferred_exam_period' => $payload['preferred_exam_period'],
                'core_subject_priority' => $payload['core_subject_priority'],
            ];

            $item = $draft->items()->create([
                'source_roster_id' => $roster?->id,
                'subject_id' => $offering->subject_id,
                'department_id' => $payload['department_id'],
                'subject_exam_offering_id' => $offering->id,
                'exam_date' => $offering->exam_date?->toDateString(),
                'start_time' => $this->timeString($offering->exam_start_time),
                'end_time' => $period['end_time'] ?? null,
                'period_type' => $period['period_type'] ?? null,
                'student_count' => $payload['student_count'],
                'regular_count' => $payload['regular_count'],
                'carry_count' => $payload['carry_count'],
                'is_shared_subject' => (bool) $offering->subject?->is_shared_subject,
                'is_core_subject' => (bool) $offering->subject?->is_core_subject,
                'shared_group_key' => (bool) $offering->subject?->is_shared_subject ? $this->sharedGroupKey($offering->subject) : null,
                'status' => 'manually_adjusted',
                'metadata' => $metadata,
            ]);

            $offering->update(['exam_schedule_draft_id' => $draft->id]);

            if ($roster) {
                $pinnedRosterIds[] = (int) $roster->id;
            }

            $this->reservePinnedItemSlot($item, $slotLoads, $dayLoads, $academicAssignments, $studentAssignments);
        }

        return array_values(array_unique($pinnedRosterIds));
    }

    protected function hasPinnedOfferingsForSettings(array $settings): bool
    {
        return $this->pinnedOfferingsForSettings($settings)->exists();
    }

    protected function pinnedOfferingsForSettings(array $settings): Builder
    {
        return SubjectExamOffering::query()
            ->with(['subject.college', 'subject.department', 'subject.studyLevel', 'examStudents'])
            ->where('is_pinned', true)
            ->where('academic_year_id', $settings['academic_year_id'])
            ->where('semester_id', $settings['semester_id'])
            ->whereHas('subject', function (Builder $query) use ($settings): Builder {
                return $query
                    ->where('college_id', $settings['faculty_id'])
                    ->when($settings['department_id'], fn (Builder $query): Builder => $query->where('department_id', $settings['department_id']))
                    ->when($settings['study_level_id'], fn (Builder $query): Builder => $query->where('study_level_id', $settings['study_level_id']));
            })
            ->orderBy('exam_date')
            ->orderBy('exam_start_time')
            ->orderBy('subject_id');
    }

    protected function matchingReadyRosterForOffering(SubjectExamOffering $offering, array $settings): ?SubjectExamRoster
    {
        return SubjectExamRoster::query()
            ->with(['subject.college', 'subject.department', 'subject.studyLevel', 'college', 'department', 'studyLevel'])
            ->withCount([
                'eligibleRosterStudents as eligible_students_count',
                'eligibleRosterStudents as regular_students_count' => fn (Builder $query) => $query->where('student_type', ExamStudentType::Regular->value),
                'eligibleRosterStudents as carry_students_count' => fn (Builder $query) => $query->where('student_type', ExamStudentType::Carry->value),
            ])
            ->where('college_id', $settings['faculty_id'] ?? $offering->subject?->college_id)
            ->where('status', 'ready')
            ->where('subject_id', $offering->subject_id)
            ->where('academic_year_id', $settings['academic_year_id'] ?? $offering->academic_year_id)
            ->where('semester_id', $settings['semester_id'] ?? $offering->semester_id)
            ->when($settings['department_id'] ?? null, fn (Builder $query, int $departmentId): Builder => $query->where('department_id', $departmentId))
            ->when($settings['study_level_id'] ?? null, fn (Builder $query, int $studyLevelId): Builder => $query->where('study_level_id', $studyLevelId))
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function subjectPayloadFromOffering(SubjectExamOffering $offering): array
    {
        $offering->loadMissing(['subject.college', 'subject.department', 'subject.studyLevel', 'examStudents']);
        $studentNumbers = $offering->examStudents
            ->pluck('student_number')
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values();
        $studentCount = $studentNumbers->count();
        $storeStudentNumbers = $studentCount <= self::STUDENT_NUMBER_METADATA_LIMIT;

        return [
            'subject' => $offering->subject,
            'department_id' => $offering->subject?->department_id,
            'study_level_id' => $offering->subject?->study_level_id,
            'academic_group_key' => implode('|', [
                'department:'.($offering->subject?->department_id ?: 'none'),
                'level:'.($offering->subject?->study_level_id ?: 'none'),
            ]),
            'student_count' => $studentCount,
            'regular_count' => $offering->examStudents->where('student_type', ExamStudentType::Regular->value)->count(),
            'carry_count' => $offering->examStudents->where('student_type', ExamStudentType::Carry->value)->count(),
            'student_numbers' => $studentNumbers->all(),
            'student_numbers_for_metadata' => $storeStudentNumbers ? $studentNumbers->all() : [],
            'student_numbers_truncated' => ! $storeStudentNumbers,
            'student_examples' => $offering->examStudents
                ->take(5)
                ->map(fn (ExamStudent $student): string => $student->student_number.' - '.$student->full_name)
                ->values()
                ->all(),
            'is_core_subject' => (bool) $offering->subject?->is_core_subject,
            'preferred_exam_period' => (string) ($offering->subject?->preferred_exam_period ?: ((bool) $offering->subject?->is_core_subject ? 'morning' : 'none')),
            'core_subject_priority' => (string) ($offering->subject?->core_subject_priority ?: 'preference'),
        ];
    }

    /**
     * @param  array<string, int>  $slotLoads
     * @param  array<string, int>  $dayLoads
     * @param  array<string, array<int, array<string, mixed>>>  $academicAssignments
     * @param  array<string, array<int, array<string, mixed>>>  $studentAssignments
     */
    protected function reservePinnedItemSlot(
        ExamScheduleDraftItem $item,
        array &$slotLoads,
        array &$dayLoads,
        array &$academicAssignments,
        array &$studentAssignments,
    ): void {
        $date = $item->exam_date?->toDateString();
        $startTime = $this->timeString($item->start_time);

        if (blank($date) || blank($startTime)) {
            return;
        }

        $slotKey = $date.'|'.$startTime;
        $slotLoads[$slotKey] = ($slotLoads[$slotKey] ?? 0) + 1;
        $dayLoads[$date] = ($dayLoads[$date] ?? 0) + 1;

        $academicAssignments[$this->academicGroupKeyForItem($item)][] = [
            'date' => $date,
            'start_time' => $startTime,
            'shared_group_key' => $item->shared_group_key,
        ];

        foreach ($this->studentNumbersForItem($item) as $studentNumber) {
            $studentAssignments[$studentNumber][] = [
                'date' => $date,
                'start_time' => $startTime,
                'shared_group_key' => $item->shared_group_key,
            ];
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $units
     * @param  array<int>  $pinnedRosterIds
     * @return Collection<int, array<string, mixed>>
     */
    protected function withoutPinnedSubjects(Collection $units, array $pinnedRosterIds): Collection
    {
        if ($pinnedRosterIds === []) {
            return $units;
        }

        return $units
            ->map(function (array $unit) use ($pinnedRosterIds): ?array {
                $subjects = collect($unit['subjects'])
                    ->reject(fn (array $payload): bool => in_array((int) $payload['roster']->id, $pinnedRosterIds, true))
                    ->values();

                if ($subjects->isEmpty()) {
                    return null;
                }

                return $this->unitFromPayloads(
                    $subjects,
                    $unit['shared_group_key'] ?? null,
                    $unit['shared_subject_scheduling_mode'] ?? 'single',
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $units
     */
    protected function ensureUnitsHaveStudents(Collection $units, array $settings): void
    {
        $emptySubjects = $units
            ->flatMap(fn (array $unit): array => $unit['subjects'] ?? [])
            ->filter(fn (array $payload): bool => (int) ($payload['student_count'] ?? 0) <= 0)
            ->map(fn (array $payload): array => $this->subjectFailureDetailFromPayload($payload))
            ->values()
            ->all();

        if ($emptySubjects === []) {
            return;
        }

        $this->throwGenerationFailure(
            reasonCode: 'missing_student_data',
            settings: $settings,
            details: $emptySubjects,
            technicalDetails: ['empty_subjects_count' => count($emptySubjects)],
        );
    }

    protected function ensurePinnedItemsDoNotConflict(ExamScheduleDraft $draft, array $settings): void
    {
        $items = $draft->items()
            ->with(['subject.college', 'subject.department', 'department'])
            ->get()
            ->filter(fn (ExamScheduleDraftItem $item): bool => (bool) (($item->metadata ?? [])['pinned'] ?? false))
            ->values();

        $conflicts = [];

        for ($firstIndex = 0; $firstIndex < $items->count(); $firstIndex++) {
            for ($secondIndex = $firstIndex + 1; $secondIndex < $items->count(); $secondIndex++) {
                /** @var ExamScheduleDraftItem $first */
                $first = $items->get($firstIndex);
                /** @var ExamScheduleDraftItem $second */
                $second = $items->get($secondIndex);

                $firstDate = $first->exam_date?->toDateString();
                $secondDate = $second->exam_date?->toDateString();
                $firstTime = $this->timeString($first->start_time);
                $secondTime = $this->timeString($second->start_time);

                if (blank($firstDate) || blank($firstTime) || $firstDate !== $secondDate || $firstTime !== $secondTime) {
                    continue;
                }

                $sameAcademicGroup = $this->academicGroupKeyForItem($first) === $this->academicGroupKeyForItem($second);
                $sharedStudents = array_values(array_intersect(
                    $this->studentNumbersForItem($first),
                    $this->studentNumbersForItem($second),
                ));

                if (! $sameAcademicGroup && $sharedStudents === []) {
                    continue;
                }

                $conflicts[] = [
                    'first_subject' => $first->subject?->name,
                    'second_subject' => $second->subject?->name,
                    'subject' => $first->subject?->name,
                    'college' => $first->subject?->college?->name,
                    'department' => $first->department?->name ?? $first->subject?->department?->name,
                    'date' => $firstDate,
                    'time' => substr((string) $firstTime, 0, 5),
                    'reason' => $sameAcademicGroup ? 'same_academic_group_time' : 'same_student_time',
                    'conflicting_student_numbers' => $this->mergeLimitedStudentNumbers([], $sharedStudents, 20),
                    'conflicting_student_numbers_count' => count($sharedStudents),
                ];
            }
        }

        if ($conflicts === []) {
            return;
        }

        $this->throwGenerationFailure(
            reasonCode: 'pinned_conflict',
            settings: $settings,
            details: $conflicts,
            draftId: $draft->id,
            technicalDetails: ['conflicts' => $conflicts],
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildSchedulingUnits(array $settings): Collection
    {
        Log::info('Exam schedule draft generation: buildSchedulingUnits entered.', [
            'user_id' => auth()->id(),
            'settings' => $this->loggableSettings($settings),
        ]);

        try {
            Log::info('Exam schedule draft generation: before ready rosters query.', [
                'user_id' => auth()->id(),
                'college_id' => $settings['faculty_id'] ?? null,
                'academic_year_id' => $settings['academic_year_id'] ?? null,
                'semester_id' => $settings['semester_id'] ?? null,
                'department_id' => $settings['department_id'] ?? null,
                'study_level_id' => $settings['study_level_id'] ?? null,
            ]);

            $rosters = SubjectExamRoster::query()
                ->with(['subject.college', 'subject.department', 'subject.studyLevel', 'college', 'department', 'studyLevel'])
                ->withCount([
                    'eligibleRosterStudents as eligible_students_count',
                    'eligibleRosterStudents as regular_students_count' => fn (Builder $query) => $query->where('student_type', ExamStudentType::Regular->value),
                    'eligibleRosterStudents as carry_students_count' => fn (Builder $query) => $query->where('student_type', ExamStudentType::Carry->value),
                ])
                ->where('college_id', $settings['faculty_id'])
                ->where('status', 'ready')
                ->where('academic_year_id', $settings['academic_year_id'])
                ->where('semester_id', $settings['semester_id'])
                ->whereHas('subject', fn (Builder $query) => $query->where('is_active', true))
                ->when($settings['department_id'], fn (Builder $query) => $query->where('department_id', $settings['department_id']))
                ->when($settings['study_level_id'], fn (Builder $query) => $query->where('study_level_id', $settings['study_level_id']))
                ->orderBy('department_id')
                ->orderBy('study_level_id')
                ->orderBy('subject_id')
                ->get();

            Log::info('Exam schedule draft generation: ready rosters fetched.', [
                'user_id' => auth()->id(),
                'rosters_count' => $rosters->count(),
                'eligible_students_count' => (int) $rosters->sum('eligible_students_count'),
            ]);

            if ($rosters->isEmpty() && ! $this->hasPinnedOfferingsForSettings($settings)) {
                throw ValidationException::withMessages([
                    'rosters' => 'لا توجد قوائم مواد جاهزة ضمن الكلية والعام والفصل المحددين.',
                ]);
            }

            $rosters->each(function (SubjectExamRoster $roster): void {
                Log::info('Exam schedule draft generation: ready roster row.', [
                    'user_id' => auth()->id(),
                    'roster_id' => $roster->id,
                    'subject_id' => $roster->subject_id,
                    'department_id' => $roster->department_id,
                    'study_level_id' => $roster->study_level_id,
                    'students_count' => (int) ($roster->eligible_students_count ?? 0),
                    'has_subject' => $roster->subject !== null,
                    'has_department' => $roster->department !== null,
                    'has_study_level' => $roster->studyLevel !== null,
                ]);
            });

            $this->validateRosterRelations($rosters);

            $subjectPayloads = $rosters->map(fn (SubjectExamRoster $roster): array => $this->subjectPayload($roster, $settings));
            $units = collect();
            $handledSubjectIds = [];

            $sharedGroups = $subjectPayloads
                ->filter(fn (array $payload): bool => (bool) $payload['subject']->is_shared_subject)
                ->groupBy(fn (array $payload): string => $this->sharedGroupKey($payload['subject']));

            foreach ($sharedGroups as $groupKey => $payloads) {
                $mode = $this->sharedSchedulingMode($payloads);
                Log::info('Exam schedule draft generation: shared subject group.', [
                    'user_id' => auth()->id(),
                    'group_key' => $groupKey,
                    'mode' => $mode,
                    'payloads_count' => $payloads->count(),
                    'subject_ids' => $payloads->pluck('subject.id')->values()->all(),
                    'students_count' => (int) $payloads->sum('student_count'),
                ]);

                if ($mode === 'all_departments_together' || $mode === 'auto') {
                    $units->push($this->unitFromPayloads($payloads, $groupKey, $mode));
                    $handledSubjectIds = array_merge($handledSubjectIds, $payloads->pluck('subject.id')->all());

                    continue;
                }

                foreach ($payloads as $payload) {
                    Log::info('Exam schedule draft generation: separate shared roster unit.', [
                        'user_id' => auth()->id(),
                        'roster_id' => $payload['roster']->id,
                        'subject_id' => $payload['subject']->id,
                        'students_count' => $payload['student_count'],
                    ]);
                    $units->push($this->unitFromPayloads(collect([$payload]), $groupKey, $mode));
                    $handledSubjectIds[] = $payload['subject']->id;
                }
            }

            foreach ($subjectPayloads->reject(fn (array $payload): bool => in_array($payload['subject']->id, $handledSubjectIds, true)) as $payload) {
                Log::info('Exam schedule draft generation: regular roster unit.', [
                    'user_id' => auth()->id(),
                    'roster_id' => $payload['roster']->id,
                    'subject_id' => $payload['subject']->id,
                    'students_count' => $payload['student_count'],
                ]);
                $units->push($this->unitFromPayloads(collect([$payload]), null, 'single'));
            }

            Log::info('Exam schedule draft generation: buildSchedulingUnits returning.', [
                'user_id' => auth()->id(),
                'units_count' => $units->count(),
                'subjects_count' => $units->sum(fn (array $unit): int => count($unit['subjects'] ?? [])),
            ]);

            return $units;
        } catch (\Throwable $exception) {
            Log::error('Exam schedule draft generation: buildSchedulingUnits failed.', [
                'user_id' => auth()->id(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  Collection<int, SubjectExamRoster>  $rosters
     */
    protected function validateRosterRelations(Collection $rosters): void
    {
        $invalidRows = $rosters
            ->filter(function (SubjectExamRoster $roster): bool {
                return $roster->subject === null
                    || ($roster->department === null && $roster->subject?->department === null)
                    || ($roster->studyLevel === null && $roster->subject?->studyLevel === null);
            })
            ->map(fn (SubjectExamRoster $roster): array => [
                'roster_id' => $roster->id,
                'subject_id' => $roster->subject_id,
                'has_subject' => $roster->subject !== null,
                'has_department' => $roster->department !== null || $roster->subject?->department !== null,
                'has_study_level' => $roster->studyLevel !== null || $roster->subject?->studyLevel !== null,
            ])
            ->values();

        if ($invalidRows->isEmpty()) {
            return;
        }

        Log::warning('Exam schedule draft generation: ready rosters have missing relations.', [
            'user_id' => auth()->id(),
            'invalid_rosters' => $invalidRows->all(),
        ]);

        throw ValidationException::withMessages([
            'rosters' => 'توجد قوائم مواد جاهزة لكن بيانات المادة أو القسم أو السنة الدراسية ناقصة. راجع القوائم والمواد المرتبطة بها.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function subjectPayload(SubjectExamRoster $roster, array $settings): array
    {
        $subject = $roster->subject;
        Log::info('Exam schedule draft generation: before building roster student numbers.', [
            'user_id' => auth()->id(),
            'roster_id' => $roster->id,
            'subject_id' => $roster->subject_id,
            'students_count' => (int) ($roster->eligible_students_count ?? 0),
        ]);

        $studentNumbers = $roster->eligibleRosterStudents()
            ->orderBy('student_number')
            ->pluck('student_number')
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values();
        $studentCount = $studentNumbers->count();
        $storeStudentNumbers = $studentCount <= self::STUDENT_NUMBER_METADATA_LIMIT;

        Log::info('Exam schedule draft generation: roster student numbers built.', [
            'user_id' => auth()->id(),
            'roster_id' => $roster->id,
            'subject_id' => $roster->subject_id,
            'student_numbers_count' => $studentCount,
            'stored_in_metadata' => $storeStudentNumbers,
        ]);

        return [
            'roster' => $roster,
            'subject' => $subject,
            'department_id' => $roster->department_id ?: $subject->department_id,
            'study_level_id' => $roster->study_level_id ?: $subject->study_level_id,
            'academic_group_key' => $this->academicGroupKeyForRoster($roster),
            'student_count' => $studentCount,
            'regular_count' => (int) ($roster->regular_students_count ?? 0),
            'carry_count' => (int) ($roster->carry_students_count ?? 0),
            'student_numbers' => $studentNumbers->all(),
            'student_numbers_for_metadata' => $storeStudentNumbers ? $studentNumbers->all() : [],
            'student_numbers_truncated' => ! $storeStudentNumbers,
            'student_examples' => $roster->eligibleRosterStudents()
                ->orderBy('student_number')
                ->limit(5)
                ->get(['student_number', 'full_name'])
                ->map(fn ($student): string => $student->student_number.' - '.$student->full_name)
                ->values()
                ->all(),
            'is_core_subject' => (bool) $subject->is_core_subject,
            'preferred_exam_period' => (string) ($subject->preferred_exam_period ?: ((bool) $subject->is_core_subject ? 'morning' : 'none')),
            'core_subject_priority' => (string) ($subject->core_subject_priority ?: 'preference'),
        ];
    }

    protected function sharedGroupKey(mixed $subject): string
    {
        $key = filled($subject->code) ? $subject->code : $subject->name;
        $slug = Str::slug(Str::lower((string) $key));

        return 'shared:'.($slug ?: md5((string) $key));
    }

    protected function sharedSchedulingMode(Collection $payloads): string
    {
        $modes = $payloads
            ->pluck('subject.shared_subject_scheduling_mode')
            ->filter()
            ->unique()
            ->values();

        if ($modes->contains('all_departments_together')) {
            return 'all_departments_together';
        }

        if ($modes->contains('separate_departments')) {
            return 'separate_departments';
        }

        return 'auto';
    }

    protected function unitFromPayloads(Collection $payloads, ?string $groupKey, string $sharedSubjectSchedulingMode): array
    {
        $isShared = $payloads->contains(fn (array $payload): bool => (bool) $payload['subject']->is_shared_subject);
        $academicGroupKeys = $payloads->pluck('academic_group_key')->unique()->values();
        $studentNumbers = $payloads
            ->flatMap(fn (array $payload): array => $payload['student_numbers'])
            ->unique()
            ->values();

        return [
            'subjects' => $payloads->values()->all(),
            'academic_group_keys' => $academicGroupKeys->all(),
            'student_numbers' => $studentNumbers->all(),
            'is_shared_subject' => $isShared,
            'shared_group_key' => $isShared ? $groupKey : null,
            'shared_subject_scheduling_mode' => $sharedSubjectSchedulingMode,
        ];
    }

    protected function chooseSlot(array $unit, array $slots, array $slotLoads, array $dayLoads, array $academicAssignments, array $studentAssignments, array $settings): array
    {
        $candidates = [];
        $diagnostics = $this->initialSlotDiagnostics($slots);

        if ($slots === []) {
            return $this->slotFailureResult('no_available_slots', $diagnostics);
        }

        if (collect($unit['subjects'] ?? [])->contains(fn (array $payload): bool => (int) ($payload['student_count'] ?? 0) <= 0)) {
            return $this->slotFailureResult('missing_student_data', $diagnostics);
        }

        foreach ($slots as $slot) {
            $isBlocked = false;
            $academicConflict = $this->academicHardConflictDetails($unit, $slot, $academicAssignments, $settings);
            $studentConflict = $this->studentHardConflictDetails($unit, $slot, $studentAssignments, $settings);
            $preferredPeriodBlocked = $this->hasStrictCorePeriodConflict($unit, $slot);

            if ($academicConflict['blocked']) {
                $diagnostics['academic_conflict_slots_count']++;
                $isBlocked = true;
            }

            if ($studentConflict['blocked']) {
                $diagnostics['student_conflict_slots_count']++;
                $diagnostics['sample_conflicting_student_numbers'] = $this->mergeLimitedStudentNumbers(
                    $diagnostics['sample_conflicting_student_numbers'],
                    $studentConflict['student_numbers'],
                );
                $diagnostics['conflicting_student_numbers_count'] = count(array_unique(array_merge(
                    $diagnostics['all_conflicting_student_numbers'],
                    $studentConflict['student_numbers'],
                )));
                $diagnostics['all_conflicting_student_numbers'] = array_values(array_unique(array_merge(
                    $diagnostics['all_conflicting_student_numbers'],
                    $studentConflict['student_numbers'],
                )));
                $isBlocked = true;
            }

            if ($preferredPeriodBlocked) {
                $diagnostics['preferred_period_blocked_count']++;
                $isBlocked = true;
            }

            if ($isBlocked) {
                $diagnostics['blocked_slots_count']++;

                continue;
            }

            $score = ($slotLoads[$slot['key']] ?? 0) * 2
                + ($dayLoads[$slot['date']] ?? 0)
                + $this->sharedSubjectSeparationPenalty($unit, $slot, $academicAssignments)
                + $this->coreSubjectPeriodPenalty($unit, $slot);

            $candidates[] = $slot + [
                'score' => $score,
            ];
        }

        if ($candidates === []) {
            return $this->slotFailureResult($this->resolveSlotFailureReason($diagnostics), $diagnostics);
        }

        usort($candidates, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return [
            'slot' => $candidates[0],
            'failure_reason_code' => null,
            'failure_reason' => null,
            'diagnostics' => $this->publicSlotDiagnostics($diagnostics),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function initialSlotDiagnostics(array $slots): array
    {
        return [
            'attempted_slots_count' => count($slots),
            'blocked_slots_count' => 0,
            'student_conflict_slots_count' => 0,
            'academic_conflict_slots_count' => 0,
            'preferred_period_blocked_count' => 0,
            'max_daily_load_reached_count' => 0,
            'sample_conflicting_student_numbers' => [],
            'all_conflicting_student_numbers' => [],
            'conflicting_student_numbers_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function slotFailureResult(string $reasonCode, array $diagnostics): array
    {
        if ($reasonCode === 'unknown') {
            Log::warning('Exam schedule slot failure reason is unknown.', [
                'user_id' => auth()->id(),
                'diagnostics' => $this->publicSlotDiagnostics($diagnostics),
            ]);
        }

        return [
            'slot' => null,
            'failure_reason_code' => $reasonCode,
            'failure_reason' => $this->unscheduledReason($reasonCode),
            'suggested_action' => $this->unscheduledSuggestedAction($reasonCode),
            'diagnostics' => $this->publicSlotDiagnostics($diagnostics),
        ];
    }

    protected function resolveSlotFailureReason(array $diagnostics): string
    {
        $attemptedSlots = (int) ($diagnostics['attempted_slots_count'] ?? 0);

        if ($attemptedSlots <= 0) {
            return 'no_available_slots';
        }

        $fullBlockers = [
            'student_conflict' => (int) ($diagnostics['student_conflict_slots_count'] ?? 0),
            'academic_group_conflict' => (int) ($diagnostics['academic_conflict_slots_count'] ?? 0),
            'preferred_period_constraint' => (int) ($diagnostics['preferred_period_blocked_count'] ?? 0),
            'max_daily_load_reached' => (int) ($diagnostics['max_daily_load_reached_count'] ?? 0),
        ];

        foreach ($fullBlockers as $reasonCode => $count) {
            if ($count >= $attemptedSlots) {
                return $reasonCode;
            }
        }

        arsort($fullBlockers);
        $reasonCode = array_key_first($fullBlockers);

        return ($reasonCode && $fullBlockers[$reasonCode] > 0) ? $reasonCode : 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    protected function publicSlotDiagnostics(array $diagnostics): array
    {
        unset($diagnostics['all_conflicting_student_numbers']);

        return $diagnostics;
    }

    /**
     * @param  array<int, string>  $current
     * @param  array<int, string>  $new
     * @return array<int, string>
     */
    protected function mergeLimitedStudentNumbers(array $current, array $new, int $limit = 50): array
    {
        return collect($current)
            ->merge($new)
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array{blocked: bool, academic_group_keys: array<int, string>}
     */
    protected function academicHardConflictDetails(array $unit, array $slot, array $academicAssignments, array $settings): array
    {
        $conflictingGroups = [];

        foreach ($unit['academic_group_keys'] as $academicGroupKey) {
            foreach ($academicAssignments[$academicGroupKey] ?? [] as $assignment) {
                $sameSlot = $assignment['date'] === $slot['date'] && $assignment['start_time'] === $slot['start_time'];
                $sameDay = (bool) ($settings['prevent_same_day'] ?? false) && $assignment['date'] === $slot['date'];

                if ($sameSlot || $sameDay) {
                    $conflictingGroups[] = (string) $academicGroupKey;
                    break;
                }
            }
        }

        return [
            'blocked' => $conflictingGroups !== [],
            'academic_group_keys' => array_values(array_unique($conflictingGroups)),
        ];
    }

    /**
     * @return array{blocked: bool, student_numbers: array<int, string>}
     */
    protected function studentHardConflictDetails(array $unit, array $slot, array $studentAssignments, array $settings): array
    {
        $conflictingStudentNumbers = [];

        foreach ($unit['student_numbers'] as $studentNumber) {
            foreach ($studentAssignments[$studentNumber] ?? [] as $assignment) {
                $sameSlot = $assignment['date'] === $slot['date'] && $assignment['start_time'] === $slot['start_time'];
                $sameDay = (bool) ($settings['prevent_same_day'] ?? false) && $assignment['date'] === $slot['date'];

                if ($sameSlot || $sameDay) {
                    $conflictingStudentNumbers[] = (string) $studentNumber;
                    break;
                }
            }
        }

        return [
            'blocked' => $conflictingStudentNumbers !== [],
            'student_numbers' => array_values(array_unique($conflictingStudentNumbers)),
        ];
    }

    protected function hasAcademicHardConflict(array $unit, array $slot, array $academicAssignments, array $settings): bool
    {
        foreach ($unit['academic_group_keys'] as $academicGroupKey) {
            foreach ($academicAssignments[$academicGroupKey] ?? [] as $assignment) {
                if ($assignment['date'] === $slot['date'] && $assignment['start_time'] === $slot['start_time']) {
                    return true;
                }

                if ((bool) ($settings['prevent_same_day'] ?? false) && $assignment['date'] === $slot['date']) {
                    return true;
                }

            }
        }

        return false;
    }

    protected function hasStudentHardConflict(array $unit, array $slot, array $studentAssignments, array $settings): bool
    {
        foreach ($unit['student_numbers'] as $studentNumber) {
            foreach ($studentAssignments[$studentNumber] ?? [] as $assignment) {
                if ($assignment['date'] === $slot['date'] && $assignment['start_time'] === $slot['start_time']) {
                    return true;
                }

                if ((bool) ($settings['prevent_same_day'] ?? false) && $assignment['date'] === $slot['date']) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function hasStrictCorePeriodConflict(array $unit, array $slot): bool
    {
        foreach ($unit['subjects'] as $payload) {
            if (! $payload['is_core_subject'] || ($payload['core_subject_priority'] ?? 'preference') !== 'strict') {
                continue;
            }

            $preferred = $payload['preferred_exam_period'] ?: 'morning';

            if ($preferred !== 'none' && $slot['period_type'] !== $preferred) {
                return true;
            }
        }

        return false;
    }

    protected function coreSubjectPeriodPenalty(array $unit, array $slot): int
    {
        $penalty = 0;

        foreach ($unit['subjects'] as $payload) {
            if (! $payload['is_core_subject']) {
                continue;
            }

            $preferred = $payload['preferred_exam_period'] ?: 'morning';

            if ($preferred === 'none' || $slot['period_type'] === $preferred) {
                continue;
            }

            $penalty += ($payload['core_subject_priority'] ?? 'preference') === 'enforce_if_possible' ? 500 : 25;
        }

        return $penalty;
    }

    protected function sharedSubjectSeparationPenalty(array $unit, array $slot, array $academicAssignments): int
    {
        if (($unit['shared_subject_scheduling_mode'] ?? null) !== 'separate_departments' || blank($unit['shared_group_key'] ?? null)) {
            return 0;
        }

        foreach ($academicAssignments as $assignments) {
            foreach ($assignments as $assignment) {
                if (($assignment['shared_group_key'] ?? null) === $unit['shared_group_key'] && $assignment['date'] === $slot['date']) {
                    return 1000;
                }
            }
        }

        return 0;
    }

    protected function createUnscheduledItems(ExamScheduleDraft $draft, array $unit, array $failureDiagnostics): void
    {
        $reasonCode = (string) ($failureDiagnostics['failure_reason_code'] ?? 'unknown');
        $reason = (string) ($failureDiagnostics['failure_reason'] ?? $this->unscheduledReason($reasonCode));
        $suggestedAction = (string) ($failureDiagnostics['suggested_action'] ?? $this->unscheduledSuggestedAction($reasonCode));
        $diagnostics = $failureDiagnostics['diagnostics'] ?? [];
        $conflictNotes = $this->unscheduledDetailsFromMetadata([
            'unscheduled_reason_code' => $reasonCode,
            'unscheduled_reason' => $reason,
            'unscheduled_suggested_action' => $suggestedAction,
            'attempted_slots_count' => $diagnostics['attempted_slots_count'] ?? null,
            'blocked_slots_count' => $diagnostics['blocked_slots_count'] ?? null,
            'student_conflict_slots_count' => $diagnostics['student_conflict_slots_count'] ?? null,
            'academic_conflict_slots_count' => $diagnostics['academic_conflict_slots_count'] ?? null,
            'preferred_period_blocked_count' => $diagnostics['preferred_period_blocked_count'] ?? null,
            'max_daily_load_reached_count' => $diagnostics['max_daily_load_reached_count'] ?? null,
            'sample_conflicting_student_numbers' => $diagnostics['sample_conflicting_student_numbers'] ?? [],
            'conflicting_student_numbers_count' => $diagnostics['conflicting_student_numbers_count'] ?? null,
        ]);

        foreach ($unit['subjects'] as $subjectPayload) {
            $draft->items()->create([
                'source_roster_id' => $subjectPayload['roster']->id,
                'subject_id' => $subjectPayload['subject']->id,
                'department_id' => $subjectPayload['department_id'],
                'student_count' => $subjectPayload['student_count'],
                'regular_count' => $subjectPayload['regular_count'],
                'carry_count' => $subjectPayload['carry_count'],
                'is_shared_subject' => $unit['is_shared_subject'],
                'is_core_subject' => $subjectPayload['is_core_subject'],
                'shared_group_key' => $unit['shared_group_key'],
                'status' => 'unscheduled',
                'conflict_notes' => $conflictNotes,
                'metadata' => [
                    'academic_group_key' => $subjectPayload['academic_group_key'],
                    'shared_subject_scheduling_mode' => $unit['shared_subject_scheduling_mode'],
                    'student_numbers' => $subjectPayload['student_numbers_for_metadata'],
                    'student_numbers_truncated' => $subjectPayload['student_numbers_truncated'],
                    'student_numbers_count' => $subjectPayload['student_count'],
                    'student_examples' => $subjectPayload['student_examples'],
                    'preferred_exam_period' => $subjectPayload['preferred_exam_period'],
                    'core_subject_priority' => $subjectPayload['core_subject_priority'],
                    'unscheduled_reason_code' => $reasonCode,
                    'unscheduled_reason' => $reason,
                    'unscheduled_suggested_action' => $suggestedAction,
                    'attempted_slots_count' => $diagnostics['attempted_slots_count'] ?? null,
                    'blocked_slots_count' => $diagnostics['blocked_slots_count'] ?? null,
                    'student_conflict_slots_count' => $diagnostics['student_conflict_slots_count'] ?? null,
                    'academic_conflict_slots_count' => $diagnostics['academic_conflict_slots_count'] ?? null,
                    'preferred_period_blocked_count' => $diagnostics['preferred_period_blocked_count'] ?? null,
                    'max_daily_load_reached_count' => $diagnostics['max_daily_load_reached_count'] ?? null,
                    'sample_conflicting_student_numbers' => $diagnostics['sample_conflicting_student_numbers'] ?? [],
                    'conflicting_student_numbers_count' => $diagnostics['conflicting_student_numbers_count'] ?? null,
                ],
            ]);
        }
    }

    protected function throwGenerationFailureForUnit(array $settings, ExamScheduleDraft $draft, array $unit, array $choiceResult): never
    {
        $reasonCode = (string) ($choiceResult['failure_reason_code'] ?? 'unknown');
        $details = collect($unit['subjects'] ?? [])
            ->map(function (array $payload) use ($choiceResult, $reasonCode): array {
                return $this->subjectFailureDetailFromPayload($payload) + [
                    'reason_code' => $reasonCode,
                    'attempted_slots_count' => $choiceResult['diagnostics']['attempted_slots_count'] ?? null,
                    'blocked_slots_count' => $choiceResult['diagnostics']['blocked_slots_count'] ?? null,
                    'student_conflict_slots_count' => $choiceResult['diagnostics']['student_conflict_slots_count'] ?? null,
                    'academic_conflict_slots_count' => $choiceResult['diagnostics']['academic_conflict_slots_count'] ?? null,
                    'conflicting_student_numbers' => $choiceResult['diagnostics']['sample_conflicting_student_numbers'] ?? [],
                    'conflicting_student_numbers_count' => $choiceResult['diagnostics']['conflicting_student_numbers_count'] ?? null,
                ];
            })
            ->values()
            ->all();

        $this->throwGenerationFailure(
            reasonCode: $reasonCode,
            settings: $settings,
            details: $details,
            draftId: $draft->id,
            technicalDetails: [
                'unit_key' => $unit['shared_group_key'] ?? null,
                'diagnostics' => $choiceResult['diagnostics'] ?? [],
            ],
        );
    }

    protected function throwGenerationFailureForValidation(array $settings, ExamScheduleDraft $draft, array $validation): never
    {
        $conflicts = collect($validation['conflicts'] ?? [])
            ->where('hard', true)
            ->values();
        $reasonCode = $conflicts->contains(fn (array $conflict): bool => (bool) ($conflict['pinned'] ?? false))
            ? 'pinned_conflict'
            : (string) ($conflicts->first()['type'] ?? 'unknown');

        $this->throwGenerationFailure(
            reasonCode: $reasonCode,
            settings: $settings,
            details: $conflicts->take(10)->all(),
            draftId: $draft->id,
            technicalDetails: [
                'summary' => $validation['summary'] ?? [],
                'hard_conflicts_count' => $validation['hard_conflicts_count'] ?? null,
                'conflicts' => $conflicts->all(),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @param  array<string, mixed>  $technicalDetails
     */
    protected function throwGenerationFailure(
        string $reasonCode,
        array $settings,
        array $details = [],
        ?int $draftId = null,
        array $technicalDetails = [],
        ?\Throwable $previous = null,
    ): never {
        $message = $this->generationFailureUserMessage($reasonCode);
        $logContext = [
            'user_id' => auth()->id(),
            'college_id' => $settings['faculty_id'] ?? null,
            'academic_year_id' => $settings['academic_year_id'] ?? null,
            'semester_id' => $settings['semester_id'] ?? null,
            'draft_id' => $draftId,
            'reason_code' => $reasonCode,
            'details' => $details,
            'technical_details' => $technicalDetails,
        ];

        Log::warning('Exam schedule draft generation stopped with user-facing failure.', $logContext);

        throw new ExamScheduleGenerationException(
            reasonCode: $reasonCode,
            userTitle: 'تعذر توليد البرنامج الامتحاني',
            userMessage: $message,
            details: $details,
            logContext: $logContext,
            technicalMessage: 'Exam schedule generation failed: '.$reasonCode,
            previous: $previous,
        );
    }

    protected function generationFailureUserMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'missing_student_data' => 'توجد مادة امتحانية بدون طلاب. يرجى إضافة الطلاب أو حذف المادة من البرنامج.',
            'no_available_slots' => 'لا توجد أيام أو فترات كافية لتوزيع جميع المواد.',
            'pinned_conflict' => 'توجد مادة مثبتة تتعارض مع مادة أخرى في نفس الموعد.',
            'missing_exam_periods' => 'لا توجد فترات امتحانية معرفة في الإعدادات.',
            'missing_exam_days' => 'لا توجد أيام امتحانية متاحة للتوليد.',
            'draft_validation_failed' => 'تم توليد المسودة مبدئيًا، لكن فشل التحقق من صحتها. لم يتم حفظ أي مسودة ناقصة، يرجى المحاولة مرة أخرى أو التواصل مع الدعم الفني.',
            'student_conflict', 'same_student_time', 'same_student_day' => 'تعذر توليد البرنامج الامتحاني لأن بعض الطلاب لديهم تعارض في جميع المواعيد المتاحة.',
            'academic_group_conflict', 'same_academic_group_time', 'same_academic_group_day' => 'تعذر توليد البرنامج الامتحاني بسبب تعارض مواد من نفس القسم أو السنة في المواعيد المتاحة.',
            'preferred_period_constraint', 'core_subject_strict_period' => 'تعذر توليد البرنامج الامتحاني لأن بعض المواد مقيدة بفترة محددة ولا توجد فترة مناسبة لها.',
            default => 'حدث خطأ غير متوقع أثناء توليد البرنامج الامتحاني. لم يتم حفظ أي مسودة ناقصة، يرجى المحاولة مرة أخرى أو التواصل مع الدعم الفني.',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function subjectFailureDetailFromPayload(array $payload): array
    {
        /** @var SubjectExamRoster|null $roster */
        $roster = $payload['roster'] ?? null;
        $subject = $payload['subject'] ?? null;

        return [
            'subject_id' => $subject?->id,
            'subject' => $subject?->name,
            'college' => $subject?->college?->name ?? $roster?->college?->name,
            'college_id' => $subject?->college_id ?? $roster?->college_id,
            'department' => $roster?->department?->name ?? $subject?->department?->name,
            'department_id' => $payload['department_id'] ?? $roster?->department_id ?? $subject?->department_id,
            'roster_id' => $roster?->id,
            'roster_name' => $roster?->name,
            'student_count' => (int) ($payload['student_count'] ?? 0),
        ];
    }

    protected function isExcludedDate(CarbonInterface $date, array $settings): bool
    {
        if (in_array((int) $date->dayOfWeek, $settings['excluded_weekdays'] ?? [], true)) {
            return true;
        }

        return collect($settings['holidays'] ?? [])
            ->contains(fn (array $holiday): bool => ($holiday['date'] ?? null) === $date->toDateString());
    }

    protected function unscheduledReason(string $reasonCode): string
    {
        return match ($reasonCode) {
            'no_available_slots' => 'لا توجد أي فترات متاحة ضمن المدة المحددة بعد استبعاد العطل والأيام المستبعدة.',
            'student_conflict' => 'تعذر إيجاد موعد لا يسبب تعارضاً للطلاب المسجلين في هذه المادة.',
            'academic_group_conflict' => 'كل الفترات الممكنة تسبب تعارضاً لنفس القسم أو السنة أو المجموعة الأكاديمية.',
            'preferred_period_constraint' => 'المادة مقيدة بفترة امتحانية محددة، ولم توجد فترة مناسبة لها.',
            'max_daily_load_reached' => 'تم الوصول إلى الحد الأقصى للمواد في اليوم أو الفترة.',
            'missing_student_data' => 'لا توجد أرقام طلاب صالحة للمادة أو أن القائمة غير مكتملة.',
            'missing_roster_data' => 'بيانات القائمة ناقصة: مادة، قسم، سنة، أو عدد طلاب.',
            default => 'تعذر تحديد سبب عدم الجدولة بدقة. راجع سجل النظام.',
        };
    }

    protected function unscheduledSuggestedAction(string $reasonCode): string
    {
        return match ($reasonCode) {
            'student_conflict' => 'غيّر موعد إحدى المواد المشتركة أو اسمح بتوليد أوسع أو راجع الطلاب المشتركين.',
            'no_available_slots' => 'زد عدد أيام الامتحان أو أضف فترة امتحانية جديدة.',
            'preferred_period_constraint' => 'خفف إلزام الفترة المفضلة أو اسمح بفترة بديلة.',
            'academic_group_conflict' => 'راجع قيود القسم/السنة أو اسمح بتباعد أقل بين مواد نفس المجموعة.',
            'missing_student_data' => 'راجع ملف الطلاب أو تأكد أن قائمة المادة جاهزة وفيها طلاب مؤهلون.',
            'missing_roster_data' => 'راجع بيانات القائمة وتأكد من ربط المادة والقسم والسنة وعدد الطلاب.',
            'max_daily_load_reached' => 'زد الطاقة اليومية أو أضف أياماً وفترات إضافية.',
            default => 'راجع إعدادات التوليد وسجل النظام ثم أعد المحاولة.',
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function unscheduledDetailsFromMetadata(array $metadata): string
    {
        $reasonCode = (string) ($metadata['unscheduled_reason_code'] ?? 'unknown');
        $reason = (string) ($metadata['unscheduled_reason'] ?? $this->unscheduledReason($reasonCode));
        $parts = ['لم يتم جدولتها: '.$reason];

        if (array_key_exists('attempted_slots_count', $metadata) && $metadata['attempted_slots_count'] !== null) {
            $parts[] = 'عدد الفترات المجربة: '.(int) $metadata['attempted_slots_count'].'.';
        }

        if (array_key_exists('blocked_slots_count', $metadata) && $metadata['blocked_slots_count'] !== null) {
            $parts[] = 'عدد الفترات المرفوضة: '.(int) $metadata['blocked_slots_count'].'.';
        }

        $studentNumbers = $this->sampleConflictingStudentNumbersFromMetadata($metadata);

        if ($studentNumbers !== []) {
            $parts[] = 'طلاب متأثرون: '.$this->formatConflictStudentNumbers(
                $studentNumbers,
                (int) ($metadata['conflicting_student_numbers_count'] ?? count($studentNumbers)),
            ).'.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    protected function sampleConflictingStudentNumbersFromMetadata(array $metadata): array
    {
        return collect($metadata['sample_conflicting_student_numbers'] ?? [])
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function unscheduledItemSummary(ExamScheduleDraftItem $item): array
    {
        $metadata = $item->metadata ?? [];
        $reasonCode = (string) ($metadata['unscheduled_reason_code'] ?? 'unknown');
        $studentNumbers = $this->sampleConflictingStudentNumbersFromMetadata($metadata);

        return [
            'item_id' => $item->id,
            'subject' => $item->subject?->name,
            'department' => $item->department?->name ?? $item->subject?->department?->name,
            'student_count' => $item->student_count,
            'reason_code' => $reasonCode,
            'reason' => $metadata['unscheduled_reason'] ?? $this->unscheduledReason($reasonCode),
            'details' => $this->unscheduledDetailsFromMetadata($metadata),
            'attempted_slots_count' => $metadata['attempted_slots_count'] ?? null,
            'blocked_slots_count' => $metadata['blocked_slots_count'] ?? null,
            'student_conflict_slots_count' => $metadata['student_conflict_slots_count'] ?? null,
            'academic_conflict_slots_count' => $metadata['academic_conflict_slots_count'] ?? null,
            'conflicting_student_numbers' => $studentNumbers,
            'conflicting_student_numbers_label' => $this->formatConflictStudentNumbers(
                $studentNumbers,
                (int) ($metadata['conflicting_student_numbers_count'] ?? count($studentNumbers)),
            ),
            'suggested_action' => $metadata['unscheduled_suggested_action'] ?? $this->unscheduledSuggestedAction($reasonCode),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function conflictRow(
        ExamScheduleDraftItem $item,
        string $type,
        string $label,
        string $impact,
        string $suggestedAction,
        ?string $details = null,
        bool $hard = true,
        int $affectedStudents = 0,
        array $conflictingStudentNumbers = [],
    ): array {
        $conflictingStudentNumbers = collect($conflictingStudentNumbers)
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values()
            ->all();

        return [
            'item_id' => $item->id,
            'subject' => $item->subject?->name,
            'department' => $item->department?->name ?? $item->subject?->department?->name,
            'date' => $item->exam_date?->toDateString(),
            'time' => substr((string) $item->start_time, 0, 5),
            'type' => $type,
            'type_label' => $label,
            'impact' => $impact,
            'affected_students' => $affectedStudents,
            'conflicting_student_numbers' => $conflictingStudentNumbers,
            'student_numbers' => $conflictingStudentNumbers,
            'conflicting_student_numbers_label' => $this->formatConflictStudentNumbers($conflictingStudentNumbers, $affectedStudents),
            'details' => $details ?: $label,
            'suggested_action' => $suggestedAction,
            'hard' => $hard,
        ];
    }

    /**
     * @param  array<int, ExamScheduleDraftItem>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function studentConflictPairRows(
        ExamScheduleDraft $draft,
        string $studentNumber,
        array $items,
        string $conflictType,
        string $details,
        bool $skipSameSlotPairs = false,
    ): array {
        $rows = [];
        $items = collect($items)
            ->sortBy([
                ['exam_date', 'asc'],
                ['start_time', 'asc'],
                ['subject.name', 'asc'],
            ])
            ->values();

        for ($firstIndex = 0; $firstIndex < $items->count(); $firstIndex++) {
            for ($secondIndex = $firstIndex + 1; $secondIndex < $items->count(); $secondIndex++) {
                /** @var ExamScheduleDraftItem $first */
                $first = $items->get($firstIndex);
                /** @var ExamScheduleDraftItem $second */
                $second = $items->get($secondIndex);
                $firstDate = $first->exam_date?->toDateString();
                $firstTime = $this->timeString($first->start_time);
                $secondDate = $second->exam_date?->toDateString();
                $secondTime = $this->timeString($second->start_time);

                if ($skipSameSlotPairs && $firstDate === $secondDate && $firstTime === $secondTime) {
                    continue;
                }

                $rows[] = [
                    'student_number' => $studentNumber,
                    'conflict_date' => $firstDate ?: $secondDate,
                    'conflict_time' => $firstTime === $secondTime ? substr((string) $firstTime, 0, 5) : collect([$firstTime, $secondTime])
                        ->filter()
                        ->map(fn (string $time): string => substr($time, 0, 5))
                        ->implode(' / '),
                    'first_subject' => $first->subject?->name,
                    'first_department' => $first->department?->name ?? $first->subject?->department?->name,
                    'second_subject' => $second->subject?->name,
                    'second_department' => $second->department?->name ?? $second->subject?->department?->name,
                    'conflict_type' => $conflictType,
                    'draft_id' => $draft->id,
                    'details' => $details,
                ];
            }
        }

        return $rows;
    }

    protected function formatConflictStudentNumbers(array $studentNumbers, ?int $totalCount = null): string
    {
        $studentNumbers = collect($studentNumbers)
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values();

        $totalCount = $totalCount && $totalCount > 0 ? $totalCount : $studentNumbers->count();

        if ($studentNumbers->isEmpty() || $totalCount <= 0) {
            return '—';
        }

        $visibleNumbers = $studentNumbers->take(10);
        $visible = $visibleNumbers->implode(', ');
        $remaining = $totalCount - $visibleNumbers->count();

        if ($remaining <= 0) {
            return $visible;
        }

        return $visible.' + '.$remaining.' آخرين';
    }

    protected function academicGroupKeyForRoster(SubjectExamRoster $roster): string
    {
        return implode('|', [
            'department:'.($roster->department_id ?: $roster->subject?->department_id ?: 'none'),
            'level:'.($roster->study_level_id ?: $roster->subject?->study_level_id ?: 'none'),
        ]);
    }

    protected function academicGroupKeyForItem(ExamScheduleDraftItem $item): string
    {
        $metadata = $item->metadata ?? [];

        if (filled($metadata['academic_group_key'] ?? null)) {
            return (string) $metadata['academic_group_key'];
        }

        return implode('|', [
            'department:'.($item->department_id ?: $item->subject?->department_id ?: 'none'),
            'level:'.($item->subject?->study_level_id ?: 'none'),
        ]);
    }

    protected function syncValidationToDraft(ExamScheduleDraft $draft, array $validation): void
    {
        $notesByItem = collect($validation['conflicts'] ?? [])
            ->groupBy('item_id')
            ->map(fn (Collection $rows): string => $rows->pluck('details')->unique()->implode("\n"));

        foreach ($draft->items as $item) {
            $notes = $notesByItem->get($item->id);

            if ($item->status === 'unscheduled') {
                $item->update([
                    'conflict_notes' => $notes ?: $item->conflict_notes,
                ]);

                continue;
            }

            $item->update([
                'status' => filled($notes) ? 'conflict' : ($item->status === 'manually_adjusted' ? 'manually_adjusted' : 'scheduled'),
                'conflict_notes' => $notes,
            ]);
        }
    }

    protected function timeString(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        return strlen((string) $time) === 5 ? ((string) $time).':00' : substr((string) $time, 0, 8);
    }

    protected function periodTypeForIndex(int $index): string
    {
        return match ($index) {
            0 => 'morning',
            1 => 'mid_day',
            default => 'evening',
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function studentNumbersByItemForValidation(Collection $items): array
    {
        $numbersByItemId = [];
        $fallbackRosterIdsByItemId = [];

        foreach ($items as $item) {
            if (! $item instanceof ExamScheduleDraftItem) {
                continue;
            }

            $metadata = $item->metadata ?? [];
            $metadataNumbers = collect($metadata['student_numbers'] ?? [])
                ->filter()
                ->map(fn ($number): string => (string) $number)
                ->unique()
                ->values()
                ->all();

            if ($metadataNumbers !== [] && ! (bool) ($metadata['student_numbers_truncated'] ?? false)) {
                $numbersByItemId[$item->id] = $metadataNumbers;

                continue;
            }

            if (! $item->source_roster_id) {
                $numbersByItemId[$item->id] = $metadataNumbers;

                continue;
            }

            $fallbackRosterIdsByItemId[$item->id] = (int) $item->source_roster_id;
        }

        if ($fallbackRosterIdsByItemId === []) {
            return $numbersByItemId;
        }

        $numbersByRosterId = [];

        SubjectExamRosterStudent::query()
            ->whereIn('subject_exam_roster_id', array_values(array_unique($fallbackRosterIdsByItemId)))
            ->where('is_eligible', true)
            ->orderBy('subject_exam_roster_id')
            ->orderBy('student_number')
            ->get(['subject_exam_roster_id', 'student_number'])
            ->each(function (SubjectExamRosterStudent $student) use (&$numbersByRosterId): void {
                if (blank($student->student_number)) {
                    return;
                }

                $numbersByRosterId[(int) $student->subject_exam_roster_id][] = (string) $student->student_number;
            });

        foreach ($numbersByRosterId as $rosterId => $numbers) {
            $numbersByRosterId[$rosterId] = array_values(array_unique($numbers));
        }

        foreach ($fallbackRosterIdsByItemId as $itemId => $rosterId) {
            $numbersByItemId[$itemId] = $numbersByRosterId[$rosterId] ?? [];
        }

        return $numbersByItemId;
    }

    /**
     * @return array<int, string>
     */
    protected function studentNumbersForItem(ExamScheduleDraftItem $item): array
    {
        $metadata = $item->metadata ?? [];
        $metadataNumbers = collect($metadata['student_numbers'] ?? [])
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values();

        if ($metadataNumbers->isNotEmpty() && ! (bool) ($metadata['student_numbers_truncated'] ?? false)) {
            return $metadataNumbers->all();
        }

        if (! $item->source_roster_id) {
            return $metadataNumbers->all();
        }

        $sourceRoster = SubjectExamRoster::query()->find($item->source_roster_id);

        if (! $sourceRoster) {
            return $metadataNumbers->all();
        }

        return $sourceRoster
            ->eligibleRosterStudents()
            ->pluck('student_number')
            ->filter()
            ->map(fn ($number): string => (string) $number)
            ->unique()
            ->values()
            ->all();
    }

    protected function coreSubjectIsOutsidePreferredPeriod(ExamScheduleDraftItem $item): bool
    {
        $preferred = (string) (($item->metadata ?? [])['preferred_exam_period'] ?? 'morning');

        return $preferred !== 'none' && filled($item->period_type) && $item->period_type !== $preferred;
    }

    protected function studentExamplesForItems(Collection $items, array $studentNumbers): array
    {
        return $items
            ->flatMap(fn (ExamScheduleDraftItem $item): array => ($item->metadata ?? [])['student_examples'] ?? [])
            ->filter(fn (string $example): bool => collect($studentNumbers)->contains(fn (string $number): bool => str_starts_with($example, $number)))
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    protected function copyRosterStudentsToOffering(ExamScheduleDraftItem $item, SubjectExamOffering $offering): void
    {
        $item->loadMissing('sourceRoster.rosterStudents');

        foreach ($item->sourceRoster?->rosterStudents?->where('is_eligible', true) ?? [] as $student) {
            ExamStudent::query()->updateOrCreate(
                [
                    'subject_exam_offering_id' => $offering->id,
                    'student_number' => $student->student_number,
                ],
                [
                    'full_name' => $student->full_name,
                    'student_type' => $student->student_type,
                    'notes' => $student->notes,
                ],
            );
        }
    }
}
