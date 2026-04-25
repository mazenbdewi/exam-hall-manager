<?php

namespace App\Filament\Resources\ExamHalls\Schemas;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Support\ExamCollegeScope;
use App\Support\HallClassification;
use Filament\Support\Icons\Heroicon;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ExamHallForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.exam_hall_details'))
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
                            ->label(__('exam.fields.hall_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('location')
                            ->label(__('exam.fields.hall_location'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('capacity')
                            ->label(__('exam.fields.capacity'))
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->live()
                            ->required(),
                        Select::make('hall_type')
                            ->label(__('exam.fields.hall_type'))
                            ->options(ExamHallType::options())
                            ->hint(fn (Get $get): ?string => HallClassification::hasMismatch(
                                $get('capacity'),
                                selectedType: $get('hall_type'),
                            ) ? __('exam.helpers.live_hall_type_warning_title') : null)
                            ->hintColor(fn (Get $get): ?string => HallClassification::hasMismatch(
                                $get('capacity'),
                                selectedType: $get('hall_type'),
                            ) ? 'danger' : null)
                            ->hintIcon(fn (Get $get): ?Heroicon => HallClassification::hasMismatch(
                                $get('capacity'),
                                selectedType: $get('hall_type'),
                            ) ? Heroicon::OutlinedExclamationTriangle : null)
                            ->helperText(fn (Get $get) => HallClassification::expectedTypeHelperHtml(
                                $get('capacity'),
                                selectedType: $get('hall_type'),
                            ))
                            ->required(),
                        Select::make('priority')
                            ->label(__('exam.fields.priority'))
                            ->options(ExamHallPriority::options())
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('exam.fields.status'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }
}
