<?php

namespace App\Filament\Resources\Invigilators\Pages;

use App\Exports\InvigilatorsTemplateExport;
use App\Filament\Resources\Invigilators\InvigilatorResource;
use App\Imports\InvigilatorsImport;
use App\Models\College;
use App\Support\ExamCollegeScope;
use App\Support\ShieldPermission;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ListInvigilators extends ListRecords
{
    protected static string $resource = InvigilatorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('downloadTemplate')
                ->label(__('exam.actions.download_invigilators_template'))
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => Excel::download(new InvigilatorsTemplateExport, 'invigilators-template.xlsx')),
            Action::make('importInvigilators')
                ->label(__('exam.actions.import_invigilators'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->can(ShieldPermission::resource('import', 'Invigilator')) ?? false)
                ->form([
                    Select::make('college_id')
                        ->label(__('exam.fields.college'))
                        ->options(fn (): array => College::query()
                            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?int => ExamCollegeScope::currentCollegeId())
                        ->required()
                        ->hidden(fn (): bool => ! ExamCollegeScope::isSuperAdmin()),
                    FileUpload::make('file')
                        ->label(__('exam.actions.import_invigilators'))
                        ->disk('local')
                        ->directory('imports/invigilators')
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->maxSize(5120),
                ])
                ->action(function (array $data): void {
                    $collegeId = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);
                    $college = College::query()->findOrFail($collegeId);
                    $path = $data['file'] ?? null;

                    if (! $path) {
                        throw ValidationException::withMessages([
                            'file' => __('exam.validation.excel_file_required'),
                        ]);
                    }

                    $import = new InvigilatorsImport($college);

                    try {
                        Excel::import($import, Storage::disk('local')->path($path));

                        Notification::make()
                            ->success()
                            ->title(__('exam.notifications.invigilators_imported'))
                            ->body(__('exam.notifications.invigilators_imported_body', ['count' => $import->getImportedCount()]))
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title(__('exam.notifications.import_validation_failed'))
                            ->body(collect($exception->errors())->flatten()->take(6)->implode(' | '))
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
        ];
    }
}
