<?php

namespace App\Filament\Resources\Subjects\Schemas;

use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SubjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.subject_details'))
                    ->columns(2)
                    ->schema([
                        Select::make('college_id')
                            ->label(__('exam.fields.college'))
                            ->relationship(
                                name: 'college',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn (): ?int => ExamCollegeScope::currentCollegeId())
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('department_id', null))
                            ->hidden(fn (): bool => ! ExamCollegeScope::isSuperAdmin()),
                        Select::make('department_id')
                            ->label(__('exam.fields.department'))
                            ->relationship(
                                name: 'department',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                    $collegeId = ExamCollegeScope::isSuperAdmin()
                                        ? $get('college_id')
                                        : ExamCollegeScope::currentCollegeId();

                                    return $query
                                        ->when($collegeId, fn (Builder $departmentQuery) => $departmentQuery->where('college_id', $collegeId))
                                        ->orderBy('name');
                                },
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('study_level_id')
                            ->label(__('exam.fields.study_level'))
                            ->relationship(
                                name: 'studyLevel',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label(__('exam.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label(__('exam.fields.code'))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('exam.fields.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }
}
