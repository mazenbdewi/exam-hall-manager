<?php

namespace App\Filament\Resources\Subjects\Pages;

use App\Filament\Resources\SubjectExamRosters\SubjectExamRosterResource;
use App\Filament\Resources\Subjects\SubjectResource;
use App\Models\SubjectExamRoster;
use App\Support\ExamCollegeScope;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EditSubject extends EditRecord
{
    protected static string $resource = SubjectResource::class;

    protected function afterFill(): void
    {
        $subject = $this->getRecord();

        if (! $subject->is_shared_subject || $subject->sharedDepartments()->exists()) {
            return;
        }

        Notification::make()
            ->title('هذه المادة محددة كمادة مشتركة، لكن لم يتم تحديد الأقسام المشتركة معها بعد.')
            ->body('حدد الأقسام التي تدرس هذه المادة قبل استخدامها في توليد البرنامج الامتحاني.')
            ->warning()
            ->persistent()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);
        ExamCollegeScope::ensureDepartmentBelongsToCollege($data['department_id'] ?? null, $data['college_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->getRecord()->is_shared_subject) {
            $this->getRecord()->sharedDepartments()->detach();
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.resources.subjects.subject-rosters-summary'),
                $this->getFormContentComponent(),
            ]);
    }

    public function subjectRosterSummary(): array
    {
        $rosters = SubjectExamRoster::query()
            ->where('subject_id', $this->getRecord()->getKey())
            ->withCount([
                'rosterStudents',
                'rosterStudents as regular_students_count' => fn (Builder $query) => $query->where('student_type', 'regular'),
                'rosterStudents as carry_students_count' => fn (Builder $query) => $query->where('student_type', 'carry'),
            ])
            ->get();

        return [
            'rosters_count' => $rosters->count(),
            'ready_count' => $rosters->where('status', 'ready')->count(),
            'students_count' => (int) $rosters->sum('roster_students_count'),
            'regular_count' => (int) $rosters->sum('regular_students_count'),
            'carry_count' => (int) $rosters->sum('carry_students_count'),
            'last_updated_at' => $rosters->max('updated_at'),
        ];
    }

    public function manageSubjectRostersUrl(): string
    {
        return SubjectExamRosterResource::getUrl('index', [
            'tableFilters' => [
                'subject_id' => [
                    'value' => $this->getRecord()->getKey(),
                ],
            ],
        ]);
    }

    public function createSubjectRosterUrl(): string
    {
        $subject = $this->getRecord();

        return SubjectExamRosterResource::getUrl('create', [
            'subject_id' => $subject->getKey(),
            'college_id' => $subject->college_id,
            'department_id' => $subject->department_id,
            'study_level_id' => $subject->study_level_id,
        ]);
    }
}
