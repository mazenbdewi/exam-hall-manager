<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\StudentDistributionRun;
use App\Services\StudentDistributionRunReportService;
use App\Support\ExamCollegeScope;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GlobalDistributionResults extends Page
{
    protected static string $resource = SubjectExamOfferingResource::class;

    protected string $view = 'filament.resources.subject-exam-offerings.pages.global-distribution-results';

    public ?StudentDistributionRun $run = null;

    public function mount(StudentDistributionRun|int|string|null $run = null): void
    {
        $runId = $run instanceof StudentDistributionRun ? $run->getKey() : $run;

        $query = StudentDistributionRun::query()
            ->with(['college', 'executor', 'issues.subjectExamOffering.subject']);

        if (filled($runId)) {
            $query->whereKey($runId);
        } else {
            $query->latest('executed_at')->latest('id');
        }

        if (! ExamCollegeScope::isSuperAdmin()) {
            $query->where('college_id', ExamCollegeScope::currentCollegeId());
        }

        $this->run = $query->first();
    }

    public function getTitle(): string|Htmlable
    {
        return __('exam.global_hall_distribution.results_title');
    }

    public function exportSummaryPdf(): StreamedResponse|Response|null
    {
        if (! $this->run) {
            return $this->missingRunNotification();
        }

        return app(StudentDistributionRunReportService::class)->downloadSummaryPdf($this->run);
    }

    public function exportUnassignedPdf(): StreamedResponse|Response|null
    {
        if (! $this->run) {
            return $this->missingRunNotification();
        }

        return app(StudentDistributionRunReportService::class)->downloadUnassignedPdf($this->run);
    }

    public function exportUnassignedExcel(): StreamedResponse|Response|null
    {
        if (! $this->run) {
            return $this->missingRunNotification();
        }

        return app(StudentDistributionRunReportService::class)->downloadUnassignedExcel($this->run);
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
