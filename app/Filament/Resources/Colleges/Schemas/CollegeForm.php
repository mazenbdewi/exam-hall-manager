<?php

namespace App\Filament\Resources\Colleges\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CollegeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.college_details'))
                    ->columns(2)
                    ->schema([
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
                        Toggle::make('enable_department_student_number_prefix')
                            ->label('تفعيل ترميز الأرقام الجامعية حسب القسم')
                            ->helperText('عند التفعيل يمكن تعديل أرقام طلاب القوائم بإضافة ترميز القسم لتفادي التشابه بين الأقسام.')
                            ->default(false)
                            ->inline(false),
                    ]),
            ]);
    }
}
