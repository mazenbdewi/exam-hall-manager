<?php

namespace App\Filament\Resources\SubjectExamRosters\RelationManagers;

use App\Models\SubjectExamRoster;
use App\Services\RosterStudentNumberPrefixService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RosterStudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'rosterStudents';

    protected static ?string $modelLabel = 'طالب في قائمة المادة';

    protected static ?string $pluralModelLabel = 'طلاب قائمة المادة';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('student_number')
                ->label('الرقم الامتحاني')
                ->required()
                ->maxLength(255),
            TextInput::make('full_name')
                ->label('اسم الطالب')
                ->required()
                ->maxLength(255),
            Select::make('student_type')
                ->label('نوع الطالب')
                ->options([
                    'regular' => 'مستجد',
                    'carry' => 'حملة',
                ])
                ->default('regular')
                ->required(),
            Toggle::make('is_eligible')
                ->label('نشط')
                ->default(true),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel('طالب في قائمة المادة')
            ->pluralModelLabel('طلاب قائمة المادة')
            ->recordTitleAttribute('full_name')
            ->defaultSort('student_number')
            ->columns([
                TextColumn::make('student_number')
                    ->label('الرقم الامتحاني')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('original_student_number')
                    ->label('الرقم الجامعي الأصلي')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('full_name')
                    ->label('اسم الطالب')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('student_type')
                    ->label('نوع الطالب')
                    ->formatStateUsing(fn (string $state): string => $state === 'carry' ? 'حملة' : 'مستجد')
                    ->badge(),
                IconColumn::make('is_eligible')
                    ->label('نشط')
                    ->boolean(),
                TextColumn::make('notes')
                    ->label('ملاحظات')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('student_type')
                    ->label('نوع الطالب')
                    ->options([
                        'regular' => 'مستجد',
                        'carry' => 'حملة',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->modelLabel('طالب في قائمة المادة')
                    ->pluralModelLabel('طلاب قائمة المادة')
                    ->label('إضافة طالب إلى القائمة')
                    ->modalHeading('إضافة طالب إلى القائمة')
                    ->modalSubmitActionLabel('إضافة')
                    ->createAnother(false),
                Action::make('prefixStudentNumbers')
                    ->label('تعديل الأرقام الجامعية')
                    ->icon('heroicon-o-identification')
                    ->color('warning')
                    ->visible(fn (): bool => app(RosterStudentNumberPrefixService::class)->featureIsEnabled($this->ownerRoster()))
                    ->requiresConfirmation()
                    ->modalHeading('تعديل الأرقام الجامعية')
                    ->modalDescription(fn (): string => $this->studentNumberPrefixConfirmationText())
                    ->modalSubmitActionLabel('تعديل الأرقام')
                    ->action(function (): void {
                        $this->prefixStudentNumbers();
                    }),
                Action::make('restoreOriginalStudentNumbers')
                    ->label('استعادة الأرقام الأصلية')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('gray')
                    ->visible(fn (): bool => app(RosterStudentNumberPrefixService::class)->featureIsEnabled($this->ownerRoster())
                        && app(RosterStudentNumberPrefixService::class)->hasRestorableNumbers($this->ownerRoster()))
                    ->requiresConfirmation()
                    ->modalHeading('استعادة الأرقام الجامعية الأصلية')
                    ->modalDescription('سيتم إعادة الرقم المستخدم في التوزيع إلى الرقم الأصلي المحفوظ لكل طالب معدل في هذه القائمة.')
                    ->modalSubmitActionLabel('استعادة')
                    ->action(function (): void {
                        $this->restoreOriginalStudentNumbers();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modelLabel('طالب في قائمة المادة')
                    ->pluralModelLabel('طلاب قائمة المادة')
                    ->label('تعديل')
                    ->modalHeading('تعديل طالب في قائمة المادة')
                    ->modalSubmitActionLabel('حفظ التعديلات'),
                DeleteAction::make()
                    ->modelLabel('طالب في قائمة المادة')
                    ->pluralModelLabel('طلاب قائمة المادة')
                    ->label('حذف')
                    ->modalHeading('حذف طالب من القائمة')
                    ->modalSubmitActionLabel('حذف'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modelLabel('طالب في قائمة المادة')
                        ->pluralModelLabel('طلاب قائمة المادة')
                        ->label('حذف المحدد')
                        ->modalHeading('حذف الطلاب المحددين')
                        ->modalSubmitActionLabel('حذف'),
                ]),
            ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'الطلاب';
    }

    protected function ownerRoster(): SubjectExamRoster
    {
        /** @var SubjectExamRoster $roster */
        $roster = $this->getOwnerRecord();

        return $roster;
    }

    protected function studentNumberPrefixConfirmationText(): string
    {
        try {
            $preview = app(RosterStudentNumberPrefixService::class)->previewPrefixing($this->ownerRoster());
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
            $result = app(RosterStudentNumberPrefixService::class)->applyPrefixing($this->ownerRoster());
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
            $result = app(RosterStudentNumberPrefixService::class)->restoreOriginalNumbers($this->ownerRoster());
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
