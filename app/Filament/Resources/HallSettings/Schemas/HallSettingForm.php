<?php

namespace App\Filament\Resources\HallSettings\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
