<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\ExamHalls\ExamHallResource;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\StudentDistributionRun;
use App\Models\StudentDistributionRunIssue;
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
            ->with(['college', 'executor', 'issues.subjectExamOffering.subject.college', 'issues.subjectExamOffering.subject.department']);

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

        if (! $this->canExportUnassignedReports()) {
            return $this->unassignedReportNotNeededNotification();
        }

        return app(StudentDistributionRunReportService::class)->downloadUnassignedPdf($this->run);
    }

    public function exportUnassignedExcel(): StreamedResponse|Response|null
    {
        if (! $this->run) {
            return $this->missingRunNotification();
        }

        if (! $this->canExportUnassignedReports()) {
            return $this->unassignedReportNotNeededNotification();
        }

        return app(StudentDistributionRunReportService::class)->downloadUnassignedExcel($this->run);
    }

    public function savedUnassignedStudentsCount(): int
    {
        if (! $this->run) {
            return 0;
        }

        return (int) data_get(
            $this->run->summary_json,
            'validation.unassigned_students',
            $this->run->unassigned_students,
        );
    }

    public function canExportUnassignedReports(): bool
    {
        return $this->savedUnassignedStudentsCount() > 0;
    }

    public function unassignedReportDisabledMessage(): string
    {
        return __('exam.global_hall_distribution.unassigned_report_not_needed');
    }

    public function failureDetails(): array
    {
        if (! $this->run) {
            return [];
        }

        $details = collect($this->run->summary_json['failure_details'] ?? []);

        if ($details->isEmpty()) {
            $details = $this->run->issues
                ->filter(fn (StudentDistributionRunIssue $issue): bool => (int) $issue->affected_students_count > 0)
                ->map(fn (StudentDistributionRunIssue $issue): array => [
                    ...($issue->payload_json ?? []),
                    'subject_exam_offering_id' => $issue->subject_exam_offering_id,
                    'subject_name' => $issue->subjectExamOffering?->subject?->name,
                    'college_name' => $issue->subjectExamOffering?->subject?->college?->name,
                    'department_name' => $issue->subjectExamOffering?->subject?->department?->name,
                    'students_count' => $issue->affected_students_count,
                    'exam_date' => $issue->exam_date?->format('Y-m-d'),
                    'start_time' => substr((string) $issue->start_time, 0, 5),
                    'reason_code' => $issue->payload_json['reason_code'] ?? $issue->issue_type,
                    'reason_message' => $issue->message,
                    'suggested_action' => $issue->payload_json['suggested_action'] ?? __('exam.global_hall_distribution.failure_actions.review_data'),
                ]);
        }

        return $details
            ->map(fn (array $detail): array => [
                ...$detail,
                'subject_url' => filled($detail['subject_exam_offering_id'] ?? null)
                    ? SubjectExamOfferingResource::getUrl('edit', ['record' => $detail['subject_exam_offering_id']])
                    : null,
                'distribution_url' => filled($detail['subject_exam_offering_id'] ?? null)
                    ? SubjectExamOfferingResource::getUrl('distribution', ['record' => $detail['subject_exam_offering_id']])
                    : null,
                'halls_url' => ExamHallResource::getUrl('index'),
            ])
            ->values()
            ->all();
    }

    protected function missingRunNotification(): null
    {
        Notification::make()
            ->warning()
            ->title(__('exam.global_hall_distribution.no_previous_run'))
            ->send();

        return null;
    }

    protected function unassignedReportNotNeededNotification(): null
    {
        Notification::make()
            ->success()
            ->title(__('exam.global_hall_distribution.no_unassigned_students'))
            ->body($this->unassignedReportDisabledMessage())
            ->send();

        return null;
    }
}
