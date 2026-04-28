<?php

namespace App\Filament\Resources\StudyLevels;

use App\Filament\Resources\StudyLevels\Pages\CreateStudyLevel;
use App\Filament\Resources\StudyLevels\Pages\EditStudyLevel;
use App\Filament\Resources\StudyLevels\Pages\ListStudyLevels;
use App\Filament\Resources\StudyLevels\Schemas\StudyLevelForm;
use App\Filament\Resources\StudyLevels\Tables\StudyLevelsTable;
use App\Models\StudyLevel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StudyLevelResource extends Resource
{
    protected static ?string $model = StudyLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return StudyLevelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudyLevelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudyLevels::route('/'),
            'create' => CreateStudyLevel::route('/create'),
            'edit' => EditStudyLevel::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.academic_setup');
    }

    public static function getNavigationSort(): ?int
    {
        return 51;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.study_level.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.study_level.plural');
    }
}
