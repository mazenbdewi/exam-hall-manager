<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Services\ExamScheduleGeneratorService;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

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
        ExamCollegeScope::ensureSubjectBelongsToAccessibleCollege($data['subject_id'] ?? null);

        if ((bool) ($data['is_pinned'] ?? false)) {
            $offering = $this->getRecord()->fill($data);
            app(ExamScheduleGeneratorService::class)->ensureOfferingCanBePinned($offering);
        }

        return $data;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('exam.helpers.edit_offering_students');
    }
}
