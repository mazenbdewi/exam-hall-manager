<?php

namespace App\Filament\Resources\SubjectExamRosters\Pages;

use App\Filament\Resources\SubjectExamRosters\SubjectExamRosterResource;
use App\Models\SubjectExamRoster;
use App\Support\ExamCollegeScope;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditSubjectExamRoster extends EditRecord
{
    protected static string $resource = SubjectExamRosterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('حذف')
                ->modalHeading('حذف قائمة طلاب المادة')
                ->modalSubmitActionLabel('حذف'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);
        $subject = ExamCollegeScope::ensureSubjectBelongsToAccessibleCollege($data['subject_id'] ?? null);
        $data['department_id'] = $data['department_id'] ?: $subject->department_id;
        $data['study_level_id'] = $data['study_level_id'] ?: $subject->study_level_id;

        $this->ensureRosterIsUnique($data);

        return $data;
    }

    protected function ensureRosterIsUnique(array $data): void
    {
        $exists = SubjectExamRoster::query()
            ->whereKeyNot($this->getRecord()->getKey())
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
