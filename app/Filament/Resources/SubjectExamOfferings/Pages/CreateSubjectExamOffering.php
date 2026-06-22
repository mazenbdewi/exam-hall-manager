<?php

namespace App\Filament\Resources\SubjectExamOfferings\Pages;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\SubjectExamOffering;
use App\Services\ExamScheduleGeneratorService;
use App\Support\ExamCollegeScope;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateSubjectExamOffering extends CreateRecord
{
    protected static string $resource = SubjectExamOfferingResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        ExamCollegeScope::ensureSubjectBelongsToAccessibleCollege($data['subject_id'] ?? null);

        if ((bool) ($data['is_pinned'] ?? false)) {
            app(ExamScheduleGeneratorService::class)->ensureOfferingCanBePinned(new SubjectExamOffering($data));
        }

        return $data;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('exam.helpers.create_offering_students_after_save');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
