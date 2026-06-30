<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Services\SubjectExamOfferingRosterSyncService;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListSubjectExamOfferings extends ListRecords
{
    protected static string $resource = SubjectExamOfferingResource::class;

    public function getTitle(): string
    {
        return 'مسودة البرنامج الامتحاني';
    }

    public function getTabs(): array
    {
        return [
            'drafts' => Tab::make('مسودات البرنامج')
                ->query(fn (Builder $query): Builder => $query->where('status', 'draft'))
                ->badge(fn (): int => $this->getOfferingsCount('drafts')),
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
            Action::make('syncRosters')
                ->label('مزامنة قوائم الطلاب مع البرامج الامتحانية')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('مزامنة قوائم الطلاب مع البرامج الامتحانية')
                ->modalDescription('سيتم تحديث طلاب البرامج الامتحانية من قوائم الطلاب الجاهزة المطابقة دون فتح كل برنامج أو قائمة يدوياً.')
                ->action(function (): void {
                    $filters = [];

                    if (! ExamCollegeScope::isSuperAdmin() && ExamCollegeScope::currentCollegeId()) {
                        $filters['college_id'] = ExamCollegeScope::currentCollegeId();
                    }

                    $summary = app(SubjectExamOfferingRosterSyncService::class)->syncOfferings($filters);

                    Notification::make()
                        ->title('تمت مزامنة قوائم الطلاب')
                        ->body(implode(' | ', [
                            'البرامج المفحوصة: '.$summary['offerings_scanned'],
                            'القوائم المطابقة: '.$summary['rosters_matched'],
                            'الطلاب المزامنون: '.$summary['students_synced'],
                            'بدون قائمة جاهزة: '.$summary['offerings_without_ready_roster'],
                            'الأخطاء: '.$summary['errors_count'],
                        ]))
                        ->color($summary['errors_count'] > 0 ? 'warning' : 'success')
                        ->send();
                }),
            CreateAction::make()
                ->label('إضافة مادة امتحانية')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getOfferingsCount(string $scope): int
    {
        $query = SubjectExamOfferingResource::getEloquentQuery();

        return match ($scope) {
            'today' => $query->whereTodayExam()->count(),
            'upcoming' => $query->whereUpcomingExam()->count(),
            'finished' => $query->whereFinishedExam()->count(),
            'drafts' => $query->where('status', 'draft')->count(),
            default => $query->count(),
        };
    }
}
