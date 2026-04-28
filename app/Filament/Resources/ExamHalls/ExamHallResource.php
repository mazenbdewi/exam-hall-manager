<?php

namespace App\Filament\Resources\ExamHalls;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Filament\Resources\ExamHalls\Pages\CreateExamHall;
use App\Filament\Resources\ExamHalls\Pages\EditExamHall;
use App\Filament\Resources\ExamHalls\Pages\ListExamHalls;
use App\Filament\Resources\ExamHalls\Schemas\ExamHallForm;
use App\Filament\Resources\ExamHalls\Tables\ExamHallsTable;
use App\Models\ExamHall;
use App\Support\ExamCollegeScope;
use App\Support\HallClassification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExamHallResource extends Resource
{
    protected static ?string $model = ExamHall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ExamHallForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExamHallsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExamHalls::route('/'),
            'create' => CreateExamHall::route('/create'),
            'edit' => EditExamHall::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.master_data');
    }

    public static function getNavigationSort(): ?int
    {
        return 32;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.exam_hall.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.exam_hall.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return ExamCollegeScope::applyCollegeScope(parent::getEloquentQuery()->with('college'));
    }

    public static function validateAndNormalizeData(array $data, ?ExamHall $record = null): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);
        $validator = Validator::make(
            $data,
            [
                'college_id' => ['required', 'integer', 'exists:colleges,id'],
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('exam_halls', 'name')
                        ->where(fn ($query) => $query->where('college_id', $data['college_id']))
                        ->ignore($record?->getKey()),
                ],
                'location' => ['required', 'string', 'max:255'],
                'capacity' => ['required', 'integer', 'min:1'],
                'hall_type' => ['required', Rule::in(ExamHallType::values())],
                'priority' => ['required', Rule::in(ExamHallPriority::values())],
                'is_active' => ['boolean'],
            ],
            attributes: [
                'college_id' => __('exam.fields.college'),
                'name' => __('exam.fields.hall_name'),
                'location' => __('exam.fields.hall_location'),
                'capacity' => __('exam.fields.capacity'),
                'hall_type' => __('exam.fields.hall_type'),
                'priority' => __('exam.fields.priority'),
                'is_active' => __('exam.fields.status'),
            ],
        );

        $validator->after(function ($validator) use ($data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $expectedType = HallClassification::expectedTypeForCapacity($data['capacity'] ?? null);

            if (! $expectedType || ($data['hall_type'] ?? null) === $expectedType->value) {
                return;
            }

            $validator->errors()->add(
                'hall_type',
                __('exam.validation.hall_type_capacity_mismatch', [
                    'expected' => $expectedType->label(),
                    'selected' => ExamHallType::from($data['hall_type'])->label(),
                    'capacity' => (int) $data['capacity'],
                ]),
            );
        });

        return $validator->validate();
    }
}
