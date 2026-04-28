<?php

namespace App\Filament\Resources\Invigilators\Schemas;

use App\Enums\InvigilationRole;
use App\Enums\InvigilatorDayPreference;
use App\Enums\StaffCategory;
use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class InvigilatorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.invigilator_details'))
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
                            ->hidden(fn (): bool => ! ExamCollegeScope::isSuperAdmin()),
                        TextInput::make('name')
                            ->label(__('exam.fields.invigilator_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('exam.fields.phone'))
                            ->tel()
                            ->required()
                            ->maxLength(30)
                            ->helperText(__('exam.helpers.invigilator_phone_required')),
                        Select::make('staff_category')
                            ->label(__('exam.fields.staff_category'))
                            ->options(StaffCategory::options())
                            ->required(),
                        Select::make('invigilation_role')
                            ->label(__('exam.fields.invigilation_role'))
                            ->options(InvigilationRole::options())
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('exam.fields.is_active'))
                            ->default(true)
                            ->inline(false),
                        Textarea::make('notes')
                            ->label(__('exam.fields.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('exam.sections.invigilator_personal_distribution_settings'))
                    ->description(__('exam.helpers.invigilator_personal_distribution_settings'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('max_assignments')
                            ->label(__('exam.fields.max_assignments'))
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->helperText(__('exam.helpers.invigilator_max_assignments')),
                        TextInput::make('workload_reduction_percentage')
                            ->label(__('exam.fields.workload_reduction_percentage'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->default(0)
                            ->helperText(__('exam.helpers.workload_reduction_percentage'))
                            ->required(),
                        Select::make('allow_multiple_assignments_per_day')
                            ->label(__('exam.fields.allow_multiple_assignments_per_day'))
                            ->options([
                                1 => __('exam.boolean.yes'),
                                0 => __('exam.boolean.no'),
                            ])
                            ->placeholder(__('exam.fields.use_general_setting'))
                            ->native(false)
                            ->nullable()
                            ->live()
                            ->helperText(__('exam.helpers.invigilator_allow_multiple_assignments_per_day')),
                        TextInput::make('max_assignments_per_day')
                            ->label(__('exam.fields.max_assignments_per_day'))
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->helperText(__('exam.helpers.invigilator_max_assignments_per_day'))
                            ->visible(fn (Get $get): bool => static::allowsMultipleAssignmentsPerDay($get('allow_multiple_assignments_per_day'))),
                        Select::make('day_preference')
                            ->label(__('exam.fields.day_preference'))
                            ->options(InvigilatorDayPreference::options())
                            ->placeholder(__('exam.fields.use_general_setting'))
                            ->native(false)
                            ->nullable()
                            ->helperText(__('exam.helpers.invigilator_day_preference')),
                    ]),
            ]);
    }

    protected static function allowsMultipleAssignmentsPerDay(mixed $state): bool
    {
        return filter_var($state, FILTER_VALIDATE_BOOL);
    }
}
