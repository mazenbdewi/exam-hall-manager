<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\ExamHalls\ExamHallResource;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Services\ExamHallDistributionService;
use App\Services\HallDistributionPdfService;
use App\Support\ExamCollegeScope;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageSlotHallDistribution extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SubjectExamOfferingResource::class;

    protected string $view = 'filament.resources.subject-exam-offerings.pages.manage-slot-hall-distribution';

    protected ?array $cachedSummary = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        ExamCollegeScope::ensureOfferingBelongsToAccessibleCollege($this->getRecord());
    }

    public function getTitle(): string|Htmlable
    {
        return __('exam.pages.slot_hall_distribution');
    }

    public function getSubheading(): string|Htmlable|null
    {
        $summary = $this->getSummaryData();

        return __('exam.helpers.slot_distribution_subheading', [
            'college' => $summary['context']['college_name'] ?? '',
            'date' => $summary['exam_date'] ?? $this->getRecord()->exam_date?->format('Y-m-d'),
            'time' => $this->getFormattedExamTime(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function runDistribution(): void
    {
        $result = app(ExamHallDistributionService::class)->distributeForOffering($this->getRecord());

        $notification = Notification::make()
            ->title(match ($result['status']) {
                'success' => __('exam.notifications.distribution_completed'),
                'warning' => __('exam.notifications.distribution_warning'),
                default => __('exam.notifications.distribution_failed'),
            })
            ->body($result['message']);

        match ($result['status']) {
            'success' => $notification->success(),
            'warning' => $notification->warning()->persistent(),
            default => $notification->danger()->persistent(),
        };

        $notification->send();
        $this->forgetCachedSummary();
    }

    public function exportPdf(): StreamedResponse|Response|null
    {
        if (! $this->hasDistribution()) {
            Notification::make()
                ->warning()
                ->title(__('exam.notifications.distribution_no_assignments_to_export'))
                ->body(__('exam.helpers.no_hall_distribution_results'))
                ->send();

            return null;
        }

        return app(HallDistributionPdfService::class)->downloadForOffering($this->getRecord());
    }

    public function exportUnassignedPdf(): StreamedResponse|Response|null
    {
        $summary = $this->getSummaryData();

        if (empty($summary['unassigned_students'] ?? [])) {
            Notification::make()
                ->warning()
                ->title('لا يوجد طلاب غير موزعين')
                ->body('كل الطلاب موزعون حاليًا، لذلك لا يوجد كشف غير موزعين للتصدير.')
                ->send();

            return null;
        }

        return app(HallDistributionPdfService::class)->downloadUnassignedForOffering($this->getRecord(), $summary);
    }

    public function hasDistribution(): bool
    {
        return (int) ($this->getSummaryData()['used_halls_count'] ?? 0) > 0;
    }

    public function getDistributionActionLabel(): string
    {
        return $this->hasDistribution()
            ? __('exam.actions.redistribute')
            : __('exam.actions.run_hall_distribution');
    }

    public function getFormattedExamTime(): string
    {
        return substr((string) ($this->getSummaryData()['exam_start_time'] ?? $this->getRecord()->exam_start_time), 0, 5);
    }

    public function getSessionOfferingsCount(): int
    {
        return count($this->getSummaryData()['offerings_summary'] ?? []);
    }

    public function getCreateHallUrl(): ?string
    {
        if (! ExamHallResource::canCreate()) {
            return null;
        }

        return ExamHallResource::getUrl('create');
    }

    public function getOfferingsIndexUrl(): string
    {
        return SubjectExamOfferingResource::getUrl('index');
    }

    public function canExportExcel(): bool
    {
        return false;
    }

    public function getSummaryData(): array
    {
        if ($this->cachedSummary !== null) {
            return $this->cachedSummary;
        }

        return $this->cachedSummary = app(ExamHallDistributionService::class)->getSlotSummary($this->getRecord());
    }

    protected function forgetCachedSummary(): void
    {
        $this->cachedSummary = null;
    }

    public function rendered(View $view, string $html): void
    {
        if (mb_check_encoding($html, 'UTF-8')) {
            return;
        }

        Log::error('Invalid UTF-8 detected in rendered slot hall distribution HTML.', [
            'record_id' => $this->getRecord()->getKey(),
            'html_preview' => substr($html, 0, 300),
            'html_hex' => bin2hex(substr($html, 0, 120)),
        ]);
    }
}
