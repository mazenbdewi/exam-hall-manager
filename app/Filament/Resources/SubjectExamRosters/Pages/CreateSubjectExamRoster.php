<?php

namespace App\Filament\Resources\SubjectExamRosters\Pages;

use App\Filament\Resources\SubjectExamRosters\SubjectExamRosterResource;
use App\Models\SubjectExamRoster;
use App\Support\ExamCollegeScope;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateSubjectExamRoster extends CreateRecord
{
    protected static string $resource = SubjectExamRosterResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);
        $subject = ExamCollegeScope::ensureSubjectBelongsToAccessibleCollege($data['subject_id'] ?? null);
        $data['department_id'] = $data['department_id'] ?: $subject->department_id;
        $data['study_level_id'] = $data['study_level_id'] ?: $subject->study_level_id;

        $this->ensureRosterIsUnique($data);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function ensureRosterIsUnique(array $data): void
    {
        $exists = SubjectExamRoster::query()
            ->where('college_id', $data['college_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('semester_id', $data['semester_id'])
            ->where(function ($query) use ($data): void {
                filled($data['department_id'] ?? null)
                    ? $query->where('department_id', $data['department_id'])
                    : $query->whereNull('department_id');
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'subject_id' => 'توجد قائمة طلاب لهذه المادة ضمن نفس الكلية والقسم والعام والفصل.',
            ]);
        }
    }
}
