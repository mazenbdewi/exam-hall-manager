<?php

namespace App\Filament\Resources\SubjectExamOfferings\Schemas;

use App\Enums\ExamOfferingStatus;
use App\Models\AcademicYear;
use App\Models\Subject;
use App\Support\ExamCollegeScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
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
                        Select::make('subject_id')
                            ->label(__('exam.fields.subject'))
                            ->relationship(
                                name: 'subject',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => ExamCollegeScope::applyCollegeScope(
                                    $query->with(['department', 'studyLevel'])->orderBy('name'),
                                ),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Subject $record): string => collect([
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
                            ->native(false),
                        TimePicker::make('exam_start_time')
                            ->label(__('exam.fields.exam_start_time'))
                            ->required()
                            ->seconds(false),
                        Textarea::make('notes')
                            ->label(__('exam.fields.notes'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
