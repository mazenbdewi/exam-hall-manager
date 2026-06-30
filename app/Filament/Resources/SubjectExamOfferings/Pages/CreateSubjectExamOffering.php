<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Services\ExamScheduleGeneratorService;
use App\Services\SubjectExamOfferingRosterSyncService;
use App\Support\ExamCollegeScope;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class CreateSubjectExamOffering extends CreateRecord
{
    protected static string $resource = SubjectExamOfferingResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->validateSubjectContext(
            ExamCollegeScope::ensureSubjectBelongsToAccessibleCollege($data['subject_id'] ?? null),
        );

        if ((bool) ($data['is_pinned'] ?? false)) {
            app(ExamScheduleGeneratorService::class)->ensureOfferingCanBePinned(new SubjectExamOffering($data));
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        app(SubjectExamOfferingRosterSyncService::class)->syncOffering($this->getRecord());
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('exam.helpers.create_offering_students_after_save');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
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
