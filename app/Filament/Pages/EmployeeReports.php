<?php

namespace App\Filament\Pages;

use App\Models\College;
use App\Services\AuditLogService;
use App\Services\EmployeePerformancePdfService;
use App\Services\EmployeePerformanceReportService;
use App\Support\ExamCollegeScope;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $slug = 'employee-reports';

    protected string $view = 'filament.pages.employee-reports';

    public ?int $college_id = null;

    public ?string $from_date = null;

    public ?string $to_date = null;

    protected ?array $cachedReport = null;

    public function mount(EmployeePerformanceReportService $reportService): void
    {
        $this->college_id = request()->integer('college_id')
            ?: College::query()->where('is_active', true)->orderBy('name')->value('id');

        $college = $this->selectedCollege();

        if ($college) {
            $this->from_date = request()->string('from_date')->toString() ?: $reportService->firstReportDate($college);
            $this->to_date = request()->string('to_date')->toString() ?: $reportService->lastReportDate($college);
        }
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.reports_printing');
    }

    public static function getNavigationSort(): ?int
    {
        return 21;
    }

    public static function getNavigationLabel(): string
    {
        return 'تقارير الموظفين';
    }

    public function getTitle(): string|Htmlable
    {
        return 'تقارير الموظفين';
    }

    public function getHeading(): string
    {
        return 'تقارير الموظفين';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return ExamCollegeScope::isSuperAdmin();
    }

    public function updated(string $property): void
    {
        $this->cachedReport = null;

        if ($property === 'college_id') {
            $college = $this->selectedCollege();

            if ($college) {
                $service = app(EmployeePerformanceReportService::class);
                $this->from_date = $service->firstReportDate($college);
                $this->to_date = $service->lastReportDate($college);
            }
        }
    }

    public function collegeOptions(): array
    {
        return College::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        if ($this->cachedReport !== null) {
            return $this->cachedReport;
        }

        $college = $this->selectedCollege();

        if (! $college) {
            return $this->cachedReport = [
                'college' => null,
                'from_date' => $this->from_date,
                'to_date' => $this->to_date,
                'total_employees' => 0,
                'active_employees' => 0,
                'assigned_tasks_count' => 0,
                'required_tasks_count' => 0,
                'shortage_count' => 0,
                'completion_percentage' => 0,
                'days_count' => 0,
                'halls_count' => 0,
                'employees' => collect(),
                'top_employees' => [],
                'status_counts' => [],
            ];
        }

        return $this->cachedReport = app(EmployeePerformanceReportService::class)
            ->report($college, $this->from_date, $this->to_date);
    }

    public function exportPdf(): StreamedResponse|Response|null
    {
        $college = $this->selectedCollege();

        if (! $college) {
            Notification::make()
                ->warning()
                ->title(__('exam.readiness.reasons.college_missing'))
                ->send();

            return null;
        }

        app(AuditLogService::class)->log(
            action: 'export.pdf',
            module: 'exports',
            description: 'تصدير تقرير الموظفين',
            metadata: [
                'report_type' => 'employee_performance',
                'faculty_id' => $college->getKey(),
                'from_date' => $this->from_date,
                'to_date' => $this->to_date,
            ],
        );

        return app(EmployeePerformancePdfService::class)->download($college, $this->from_date, $this->to_date);
    }

    protected function selectedCollege(): ?College
    {
        if (! $this->college_id) {
            return null;
        }

        return College::query()
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
            ->find($this->college_id);
    }
}
