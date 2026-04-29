<?php

namespace App\Filament\Resources\SubjectExamRosters\Pages;

use App\Filament\Resources\SubjectExamRosters\SubjectExamRosterResource;
use App\Models\SubjectExamRoster;
use App\Support\ExamCollegeScope;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

class ListSubjectExamRosters extends ListRecords
{
    protected static string $resource = SubjectExamRosterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('إنشاء قائمة طلاب مادة'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                View::make('filament.resources.subject-exam-rosters.overview'),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    public function rosterSummary(): array
    {
        $rosters = ExamCollegeScope::applyCollegeScope(
            SubjectExamRoster::query()
                ->with(['subject'])
                ->withCount([
                    'rosterStudents',
                    'rosterStudents as regular_students_count' => fn ($query) => $query->where('student_type', 'regular'),
                    'rosterStudents as carry_students_count' => fn ($query) => $query->where('student_type', 'carry'),
                ]),
        )->get();

        return [
            'rosters_count' => $rosters->count(),
            'students_count' => (int) $rosters->sum('roster_students_count'),
            'regular_count' => (int) $rosters->sum('regular_students_count'),
            'carry_count' => (int) $rosters->sum('carry_students_count'),
            'draft_count' => $rosters->where('status', 'draft')->count(),
            'ready_count' => $rosters->where('status', 'ready')->count(),
            'used_count' => $rosters->where('status', 'used')->count(),
            'archived_count' => $rosters->where('status', 'archived')->count(),
        ];
    }
}
