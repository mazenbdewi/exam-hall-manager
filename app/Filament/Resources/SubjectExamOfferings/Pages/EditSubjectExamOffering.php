<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\Subject;
use App\Services\ExamScheduleGeneratorService;
use App\Services\SubjectExamOfferingRosterSyncService;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class EditSubjectExamOffering extends EditRecord
{
    protected static string $resource = SubjectExamOfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageDistribution')
                ->label(__('exam.actions.manage_hall_distribution'))
                ->icon('heroicon-o-squares-2x2')
                ->url(fn (): string => SubjectExamOfferingResource::getUrl('distribution', ['record' => $this->getRecord()])),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validateSubjectContext(
            ExamCollegeScope::ensureSubjectBelongsToAccessibleCollege($data['subject_id'] ?? null),
        );

        if ((bool) ($data['is_pinned'] ?? false)) {
            $offering = $this->getRecord()->fill($data);
            app(ExamScheduleGeneratorService::class)->ensureOfferingCanBePinned($offering);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        app(SubjectExamOfferingRosterSyncService::class)->syncOffering(
            $this->getRecord(),
            replaceExisting: $this->getRecord()->wasChanged(['subject_id', 'academic_year_id', 'semester_id']),
        );
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('exam.helpers.edit_offering_students');
    }

    protected function validateSubjectContext(Subject $subject): void
    {
        if (! filled($subject->college_id) || ! filled($subject->department_id)) {
            throw ValidationException::withMessages([
                'subject_id' => __('exam.validation.subject_missing_college_department'),
            ]);
        }
    }
}
