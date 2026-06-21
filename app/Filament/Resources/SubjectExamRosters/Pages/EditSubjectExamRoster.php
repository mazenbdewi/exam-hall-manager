<?php

namespace App\Filament\Resources\SubjectExamRosters\Pages;

use App\Filament\Resources\SubjectExamRosters\SubjectExamRosterResource;
use App\Models\SubjectExamRoster;
use App\Services\RosterStudentNumberPrefixService;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditSubjectExamRoster extends EditRecord
{
    protected static string $resource = SubjectExamRosterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prefixStudentNumbers')
                ->label('تعديل الأرقام الجامعية')
                ->icon('heroicon-o-hashtag')
                ->color('warning')
                ->visible(fn (): bool => app(RosterStudentNumberPrefixService::class)->featureIsEnabled($this->getRecord()))
                ->requiresConfirmation()
                ->modalHeading('تعديل الأرقام الجامعية')
                ->modalDescription(fn (): string => $this->studentNumberPrefixConfirmationText())
                ->modalSubmitActionLabel('تعديل الأرقام')
                ->action(function (): void {
                    $this->prefixStudentNumbers();
                }),
            Action::make('restoreOriginalStudentNumbers')
                ->label('استعادة الأرقام الأصلية')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => app(RosterStudentNumberPrefixService::class)->featureIsEnabled($this->getRecord())
                    && app(RosterStudentNumberPrefixService::class)->hasRestorableNumbers($this->getRecord()))
                ->requiresConfirmation()
                ->modalHeading('استعادة الأرقام الجامعية الأصلية')
                ->modalDescription('سيتم إعادة الرقم المستخدم في التوزيع إلى الرقم الأصلي المحفوظ لكل طالب معدل في هذه القائمة.')
                ->modalSubmitActionLabel('استعادة')
                ->action(function (): void {
                    $this->restoreOriginalStudentNumbers();
                }),
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

    protected function studentNumberPrefixConfirmationText(): string
    {
        try {
            $preview = app(RosterStudentNumberPrefixService::class)->previewPrefixing($this->getRecord());
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->implode(' ');
        }

        $examples = collect($preview['preview_rows'] ?? [])
            ->map(fn (array $row): string => ($row['name'] ?? 'طالب').' | '.($row['old_number'] ?? '—').' ← '.($row['new_number'] ?? '—'))
            ->implode("\n");

        return trim(implode("\n", [
            'يقوم بإضافة ترميز القسم إلى بداية الرقم الجامعي لتفادي تشابه الأرقام بين الأقسام.',
            'عدد الطلاب في القائمة: '.($preview['students_count'] ?? 0),
            'عدد الطلاب المتوقع تعديلهم: '.($preview['updatable_students_count'] ?? 0),
            'ترميز القسم المستخدم: '.($preview['prefix'] ?? '—'),
            'معاينة أول 10 طلاب:',
            $examples ?: 'لا توجد بيانات طلاب للمعاينة.',
            'تنبيه: العملية تؤثر على منطق كشف التعارضات في مولّد البرنامج الامتحاني.',
        ]));
    }

    protected function prefixStudentNumbers(): void
    {
        try {
            $result = app(RosterStudentNumberPrefixService::class)->applyPrefixing($this->getRecord());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('تعذر تعديل الأرقام الجامعية')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('تم تعديل الأرقام الجامعية بنجاح')
            ->body('عدد الطلاب المعدلين: '.($result['updated_students_count'] ?? 0))
            ->success()
            ->send();
    }

    protected function restoreOriginalStudentNumbers(): void
    {
        try {
            $result = app(RosterStudentNumberPrefixService::class)->restoreOriginalNumbers($this->getRecord());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('تعذر استعادة الأرقام الأصلية')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('تمت استعادة الأرقام الأصلية')
            ->body('عدد الطلاب المستعادين: '.($result['updated_students_count'] ?? 0))
            ->success()
            ->send();
    }
}
