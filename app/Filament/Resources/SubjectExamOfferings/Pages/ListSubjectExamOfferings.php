<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\College;
use App\Models\StudentDistributionRun;
use App\Services\AuditLogService;
use App\Services\ExamHallDistributionService;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ListSubjectExamOfferings extends ListRecords
{
    protected static string $resource = SubjectExamOfferingResource::class;

    public function getTabs(): array
    {
        return [
            'today' => Tab::make('امتحانات اليوم')
                ->query(fn (Builder $query): Builder => $query->whereTodayExam())
                ->badge(fn (): int => $this->getOfferingsCount('today')),
            'upcoming' => Tab::make('الامتحانات القادمة')
                ->query(fn (Builder $query): Builder => $query->whereUpcomingExam())
                ->badge(fn (): int => $this->getOfferingsCount('upcoming')),
            'finished' => Tab::make('الامتحانات المنتهية')
                ->query(fn (Builder $query): Builder => $query->whereFinishedExam())
                ->badge(fn (): int => $this->getOfferingsCount('finished')),
            'all' => Tab::make('الكل')
                ->badge(fn (): int => $this->getOfferingsCount('all')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('globalStudentHallDistribution')
                ->label(__('exam.actions.global_hall_distribution_by_college'))
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalHeading(__('exam.global_hall_distribution.modal_title'))
                ->modalDescription(__('exam.global_hall_distribution.modal_description'))
                ->modalSubmitAction(fn (Action $action): Action => $action
                    ->label(__('exam.actions.run_global_hall_distribution')))
                ->modalWidth('2xl')
                ->closeModalByClickingAway(false)
                ->form([
                    Select::make('college_id')
                        ->label(__('exam.fields.college'))
                        ->options(fn (): array => College::query()
                            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?int => ExamCollegeScope::currentCollegeId())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->hidden(fn (): bool => ! ExamCollegeScope::isSuperAdmin()),
                    DatePicker::make('from_date')
                        ->label(__('exam.fields.from_date'))
                        ->required()
                        ->live(),
                    DatePicker::make('to_date')
                        ->label(__('exam.fields.to_date'))
                        ->required()
                        ->live()
                        ->afterOrEqual('from_date'),
                    Checkbox::make('redistribute')
                        ->label(__('exam.global_hall_distribution.redistribute_label'))
                        ->helperText(__('exam.global_hall_distribution.redistribute_helper'))
                        ->live(),
                    Checkbox::make('confirmed')
                        ->label(__('exam.global_hall_distribution.confirmation_label'))
                        ->accepted()
                        ->required()
                        ->live(),
                ])
                ->action(function (array $data): void {
                    Log::info('Global student distribution action data', $data);

                    if (
                        (ExamCollegeScope::isSuperAdmin() && empty($data['college_id']))
                        || empty($data['from_date'])
                        || empty($data['to_date'])
                        || ! (bool) ($data['confirmed'] ?? false)
                    ) {
                        Notification::make()
                            ->danger()
                            ->title(__('exam.notifications.global_hall_distribution_failed'))
                            ->body(__('exam.global_hall_distribution.reasons.missing_required_inputs'))
                            ->send();

                        app(AuditLogService::class)->log(
                            action: 'student_distribution.run',
                            module: 'student_distribution',
                            description: 'تنفيذ توزيع الطلاب',
                            metadata: [
                                'faculty_id' => $data['college_id'] ?? null,
                                'from_date' => $data['from_date'] ?? null,
                                'to_date' => $data['to_date'] ?? null,
                                'status' => 'missing_required_inputs',
                            ],
                            status: 'failed',
                        );

                        return;
                    }

                    $collegeId = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);
                    $result = app(ExamHallDistributionService::class)->distributeForFacultyDateRange(
                        collegeId: $collegeId,
                        fromDate: (string) $data['from_date'],
                        toDate: (string) $data['to_date'],
                        redistribute: (bool) ($data['redistribute'] ?? false),
                    );

                    app(AuditLogService::class)->log(
                        action: (bool) ($data['redistribute'] ?? false)
                            ? 'student_distribution.rerun'
                            : 'student_distribution.run',
                        module: 'student_distribution',
                        description: (bool) ($data['redistribute'] ?? false)
                            ? 'إعادة توزيع الطلاب'
                            : 'تنفيذ توزيع الطلاب',
                        metadata: [
                            'faculty_id' => $collegeId,
                            'from_date' => (string) $data['from_date'],
                            'to_date' => (string) $data['to_date'],
                            'total_students' => $result['total_students'] ?? null,
                            'distributed_students' => $result['distributed_students'] ?? null,
                            'unassigned_students' => $result['unassigned_students'] ?? null,
                            'used_halls' => $result['used_halls'] ?? null,
                            'status' => $result['status'] ?? null,
                            'run_id' => $result['run_id'] ?? null,
                        ],
                        status: match ($result['status'] ?? 'failed') {
                            'success' => 'success',
                            'partial' => 'warning',
                            default => 'failed',
                        },
                    );

                    if (($result['status'] ?? 'failed') === 'failed') {
                        Notification::make()
                            ->danger()
                            ->title(__('exam.notifications.global_hall_distribution_failed'))
                            ->body(collect([
                                $result['reason'] ?? $result['message'],
                                __('exam.global_hall_distribution.review_failure_reason'),
                            ])->filter()->implode(' '))
                            ->persistent()
                            ->send();

                        $this->redirect($result['result_url']);

                        return;
                    }

                    $notification = Notification::make()
                        ->title(match ($result['status']) {
                            'success' => __('exam.notifications.global_hall_distribution_completed'),
                            'partial' => __('exam.notifications.global_hall_distribution_completed_with_issues'),
                            default => __('exam.notifications.global_hall_distribution_failed'),
                        })
                        ->body($this->globalDistributionSummaryBody($result));

                    (match ($result['status']) {
                        'success' => $notification->success(),
                        'partial' => $notification->warning()->persistent(),
                        default => $notification->danger()->persistent(),
                    })->send();

                    $this->redirect($result['result_url']);
                }),
            CreateAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                View::make('filament.resources.subject-exam-offerings.latest-global-distribution-run'),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    public function latestDistributionRun(): ?StudentDistributionRun
    {
        return StudentDistributionRun::query()
            ->with(['college', 'executor'])
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('college_id', ExamCollegeScope::currentCollegeId()))
            ->latest('executed_at')
            ->latest('id')
            ->first();
    }

    protected function globalDistributionSummaryBody(array $result): string
    {
        $intro = match ($result['status'] ?? 'failed') {
            'success' => __('exam.global_hall_distribution.success_notification_body'),
            'partial' => __('exam.global_hall_distribution.partial_notification_body').' '.__('exam.global_hall_distribution.summary.unassigned_students_count').': '.($result['unassigned_students'] ?? 0).'. '.__('exam.global_hall_distribution.shortage_report_hint'),
            default => __('exam.global_hall_distribution.failed_notification_body'),
        };

        return collect([
            $intro,
            __('exam.global_hall_distribution.summary.offerings_count').': '.($result['total_offerings'] ?? 0),
            __('exam.global_hall_distribution.summary.slots_count').': '.($result['total_slots'] ?? 0),
            __('exam.global_hall_distribution.summary.students_count').': '.($result['total_students'] ?? 0),
            __('exam.global_hall_distribution.summary.assigned_students_count').': '.($result['distributed_students'] ?? 0),
            __('exam.global_hall_distribution.summary.unassigned_students_count').': '.($result['unassigned_students'] ?? 0),
            __('exam.global_hall_distribution.summary.used_halls_count').': '.($result['used_halls'] ?? 0),
            __('exam.global_hall_distribution.summary.total_capacity').': '.($result['total_capacity'] ?? 0),
            __('exam.global_hall_distribution.summary.capacity_shortage').': '.($result['capacity_shortage'] ?? 0),
            __('exam.global_hall_distribution.summary.distributed_slots_count').': '.($result['distributed_slots_count'] ?? 0),
            __('exam.global_hall_distribution.summary.skipped_slots_count').': '.($result['skipped_slots_count'] ?? 0),
            __('exam.global_hall_distribution.summary.issue_slots_count').': '.($result['issue_slots_count'] ?? 0),
        ])->implode(' | ');
    }

    protected function getOfferingsCount(string $scope): int
    {
        $query = SubjectExamOfferingResource::getEloquentQuery();

        return match ($scope) {
            'today' => $query->whereTodayExam()->count(),
            'upcoming' => $query->whereUpcomingExam()->count(),
            'finished' => $query->whereFinishedExam()->count(),
            default => $query->count(),
        };
    }
}
