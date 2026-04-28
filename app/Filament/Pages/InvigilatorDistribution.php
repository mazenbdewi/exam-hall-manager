<?php

namespace App\Filament\Pages;

use App\Models\College;
use App\Models\HallAssignment;
use App\Models\InvigilatorAssignment;
use App\Models\SubjectExamOffering;
use App\Services\AuditLogService;
use App\Services\InvigilatorDistributionPdfService;
use App\Services\InvigilatorDistributionService;
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

class InvigilatorDistribution extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $slug = 'invigilator-distribution';

    protected string $view = 'filament.pages.invigilator-distribution';

    public ?int $college_id = null;

    public string $scope = 'date_range';

    public ?string $exam_date = null;

    public ?string $start_time = null;

    public ?string $from_date = null;

    public ?string $to_date = null;

    public bool $readiness_confirmed = false;

    public string $active_tab = 'day';

    protected ?array $cachedSummary = null;

    protected ?array $cachedReadiness = null;

    public function mount(): void
    {
        $this->college_id = request()->integer('college_id') ?: (ExamCollegeScope::currentCollegeId()
            ?? College::query()->orderBy('name')->value('id'));
        $this->scope = 'date_range';
        $this->from_date = request()->string('from_date')->toString() ?: $this->firstExamDate();
        $this->to_date = request()->string('to_date')->toString() ?: $this->lastExamDate();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.core_operations');
    }

    public static function getNavigationSort(): ?int
    {
        return 13;
    }

    public static function getNavigationLabel(): string
    {
        return __('exam.pages.invigilator_distribution');
    }

    public function getTitle(): string|Htmlable
    {
        return __('exam.pages.invigilator_distribution');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        if (ExamCollegeScope::isSuperAdmin()) {
            return true;
        }

        return static::userCan('view_invigilator_distribution')
            || static::userCan(ShieldPermission::resource('viewAny', 'InvigilatorAssignment'));
    }

    public function updated(string $property): void
    {
        $this->cachedSummary = null;
        $this->cachedReadiness = null;

        if (in_array($property, ['college_id', 'from_date', 'to_date'], true)) {
            $this->readiness_confirmed = false;
        }
    }

    public function runDistribution(): void
    {
        if (! $this->canRunDistributionPermission()) {
            abort(403);
        }

        $college = $this->selectedCollege();

        if (! $college) {
            return;
        }

        $readiness = $this->getReadinessData();

        if (! $readiness['is_ready'] || ! $this->readiness_confirmed) {
            Notification::make()
                ->title(__('exam.notifications.invigilator_distribution_blocked'))
                ->body($readiness['blocking_message'] ?? __('exam.readiness.reasons.student_distribution_missing'))
                ->danger()
                ->persistent()
                ->send();

            app(AuditLogService::class)->log(
                action: 'invigilator_distribution.run',
                module: 'invigilator_distribution',
                description: 'تنفيذ توزيع المراقبين',
                metadata: [
                    'faculty_id' => $college->getKey(),
                    'from_date' => $this->from_date,
                    'to_date' => $this->to_date,
                    'status' => 'blocked',
                    'message' => $readiness['blocking_message'] ?? null,
                ],
                status: 'failed',
            );

            return;
        }

        $service = app(InvigilatorDistributionService::class);
        $wasDistributed = $this->hasExistingDistribution();
        $result = $service->distributeForFaculty(
            $college,
            Carbon::parse($this->from_date),
            Carbon::parse($this->to_date),
        );

        app(AuditLogService::class)->log(
            action: $wasDistributed ? 'invigilator_distribution.rerun' : 'invigilator_distribution.run',
            module: 'invigilator_distribution',
            description: $wasDistributed ? 'إعادة توزيع المراقبين' : 'تنفيذ توزيع المراقبين',
            metadata: [
                'faculty_id' => $college->getKey(),
                'from_date' => $this->from_date,
                'to_date' => $this->to_date,
                'total_required' => ($result['assigned_count'] ?? 0) + ($result['shortage_count'] ?? 0),
                'assigned_count' => $result['assigned_count'] ?? null,
                'shortage_count' => $result['shortage_count'] ?? null,
                'status' => $result['status'] ?? null,
            ],
            status: match ($result['status'] ?? 'warning') {
                'success' => 'success',
                'danger' => 'failed',
                default => 'warning',
            },
        );

        $notification = Notification::make()
            ->title(match ($result['status'] ?? 'warning') {
                'success' => __('exam.notifications.invigilator_distribution_completed'),
                'danger' => __('exam.notifications.invigilator_distribution_blocked'),
                'partial' => __('exam.notifications.invigilator_distribution_partial'),
                default => __('exam.notifications.invigilator_distribution_warning'),
            })
            ->body($result['message'] ?? __('exam.notifications.invigilator_distribution_completed_with_shortage', ['count' => $result['shortage_count'] ?? 0]));

        match ($result['status'] ?? 'warning') {
            'success' => $notification->success(),
            'danger' => $notification->danger()->persistent(),
            'partial' => $notification->warning()->persistent(),
            default => $notification->warning()->persistent(),
        };

        $notification->send();

        $this->cachedSummary = null;
        $this->cachedReadiness = null;
        $this->readiness_confirmed = false;
    }

    public function exportPdfByInvigilator(): StreamedResponse|Response|null
    {
        if (! $this->canExportDistribution()) {
            abort(403);
        }

        $college = $this->selectedCollege();

        if ($college) {
            app(AuditLogService::class)->log(
                action: 'export.pdf',
                module: 'exports',
                description: 'تصدير تقرير',
                metadata: [
                    'report_type' => 'invigilator_distribution_by_invigilator',
                    'faculty_id' => $college->getKey(),
                    'date_range' => collect([$this->from_date, $this->to_date])->filter()->implode(' - '),
                ],
            );
        }

        return $college
            ? app(InvigilatorDistributionPdfService::class)->downloadByInvigilator($college, ...$this->exportFilters())
            : null;
    }

    public function exportPdfByHall(): StreamedResponse|Response|null
    {
        if (! $this->canExportDistribution()) {
            abort(403);
        }

        $college = $this->selectedCollege();

        if ($college) {
            app(AuditLogService::class)->log(
                action: 'export.pdf',
                module: 'exports',
                description: 'تصدير تقرير',
                metadata: [
                    'report_type' => 'invigilator_distribution_by_hall',
                    'faculty_id' => $college->getKey(),
                    'date_range' => collect([$this->from_date, $this->to_date])->filter()->implode(' - '),
                ],
            );
        }

        return $college
            ? app(InvigilatorDistributionPdfService::class)->downloadByHall($college, ...$this->exportFilters())
            : null;
    }

    public function exportPdfByDay(): StreamedResponse|Response|null
    {
        if (! $this->canExportDistribution()) {
            abort(403);
        }

        $college = $this->selectedCollege();

        if ($college) {
            app(AuditLogService::class)->log(
                action: 'export.pdf',
                module: 'exports',
                description: 'تصدير تقرير',
                metadata: [
                    'report_type' => 'invigilator_distribution_by_day',
                    'faculty_id' => $college->getKey(),
                    'date_range' => collect([$this->from_date, $this->to_date])->filter()->implode(' - '),
                ],
            );
        }

        return $college
            ? app(InvigilatorDistributionPdfService::class)->downloadByDay($college, ...$this->exportFilters())
            : null;
    }

    public function exportShortagePdf(): StreamedResponse|Response|null
    {
        if (! (ExamCollegeScope::isSuperAdmin() || static::userCan('view_invigilator_shortage_report') || $this->canExportDistribution())) {
            abort(403);
        }

        $college = $this->selectedCollege();

        if (! $college) {
            return null;
        }

        if (empty($this->getSummaryData()['shortages'] ?? [])) {
            Notification::make()
                ->success()
                ->title(__('exam.notifications.no_invigilator_shortage'))
                ->send();

            return null;
        }

        app(AuditLogService::class)->log(
            action: 'export.pdf',
            module: 'exports',
            description: 'تصدير تقرير',
            metadata: [
                'report_type' => 'invigilator_distribution_shortage',
                'faculty_id' => $college->getKey(),
                'date_range' => collect([$this->from_date, $this->to_date])->filter()->implode(' - '),
            ],
        );

        return app(InvigilatorDistributionPdfService::class)->downloadShortage($college, ...$this->exportFilters());
    }

    public function getSummaryData(): array
    {
        if ($this->cachedSummary !== null) {
            return $this->cachedSummary;
        }

        $college = $this->selectedCollege();

        if (! $college) {
            return [
                'total_invigilators' => 0,
                'available_invigilators' => 0,
                'required_count' => 0,
                'assigned_count' => 0,
                'shortage_count' => 0,
                'halls_count' => 0,
                'days_count' => 0,
                'slots_count' => 0,
                'slots' => [],
                'shortages' => [],
                'diagnosis' => [],
                'by_invigilator' => [],
                'by_day' => [],
            ];
        }

        return $this->cachedSummary = app(InvigilatorDistributionService::class)->getSummary($college, ...$this->exportFilters());
    }

    public function getReadinessData(): array
    {
        if ($this->cachedReadiness !== null) {
            return $this->cachedReadiness;
        }

        $college = $this->selectedCollege();

        if (! $college) {
            return $this->cachedReadiness = [
                'is_ready' => false,
                'blocking_message' => __('exam.readiness.reasons.college_missing'),
                'offerings_count' => 0,
                'slots_count' => 0,
                'distributed_slots_count' => 0,
                'used_halls_count' => 0,
                'halls_needing_invigilators_count' => 0,
                'assigned_students_count' => 0,
                'unassigned_students_count' => 0,
                'incomplete_slots_count' => 0,
                'incomplete_slots' => [],
            ];
        }

        return $this->cachedReadiness = app(InvigilatorDistributionService::class)
            ->studentDistributionReadiness($college, $this->from_date, $this->to_date);
    }

    public function distributionDisabledReasons(): array
    {
        $reasons = [];
        $readiness = $this->getReadinessData();

        if (! $this->college_id) {
            $reasons[] = __('exam.readiness.reasons.college_missing');
        }

        if (! $this->from_date || ! $this->to_date) {
            $reasons[] = __('exam.readiness.reasons.period_missing');
        }

        if (($readiness['offerings_count'] ?? 0) === 0) {
            $reasons[] = __('exam.readiness.reasons.no_offerings');
        }

        if (($readiness['unassigned_students_count'] ?? 0) > 0) {
            $reasons[] = __('exam.readiness.reasons.unassigned_students_block_invigilators');
        } elseif (($readiness['incomplete_slots_count'] ?? 0) > 0) {
            $reasons[] = __('exam.readiness.reasons.student_distribution_missing');
        }

        if (($readiness['used_halls_count'] ?? 0) === 0 && ($readiness['offerings_count'] ?? 0) > 0) {
            $reasons[] = __('exam.readiness.reasons.no_used_halls');
        }

        if (! $this->readiness_confirmed) {
            $reasons[] = __('exam.readiness.reasons.confirmation_missing');
        }

        if (! $this->canRunDistributionPermission()) {
            $reasons[] = __('exam.readiness.reasons.permission_missing');
        }

        return array_values(array_unique($reasons));
    }

    public function collegeOptions(): array
    {
        return College::query()
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function dateOptions(): array
    {
        if (! $this->college_id) {
            return [];
        }

        return HallAssignment::query()
            ->where('college_id', $this->college_id)
            ->select('exam_date')
            ->distinct()
            ->orderBy('exam_date')
            ->pluck('exam_date')
            ->mapWithKeys(fn ($date): array => [substr((string) $date, 0, 10) => substr((string) $date, 0, 10)])
            ->all();
    }

    public function timeOptions(): array
    {
        if (! $this->college_id || ! $this->exam_date) {
            return [];
        }

        return HallAssignment::query()
            ->where('college_id', $this->college_id)
            ->whereDate('exam_date', $this->exam_date)
            ->select('exam_start_time')
            ->distinct()
            ->orderBy('exam_start_time')
            ->pluck('exam_start_time')
            ->mapWithKeys(fn ($time): array => [strlen((string) $time) === 5 ? $time.':00' : (string) $time => substr((string) $time, 0, 5)])
            ->all();
    }

    protected function selectedCollege(): ?College
    {
        if (! $this->college_id) {
            return null;
        }

        if (! ExamCollegeScope::userCanAccessCollegeId(auth()->user(), $this->college_id)) {
            abort(403);
        }

        return College::query()->find($this->college_id);
    }

    public function canRunDistribution(): bool
    {
        return $this->canRunDistributionPermission()
            && $this->readiness_confirmed
            && (bool) ($this->getReadinessData()['is_ready'] ?? false);
    }

    protected function canRunDistributionPermission(): bool
    {
        if (ExamCollegeScope::isSuperAdmin()) {
            return true;
        }

        $hasExistingDistribution = $this->hasExistingDistribution();

        return (! $hasExistingDistribution && static::userCan('run_invigilator_distribution'))
            || ($hasExistingDistribution && static::userCan('rerun_invigilator_distribution'))
            || static::userCan(ShieldPermission::resource('run', 'InvigilatorAssignment'));
    }

    public function canExportDistribution(): bool
    {
        if (ExamCollegeScope::isSuperAdmin()) {
            return true;
        }

        return static::userCan('export_invigilator_distribution')
            || static::userCan(ShieldPermission::resource('export', 'InvigilatorAssignment'));
    }

    public function hasExistingDistribution(): bool
    {
        return $this->assignmentQueryForSelection()->exists();
    }

    public function hasManualAssignments(): bool
    {
        return $this->assignmentQueryForSelection()
            ->where('assignment_status', 'manual')
            ->exists();
    }

    public function distributionButtonLabel(): string
    {
        return $this->hasExistingDistribution()
            ? __('exam.actions.rerun_invigilator_distribution')
            : __('exam.actions.run_invigilator_distribution');
    }

    protected function exportFilters(): array
    {
        return [null, null, $this->from_date, $this->to_date];
    }

    protected function assignmentQueryForSelection(): Builder
    {
        $query = InvigilatorAssignment::query()
            ->where('college_id', $this->college_id ?: 0);

        return $query
            ->when($this->from_date, fn (Builder $query) => $query->whereDate('exam_date', '>=', $this->from_date))
            ->when($this->to_date, fn (Builder $query) => $query->whereDate('exam_date', '<=', $this->to_date));
    }

    protected function firstExamDate(): ?string
    {
        return $this->examDateQuery()->min('exam_date');
    }

    protected function lastExamDate(): ?string
    {
        return $this->examDateQuery()->max('exam_date');
    }

    protected function examDateQuery(): Builder
    {
        return SubjectExamOffering::query()
            ->whereHas('subject', fn (Builder $query) => $query->where('college_id', $this->college_id ?: 0));
    }

    protected static function userCan(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }
}
