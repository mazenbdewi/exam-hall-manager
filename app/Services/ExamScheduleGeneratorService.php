<?php

namespace App\Services;

use App\Enums\ExamOfferingStatus;
use App\Enums\ExamStudentType;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\ExamStudent;
use App\Models\SubjectExamOffering;
use App\Models\SubjectExamRoster;
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
                'status' => 'draft',
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
            $units = $this->withoutPinnedSubjects($units, $pinnedRosterIds);

            foreach ($units->sortByDesc(fn (array $unit): int => count($unit['subjects']))->values() as $unit) {
                $choice = $this->chooseSlot($unit, $slots, $slotLoads, $dayLoads, $academicAssignments, $studentAssignments, $settings);

                if (! $choice) {
                    $this->createUnscheduledItems($draft, $unit);
                    continue;
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

            Log::info('Exam schedule draft generation: before validating draft.', [
                'user_id' => auth()->id(),
                'draft_id' => $draft->id,
                'items_count' => $draft->items()->count(),
            ]);

            $validation = $this->validateDraft($draft->refresh());
            $this->syncValidationToDraft($draft, $validation);
            Log::info('Exam schedule draft generation: validation synced to draft.', [
                'user_id' => auth()->id(),
                'draft_id' => $draft->id,
                'summary' => $validation['summary'] ?? [],
                'hard_conflicts_count' => $validation['hard_conflicts_count'] ?? null,
                'warnings_count' => $validation['warnings_count'] ?? null,
            ]);

            $draft->update([
                'status' => 'generated',
                'summary_json' => $validation['summary'],
            ]);

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
        $draft->loadMissing(['items.department', 'items.subject.department', 'items.subject.studyLevel', 'college']);

        $settings = $this->normalizeSettings($draft->settings_json ?? []);
        $settings['faculty_id'] = $draft->faculty_id;
        $settings['academic_year_id'] = $draft->academic_year_id;
        $settings['semester_id'] = $draft->semester_id;
        $settings['start_date'] = $draft->start_date?->toDateString();
        $settings['end_date'] = $draft->end_date?->toDateString();

        $slotAcademicGroups = [];
        $dayAcademicGroups = [];
        $slotStudents = [];
        $dayStudents = [];
        $slotLoads = [];
        $conflicts = [];

        foreach ($draft->items as $item) {
            $date = $item->exam_date?->toDateString();
            $time = $this->timeString($item->start_time);

            if ($item->status === 'unscheduled' || blank($date) || blank($time)) {
                $conflicts[] = $this->conflictRow($item, 'unscheduled', 'مادة لم يتم جدولتها', 'غير مجدولة', 'اختر موعداً يدوياً ثم أعد فحص التعارضات.');
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

            foreach ($this->studentNumbersForItem($item) as $studentNumber) {
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

        foreach ($slotStudents as $students) {
            foreach ($students as $studentNumber => $items) {
                if (count($items) <= 1) {
                    continue;
                }

                $examples = $this->studentExamplesForItems(collect($items), [$studentNumber]);

                foreach ($items as $item) {
                    $conflicts[] = $this->conflictRow(
                        $item,
                        'same_student_time',
                        'طالب لديه مادتان في نفس الوقت',
                        'طلاب متأثرون: 1',
                        'غيّر موعد إحدى المواد المتعارضة.',
                        'لا يمكن أن يكون للطالب مادتان في نفس الوقت. أمثلة: '.implode('، ', $examples),
                        true,
                        1,
                    );
                }
            }
        }

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
            foreach ($dayStudents as $students) {
                foreach ($students as $studentNumber => $items) {
                    if (count($items) <= 1) {
                        continue;
                    }

                    $examples = $this->studentExamplesForItems(collect($items), [$studentNumber]);

                    foreach ($items as $item) {
                        $conflicts[] = $this->conflictRow(
                            $item,
                            'same_student_day',
                            'طالب لديه مادتان في نفس اليوم',
                            'طلاب متأثرون: 1',
                            'انقل إحدى المواد إلى يوم آخر.',
                            'منع مادتين في نفس اليوم لنفس الطالب مفعل. أمثلة: '.implode('، ', $examples),
                            true,
                            1,
                        );
                    }
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

        foreach ($draft->items->where('is_shared_subject', true)->groupBy('shared_group_key') as $items) {
            $requiresSeparateDays = $items->contains(
                fn (ExamScheduleDraftItem $item): bool => $item->subject?->shared_subject_scheduling_mode === 'separate_departments',
            );

            if (! $requiresSeparateDays) {
                continue;
            }

            foreach ($items->whereNotNull('exam_date')->groupBy(fn (ExamScheduleDraftItem $item): string => $item->exam_date?->toDateString() ?? '') as $sameDateItems) {
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

        $hardConflictTypes = ['unscheduled', 'outside_range', 'holiday', 'same_academic_group_time', 'same_student_time', 'core_subject_strict_period'];

        if ((bool) ($settings['prevent_same_day'] ?? false)) {
            $hardConflictTypes[] = 'same_academic_group_day';
            $hardConflictTypes[] = 'same_student_day';
        }

        $hardConflictsCount = collect($conflicts)->whereIn('type', $hardConflictTypes)->count();
        $warningsCount = count($conflicts) - $hardConflictsCount;
        $scheduledCount = $draft->items->whereIn('status', ['scheduled', 'manually_adjusted', 'conflict'])->whereNotNull('exam_date')->count();
        $unscheduledCount = $draft->items->count() - $scheduledCount;
        $usedDays = $draft->items->pluck('exam_date')->filter()->map(fn ($date) => $date->toDateString())->unique()->count();
        $busiestDay = collect($slotLoads)
            ->mapToGroups(fn (int $count, string $slot): array => [explode('|', $slot)[0] => $count])
            ->map(fn (Collection $counts): int => $counts->sum())
            ->sortDesc()
            ->keys()
            ->first();

        $summary = [
            'status' => $hardConflictsCount > 0 ? 'failed' : ($warningsCount > 0 ? 'warning' : 'success'),
            'subjects_count' => $draft->items->count(),
            'scheduled_subjects_count' => $scheduledCount,
            'unscheduled_subjects_count' => $unscheduledCount,
            'conflicts_count' => $hardConflictsCount,
            'warnings_count' => $warningsCount,
            'used_days_count' => $usedDays,
            'busiest_day' => $busiestDay,
            'shared_subject_notes_count' => collect($conflicts)->where('type', 'shared_subject_not_separated')->count(),
            'core_subject_notes_count' => collect($conflicts)->whereIn('type', ['core_subject_not_preferred_period', 'core_subject_strict_period'])->count(),
        ];

        return [
            'summary' => $summary,
            'conflicts' => $conflicts,
            'hard_conflicts_count' => $hardConflictsCount,
            'warnings_count' => $warningsCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function approveDraft(ExamScheduleDraft $draft, string $existingOfferingStrategy = 'create_missing'): array
    {
        $draft->loadMissing('items.subject', 'items.sourceRoster.rosterStudents');

        if ($draft->status === 'approved') {
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

                $existingOffering = SubjectExamOffering::query()
                    ->where('subject_id', $item->subject_id)
                    ->where('academic_year_id', $draft->academic_year_id)
                    ->where('semester_id', $draft->semester_id)
                    ->first();

                if ($existingOffering) {
                    if ($existingOfferingStrategy !== 'update_existing') {
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
                'status' => 'approved',
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

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function normalizeSettings(array $settings): array
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
            $periods = [
                ['key' => '0', 'name' => 'الفترة الأولى', 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'period_type' => 'morning'],
            ];
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

        foreach (CarbonPeriod::create($settings['start_date'], $settings['end_date']) as $date) {
            if ($this->isExcludedDate($date, $settings)) {
                continue;
            }

            foreach ($settings['periods'] as $period) {
                $slots[] = [
                    'key' => $date->toDateString().'|'.$period['start_time'],
                    'date' => $date->toDateString(),
                    'start_time' => $period['start_time'],
                    'end_time' => $period['end_time'],
                    'period_type' => $period['period_type'],
                    'period_name' => $period['name'],
                    'date_time' => Carbon::parse($date->toDateString().' '.$period['start_time']),
                ];
            }
        }

        return $slots;
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
                ->with(['subject.department', 'subject.studyLevel', 'department', 'studyLevel'])
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

            if ($rosters->isEmpty()) {
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

    protected function chooseSlot(array $unit, array $slots, array $slotLoads, array $dayLoads, array $academicAssignments, array $studentAssignments, array $settings): ?array
    {
        $candidates = [];

        foreach ($slots as $slot) {
            if ($this->hasAcademicHardConflict($unit, $slot, $academicAssignments, $settings)) {
                continue;
            }

            if ($this->hasStudentHardConflict($unit, $slot, $studentAssignments, $settings)) {
                continue;
            }

            if ($this->hasStrictCorePeriodConflict($unit, $slot)) {
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
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return $candidates[0];
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

    protected function createUnscheduledItems(ExamScheduleDraft $draft, array $unit): void
    {
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
                'conflict_notes' => 'تعذر إيجاد موعد يحقق القيود المحددة.',
                'metadata' => [
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
    }

    protected function isExcludedDate(CarbonInterface $date, array $settings): bool
    {
        if (in_array((int) $date->dayOfWeek, $settings['excluded_weekdays'] ?? [], true)) {
            return true;
        }

        return collect($settings['holidays'] ?? [])
            ->contains(fn (array $holiday): bool => ($holiday['date'] ?? null) === $date->toDateString());
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
    ): array {
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
            'details' => $details ?: $label,
            'suggested_action' => $suggestedAction,
            'hard' => $hard,
        ];
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
