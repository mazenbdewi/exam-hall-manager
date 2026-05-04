<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use Filament\Actions\CreateAction;
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
            CreateAction::make(),
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
            default => $query->count(),
        };
    }
}
