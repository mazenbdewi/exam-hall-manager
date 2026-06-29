<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\College;
use App\Models\HallAssignment;
use App\Models\InvigilatorAssignment;
use App\Models\InvigilatorDistributionDraft;
use App\Models\SubjectExamOffering;
use App\Exports\InvigilatorDistributionDraftExport;
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
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
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

    public int $shortage_page = 1;

    public int $shortage_per_page = 10;

    public ?array $lastNormalDistributionResult = null;

    public ?array $lastFairDistributionResult = null;

    protected ?array $cachedSummary = null;

    protected ?array $cachedReadiness = null;

    public function mount(): void
    {
        $this->college_id = request()->integer('college_id') ?: (ExamCollegeScope::currentCollegeId()
            ?? College::query()->orderBy('name')->value('id'));
        $this->scope = 'date_range';
        $this->from_date = request()->string('from_date')->toString() ?: $this->firstExamDate();
        $this->to_date = request()->string('to_date')->toString() ?: $this->lastExamDate();
        $this->refreshLastResultSummaries();
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
            $this->shortage_page = 1;
            $this->refreshLastResultSummaries();
        }

        if ($property === 'shortage_per_page') {
            $this->shortage_per_page = in_array((int) $this->shortage_per_page, [10, 25, 50, 100], true)
                ? (int) $this->shortage_per_page
                : 10;
            $this->shortage_page = 1;
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

        $dateRange = $this->selectedDateRange();

        if (! $dateRange) {
            $this->lastNormalDistributionResult = $this->failedNormalDistributionResult(
                $college,
                null,
                __('exam.readiness.reasons.period_missing'),
            );

            Notification::make()
                ->title(__('exam.notifications.invigilator_distribution_blocked'))
                ->body(__('exam.readiness.reasons.period_missing'))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $readiness = $this->getReadinessData();

        if (! ($readiness['is_ready'] ?? false)) {
            $blockingMessage = $readiness['blocking_message'] ?? __('exam.readiness.reasons.student_distribution_missing');
            $this->lastNormalDistributionResult = $this->failedNormalDistributionResult(
                $college,
                $dateRange,
                $blockingMessage,
            );

            Notification::make()
                ->title(__('exam.notifications.invigilator_distribution_blocked'))
                ->body($blockingMessage)
                ->danger()
                ->persistent()
                ->send();

            app(AuditLogService::class)->log(
                action: 'invigilator_distribution.run',
                module: 'invigilator_distribution',
                description: 'تنفيذ توزيع المراقبين',
                metadata: [
                    'faculty_id' => $college->getKey(),
                    'from_date' => $dateRange[0],
                    'to_date' => $dateRange[1],
                    'status' => 'blocked',
                    'message' => $blockingMessage,
                    'result_summary' => $this->lastNormalDistributionResult,
                ],
                status: 'failed',
            );

            return;
        }

        $service = app(InvigilatorDistributionService::class);
        $wasDistributed = $this->hasExistingDistribution();
        $result = $service->distributeForFaculty(
            $college,
            Carbon::parse($dateRange[0]),
            Carbon::parse($dateRange[1]),
        );
        $this->lastNormalDistributionResult = $this->normalDistributionResultFromServiceResult($result, $college, $dateRange);

        app(AuditLogService::class)->log(
            action: $wasDistributed ? 'invigilator_distribution.rerun' : 'invigilator_distribution.run',
            module: 'invigilator_distribution',
            description: $wasDistributed ? 'إعادة توزيع المراقبين' : 'تنفيذ توزيع المراقبين',
            metadata: [
                'faculty_id' => $college->getKey(),
                'from_date' => $dateRange[0],
                'to_date' => $dateRange[1],
                'total_required' => ($result['assigned_count'] ?? 0) + ($result['shortage_count'] ?? 0),
                'assigned_count' => $result['assigned_count'] ?? null,
                'shortage_count' => $result['shortage_count'] ?? null,
                'status' => $result['status'] ?? null,
                'result_summary' => $this->lastNormalDistributionResult,
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
    }

    public function createFairBalancedDraft(): void
    {
        if (! $this->canRunDistributionPermission()) {
            abort(403);
        }

        $college = $this->selectedCollege();

        if (! $college) {
            return;
        }

        $dateRange = $this->selectedDateRange();

        if (! $dateRange) {
            Notification::make()
                ->danger()
                ->title(__('exam.notifications.invigilator_distribution_blocked'))
                ->body(__('exam.readiness.reasons.period_missing'))
                ->persistent()
                ->send();

            return;
        }

        $readiness = $this->getReadinessData();

        if (! ($readiness['is_ready'] ?? false)) {
            Notification::make()
                ->danger()
                ->title(__('exam.notifications.invigilator_distribution_blocked'))
                ->body($readiness['blocking_message'] ?? __('exam.readiness.not_ready_message'))
                ->persistent()
                ->send();

            return;
        }

        $draft = app(InvigilatorDistributionService::class)->createFairBalancedDraft(
            $college,
            $dateRange[0],
            $dateRange[1],
            auth()->id(),
        );
        $this->lastFairDistributionResult = $this->fairDistributionResultFromDraft($draft);

        app(AuditLogService::class)->log(
            action: 'invigilator_distribution.fair_draft.create',
            module: 'invigilator_distribution',
            description: 'إنشاء مسودة توزيع عادل للمراقبين',
            metadata: [
                'draft_id' => $draft->getKey(),
                'faculty_id' => $college->getKey(),
                'from_date' => $dateRange[0],
                'to_date' => $dateRange[1],
                'result_summary' => $this->lastFairDistributionResult,
            ],
        );

        Notification::make()
            ->success()
            ->title(__('exam.fair_draft.notifications.created'))
            ->body(__('exam.fair_draft.notifications.created_body', ['draft' => $draft->getKey()]))
            ->send();
    }

    public function approveFairBalancedDraft(int $draftId): void
    {
        if (! $this->canRunDistributionPermission()) {
            abort(403);
        }

        $draft = $this->draftQueryForSelection()->whereKey($draftId)->firstOrFail();

        try {
            $draft = app(InvigilatorDistributionService::class)->approveFairBalancedDraft($draft, auth()->id());
        } catch (RuntimeException $exception) {
            Notification::make()
                ->danger()
                ->title(__('exam.fair_draft.notifications.approval_failed'))
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->cachedSummary = null;
        $this->cachedReadiness = null;
        $this->lastFairDistributionResult = $this->fairDistributionResultFromDraft($draft);

        Notification::make()
            ->success()
            ->title(__('exam.fair_draft.notifications.approved'))
            ->send();
    }

    public function cancelFairBalancedDraft(int $draftId): void
    {
        if (! $this->canRunDistributionPermission()) {
            abort(403);
        }

        $draft = $this->draftQueryForSelection()->whereKey($draftId)->firstOrFail();

        try {
            $draft = app(InvigilatorDistributionService::class)->cancelFairBalancedDraft($draft);
        } catch (RuntimeException $exception) {
            Notification::make()
                ->danger()
                ->title(__('exam.fair_draft.notifications.cancel_failed'))
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->lastFairDistributionResult = $this->fairDistributionResultFromDraft($draft);

        Notification::make()
            ->success()
            ->title(__('exam.fair_draft.notifications.cancelled'))
            ->send();
    }

    public function exportFairBalancedDraftPdf(int $draftId): StreamedResponse|Response
    {
        if (! $this->canExportDistribution()) {
            abort(403);
        }

        $draft = $this->draftQueryForSelection()->whereKey($draftId)->firstOrFail();

        return app(InvigilatorDistributionPdfService::class)->downloadFairBalancedDraft($draft);
    }

    public function exportFairBalancedDraftExcel(int $draftId): Response
    {
        if (! $this->canExportDistribution()) {
            abort(403);
        }

        $draft = $this->draftQueryForSelection()->whereKey($draftId)->firstOrFail();

        return Excel::download(
            new InvigilatorDistributionDraftExport($draft),
            'invigilator-fair-draft-'.$draft->getKey().'-'.now()->format('Y-m-d-H-i').'.xlsx',
        );
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

        if ((int) ($this->getSummaryData()['shortage_count'] ?? 0) <= 0) {
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

    public function exportDutyIncreaseRecommendationsPdf(): StreamedResponse|Response|null
    {
        if (! (ExamCollegeScope::isSuperAdmin() || static::userCan('view_invigilator_shortage_report') || $this->canExportDistribution())) {
            abort(403);
        }

        $college = $this->selectedCollege();

        if (! $college) {
            return null;
        }

        $report = $this->getSummaryData()['duty_increase_recommendations'] ?? [];

        if ((int) ($report['total_uncovered_duties'] ?? 0) <= 0) {
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
                'report_type' => 'invigilator_duty_increase_recommendations',
                'faculty_id' => $college->getKey(),
                'date_range' => collect([$this->from_date, $this->to_date])->filter()->implode(' - '),
            ],
        );

        return app(InvigilatorDistributionPdfService::class)->downloadDutyIncreaseRecommendations($college, ...$this->exportFilters());
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

        [$examDate, $startTime, $fromDate, $toDate] = $this->exportFilters();

        return $this->cachedSummary = app(InvigilatorDistributionService::class)->getSummary(
            $college,
            $examDate,
            $startTime,
            $fromDate,
            $toDate,
            includeShortageDetails: false,
            includeReportDetails: false,
        );
    }

    public function getPaginatedShortagesData(): array
    {
        $college = $this->selectedCollege();

        if (! $college) {
            return [
                'data' => [],
                'total' => 0,
                'per_page' => 10,
                'current_page' => 1,
                'last_page' => 1,
                'from' => 0,
                'to' => 0,
                'has_pages' => false,
                'per_page_options' => [10, 25, 50, 100],
            ];
        }

        [$examDate, $startTime, $fromDate, $toDate] = $this->exportFilters();

        return app(InvigilatorDistributionService::class)->getShortagePage(
            $college,
            $examDate,
            $startTime,
            $fromDate,
            $toDate,
            $this->shortage_page,
            $this->shortage_per_page,
        );
    }

    public function getFairBalancedDraftsData(): array
    {
        return $this->draftQueryForSelection()
            ->with(['creator'])
            ->withCount('assignments')
            ->latest()
            ->limit(8)
            ->get()
            ->map(function (InvigilatorDistributionDraft $draft): array {
                $summary = $draft->summary_json ?? [];

                return [
                    'id' => $draft->getKey(),
                    'status' => $draft->status,
                    'status_label' => __('exam.fair_draft.statuses.'.$draft->status),
                    'created_at' => $draft->created_at?->format('Y-m-d H:i'),
                    'created_by' => $draft->creator?->name ?? '—',
                    'period' => ($draft->exam_date_from?->format('Y-m-d') ?? '—').' - '.($draft->exam_date_to?->format('Y-m-d') ?? '—'),
                    'assignments_count' => $draft->assignments_count,
                    'summary' => $summary,
                ];
            })
            ->all();
    }

    public function nextShortagePage(): void
    {
        $pagination = $this->getPaginatedShortagesData();
        $this->shortage_page = min((int) $pagination['last_page'], (int) $pagination['current_page'] + 1);
    }

    public function previousShortagePage(): void
    {
        $pagination = $this->getPaginatedShortagesData();
        $this->shortage_page = max(1, (int) $pagination['current_page'] - 1);
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

        $dateRange = $this->selectedDateRange();

        if (! $dateRange) {
            return $this->cachedReadiness = [
                'is_ready' => false,
                'blocking_message' => __('exam.readiness.reasons.period_missing'),
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
            ->lightweightStudentDistributionReadiness($college, $dateRange[0], $dateRange[1]);
    }

    public function distributionDisabledReasons(): array
    {
        $reasons = [];
        $readiness = $this->getReadinessData();

        if (! $this->college_id) {
            $reasons[] = __('exam.readiness.reasons.college_missing');
        }

        if (! $this->selectedDateRange()) {
            $reasons[] = __('exam.readiness.reasons.period_missing');
        }

        if (($readiness['offerings_count'] ?? 0) === 0) {
            $reasons[] = __('exam.readiness.reasons.no_offerings');
        }

        if (! ($readiness['has_student_distribution_run'] ?? true) && ($readiness['offerings_count'] ?? 0) > 0) {
            $reasons[] = __('exam.readiness.reasons.student_distribution_missing');
        }

        if (($readiness['unassigned_students_count'] ?? 0) > 0) {
            $reasons[] = __('exam.readiness.reasons.unassigned_students_block_invigilators');
        } elseif (($readiness['incomplete_slots_count'] ?? 0) > 0) {
            $reasons[] = __('exam.readiness.reasons.student_distribution_missing');
        }

        if (! ($readiness['has_hall_assignments'] ?? true) && ($readiness['offerings_count'] ?? 0) > 0) {
            $reasons[] = __('exam.readiness.reasons.no_used_halls');
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

    protected function refreshLastResultSummaries(): void
    {
        $this->lastNormalDistributionResult = $this->latestNormalDistributionResult();
        $this->lastFairDistributionResult = $this->latestFairDistributionResult();
    }

    protected function latestNormalDistributionResult(): ?array
    {
        $collegeId = (int) ($this->college_id ?: 0);
        $dateRange = $this->selectedDateRange();

        if ($collegeId <= 0 || ! $dateRange) {
            return null;
        }

        return AuditLog::query()
            ->with('user')
            ->where('module', 'invigilator_distribution')
            ->whereIn('action', ['invigilator_distribution.run', 'invigilator_distribution.rerun'])
            ->latest('created_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $log): ?array => $this->normalDistributionResultFromAudit($log, $collegeId, $dateRange))
            ->filter()
            ->first();
    }

    protected function normalDistributionResultFromAudit(AuditLog $log, int $collegeId, array $dateRange): ?array
    {
        $metadata = $log->metadata ?? [];

        if ((int) ($metadata['faculty_id'] ?? 0) !== $collegeId) {
            return null;
        }

        if (($metadata['from_date'] ?? null) !== $dateRange[0] || ($metadata['to_date'] ?? null) !== $dateRange[1]) {
            return null;
        }

        $summary = is_array($metadata['result_summary'] ?? null)
            ? $metadata['result_summary']
            : null;

        if ($summary) {
            $summary['executed_at'] = $summary['executed_at'] ?? $log->created_at?->format('Y-m-d H:i');
            $summary['executed_by'] = $summary['executed_by'] ?? ($log->user?->name ?? '—');

            return $summary;
        }

        $assigned = (int) ($metadata['assigned_count'] ?? 0);
        $uncovered = (int) ($metadata['shortage_count'] ?? 0);
        $required = (int) ($metadata['total_required'] ?? ($assigned + $uncovered));
        $status = $this->normalResultStatus((string) ($metadata['status'] ?? 'warning'), $assigned, $uncovered);

        return $this->normalDistributionResultPayload(
            statusType: $status,
            collegeName: $this->selectedCollegeName(),
            dateRange: $dateRange,
            totalRequired: $required,
            assigned: $assigned,
            uncovered: $uncovered,
            executedAt: $log->created_at?->format('Y-m-d H:i'),
            executedBy: $log->user?->name ?? '—',
            message: $metadata['message'] ?? null,
        );
    }

    protected function latestFairDistributionResult(): ?array
    {
        $draft = $this->draftQueryForSelection()
            ->with('creator')
            ->latest()
            ->first();

        return $draft
            ? $this->fairDistributionResultFromDraft($draft)
            : null;
    }

    protected function normalDistributionResultFromServiceResult(array $result, College $college, array $dateRange): array
    {
        $assigned = (int) ($result['assigned_count'] ?? 0);
        $uncovered = (int) ($result['shortage_count'] ?? 0);
        $required = $assigned + $uncovered;
        $status = $this->normalResultStatus((string) ($result['status'] ?? 'warning'), $assigned, $uncovered);

        return $this->normalDistributionResultPayload(
            statusType: $status,
            collegeName: $college->name,
            dateRange: $dateRange,
            totalRequired: $required,
            assigned: $assigned,
            uncovered: $uncovered,
            executedAt: now()->format('Y-m-d H:i'),
            executedBy: auth()->user()?->name ?? '—',
            message: $result['message'] ?? null,
        );
    }

    protected function failedNormalDistributionResult(College $college, ?array $dateRange, string $message): array
    {
        return $this->normalDistributionResultPayload(
            statusType: 'failed',
            collegeName: $college->name,
            dateRange: $dateRange ?? ['—', '—'],
            totalRequired: 0,
            assigned: 0,
            uncovered: 0,
            executedAt: now()->format('Y-m-d H:i'),
            executedBy: auth()->user()?->name ?? '—',
            message: $message,
        );
    }

    protected function normalDistributionResultPayload(
        string $statusType,
        string $collegeName,
        array $dateRange,
        int $totalRequired,
        int $assigned,
        int $uncovered,
        ?string $executedAt,
        ?string $executedBy,
        ?string $message = null,
    ): array {
        $statusLabel = match ($statusType) {
            'success' => 'تم التوزيع بنجاح',
            'partial' => 'تم التوزيع مع وجود مهام غير مغطاة',
            default => 'فشل التوزيع',
        };

        $explanation = match ($statusType) {
            'success' => 'تم إسناد جميع مهام المراقبة بنجاح.',
            'partial' => 'تم تنفيذ التوزيع، لكن بقيت بعض المهام غير مغطاة. يرجى مراجعة تقرير المهام غير المغطاة أو إضافة مراقبين/تعديل القيود.',
            default => $message ?: 'لم يتم تنفيذ التوزيع بسبب وجود مشكلة تمنع التوزيع. يرجى مراجعة سبب الخطأ.',
        };

        return [
            'title' => 'نتيجة توزيع المراقبين',
            'status_type' => $statusType,
            'status_label' => $statusLabel,
            'college_name' => $collegeName,
            'total_required' => max(0, $totalRequired),
            'assigned_count' => max(0, $assigned),
            'uncovered_count' => max(0, $uncovered),
            'coverage_percentage' => $this->coveragePercentage($assigned, $totalRequired),
            'period' => ($dateRange[0] ?? '—').' - '.($dateRange[1] ?? '—'),
            'from_date' => $dateRange[0] ?? null,
            'to_date' => $dateRange[1] ?? null,
            'explanation' => $explanation,
            'message' => $message,
            'executed_at' => $executedAt,
            'executed_by' => $executedBy,
            'can_use_result' => $statusType !== 'failed',
        ];
    }

    protected function normalResultStatus(string $serviceStatus, int $assigned, int $uncovered): string
    {
        if ($serviceStatus === 'danger' || ($assigned <= 0 && $uncovered > 0)) {
            return 'failed';
        }

        if ($uncovered > 0 || $serviceStatus === 'partial') {
            return 'partial';
        }

        return 'success';
    }

    protected function fairDistributionResultFromDraft(InvigilatorDistributionDraft $draft): array
    {
        $draft->loadMissing('creator');
        $summary = $draft->summary_json ?? [];
        $isFailed = (int) ($summary['proposed_duties'] ?? 0) <= 0 && (int) ($summary['total_duties'] ?? 0) > 0;

        return [
            'title' => 'نتيجة مسودة التوزيع العادل',
            'status_type' => $isFailed ? 'failed' : 'success',
            'status_label' => $isFailed ? 'فشل إنشاء مسودة التوزيع العادل' : 'تم إنشاء مسودة التوزيع العادل',
            'draft_status' => $draft->status,
            'draft_status_label' => __('exam.fair_draft.statuses.'.$draft->status),
            'draft_id' => $draft->getKey(),
            'created_at' => $draft->created_at?->format('Y-m-d H:i'),
            'created_by' => $draft->creator?->name ?? '—',
            'period' => ($draft->exam_date_from?->format('Y-m-d') ?? '—').' - '.($draft->exam_date_to?->format('Y-m-d') ?? '—'),
            'total_observers' => (int) ($summary['total_observers'] ?? 0),
            'total_duties' => (int) ($summary['total_duties'] ?? 0),
            'min_duties' => (int) ($summary['min_duties'] ?? 0),
            'max_duties' => (int) ($summary['max_duties'] ?? 0),
            'average_duties' => (float) ($summary['average_duties'] ?? 0),
            'changed_observers_count' => (int) ($summary['changed_observers_count'] ?? 0),
            'relaxed_constraints_count' => (int) ($summary['relaxed_constraints_count'] ?? 0),
            'uncovered_duties' => (int) ($summary['uncovered_duties'] ?? 0),
        ];
    }

    protected function coveragePercentage(int $assigned, int $required): float
    {
        if ($required <= 0) {
            return 0.0;
        }

        return round(($assigned / $required) * 100, 2);
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
            && (bool) ($this->getReadinessData()['is_ready'] ?? false);
    }

    public function selectedCollegeName(): string
    {
        return $this->selectedCollege()?->name ?? '—';
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

    public function reportsDashboardUrl(): string
    {
        return ReportsDashboard::getUrl();
    }

    protected function exportFilters(): array
    {
        $dateRange = $this->selectedDateRange();

        return [null, null, $dateRange[0] ?? null, $dateRange[1] ?? null];
    }

    protected function selectedDateRange(): ?array
    {
        if (! filled($this->from_date) || ! filled($this->to_date)) {
            return null;
        }

        try {
            $fromDate = Carbon::parse($this->from_date)->toDateString();
            $toDate = Carbon::parse($this->to_date)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        if ($fromDate > $toDate) {
            return null;
        }

        return [$fromDate, $toDate];
    }

    protected function assignmentQueryForSelection(): Builder
    {
        $dateRange = $this->selectedDateRange();
        $query = InvigilatorAssignment::query()
            ->where('college_id', $this->college_id ?: 0);

        return $query
            ->when($dateRange, fn (Builder $query) => $query
                ->whereDate('exam_date', '>=', $dateRange[0])
                ->whereDate('exam_date', '<=', $dateRange[1]));
    }

    protected function draftQueryForSelection(): Builder
    {
        $dateRange = $this->selectedDateRange();

        return InvigilatorDistributionDraft::query()
            ->where('college_id', $this->college_id ?: 0)
            ->when($dateRange, fn (Builder $query) => $query
                ->whereDate('exam_date_from', '>=', $dateRange[0])
                ->whereDate('exam_date_to', '<=', $dateRange[1]));
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
