<?php

namespace App\Filament\Resources\SubjectExamRosters;

use App\Filament\Resources\SubjectExamRosters\Pages\CreateSubjectExamRoster;
use App\Filament\Resources\SubjectExamRosters\Pages\EditSubjectExamRoster;
use App\Filament\Resources\SubjectExamRosters\Pages\ListSubjectExamRosters;
use App\Filament\Resources\SubjectExamRosters\RelationManagers\RosterStudentsRelationManager;
use App\Filament\Resources\SubjectExamRosters\Schemas\SubjectExamRosterForm;
use App\Filament\Resources\SubjectExamRosters\Tables\SubjectExamRostersTable;
use App\Models\SubjectExamRoster;
use App\Support\ExamCollegeScope;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubjectExamRosterResource extends Resource
{
    protected static ?string $model = SubjectExamRoster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SubjectExamRosterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubjectExamRostersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RosterStudentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubjectExamRosters::route('/'),
            'create' => CreateSubjectExamRoster::route('/create'),
            'edit' => EditSubjectExamRoster::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.core_operations');
    }

    public static function getNavigationSort(): ?int
    {
        return 9;
    }

    public static function getModelLabel(): string
    {
        return 'قائمة طلاب مادة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'قوائم طلاب المواد';
    }

    public static function getEloquentQuery(): Builder
    {
        return ExamCollegeScope::applyCollegeScope(
            parent::getEloquentQuery()
                ->with(['college', 'department', 'subject', 'academicYear', 'semester', 'studyLevel'])
                ->withCount([
                    'rosterStudents',
                    'rosterStudents as regular_students_count' => fn (Builder $query) => $query->where('student_type', 'regular'),
                    'rosterStudents as carry_students_count' => fn (Builder $query) => $query->where('student_type', 'carry'),
                ]),
        );
    }
}
