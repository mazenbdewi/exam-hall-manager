<?php

namespace App\Filament\Resources\SystemSettings\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SystemSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.system_setting_details'))
                    ->description(__('exam.helpers.system_setting_details'))
                    ->schema([
                        TextInput::make('university_name')
                            ->label(__('exam.fields.university_name'))
                            ->required()
                            ->maxLength(255),
                        FileUpload::make('university_logo')
                            ->label(__('exam.fields.university_logo'))
                            ->disk('public')
                            ->directory('settings/university')
                            ->image()
                            ->imageEditor()
                            ->maxSize(5120),
                    ]),
            ]);
    }
}
