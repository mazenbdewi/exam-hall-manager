<?php

namespace App\Filament\Resources\StudentPublicLookupSettings;

use App\Filament\Resources\StudentPublicLookupSettings\Pages\CreateStudentPublicLookupSetting;
use App\Filament\Resources\StudentPublicLookupSettings\Pages\EditStudentPublicLookupSetting;
use App\Filament\Resources\StudentPublicLookupSettings\Pages\ListStudentPublicLookupSettings;
use App\Filament\Resources\StudentPublicLookupSettings\Schemas\StudentPublicLookupSettingForm;
use App\Filament\Resources\StudentPublicLookupSettings\Tables\StudentPublicLookupSettingsTable;
use App\Models\StudentPublicLookupSetting;
use App\Support\ExamCollegeScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StudentPublicLookupSettingResource extends Resource
{
    protected static ?string $model = StudentPublicLookupSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    public static function form(Schema $schema): Schema
    {
        return StudentPublicLookupSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentPublicLookupSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentPublicLookupSettings::route('/'),
            'create' => CreateStudentPublicLookupSetting::route('/create'),
            'edit' => EditStudentPublicLookupSetting::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.exam_management');
    }

    public static function getNavigationSort(): ?int
    {
        return 30;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.student_public_lookup_setting.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.student_public_lookup_setting.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return ExamCollegeScope::applyCollegeScope(parent::getEloquentQuery()->with('college'));
    }

    public static function canCreate(): bool
    {
        if (! parent::canCreate()) {
            return false;
        }

        if (ExamCollegeScope::isSuperAdmin()) {
            return true;
        }

        return ! static::getModel()::query()
            ->where('college_id', ExamCollegeScope::currentCollegeId())
            ->exists();
    }

    public static function validateAndNormalizeData(array $data, ?StudentPublicLookupSetting $record = null): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);

        return Validator::make(
            $data,
            [
                'college_id' => [
                    'required',
                    'integer',
                    'exists:colleges,id',
                    Rule::unique('student_public_lookup_settings', 'college_id')->ignore($record?->getKey()),
                ],
                'show_all_student_assignments' => ['boolean'],
                'visibility_before_minutes' => ['required', 'integer', Rule::in([30, 60, 120, 180])],
                'visibility_after_minutes' => ['required', 'integer', 'min:1'],
            ],
            attributes: [
                'college_id' => __('exam.fields.college'),
                'show_all_student_assignments' => __('exam.fields.show_all_student_assignments'),
                'visibility_before_minutes' => __('exam.fields.student_visibility_before_minutes'),
                'visibility_after_minutes' => __('exam.fields.student_visibility_after_minutes'),
            ],
        )->validate();
    }
}
