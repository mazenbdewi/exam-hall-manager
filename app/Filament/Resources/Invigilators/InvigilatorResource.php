<?php

namespace App\Filament\Resources\Invigilators;

use App\Enums\InvigilationRole;
use App\Enums\InvigilatorDayPreference;
use App\Enums\StaffCategory;
use App\Filament\Resources\Invigilators\Pages\CreateInvigilator;
use App\Filament\Resources\Invigilators\Pages\EditInvigilator;
use App\Filament\Resources\Invigilators\Pages\ListInvigilators;
use App\Filament\Resources\Invigilators\Schemas\InvigilatorForm;
use App\Filament\Resources\Invigilators\Tables\InvigilatorsTable;
use App\Models\Invigilator;
use App\Support\ExamCollegeScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InvigilatorResource extends Resource
{
    protected static ?string $model = Invigilator::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return InvigilatorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvigilatorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvigilators::route('/'),
            'create' => CreateInvigilator::route('/create'),
            'edit' => EditInvigilator::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.invigilators');
    }

    public static function getNavigationSort(): ?int
    {
        return 41;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.invigilator.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.invigilator.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return ExamCollegeScope::applyCollegeScope(parent::getEloquentQuery()->with('college'));
    }

    public static function validateAndNormalizeData(array $data, ?Invigilator $record = null): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);

        $validator = Validator::make(
            $data,
            [
                'college_id' => ['required', 'integer', 'exists:colleges,id'],
                'name' => ['required', 'string', 'max:255'],
                'phone' => [
                    'required',
                    'string',
                    'max:30',
                    Rule::unique('invigilators', 'phone')
                        ->where(fn ($query) => $query->where('college_id', $data['college_id']))
                        ->ignore($record?->getKey()),
                ],
                'staff_category' => ['required', Rule::in(StaffCategory::values())],
                'invigilation_role' => ['required', Rule::in(InvigilationRole::values())],
                'max_assignments' => ['nullable', 'integer', 'min:0'],
                'max_assignments_per_day' => ['nullable', 'integer', 'min:1'],
                'allow_multiple_assignments_per_day' => ['nullable', 'boolean'],
                'day_preference' => ['nullable', Rule::in(InvigilatorDayPreference::values())],
                'workload_reduction_percentage' => ['required', 'integer', 'min:0', 'max:100'],
                'is_active' => ['boolean'],
                'notes' => ['nullable', 'string'],
            ],
            messages: [
                'phone.required' => __('exam.validation.invigilator_phone_required'),
            ],
            attributes: [
                'college_id' => __('exam.fields.college'),
                'name' => __('exam.fields.invigilator_name'),
                'phone' => __('exam.fields.phone'),
                'staff_category' => __('exam.fields.staff_category'),
                'invigilation_role' => __('exam.fields.invigilation_role'),
                'max_assignments' => __('exam.fields.max_assignments'),
                'max_assignments_per_day' => __('exam.fields.max_assignments_per_day'),
                'allow_multiple_assignments_per_day' => __('exam.fields.allow_multiple_assignments_per_day'),
                'day_preference' => __('exam.fields.day_preference'),
                'workload_reduction_percentage' => __('exam.fields.workload_reduction_percentage'),
                'is_active' => __('exam.fields.is_active'),
                'notes' => __('exam.fields.notes'),
            ],
        );

        $validated = $validator->validate();
        $validated['phone'] = trim((string) $validated['phone']);

        return $validated;
    }
}
