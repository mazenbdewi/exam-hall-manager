<?php

namespace App\Filament\Resources\InvigilatorHallRequirements;

use App\Enums\ExamHallType;
use App\Filament\Resources\InvigilatorHallRequirements\Pages\CreateInvigilatorHallRequirement;
use App\Filament\Resources\InvigilatorHallRequirements\Pages\EditInvigilatorHallRequirement;
use App\Filament\Resources\InvigilatorHallRequirements\Pages\ListInvigilatorHallRequirements;
use App\Filament\Resources\InvigilatorHallRequirements\Schemas\InvigilatorHallRequirementForm;
use App\Filament\Resources\InvigilatorHallRequirements\Tables\InvigilatorHallRequirementsTable;
use App\Models\InvigilatorHallRequirement;
use App\Support\ExamCollegeScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InvigilatorHallRequirementResource extends Resource
{
    protected static ?string $model = InvigilatorHallRequirement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function form(Schema $schema): Schema
    {
        return InvigilatorHallRequirementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvigilatorHallRequirementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvigilatorHallRequirements::route('/'),
            'create' => CreateInvigilatorHallRequirement::route('/create'),
            'edit' => EditInvigilatorHallRequirement::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.invigilators');
    }

    public static function getNavigationSort(): ?int
    {
        return 43;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.invigilator_hall_requirement.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.invigilator_hall_requirement.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return ExamCollegeScope::applyCollegeScope(parent::getEloquentQuery()->with('college'));
    }

    public static function validateAndNormalizeData(array $data, ?InvigilatorHallRequirement $record = null): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);

        return Validator::make(
            $data,
            [
                'college_id' => ['required', 'integer', 'exists:colleges,id'],
                'hall_type' => [
                    'required',
                    Rule::in(ExamHallType::values()),
                    Rule::unique('invigilator_hall_requirements', 'hall_type')
                        ->where(fn ($query) => $query->where('college_id', $data['college_id']))
                        ->ignore($record?->getKey()),
                ],
                'hall_head_count' => ['required', 'integer', 'min:0'],
                'secretary_count' => ['required', 'integer', 'min:0'],
                'regular_count' => ['required', 'integer', 'min:0'],
                'reserve_count' => ['required', 'integer', 'min:0'],
            ],
            attributes: [
                'college_id' => __('exam.fields.college'),
                'hall_type' => __('exam.fields.hall_type'),
                'hall_head_count' => __('exam.invigilation_roles.hall_head'),
                'secretary_count' => __('exam.invigilation_roles.secretary'),
                'regular_count' => __('exam.invigilation_roles.regular'),
                'reserve_count' => __('exam.invigilation_roles.reserve'),
            ],
        )->validate();
    }
}
