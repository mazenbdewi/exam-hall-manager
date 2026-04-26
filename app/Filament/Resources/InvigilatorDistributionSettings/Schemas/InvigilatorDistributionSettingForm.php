<?php

namespace App\Filament\Resources\InvigilatorDistributionSettings\Schemas;

use App\Enums\InvigilatorDayPreference;
use App\Enums\InvigilatorDistributionPattern;
use App\Support\ExamCollegeScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class InvigilatorDistributionSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.invigilator_distribution_settings'))
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
                        TextInput::make('default_max_assignments_per_invigilator')
                            ->label(__('exam.fields.default_max_assignments_per_invigilator'))
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->default(3)
                            ->required(),
                        Toggle::make('allow_multiple_assignments_per_day')
                            ->label(__('exam.fields.allow_multiple_assignments_per_day'))
                            ->default(false)
                            ->inline(false),
                        TextInput::make('max_assignments_per_day')
                            ->label(__('exam.fields.max_assignments_per_day'))
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->default(1)
                            ->required(),
                        Section::make(__('exam.sections.invigilator_distribution_behavior'))
                            ->description(__('exam.helpers.invigilator_distribution_settings_choice_info'))
                            ->icon(Heroicon::OutlinedInformationCircle)
                            ->iconColor('info')
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                Select::make('distribution_pattern')
                                    ->label(__('exam.fields.distribution_pattern'))
                                    ->options(InvigilatorDistributionPattern::options())
                                    ->default(InvigilatorDistributionPattern::Balanced->value)
                                    ->live()
                                    ->helperText(fn (Get $get): string => static::distributionPatternDescription($get('distribution_pattern')))
                                    ->required(),
                                Select::make('day_preference')
                                    ->label(__('exam.fields.day_preference'))
                                    ->options(InvigilatorDayPreference::options())
                                    ->default(InvigilatorDayPreference::Balanced->value)
                                    ->live()
                                    ->helperText(fn (Get $get): string => static::dayPreferenceDescription($get('day_preference')))
                                    ->required(),
                            ]),
                    ]),
            ]);
    }

    protected static function distributionPatternDescription(?string $value): string
    {
        $value = $value ?: InvigilatorDistributionPattern::Balanced->value;

        return __("exam.helpers.distribution_pattern_descriptions.{$value}");
    }

    protected static function dayPreferenceDescription(?string $value): string
    {
        $value = $value ?: InvigilatorDayPreference::Balanced->value;

        return __("exam.helpers.day_preference_descriptions.{$value}");
    }
}
