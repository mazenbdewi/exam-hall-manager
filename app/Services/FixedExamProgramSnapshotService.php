<?php

namespace App\Services;

use App\Models\Department;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\FixedExamProgram;
use App\Models\StudyLevel;
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

        $systemSetting = SystemSetting::current();
        $department = $departmentId ? Department::query()->find($departmentId) : null;
        $levels = $this->studyLevelsForDraft($draft, $departmentId);
        $entries = $this->entriesForDraft($draft);
        $rows = $this->rowsForEntries($entries, $levels);
        $academicYear = (string) ($draft->academicYear?->name ?? '—');
        $semester = (string) ($draft->semester?->name ?? '—');
        $title = 'برنامج امتحان '.$semester.' للعام الدراسي '.$academicYear;
        $fixedAt = now(config('app.timezone'));

        $snapshot = [
            'meta' => [
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
                'fixed_at' => $fixedAt->toDateTimeString(),
                'fixed_by' => auth()->user()?->name,
            ],
            'levels' => $levels->map(fn (StudyLevel $level): array => [
                'id' => $level->id,
                'name' => $level->name,
                'sort_order' => $level->sort_order,
            ])->values()->all(),
            'rows' => $rows->values()->all(),
            'entries' => $entries->values()->all(),
        ];

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
            'title' => $title,
            'status' => 'fixed',
            'fixed_at' => $fixedAt,
            'fixed_by' => auth()->id(),
            'snapshot_data' => $snapshot,
        ]);
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
    protected function entriesForDraft(ExamScheduleDraft $draft): Collection
    {
        return $draft->items
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
