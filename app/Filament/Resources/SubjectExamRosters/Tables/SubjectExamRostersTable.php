<?php

namespace App\Filament\Resources\SubjectExamRosters\Tables;

use App\Exports\SubjectExamRosterStudentsTemplateExport;
use App\Imports\SubjectExamRosterStudentsImport;
use App\Models\SubjectExamRoster;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class SubjectExamRostersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->emptyStateHeading('لا توجد قوائم طلاب جاهزة.')
            ->emptyStateDescription('يجب استيراد الطلاب وتحديد القوائم كجاهزة قبل توليد البرنامج.')
            ->columns([
                TextColumn::make('college.name')
                    ->label('الكلية')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                TextColumn::make('department.name')
                    ->label('القسم')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('subject.name')
                    ->label('المادة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('academicYear.name')
                    ->label('العام الدراسي')
                    ->sortable(),
                TextColumn::make('semester.name')
                    ->label('الفصل')
                    ->sortable(),
                TextColumn::make('roster_students_count')
                    ->label('عدد الطلاب')
                    ->sortable(),
                TextColumn::make('regular_students_count')
                    ->label('المستجدون')
                    ->sortable(),
                TextColumn::make('carry_students_count')
                    ->label('الحملة')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ready' => 'جاهزة',
                        'used' => 'مستخدمة',
                        'archived' => 'مؤرشفة',
                        default => 'مسودة',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'ready' => 'success',
                        'used' => 'info',
                        'archived' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('updated_at')
                    ->label('تاريخ آخر تعديل')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label('الكلية')
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                SelectFilter::make('department_id')
                    ->label('القسم')
                    ->relationship('department', 'name'),
                SelectFilter::make('subject_id')
                    ->label('المادة')
                    ->relationship('subject', 'name'),
                SelectFilter::make('academic_year_id')
                    ->label('العام الدراسي')
                    ->relationship('academicYear', 'name'),
                SelectFilter::make('semester_id')
                    ->label('الفصل')
                    ->relationship('semester', 'name'),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'draft' => 'مسودة',
                        'ready' => 'جاهزة',
                        'used' => 'مستخدمة',
                        'archived' => 'مؤرشفة',
                    ]),
                SelectFilter::make('student_type')
                    ->label('نوع الطلاب')
                    ->options([
                        'regular' => 'مستجد',
                        'carry' => 'حملة',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('rosterStudents', fn (Builder $students) => $students->where('student_type', $data['value']))
                        : $query),
            ])
            ->recordActions([
                static::importStudentsAction('importRegularStudents', 'تحميل الطلاب المستجدين', 'regular'),
                static::importStudentsAction('importCarryStudents', 'تحميل طلاب الحملة', 'carry'),
                Action::make('downloadTemplate')
                    ->label('تحميل قالب Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => Excel::download(new SubjectExamRosterStudentsTemplateExport('regular'), 'subject-roster-students-template.xlsx')),
                Action::make('markReady')
                    ->label('تحديد كجاهزة')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (SubjectExamRoster $record): bool => $record->status !== 'ready')
                    ->action(function (SubjectExamRoster $record): void {
                        if (! $record->subject_id || ! $record->college_id) {
                            throw ValidationException::withMessages(['roster' => 'يجب تحديد الكلية والمادة قبل تحديد القائمة كجاهزة.']);
                        }

                        if (! $record->eligibleRosterStudents()->exists()) {
                            throw ValidationException::withMessages(['roster' => 'لا يمكن تحديد القائمة كجاهزة قبل إضافة الطلاب.']);
                        }

                        $record->update(['status' => 'ready']);

                        Notification::make()->success()->title('تم تحديد القائمة كجاهزة')->send();
                    }),
                Action::make('archive')
                    ->label('أرشفة')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (SubjectExamRoster $record): bool => $record->status !== 'archived')
                    ->action(fn (SubjectExamRoster $record) => $record->update(['status' => 'archived'])),
                EditAction::make()
                    ->label('عرض الطلاب'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function importStudentsAction(string $name, string $label, string $studentType): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-up-tray')
            ->form([
                FileUpload::make('file')
                    ->label('ملف الطلاب')
                    ->disk('local')
                    ->directory('imports/subject-exam-rosters')
                    ->required()
                    ->acceptedFileTypes([
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->maxSize(5120),
            ])
            ->action(function (SubjectExamRoster $record, array $data) use ($studentType): void {
                $path = $data['file'] ?? null;

                if (! $path) {
                    throw ValidationException::withMessages(['file' => 'يجب اختيار ملف Excel.']);
                }

                $import = new SubjectExamRosterStudentsImport(
                    roster: $record,
                    defaultStudentType: $studentType,
                    markReadyAfterImport: false,
                );
                Excel::import($import, Storage::disk('local')->path($path));
                Storage::disk('local')->delete($path);

                $summary = $import->summary();

                Notification::make()
                    ->success()
                    ->title('تم استيراد الطلاب')
                    ->body('إجمالي الصفوف: '.$summary['total_rows'].' | تم استيرادها: '.$summary['imported'].' | تم تحديثها: '.$summary['updated'].' | مرفوضة: '.$summary['rejected'].'. يمكنك الآن تحديد القائمة كجاهزة لاستخدامها في توليد البرنامج الامتحاني.')
                    ->send();
            });
    }
}
