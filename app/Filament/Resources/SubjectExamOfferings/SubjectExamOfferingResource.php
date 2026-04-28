<?php

namespace App\Filament\Resources\SubjectExamOfferings;

use App\Filament\Resources\SubjectExamOfferings\Pages\CreateSubjectExamOffering;
use App\Filament\Resources\SubjectExamOfferings\Pages\EditSubjectExamOffering;
use App\Filament\Resources\SubjectExamOfferings\Pages\GlobalDistributionResults;
use App\Filament\Resources\SubjectExamOfferings\Pages\ListSubjectExamOfferings;
use App\Filament\Resources\SubjectExamOfferings\Pages\ManageSlotHallDistribution;
use App\Filament\Resources\SubjectExamOfferings\RelationManagers\CarryStudentsRelationManager;
use App\Filament\Resources\SubjectExamOfferings\RelationManagers\RegularStudentsRelationManager;
use App\Filament\Resources\SubjectExamOfferings\Schemas\SubjectExamOfferingForm;
use App\Filament\Resources\SubjectExamOfferings\Tables\SubjectExamOfferingsTable;
use App\Models\SubjectExamOffering;
use App\Support\ExamCollegeScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubjectExamOfferingResource extends Resource
{
    protected static ?string $model = SubjectExamOffering::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function form(Schema $schema): Schema
    {
        return SubjectExamOfferingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubjectExamOfferingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RegularStudentsRelationManager::class,
            CarryStudentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubjectExamOfferings::route('/'),
            'create' => CreateSubjectExamOffering::route('/create'),
            'global-distribution-results' => GlobalDistributionResults::route('/global-distribution-results/{run?}'),
            'edit' => EditSubjectExamOffering::route('/{record}/edit'),
            'distribution' => ManageSlotHallDistribution::route('/{record}/distribution'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.core_operations');
    }

    public static function getNavigationSort(): ?int
    {
        return 11;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.subject_exam_offering.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.subject_exam_offering.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return ExamCollegeScope::applyRelatedCollegeScope(
            parent::getEloquentQuery()
                ->withSameSlotOfferingsCount()
                ->withCount(['examStudents', 'studentHallAssignments'])
                ->with(['subject.department', 'subject.college', 'academicYear', 'semester']),
            'subject',
        );
    }
}
