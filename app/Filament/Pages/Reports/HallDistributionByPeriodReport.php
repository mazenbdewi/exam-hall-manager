<?php

namespace App\Filament\Pages\Reports;

use App\Exports\HallDistributionByPeriodExport;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\HallAssignment;
use App\Models\Semester;
use App\Models\StudentDistributionRun;
use App\Support\ExamCollegeScope;
use App\Support\InstitutionSettings;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HallDistributionByPeriodReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $slug = 'reports/hall-distribution-by-period';

    protected string $view = 'filament.pages.reports.hall-distribution-by-period-report';

    public ?int $college_id = null;

    public ?int $department_id = null;

    public ?int $academic_year_id = null;

    public ?int $semester_id = null;

    public ?string $date_from = null;

    public ?string $date_to = null;

    public ?string $exam_time_slot = null;

    public bool $show_report = true;

    public function mount(): void
    {
        $this->college_id = ExamCollegeScope::currentCollegeId()
            ?? College::query()->orderBy('name')->value('id');
        $this->academic_year_id = AcademicYear::query()->where('is_current', true)->value('id')
            ?? AcademicYear::query()->where('is_active', true)->latest('id')->value('id');
        $this->semester_id = Semester::query()->where('is_active', true)->orderBy('sort_order')->value('id');
        $this->date_from = HallAssignment::query()
            ->where('college_id', $this->college_id ?: 0)
            ->min('exam_date');
        $this->date_to = HallAssignment::query()
            ->where('college_id', $this->college_id ?: 0)
            ->max('exam_date');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.reports_printing');
    }

    public static function getNavigationSort(): ?int
    {
        return 22;
    }

    public static function getNavigationLabel(): string
    {
        return 'توزيع أعداد الطلاب على القاعات';
    }

    public function getTitle(): string|Htmlable
    {
        return 'تقرير توزيع أعداد الطلاب على القاعات';
    }

    public function getHeading(): string
    {
        return 'تقرير توزيع أعداد الطلاب على القاعات';
    }

    public static function canAccess(): bool
    {
        return SubjectExamOfferingResource::canViewAny();
    }

    public function updatedCollegeId(): void
    {
        $this->department_id = null;
        $this->exam_time_slot = null;
        $this->date_from = HallAssignment::query()
            ->where('college_id', $this->college_id ?: 0)
            ->min('exam_date');
        $this->date_to = HallAssignment::query()
            ->where('college_id', $this->college_id ?: 0)
            ->max('exam_date');
    }

    public function updatedDateFrom(): void
    {
        $this->exam_time_slot = null;
    }

    public function updatedDateTo(): void
    {
        $this->exam_time_slot = null;
    }

    public function showReport(): void
    {
        $this->show_report = true;
    }

    public function resetFilters(): void
    {
        $this->department_id = null;
        $this->exam_time_slot = null;
        $this->mount();
        $this->show_report = true;
    }

    public function exportExcel(): BinaryFileResponse
    {
        if (! SubjectExamOfferingResource::canViewAny()) {
            abort(403);
        }

        if ($this->college_id) {
            abort_unless(ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $this->college_id), 403);
        }

        return Excel::download(
            new HallDistributionByPeriodExport($this->reportData()),
            'hall-distribution-by-period-'.now()->format('Y-m-d-H-i').'.xlsx',
        );
    }

    public function collegeOptions(): array
    {
        return College::query()
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function departmentOptions(): array
    {
        return Department::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function academicYearOptions(): array
    {
        return AcademicYear::query()
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->pluck('name', 'id')
            ->all();
    }

    public function semesterOptions(): array
    {
        return Semester::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function timeSlotOptions(): array
    {
        return $this->baseRowsQuery()
            ->select([
                'hall_assignments.exam_start_time',
                DB::raw('max(exam_schedule_draft_items.end_time) as exam_end_time'),
            ])
            ->groupBy('hall_assignments.exam_start_time')
            ->orderBy('hall_assignments.exam_start_time')
            ->get()
            ->mapWithKeys(function (object $row): array {
                $start = $this->normalizeTime($row->exam_start_time);
                $end = $this->normalizeTime($row->exam_end_time);
                $key = $start.'|'.$end;

                return [$key => $this->displayTime($start).' - '.$this->displayTime($end)];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function reportData(): array
    {
        $rows = $this->baseRowsQuery()
            ->select([
                'hall_assignments.exam_date',
                'hall_assignments.exam_start_time',
                DB::raw('coalesce(exam_schedule_draft_items.end_time, null) as exam_end_time'),
                'exam_halls.id as hall_id',
                'exam_halls.name as hall_name',
                'exam_halls.priority as hall_priority',
                'subjects.id as subject_id',
                'subjects.name as subject_name',
                'departments.name as department_name',
                'study_levels.name as study_level_name',
                'study_levels.sort_order as study_level_sort',
                DB::raw('count(exam_student_hall_assignments.id) as students_count'),
            ])
            ->groupBy([
                'hall_assignments.exam_date',
                'hall_assignments.exam_start_time',
                'exam_schedule_draft_items.end_time',
                'exam_halls.id',
                'exam_halls.name',
                'exam_halls.priority',
                'subjects.id',
                'subjects.name',
                'departments.name',
                'study_levels.name',
                'study_levels.sort_order',
            ])
            ->orderBy('hall_assignments.exam_date')
            ->orderBy('hall_assignments.exam_start_time')
            ->get();

        return [
            'has_saved_distribution' => $this->hasSavedDistribution(),
            'periods' => $this->buildPeriods($rows),
            'meta' => $this->reportMeta(),
        ];
    }

    protected function baseRowsQuery(): \Illuminate\Database\Query\Builder
    {
        [$slotStart, $slotEnd] = $this->selectedTimeSlotParts();

        return DB::table('exam_student_hall_assignments')
            ->join('hall_assignments', 'hall_assignments.id', '=', 'exam_student_hall_assignments.hall_assignment_id')
            ->join('exam_halls', 'exam_halls.id', '=', 'hall_assignments.exam_hall_id')
            ->join('subject_exam_offerings', 'subject_exam_offerings.id', '=', 'exam_student_hall_assignments.subject_exam_offering_id')
            ->join('subjects', 'subjects.id', '=', 'subject_exam_offerings.subject_id')
            ->leftJoin('departments', 'departments.id', '=', 'subjects.department_id')
            ->leftJoin('study_levels', 'study_levels.id', '=', 'subjects.study_level_id')
            ->leftJoin('exam_schedule_draft_items', 'exam_schedule_draft_items.subject_exam_offering_id', '=', 'subject_exam_offerings.id')
            ->when($this->college_id, fn ($query) => $query->where('hall_assignments.college_id', $this->college_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn ($query) => $query->where('hall_assignments.college_id', ExamCollegeScope::currentCollegeId()))
            ->when($this->department_id, fn ($query) => $query->where('subjects.department_id', $this->department_id))
            ->when($this->academic_year_id, fn ($query) => $query->where('subject_exam_offerings.academic_year_id', $this->academic_year_id))
            ->when($this->semester_id, fn ($query) => $query->where('subject_exam_offerings.semester_id', $this->semester_id))
            ->when($this->date_from, fn ($query) => $query->whereDate('hall_assignments.exam_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($query) => $query->whereDate('hall_assignments.exam_date', '<=', $this->date_to))
            ->when($slotStart, fn ($query) => $query->whereTime('hall_assignments.exam_start_time', $slotStart))
            ->when($slotEnd, fn ($query) => $query->whereTime('exam_schedule_draft_items.end_time', $slotEnd));
    }

    protected function hasSavedDistribution(): bool
    {
        $hasDistributionRun = StudentDistributionRun::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('college_id', ExamCollegeScope::currentCollegeId()))
            ->when($this->date_from, fn (Builder $query) => $query->whereDate('to_date', '>=', $this->date_from))
            ->when($this->date_to, fn (Builder $query) => $query->whereDate('from_date', '<=', $this->date_to))
            ->exists();

        if ($hasDistributionRun) {
            return true;
        }

        return HallAssignment::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('college_id', ExamCollegeScope::currentCollegeId()))
            ->when($this->date_from, fn (Builder $query) => $query->whereDate('exam_date', '>=', $this->date_from))
            ->when($this->date_to, fn (Builder $query) => $query->whereDate('exam_date', '<=', $this->date_to))
            ->exists();
    }

    protected function buildPeriods(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (object $row): string => $this->periodKey($row))
            ->map(function (Collection $periodRows): array {
                $first = $periodRows->first();
                $halls = $periodRows
                    ->map(fn (object $row): array => [
                        'id' => (int) $row->hall_id,
                        'name' => (string) $row->hall_name,
                        'priority' => (string) ($row->hall_priority ?? ''),
                    ])
                    ->unique('id')
                    ->sort($this->hallSorter(...))
                    ->values();

                $subjects = $periodRows
                    ->groupBy('subject_id')
                    ->map(function (Collection $subjectRows) use ($halls): array {
                        $firstSubjectRow = $subjectRows->first();
                        $countsByHall = $subjectRows
                            ->groupBy('hall_id')
                            ->map(fn (Collection $hallRows): int => (int) $hallRows->sum('students_count'));

                        return [
                            'id' => (int) $firstSubjectRow->subject_id,
                            'name' => (string) $firstSubjectRow->subject_name,
                            'department_name' => (string) ($firstSubjectRow->department_name ?? ''),
                            'study_level_name' => (string) ($firstSubjectRow->study_level_name ?? ''),
                            'study_level_sort' => (int) ($firstSubjectRow->study_level_sort ?? 999),
                            'total' => (int) $subjectRows->sum('students_count'),
                            'hall_counts' => $halls
                                ->mapWithKeys(fn (array $hall): array => [
                                    $hall['id'] => (int) ($countsByHall->get($hall['id']) ?? 0),
                                ])
                                ->all(),
                        ];
                    })
                    ->sort($this->subjectSorter(...))
                    ->values();

                return [
                    'exam_date' => substr((string) $first->exam_date, 0, 10),
                    'exam_start_time' => $this->normalizeTime($first->exam_start_time),
                    'exam_end_time' => $this->normalizeTime($first->exam_end_time),
                    'title' => $this->periodTitle($first),
                    'halls' => $halls->all(),
                    'subjects' => $subjects->all(),
                ];
            })
            ->sortBy([
                ['exam_date', 'asc'],
                ['exam_start_time', 'asc'],
            ])
            ->values()
            ->all();
    }

    protected function reportMeta(): array
    {
        $institution = InstitutionSettings::make();

        return [
            'university_name' => $institution->universityName(),
            'logo_data_uri' => $institution->logoDataUri(),
            'college_name' => $this->college_id
                ? (College::query()->find($this->college_id)?->name ?? '')
                : 'كل الكليات',
            'department_name' => $this->department_id
                ? (Department::query()->find($this->department_id)?->name ?? '')
                : 'كل الأقسام',
            'academic_year' => $this->academic_year_id
                ? (AcademicYear::query()->find($this->academic_year_id)?->name ?? '—')
                : 'كل الأعوام',
            'semester' => $this->semester_id
                ? (Semester::query()->find($this->semester_id)?->name ?? '—')
                : 'كل الفصول',
            'date_from' => $this->date_from ?: '—',
            'date_to' => $this->date_to ?: '—',
            'exam_time_slot' => $this->selectedTimeSlotLabel(),
        ];
    }

    protected function selectedTimeSlotLabel(): string
    {
        [$start, $end] = $this->selectedTimeSlotParts();

        return $start || $end
            ? $this->displayTime($start).' - '.$this->displayTime($end)
            : 'كل الفترات';
    }

    protected function periodKey(object $row): string
    {
        return substr((string) $row->exam_date, 0, 10)
            .'|'.$this->normalizeTime($row->exam_start_time)
            .'|'.$this->normalizeTime($row->exam_end_time);
    }

    protected function periodTitle(object $row): string
    {
        return substr((string) $row->exam_date, 0, 10)
            .'  الفترة ('.$this->displayTime($row->exam_start_time).' - '.$this->displayTime($row->exam_end_time).')';
    }

    protected function hallSorter(array $first, array $second): int
    {
        $priorityComparison = $this->priorityRank($first['priority'] ?? '') <=> $this->priorityRank($second['priority'] ?? '');

        return $priorityComparison !== 0
            ? $priorityComparison
            : strnatcasecmp($first['name'] ?? '', $second['name'] ?? '');
    }

    protected function subjectSorter(array $first, array $second): int
    {
        $levelComparison = ($first['study_level_sort'] ?? 999) <=> ($second['study_level_sort'] ?? 999);

        return $levelComparison !== 0
            ? $levelComparison
            : strnatcasecmp($first['name'] ?? '', $second['name'] ?? '');
    }

    protected function priorityRank(?string $priority): int
    {
        return match ($priority) {
            'high' => 0,
            'medium' => 1,
            default => 2,
        };
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function selectedTimeSlotParts(): array
    {
        if (blank($this->exam_time_slot) || ! str_contains((string) $this->exam_time_slot, '|')) {
            return [null, null];
        }

        [$start, $end] = explode('|', (string) $this->exam_time_slot, 2);

        return [$this->normalizeTime($start), $this->normalizeTime($end)];
    }

    protected function normalizeTime(mixed $time): ?string
    {
        $time = trim((string) $time);

        if ($time === '') {
            return null;
        }

        try {
            return Carbon::parse($time)->format('H:i:s');
        } catch (\Throwable) {
            return strlen($time) === 5 ? $time.':00' : $time;
        }
    }

    protected function displayTime(mixed $time): string
    {
        $time = $this->normalizeTime($time);

        return $time ? substr($time, 0, 5) : '—';
    }
}
