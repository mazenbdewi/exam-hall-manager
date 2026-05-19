<?php

namespace App\Filament\Resources\HallSettings\Schemas;

use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class HallSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.hall_setting_details'))
                    ->description(__('exam.helpers.hall_type_rules_settings'))
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
                        TextInput::make('large_hall_min_capacity')
                            ->label(__('exam.fields.large_hall_min_capacity'))
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->required(),
                        TextInput::make('amphitheater_min_capacity')
                            ->label(__('exam.fields.amphitheater_min_capacity'))
                            ->numeric()
                            ->minValue(2)
                            ->step(1)
                            ->required(),
                    ]),
            ]);
    }
}
