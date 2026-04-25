<?php

namespace App\Filament\Resources\SubjectExamOfferings\RelationManagers;

use App\Enums\ExamStudentType;
use App\Exports\ExamStudentsTemplateExport;
use App\Imports\ExamStudentsImport;
use App\Models\SubjectExamOffering;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

abstract class ExamStudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'examStudents';

    abstract protected static function getStudentType(): ExamStudentType;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('student_number')
                ->label(__('exam.fields.student_number'))
                ->required()
                ->maxLength(255),
            TextInput::make('full_name')
                ->label(__('exam.fields.full_name'))
                ->required()
                ->maxLength(255),
            Textarea::make('notes')
                ->label(__('exam.fields.notes'))
                ->rows(3),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('student_type', static::getStudentType()->value))
            ->defaultSort('student_number')
            ->description(fn (): string => __('exam.helpers.students_relation', [
                'type' => static::getStudentType()->label(),
                'count' => $this->getStudentsCount(),
            ]))
            ->emptyStateHeading(__('exam.helpers.students_empty_heading', [
                'type' => static::getStudentType()->label(),
            ]))
            ->emptyStateDescription(__('exam.helpers.students_empty_description', [
                'type' => static::getStudentType()->label(),
            ]))
            ->columns([
                TextColumn::make('student_number')
                    ->label(__('exam.fields.student_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label(__('exam.fields.full_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('notes')
                    ->label(__('exam.fields.notes'))
                    ->limit(40)
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('exam.actions.add_student_type', ['type' => static::getStudentType()->label()]))
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'student_type' => static::getStudentType()->value,
                    ]),
                Action::make('downloadTemplate')
                    ->label(__('exam.actions.download_excel_template_for', ['type' => static::getStudentType()->label()]))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => Excel::download(
                        new ExamStudentsTemplateExport(),
                        str(static::getStudentType()->value)->append('-students-template.xlsx')->toString(),
                    )),
                Action::make('importStudents')
                    ->label(__('exam.actions.import_students', ['type' => static::getStudentType()->label()]))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        FileUpload::make('file')
                            ->label(__('exam.actions.import_students', ['type' => static::getStudentType()->label()]))
                            ->disk('local')
                            ->directory('imports/exam-students')
                            ->required()
                            ->acceptedFileTypes([
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->maxSize(5120),
                    ])
                    ->action(function (array $data): void {
                        $path = $data['file'] ?? null;

                        if (! $path) {
                            throw ValidationException::withMessages([
                                'file' => __('exam.validation.excel_file_required'),
                            ]);
                        }

                        $import = new ExamStudentsImport(
                            offering: $this->getOwnerRecord(),
                            studentType: static::getStudentType(),
                        );

                        try {
                            Excel::import($import, Storage::disk('local')->path($path));

                            Notification::make()
                                ->success()
                                ->title(__('exam.notifications.students_imported'))
                                ->body(__('exam.notifications.students_imported_body', [
                                    'count' => $import->getImportedCount(),
                                    'type' => static::getStudentType()->label(),
                                ]))
                                ->send();
                        } catch (ValidationException $exception) {
                            $message = collect($exception->errors())
                                ->flatten()
                                ->take(6)
                                ->implode(' | ');

                            Notification::make()
                                ->danger()
                                ->title(__('exam.notifications.import_validation_failed'))
                                ->body($message)
                                ->send();

                            throw $exception;
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('exam.notifications.import_failed'))
                                ->body($exception->getMessage())
                                ->send();

                            throw $exception;
                        } finally {
                            Storage::disk('local')->delete($path);
                        }
                    }),
                Action::make('deleteImported')
                    ->label(__('exam.actions.delete_all_students', ['type' => static::getStudentType()->label()]))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $deleted = $this->getOwnerRecord()
                            ->examStudents()
                            ->where('student_type', static::getStudentType()->value)
                            ->delete();

                        Notification::make()
                            ->success()
                            ->title(__('exam.notifications.students_deleted'))
                            ->body(__('exam.notifications.students_deleted_body', [
                                'count' => $deleted,
                                'type' => static::getStudentType()->label(),
                            ]))
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'student_type' => static::getStudentType()->value,
                    ]),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('exam.relation_titles.students', ['type' => static::getStudentType()->label()]);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof SubjectExamOffering) {
            return null;
        }

        return (string) $ownerRecord
            ->examStudents()
            ->where('student_type', static::getStudentType()->value)
            ->count();
    }

    protected function getStudentsCount(): int
    {
        return $this->getOwnerRecord()
            ->examStudents()
            ->where('student_type', static::getStudentType()->value)
            ->count();
    }
}
