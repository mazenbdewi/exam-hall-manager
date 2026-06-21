<?php

namespace App\Filament\Resources\SystemSettings\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->imageEditor()
                            ->maxSize(5120),
                        Toggle::make('allow_normal_subjects_in_drawing_studios')
                            ->label(__('exam.fields.allow_normal_subjects_in_drawing_studios'))
                            ->helperText(__('exam.helpers.allow_normal_subjects_in_drawing_studios'))
                            ->default(false)
                            ->dehydrated(true)
                            ->inline(false),
                    ]),
            ]);
    }
}
