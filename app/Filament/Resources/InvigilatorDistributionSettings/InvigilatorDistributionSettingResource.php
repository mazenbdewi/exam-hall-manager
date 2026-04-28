<?php

namespace App\Filament\Resources\InvigilatorDistributionSettings;

use App\Enums\InvigilatorDayPreference;
use App\Enums\InvigilatorDistributionPattern;
use App\Filament\Resources\InvigilatorDistributionSettings\Pages\CreateInvigilatorDistributionSetting;
use App\Filament\Resources\InvigilatorDistributionSettings\Pages\EditInvigilatorDistributionSetting;
use App\Filament\Resources\InvigilatorDistributionSettings\Pages\ListInvigilatorDistributionSettings;
use App\Filament\Resources\InvigilatorDistributionSettings\Schemas\InvigilatorDistributionSettingForm;
use App\Filament\Resources\InvigilatorDistributionSettings\Tables\InvigilatorDistributionSettingsTable;
use App\Models\InvigilatorDistributionSetting;
use App\Support\ExamCollegeScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InvigilatorDistributionSettingResource extends Resource
{
    protected static ?string $model = InvigilatorDistributionSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function form(Schema $schema): Schema
    {
        return InvigilatorDistributionSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvigilatorDistributionSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvigilatorDistributionSettings::route('/'),
            'create' => CreateInvigilatorDistributionSetting::route('/create'),
            'edit' => EditInvigilatorDistributionSetting::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.invigilators');
    }

    public static function getNavigationSort(): ?int
    {
        return 42;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.invigilator_distribution_setting.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.invigilator_distribution_setting.plural');
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

    public static function validateAndNormalizeData(array $data, ?InvigilatorDistributionSetting $record = null): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);

        return Validator::make(
            $data,
            [
                'college_id' => [
                    'required',
                    'integer',
                    'exists:colleges,id',
                    Rule::unique('invigilator_distribution_settings', 'college_id')->ignore($record?->getKey()),
                ],
                'default_max_assignments_per_invigilator' => ['required', 'integer', 'min:0'],
                'allow_multiple_assignments_per_day' => ['boolean'],
                'allow_role_fallback' => ['boolean'],
                'max_assignments_per_day' => ['required', 'integer', 'min:1'],
                'distribution_pattern' => ['required', Rule::in(InvigilatorDistributionPattern::values())],
                'day_preference' => ['required', Rule::in(InvigilatorDayPreference::values())],
            ],
            attributes: [
                'college_id' => __('exam.fields.college'),
                'default_max_assignments_per_invigilator' => __('exam.fields.default_max_assignments_per_invigilator'),
                'allow_multiple_assignments_per_day' => __('exam.fields.allow_multiple_assignments_per_day'),
                'allow_role_fallback' => __('exam.fields.allow_role_fallback'),
                'max_assignments_per_day' => __('exam.fields.max_assignments_per_day'),
                'distribution_pattern' => __('exam.fields.distribution_pattern'),
                'day_preference' => __('exam.fields.day_preference'),
            ],
        )->validate();
    }
}
