<?php

namespace App\Filament\Resources\InvigilatorHallRequirements\Schemas;

use App\Enums\ExamHallType;
use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class InvigilatorHallRequirementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.invigilator_hall_requirements'))
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
                        Select::make('hall_type')
                            ->label(__('exam.fields.hall_type'))
                            ->options(ExamHallType::options())
                            ->required(),
                        TextInput::make('hall_head_count')
                            ->label(__('exam.invigilation_roles.hall_head'))
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->default(1)
                            ->required(),
                        TextInput::make('secretary_count')
                            ->label(__('exam.invigilation_roles.secretary'))
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->default(1)
                            ->required(),
                        TextInput::make('regular_count')
                            ->label(__('exam.invigilation_roles.regular'))
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->default(1)
                            ->required(),
                        TextInput::make('reserve_count')
                            ->label(__('exam.invigilation_roles.reserve'))
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->default(0)
                            ->required(),
                    ]),
            ]);
    }
}
