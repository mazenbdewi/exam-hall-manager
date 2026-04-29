<?php

namespace App\Services;

use App\Enums\ExamOfferingStatus;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Support\ExamCollegeScope;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamScheduleGeneratorService
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function generateDraft(array $settings): ExamScheduleDraft
    {
        $settings = $this->normalizeSettings($settings);
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

        return DB::transaction(function () use ($settings, $collegeId): ExamScheduleDraft {
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

            $slots = $this->availableSlots($settings);
            $units = $this->buildSchedulingUnits($settings);
            $slotLoads = collect($slots)->mapWithKeys(fn (array $slot): array => [$slot['key'] => 0])->all();
            $dayLoads = [];
            $academicAssignments = [];

            foreach ($units->sortByDesc(fn (array $unit): int => count($unit['subjects']))->values() as $unit) {
                $choice = $this->chooseSlot($unit, $slots, $slotLoads, $dayLoads, $academicAssignments, $settings);

                if (! $choice) {
                    $this->createUnscheduledItems($draft, $unit);
                    continue;
                }

                foreach ($unit['subjects'] as $subjectPayload) {
                    $draft->items()->create([
                        'subject_id' => $subjectPayload['subject']->id,
                        'department_id' => $subjectPayload['subject']->department_id,
                        'exam_date' => $choice['date'],
                        'start_time' => $choice['start_time'],
                        'end_time' => $choice['end_time'],
                        'student_count' => 0,
                        'is_shared_subject' => $unit['is_shared_subject'],
                        'shared_group_key' => $unit['shared_group_key'],
                        'status' => 'scheduled',
                        'conflict_notes' => null,
                        'metadata' => [
                            'period_name' => $choice['period_name'],
                            'academic_group_key' => $subjectPayload['academic_group_key'],
                            'shared_subject_scheduling_mode' => $unit['shared_subject_scheduling_mode'],
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
            }

            $validation = $this->validateDraft($draft->refresh());
            $this->syncValidationToDraft($draft, $validation);

            $draft->update([
                'status' => 'generated',
                'summary_json' => $validation['summary'],
            ]);

            return $draft->refresh();
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

        $hardConflictTypes = ['unscheduled', 'outside_range', 'holiday', 'same_academic_group_time'];

        if ((bool) ($settings['prevent_same_day'] ?? false)) {
            $hardConflictTypes[] = 'same_academic_group_day';
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
    public function approveDraft(ExamScheduleDraft $draft): array
    {
        $draft->loadMissing('items.subject');

        if ($draft->status === 'approved') {
            return [
                'status' => 'success',
                'created_count' => 0,
                'updated_count' => 0,
                'message' => 'المسودة معتمدة مسبقاً.',
            ];
        }

        $validation = $this->validateDraft($draft);
        $this->syncValidationToDraft($draft, $validation);

        if (($validation['hard_conflicts_count'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'draft' => 'لا يمكن اعتماد المسودة قبل معالجة التعارضات الإلزامية.',
            ]);
        }

        return DB::transaction(function () use ($draft, $validation): array {
            $created = 0;
            $updated = 0;

            foreach ($draft->items()->with('subject')->whereIn('status', ['scheduled', 'manually_adjusted', 'conflict'])->get() as $item) {
                if (! $item->exam_date || blank($item->start_time)) {
                    continue;
                }

                $offering = new SubjectExamOffering([
                    'subject_id' => $item->subject_id,
                    'academic_year_id' => $draft->academic_year_id,
                    'semester_id' => $draft->semester_id,
                ]);

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
                $created++;

                $item->update(['subject_exam_offering_id' => $offering->id]);
            }

            $draft->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'summary_json' => $validation['summary'],
            ]);

            return [
                'status' => 'success',
                'created_count' => $created,
                'updated_count' => $updated,
                'warnings_count' => $validation['warnings_count'] ?? 0,
                'message' => 'تم اعتماد البرنامج الامتحاني بنجاح. يمكنك الآن رفع الطلاب المستجدين والحملة لكل برنامج امتحاني.',
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
            ])
            ->values()
            ->all();

        if ($periods === []) {
            $periods = [
                ['key' => '0', 'name' => 'الفترة الأولى', 'start_time' => '09:00:00', 'end_time' => '11:00:00'],
            ];
        }

        return [
            'faculty_id' => $settings['faculty_id'] ?? $settings['college_id'] ?? null,
            'academic_year_id' => filled($settings['academic_year_id'] ?? null) ? (int) $settings['academic_year_id'] : null,
            'semester_id' => filled($settings['semester_id'] ?? null) ? (int) $settings['semester_id'] : null,
            'study_level_id' => filled($settings['study_level_id'] ?? null) ? (int) $settings['study_level_id'] : null,
            'department_id' => filled($settings['department_id'] ?? null) ? (int) $settings['department_id'] : null,
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
            'break_minutes' => (int) ($settings['break_minutes'] ?? 30),
            'default_exam_duration_minutes' => (int) ($settings['default_exam_duration_minutes'] ?? 120),
            'prevent_same_day' => (bool) ($settings['prevent_same_day'] ?? false),
        ];
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
                    'period_name' => $period['name'],
                    'date_time' => Carbon::parse($date->toDateString().' '.$period['start_time']),
                ];
            }
        }

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildSchedulingUnits(array $settings): Collection
    {
        $subjects = Subject::query()
            ->with(['department', 'studyLevel'])
            ->where('college_id', $settings['faculty_id'])
            ->where('is_active', true)
            ->when($settings['department_id'], fn (Builder $query) => $query->where('department_id', $settings['department_id']))
            ->when($settings['study_level_id'], fn (Builder $query) => $query->where('study_level_id', $settings['study_level_id']))
            ->orderBy('department_id')
            ->orderBy('study_level_id')
            ->orderBy('name')
            ->get();

        $subjectPayloads = $subjects->map(fn (Subject $subject): array => $this->subjectPayload($subject, $settings));
        $units = collect();
        $handledSubjectIds = [];

        $sharedGroups = $subjectPayloads
            ->filter(fn (array $payload): bool => (bool) $payload['subject']->is_shared_subject)
            ->groupBy(fn (array $payload): string => $this->sharedGroupKey($payload['subject']));

        foreach ($sharedGroups as $groupKey => $payloads) {
            $mode = $this->sharedSchedulingMode($payloads);

            if ($mode === 'all_departments_together' || $mode === 'auto') {
                $units->push($this->unitFromPayloads($payloads, $groupKey, $mode));
                $handledSubjectIds = array_merge($handledSubjectIds, $payloads->pluck('subject.id')->all());

                continue;
            }

            foreach ($payloads as $payload) {
                $units->push($this->unitFromPayloads(collect([$payload]), $groupKey, $mode));
                $handledSubjectIds[] = $payload['subject']->id;
            }
        }

        foreach ($subjectPayloads->reject(fn (array $payload): bool => in_array($payload['subject']->id, $handledSubjectIds, true)) as $payload) {
            $units->push($this->unitFromPayloads(collect([$payload]), null, 'single'));
        }

        return $units;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function subjectPayload(Subject $subject, array $settings): array
    {
        return [
            'subject' => $subject,
            'academic_group_key' => $this->academicGroupKeyForSubject($subject),
        ];
    }

    protected function sharedGroupKey(Subject $subject): string
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

        return [
            'subjects' => $payloads->values()->all(),
            'academic_group_keys' => $academicGroupKeys->all(),
            'is_shared_subject' => $isShared,
            'shared_group_key' => $isShared ? $groupKey : null,
            'shared_subject_scheduling_mode' => $sharedSubjectSchedulingMode,
        ];
    }

    protected function chooseSlot(array $unit, array $slots, array $slotLoads, array $dayLoads, array $academicAssignments, array $settings): ?array
    {
        $candidates = [];

        foreach ($slots as $slot) {
            if ($this->hasAcademicHardConflict($unit, $slot, $academicAssignments, $settings)) {
                continue;
            }

            $score = ($slotLoads[$slot['key']] ?? 0) * 2
                + ($dayLoads[$slot['date']] ?? 0)
                + $this->sharedSubjectSeparationPenalty($unit, $slot, $academicAssignments);

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
                'subject_id' => $subjectPayload['subject']->id,
                'department_id' => $subjectPayload['subject']->department_id,
                'student_count' => 0,
                'is_shared_subject' => $unit['is_shared_subject'],
                'shared_group_key' => $unit['shared_group_key'],
                'status' => 'unscheduled',
                'conflict_notes' => 'تعذر إيجاد موعد يحقق القيود المحددة.',
                'metadata' => [
                    'academic_group_key' => $subjectPayload['academic_group_key'],
                    'shared_subject_scheduling_mode' => $unit['shared_subject_scheduling_mode'],
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
            'affected_students' => 0,
            'details' => $details ?: $label,
            'suggested_action' => $suggestedAction,
            'hard' => $hard,
        ];
    }

    protected function academicGroupKeyForSubject(Subject $subject): string
    {
        return implode('|', [
            'department:'.($subject->department_id ?: 'none'),
            'level:'.($subject->study_level_id ?: 'none'),
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
}
