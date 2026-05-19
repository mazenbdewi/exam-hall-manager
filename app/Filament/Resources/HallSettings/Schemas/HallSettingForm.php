<?php

namespace App\Filament\Resources\HallSettings\Schemas;

use App\Models\HallSetting;
use App\Support\ExamCollegeScope;
use App\Support\HallClassification;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                            ->minValue(2)
                            ->step(1)
                            ->live()
                            ->helperText(__('exam.helpers.large_hall_min_capacity_field'))
                            ->required(),
                        TextInput::make('amphitheater_min_capacity')
                            ->label(__('exam.fields.amphitheater_min_capacity'))
                            ->numeric()
                            ->minValue(2)
                            ->step(1)
                            ->live()
                            ->helperText(fn (Get $get): string => static::rangePreview(
                                $get('large_hall_min_capacity'),
                                $get('amphitheater_min_capacity'),
                            ))
                            ->required(),
                    ]),
            ]);
    }

    protected static function rangePreview(int|string|null $largeFrom, int|string|null $amphitheaterFrom): string
    {
        $settings = new HallSetting([
            'large_hall_min_capacity' => filled($largeFrom) ? (int) $largeFrom : HallSetting::defaults()['large_hall_min_capacity'],
            'amphitheater_min_capacity' => filled($amphitheaterFrom) ? (int) $amphitheaterFrom : HallSetting::defaults()['amphitheater_min_capacity'],
        ]);

        return HallClassification::rulesDescription($settings);
    }
}
