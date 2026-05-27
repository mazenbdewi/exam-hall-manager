<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Reports\HallDistributionByPeriodReport;
use App\Filament\Pages\Reports\StudentHallAssignmentReport;
use App\Filament\Resources\FixedExamPrograms\FixedExamProgramResource;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamScheduleDraft;
use App\Models\FixedExamProgram;
use App\Models\HallAssignment;
use App\Models\StudentDistributionRun;
use App\Services\InvigilatorDistributionPdfService;
use App\Services\StudentDistributionRunReportService;
use App\Support\ExamCollegeScope;
use App\Support\ShieldPermission;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports-dashboard';

    public ?int $college_id = null;

    public ?int $department_id = null;

    public ?int $fixed_exam_program_id = null;

    public ?string $attendance_slot = null;

    public ?int $hall_assignment_id = null;

    public ?string $from_date = null;

    public ?string $to_date = null;

    public function mount(): void
    {
        $this->college_id = ExamCollegeScope::currentCollegeId()
            ?? College::query()->orderBy('name')->value('id');

        $this->fixed_exam_program_id = $this->latestFixedProgram()?->getKey();
        $this->attendance_slot = $this->latestAttendanceSlotKey();
        $this->hall_assignment_id = $this->firstHallAssignmentForSelectedSlot()?->getKey();
        $this->from_date = $this->latestDistributionRun()?->from_date?->toDateString()
            ?? HallAssignment::query()->where('college_id', $this->college_id ?: 0)->min('exam_date');
        $this->to_date = $this->latestDistributionRun()?->to_date?->toDateString()
            ?? HallAssignment::query()->where('college_id', $this->college_id ?: 0)->max('exam_date');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.reports_printing');
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
    }

    public static function getNavigationLabel(): string
    {
        return 'التقارير والطباعة';
    }

    public function getTitle(): string|Htmlable
    {
        return 'التقارير والطباعة';
    }

    public function getHeading(): string
    {
        return 'التقارير والطباعة';
    }

    public static function canAccess(): bool
    {
        return SubjectExamOfferingResource::canViewAny()
            || FixedExamProgramResource::canViewAny()
            || InvigilatorDistribution::canAccess();
    }

    public function updatedCollegeId(): void
    {
        $this->department_id = null;
        $this->fixed_exam_program_id = $this->latestFixedProgram()?->getKey();
        $this->attendance_slot = $this->latestAttendanceSlotKey();
        $this->hall_assignment_id = $this->firstHallAssignmentForSelectedSlot()?->getKey();
        $this->from_date = $this->latestDistributionRun()?->from_date?->toDateString()
            ?? HallAssignment::query()->where('college_id', $this->college_id ?: 0)->min('exam_date');
        $this->to_date = $this->latestDistributionRun()?->to_date?->toDateString()
            ?? HallAssignment::query()->where('college_id', $this->college_id ?: 0)->max('exam_date');
    }

    public function updatedAttendanceSlot(): void
    {
        $this->hall_assignment_id = $this->firstHallAssignmentForSelectedSlot()?->getKey();
    }

    public function updatedDepartmentId(): void
    {
        $this->fixed_exam_program_id = $this->latestFixedProgram()?->getKey();
    }

    public function collegeOptions(): array
    {
        return College::query()
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function fixedProgramOptions(): array
    {
        return FixedExamProgram::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->when($this->department_id, fn (Builder $query) => $query->where('department_id', $this->department_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('college_id', ExamCollegeScope::currentCollegeId()))
            ->latest('fixed_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (FixedExamProgram $program): array => [
                $program->getKey() => trim(($program->title ?: 'برنامج مثبت').' - '.($program->fixed_at?->format('Y-m-d H:i') ?? '')),
            ])
            ->all();
    }

    public function departmentOptions(): array
    {
        return Department::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('college_id', ExamCollegeScope::currentCollegeId()))
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function attendanceSlotOptions(): array
    {
        return HallAssignment::query()
            ->where('college_id', $this->college_id ?: 0)
            ->select(['exam_date', 'exam_start_time'])
            ->distinct()
            ->orderByDesc('exam_date')
            ->orderByDesc('exam_start_time')
            ->get()
            ->mapWithKeys(function (HallAssignment $assignment): array {
                $date = $assignment->exam_date?->format('Y-m-d') ?? substr((string) $assignment->exam_date, 0, 10);
                $time = $this->normalizeTime($assignment->exam_start_time);

                return [$date.'|'.$time => $date.' - '.substr($time, 0, 5)];
            })
            ->all();
    }

    public function hallAssignmentOptions(): array
    {
        [$date, $time] = $this->selectedAttendanceSlotParts();

        if (! $date || ! $time) {
            return [];
        }

        return HallAssignment::query()
            ->with('examHall')
            ->where('college_id', $this->college_id ?: 0)
            ->whereDate('exam_date', $date)
            ->whereTime('exam_start_time', $time)
            ->get()
            ->sortBy(fn (HallAssignment $assignment): string => $assignment->examHall?->name ?? '')
            ->mapWithKeys(fn (HallAssignment $assignment): array => [
                $assignment->getKey() => $assignment->examHall?->name ?? 'قاعة #'.$assignment->getKey(),
            ])
            ->all();
    }

    public function examSchedulePrintUrl(): string
    {
        return route('filament.adminpanel.exam-schedules.print', collect([
            'college_id' => $this->college_id,
            'department_id' => $this->department_id,
            'fixed_exam_program_id' => $this->fixed_exam_program_id,
        ])->filter(fn ($value): bool => filled($value))->all());
    }

    public function draftExamSchedulePrintUrl(): string
    {
        $draft = $this->latestDraft();

        return route('filament.adminpanel.exam-schedules.print', collect([
            'source' => 'draft',
            'draft_id' => $draft?->getKey(),
            'college_id' => $this->college_id,
            'department_id' => $this->department_id,
            'academic_year_id' => $draft?->academic_year_id,
            'semester_id' => $draft?->semester_id,
        ])->filter(fn ($value): bool => filled($value))->all());
    }

    public function fixedProgramsUrl(): string
    {
        return FixedExamProgramResource::getUrl('index');
    }

    public function hallAttendancePrintUrl(): ?string
    {
        [$date, $time] = $this->selectedAttendanceSlotParts();

        if (! $this->college_id || ! $date || ! $time) {
            return null;
        }

        return route('filament.adminpanel.hall-assignments.attendance-print.index', [
            'college_id' => $this->college_id,
            'exam_date' => $date,
            'exam_start_time' => $time,
        ]);
    }

    public function singleHallAttendancePrintUrl(): ?string
    {
        $hallAssignment = $this->hall_assignment_id
            ? HallAssignment::query()->find($this->hall_assignment_id)
            : $this->firstHallAssignmentForSelectedSlot();

        return $hallAssignment
            ? route('filament.adminpanel.hall-assignments.attendance-print.show', ['hallAssignment' => $hallAssignment])
            : null;
    }

    public function hallDistributionByPeriodReportUrl(): string
    {
        return HallDistributionByPeriodReport::getUrl();
    }

    public function studentHallAssignmentReportUrl(): string
    {
        return StudentHallAssignmentReport::getUrl();
    }

    public function studentDistributionResultsUrl(): string
    {
        $run = $this->latestDistributionRun();

        return $run
            ? SubjectExamOfferingResource::getUrl('global-distribution-results', ['run' => $run])
            : SubjectExamOfferingResource::getUrl('global-distribution-results');
    }

    public function invigilatorDistributionUrl(): string
    {
        return InvigilatorDistribution::getUrl([
            'college_id' => $this->college_id,
            'from_date' => $this->from_date,
            'to_date' => $this->to_date,
        ]);
    }

    public function publicStudentLookupUrl(): string
    {
        return route('students.lookup');
    }

    public function publicInvigilatorLookupUrl(): string
    {
        return route('invigilators.lookup');
    }

    public function exportLatestStudentDistributionSummaryPdf(): StreamedResponse|Response|null
    {
        $run = $this->latestDistributionRun();

        if (! $run) {
            return $this->missingRunNotification();
        }

        return app(StudentDistributionRunReportService::class)->downloadSummaryPdf($run);
    }

    public function exportLatestUnassignedStudentsPdf(): StreamedResponse|Response|null
    {
        $run = $this->latestDistributionRun();

        if (! $run) {
            return $this->missingRunNotification();
        }

        if ((int) data_get($run->summary_json, 'validation.unassigned_students', $run->unassigned_students) === 0) {
            Notification::make()
                ->success()
                ->title(__('exam.global_hall_distribution.no_unassigned_students'))
                ->body(__('exam.global_hall_distribution.unassigned_report_not_needed'))
                ->send();

            return null;
        }

        return app(StudentDistributionRunReportService::class)->downloadUnassignedPdf($run);
    }

    public function exportInvigilatorPdfByHall(): StreamedResponse|Response|null
    {
        $college = $this->selectedCollegeForInvigilatorReport();

        return $college
            ? app(InvigilatorDistributionPdfService::class)->downloadByHall($college, null, null, $this->from_date, $this->to_date)
            : null;
    }

    public function exportInvigilatorPdfByDay(): StreamedResponse|Response|null
    {
        $college = $this->selectedCollegeForInvigilatorReport();

        return $college
            ? app(InvigilatorDistributionPdfService::class)->downloadByDay($college, null, null, $this->from_date, $this->to_date)
            : null;
    }

    public function exportInvigilatorPdfByInvigilator(): StreamedResponse|Response|null
    {
        $college = $this->selectedCollegeForInvigilatorReport();

        return $college
            ? app(InvigilatorDistributionPdfService::class)->downloadByInvigilator($college, null, null, $this->from_date, $this->to_date)
            : null;
    }

    protected function selectedCollegeForInvigilatorReport(): ?College
    {
        if (! $this->canExportInvigilatorDistribution()) {
            abort(403);
        }

        if (! $this->college_id) {
            Notification::make()
                ->warning()
                ->title(__('exam.readiness.reasons.college_missing'))
                ->send();

            return null;
        }

        abort_unless(ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $this->college_id), 403);

        return College::query()->find($this->college_id);
    }

    protected function canExportInvigilatorDistribution(): bool
    {
        if (ExamCollegeScope::isSuperAdmin()) {
            return true;
        }

        $user = auth()->user();

        return ($user?->can('export_invigilator_distribution') ?? false)
            || ($user?->can(ShieldPermission::resource('export', 'InvigilatorAssignment')) ?? false);
    }

    protected function latestFixedProgram(): ?FixedExamProgram
    {
        return FixedExamProgram::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->when($this->department_id, fn (Builder $query) => $query->where('department_id', $this->department_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('college_id', ExamCollegeScope::currentCollegeId()))
            ->latest('fixed_at')
            ->latest('id')
            ->first();
    }

    protected function latestDistributionRun(): ?StudentDistributionRun
    {
        return StudentDistributionRun::query()
            ->with('college')
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('college_id', ExamCollegeScope::currentCollegeId()))
            ->latest('executed_at')
            ->latest('id')
            ->first();
    }

    protected function latestDraft(): ?ExamScheduleDraft
    {
        return ExamScheduleDraft::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('faculty_id', $this->college_id))
            ->when($this->department_id, function (Builder $query, int $departmentId): void {
                $query->whereHas('items', function (Builder $itemsQuery) use ($departmentId): void {
                    $itemsQuery->where(function (Builder $departmentQuery) use ($departmentId): void {
                        $departmentQuery
                            ->where('department_id', $departmentId)
                            ->orWhereHas('subject', fn (Builder $subjectQuery) => $subjectQuery->where('department_id', $departmentId));
                    });
                });
            })
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('faculty_id', ExamCollegeScope::currentCollegeId()))
            ->whereIn('status', ['draft', 'generated', 'approved'])
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    protected function latestAttendanceSlotKey(): ?string
    {
        $assignment = HallAssignment::query()
            ->where('college_id', $this->college_id ?: 0)
            ->latest('exam_date')
            ->latest('exam_start_time')
            ->first(['exam_date', 'exam_start_time']);

        if (! $assignment) {
            return null;
        }

        $date = $assignment->exam_date?->format('Y-m-d') ?? substr((string) $assignment->exam_date, 0, 10);

        return $date.'|'.$this->normalizeTime($assignment->exam_start_time);
    }

    protected function firstHallAssignmentForSelectedSlot(): ?HallAssignment
    {
        [$date, $time] = $this->selectedAttendanceSlotParts();

        if (! $date || ! $time) {
            return null;
        }

        return HallAssignment::query()
            ->with('examHall')
            ->where('college_id', $this->college_id ?: 0)
            ->whereDate('exam_date', $date)
            ->whereTime('exam_start_time', $time)
            ->get()
            ->sortBy(fn (HallAssignment $assignment): string => $assignment->examHall?->name ?? '')
            ->first();
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function selectedAttendanceSlotParts(): array
    {
        if (blank($this->attendance_slot) || ! str_contains((string) $this->attendance_slot, '|')) {
            return [null, null];
        }

        [$date, $time] = explode('|', (string) $this->attendance_slot, 2);

        return [$date, $this->normalizeTime($time)];
    }

    protected function normalizeTime(mixed $time): string
    {
        $time = trim((string) $time);

        if ($time === '') {
            return '';
        }

        try {
            return Carbon::parse($time)->format('H:i:s');
        } catch (\Throwable) {
            return strlen($time) === 5 ? $time.':00' : $time;
        }
    }

    protected function missingRunNotification(): null
    {
        Notification::make()
            ->warning()
            ->title(__('exam.global_hall_distribution.no_previous_run'))
            ->send();

        return null;
    }
}
