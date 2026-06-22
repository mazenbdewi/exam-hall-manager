<?php

namespace App\Filament\Resources\SubjectExamOfferings\Schemas;

use App\Enums\ExamOfferingStatus;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Support\ExamCollegeScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SubjectExamOfferingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.offering_details'))
                    ->columnSpanFull()
                    ->columns([
                        'md' => 2,
                    ])
                    ->schema([
                        Select::make('college_id')
                            ->label(__('exam.fields.college'))
                            ->options(fn (): array => College::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (?SubjectExamOffering $record = null): ?int => $record?->subject?->college_id ?? ExamCollegeScope::currentCollegeId())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Set $set): void {
                                $set('department_id', null);
                                $set('subject_id', null);
                            })
                            ->hidden(fn (): bool => ! ExamCollegeScope::isSuperAdmin()),
                        Select::make('department_id')
                            ->label(__('exam.fields.department'))
                            ->options(function (Get $get): array {
                                $collegeId = ExamCollegeScope::isSuperAdmin()
                                    ? $get('college_id')
                                    : ExamCollegeScope::currentCollegeId();

                                return Department::query()
                                    ->when($collegeId, fn (Builder $query): Builder => $query->where('college_id', $collegeId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->default(fn (?SubjectExamOffering $record = null): ?int => $record?->subject?->department_id)
                            ->searchable()
                            ->preload()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set) => $set('subject_id', null)),
                        Select::make('subject_id')
                            ->label(__('exam.fields.subject'))
                            ->relationship(
                                name: 'subject',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                    $collegeId = ExamCollegeScope::isSuperAdmin()
                                        ? $get('college_id')
                                        : ExamCollegeScope::currentCollegeId();

                                    return ExamCollegeScope::applyCollegeScope(
                                        $query
                                            ->with(['college', 'department', 'studyLevel'])
                                            ->when($collegeId, fn (Builder $query): Builder => $query->where('college_id', $collegeId))
                                            ->when($get('department_id'), fn (Builder $query, int|string $departmentId): Builder => $query->where('department_id', $departmentId))
                                            ->orderBy('name'),
                                    );
                                },
                            )
                            ->getOptionLabelFromRecordUsing(fn (Subject $record): string => collect([
                                $record->college?->name,
                                $record->name,
                                $record->department?->name,
                                $record->studyLevel?->name,
                            ])->filter()->implode(' - '))
                            ->columnSpanFull()
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('academic_year_id')
                            ->label(__('exam.fields.academic_year'))
                            ->relationship(
                                name: 'academicYear',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('is_active', true)
                                    ->orderByDesc('name'),
                            )
                            ->default(fn (): ?int => AcademicYear::query()->where('is_current', true)->value('id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('semester_id')
                            ->label(__('exam.fields.semester'))
                            ->relationship(
                                name: 'semester',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('status')
                            ->label(__('exam.fields.status'))
                            ->options(ExamOfferingStatus::options())
                            ->default(ExamOfferingStatus::Draft->value)
                            ->required(),
                        DatePicker::make('exam_date')
                            ->label(__('exam.fields.exam_date'))
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d'),
                        TimePicker::make('exam_start_time')
                            ->label(__('exam.fields.exam_start_time'))
                            ->required()
                            ->seconds(false),
                        Toggle::make('is_pinned')
                            ->label('تثبيت الموعد')
                            ->helperText('سيتم الحفاظ على تاريخ ووقت هذه المادة عند توليد البرنامج الامتحاني.')
                            ->default(false)
                            ->inline(false),
                        Textarea::make('notes')
                            ->label(__('exam.fields.notes'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
