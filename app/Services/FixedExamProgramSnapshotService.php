<?php

namespace App\Services;

use App\Models\Department;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\FixedExamProgram;
use App\Models\StudyLevel;
use App\Models\SubjectExamOffering;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FixedExamProgramSnapshotService
{
    public function createFromDraft(ExamScheduleDraft $draft): FixedExamProgram
    {
        $draft->loadMissing([
            'college',
            'academicYear',
            'semester',
            'items.department',
            'items.subject.department',
            'items.subject.studyLevel',
        ]);

        $departmentId = filled($draft->settings_json['department_id'] ?? null)
            ? (int) $draft->settings_json['department_id']
            : null;

        $existing = FixedExamProgram::query()
            ->where('exam_schedule_draft_id', $draft->id)
            ->where('department_id', $departmentId)
            ->where('status', 'fixed')
            ->first();

        if ($existing) {
            return $existing;
        }

        $fixedAt = now(config('app.timezone'));
        $snapshot = $this->snapshotFromDraft(
            draft: $draft,
            departmentId: $departmentId,
            fixedAt: $fixedAt,
            fixedBy: auth()->user()?->name,
        );
        $meta = data_get($snapshot, 'meta', []);

        $department = $departmentId ? Department::query()->find($departmentId) : null;
        $academicYear = (string) ($draft->academicYear?->name ?? '—');
        $semester = (string) ($draft->semester?->name ?? '—');

        return FixedExamProgram::query()->create([
            'exam_schedule_draft_id' => $draft->id,
            'college_id' => $draft->faculty_id,
            'department_id' => $departmentId,
            'academic_year_id' => $draft->academic_year_id,
            'semester_id' => $draft->semester_id,
            'college_name' => $draft->college?->name,
            'department_name' => $department?->name ?? 'كل الأقسام',
            'academic_year' => $academicYear,
            'semester' => $semester,
            'title' => (string) data_get($meta, 'title', 'برنامج امتحان '.$semester.' للعام الدراسي '.$academicYear),
            'status' => 'fixed',
            'fixed_at' => $fixedAt,
            'fixed_by' => auth()->id(),
            'snapshot_data' => $snapshot,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFromDraft(
        ExamScheduleDraft $draft,
        ?int $departmentId = null,
        ?Carbon $fixedAt = null,
        ?string $fixedBy = null,
        string $documentStatus = 'fixed',
    ): array {
        $draft->loadMissing([
            'college',
            'academicYear',
            'semester',
            'items.department',
            'items.subject.department',
            'items.subject.studyLevel',
        ]);

        $systemSetting = SystemSetting::current();
        $department = $departmentId ? Department::query()->find($departmentId) : null;
        $levels = $this->studyLevelsForDraft($draft, $departmentId);
        $entries = $this->entriesForDraft($draft, $departmentId);
        $rows = $this->rowsForEntries($entries, $levels);
        $academicYear = (string) ($draft->academicYear?->name ?? '—');
        $semester = (string) ($draft->semester?->name ?? '—');
        $title = 'برنامج امتحان '.$semester.' للعام الدراسي '.$academicYear;

        return [
            'meta' => [
                'document_status' => $documentStatus,
                'university_name' => $systemSetting->university_name,
                'college_id' => $draft->faculty_id,
                'college_name' => $draft->college?->name,
                'department_id' => $departmentId,
                'department_name' => $department?->name ?? 'كل الأقسام',
                'academic_year_id' => $draft->academic_year_id,
                'academic_year' => $academicYear,
                'semester_id' => $draft->semester_id,
                'semester' => $semester,
                'title' => $title,
                'fixed_at' => $fixedAt?->toDateTimeString(),
                'fixed_by' => $fixedBy,
            ],
            'levels' => $levels->map(fn (StudyLevel $level): array => [
                'id' => $level->id,
                'name' => $level->name,
                'sort_order' => $level->sort_order,
            ])->values()->all(),
            'rows' => $rows->values()->all(),
            'entries' => $entries->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, SubjectExamOffering>  $offerings
     * @return array<string, mixed>
     */
    public function snapshotFromOfferings(
        Collection $offerings,
        int $collegeId,
        ?int $departmentId = null,
        string $documentStatus = 'draft',
    ): array {
        $offerings = $offerings
            ->loadMissing(['subject.college', 'subject.department', 'subject.studyLevel', 'academicYear', 'semester', 'examScheduleDraftItem'])
            ->filter(fn (SubjectExamOffering $offering): bool => $offering->subject !== null)
            ->values();

        $firstOffering = $offerings->first();
        $systemSetting = SystemSetting::current();
        $department = $departmentId ? Department::query()->find($departmentId) : null;
        $levels = $this->studyLevelsForOfferings($offerings);
        $entries = $this->entriesForOfferings($offerings);
        $rows = $this->rowsForEntries($entries, $levels);
        $academicYear = (string) ($firstOffering?->academicYear?->name ?? '—');
        $semester = (string) ($firstOffering?->semester?->name ?? '—');
        $collegeName = $firstOffering?->subject?->college?->name;
        $title = 'برنامج امتحان '.$semester.' للعام الدراسي '.$academicYear;

        return [
            'meta' => [
                'document_status' => $documentStatus,
                'university_name' => $systemSetting->university_name,
                'college_id' => $collegeId,
                'college_name' => $collegeName,
                'department_id' => $departmentId,
                'department_name' => $department?->name ?? 'كل الأقسام',
                'academic_year_id' => $firstOffering?->academic_year_id,
                'academic_year' => $academicYear,
                'semester_id' => $firstOffering?->semester_id,
                'semester' => $semester,
                'title' => $title,
                'fixed_at' => null,
                'fixed_by' => null,
            ],
            'levels' => $levels->map(fn (StudyLevel $level): array => [
                'id' => $level->id,
                'name' => $level->name,
                'sort_order' => $level->sort_order,
            ])->values()->all(),
            'rows' => $rows->values()->all(),
            'entries' => $entries->values()->all(),
        ];
    }

    /**
     * @return Collection<int, StudyLevel>
     */
    protected function studyLevelsForDraft(ExamScheduleDraft $draft, ?int $departmentId): Collection
    {
        $levels = StudyLevel::query()
            ->where('is_active', true)
            ->whereHas('subjects', function (Builder $query) use ($draft, $departmentId): void {
                $query
                    ->where('college_id', $draft->faculty_id)
                    ->when($departmentId, fn (Builder $query): Builder => $query->where('department_id', $departmentId));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($levels->isNotEmpty()) {
            return $levels;
        }

        $levelIds = $draft->items
            ->pluck('subject.study_level_id')
            ->filter()
            ->unique()
            ->values();

        return StudyLevel::query()
            ->when($levelIds->isNotEmpty(), fn (Builder $query): Builder => $query->whereIn('id', $levelIds))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function entriesForDraft(ExamScheduleDraft $draft, ?int $departmentId = null): Collection
    {
        return $draft->items
            ->filter(function (ExamScheduleDraftItem $item) use ($departmentId): bool {
                if (! $departmentId) {
                    return true;
                }

                return (int) ($item->department_id ?: $item->subject?->department_id) === $departmentId;
            })
            ->filter(fn (ExamScheduleDraftItem $item): bool => in_array($item->status, ['scheduled', 'manually_adjusted', 'conflict'], true)
                && $item->exam_date
                && filled($item->start_time)
                && $item->subject !== null)
            ->sortBy([
                ['exam_date', 'asc'],
                ['start_time', 'asc'],
                ['subject.name', 'asc'],
            ])
            ->map(function (ExamScheduleDraftItem $item): array {
                $examDate = $item->exam_date instanceof Carbon
                    ? $item->exam_date
                    : Carbon::parse($item->exam_date);

                return [
                    'exam_date' => $examDate->toDateString(),
                    'day_name' => $this->arabicDayName($examDate),
                    'subject_id' => $item->subject_id,
                    'subject_name' => $item->subject?->name,
                    'subject_code' => $item->subject?->code,
                    'department_id' => $item->department_id ?: $item->subject?->department_id,
                    'department_name' => $item->department?->name ?? $item->subject?->department?->name,
                    'study_level_id' => $item->subject?->study_level_id,
                    'study_level_name' => $item->subject?->studyLevel?->name,
                    'exam_start_time' => $this->timeString($item->start_time),
                    'exam_end_time' => $this->timeString($item->end_time),
                    'time_range' => $this->timeRange($item->start_time, $item->end_time),
                    'status' => $item->status,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, SubjectExamOffering>  $offerings
     * @return Collection<int, StudyLevel>
     */
    protected function studyLevelsForOfferings(Collection $offerings): Collection
    {
        $levelIds = $offerings
            ->pluck('subject.study_level_id')
            ->filter()
            ->unique()
            ->values();

        return StudyLevel::query()
            ->when($levelIds->isNotEmpty(), fn (Builder $query): Builder => $query->whereIn('id', $levelIds))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, SubjectExamOffering>  $offerings
     * @return Collection<int, array<string, mixed>>
     */
    protected function entriesForOfferings(Collection $offerings): Collection
    {
        return $offerings
            ->filter(fn (SubjectExamOffering $offering): bool => $offering->exam_date
                && filled($offering->exam_start_time)
                && $offering->subject !== null)
            ->sortBy([
                ['exam_date', 'asc'],
                ['exam_start_time', 'asc'],
                ['subject.name', 'asc'],
            ])
            ->map(function (SubjectExamOffering $offering): array {
                $examDate = $offering->exam_date instanceof Carbon
                    ? $offering->exam_date
                    : Carbon::parse($offering->exam_date);

                return [
                    'exam_date' => $examDate->toDateString(),
                    'day_name' => $this->arabicDayName($examDate),
                    'subject_id' => $offering->subject_id,
                    'subject_name' => $offering->subject?->name,
                    'subject_code' => $offering->subject?->code,
                    'department_id' => $offering->subject?->department_id,
                    'department_name' => $offering->subject?->department?->name,
                    'study_level_id' => $offering->subject?->study_level_id,
                    'study_level_name' => $offering->subject?->studyLevel?->name,
                    'exam_start_time' => $this->timeString($offering->exam_start_time),
                    'exam_end_time' => $this->timeString($offering->examScheduleDraftItem?->end_time),
                    'time_range' => $this->timeRange($offering->exam_start_time, $offering->examScheduleDraftItem?->end_time),
                    'status' => $offering->status instanceof \BackedEnum ? $offering->status->value : (string) $offering->status,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  Collection<int, StudyLevel>  $levels
     * @return Collection<int, array<string, mixed>>
     */
    protected function rowsForEntries(Collection $entries, Collection $levels): Collection
    {
        $levelIds = $levels->pluck('id')->map(fn (int $id): int => $id)->all();

        return $entries
            ->groupBy('exam_date')
            ->map(function (Collection $dateEntries, string $date) use ($levelIds): array {
                $cells = collect($levelIds)
                    ->mapWithKeys(fn (int $levelId): array => [$levelId => []])
                    ->all();

                foreach ($dateEntries as $entry) {
                    $levelId = $entry['study_level_id'] ?? null;

                    if (! $levelId || ! array_key_exists($levelId, $cells)) {
                        continue;
                    }

                    $cells[$levelId][] = [
                        'subject_name' => $entry['subject_name'],
                        'subject' => $entry['subject_name'],
                        'subject_level' => $entry['study_level_name'],
                        'start_time' => $entry['exam_start_time'],
                        'end_time' => $entry['exam_end_time'],
                        'time_range' => $entry['time_range'],
                        'time' => $entry['time_range'],
                    ];
                }

                $carbonDate = Carbon::parse($date);

                return [
                    'exam_date' => $carbonDate->toDateString(),
                    'date' => $carbonDate->toDateString(),
                    'day_name' => $this->arabicDayName($carbonDate),
                    'day' => $this->arabicDayName($carbonDate),
                    'cells' => $cells,
                ];
            })
            ->sortBy('date')
            ->values();
    }

    protected function timeRange(mixed $startTime, mixed $endTime): string
    {
        $start = $this->displayTime($startTime);
        $end = $this->displayTime($endTime);

        if ($start && $end) {
            return '('.$start.' - '.$end.')';
        }

        return $start ? '('.$start.')' : '';
    }

    protected function displayTime(mixed $time): ?string
    {
        $value = $this->timeString($time);

        if (! $value) {
            return null;
        }

        $display = substr($value, 0, 5);

        if (str_ends_with($display, ':00')) {
            return (string) ((int) substr($display, 0, 2));
        }

        return ltrim($display, '0') ?: '0';
    }

    protected function timeString(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        return strlen((string) $time) === 5 ? ((string) $time).':00' : substr((string) $time, 0, 8);
    }

    protected function arabicDayName(Carbon $date): string
    {
        return match ($date->dayOfWeek) {
            Carbon::SUNDAY => 'الأحد',
            Carbon::MONDAY => 'الإثنين',
            Carbon::TUESDAY => 'الثلاثاء',
            Carbon::WEDNESDAY => 'الأربعاء',
            Carbon::THURSDAY => 'الخميس',
            Carbon::FRIDAY => 'الجمعة',
            default => 'السبت',
        };
    }
}
