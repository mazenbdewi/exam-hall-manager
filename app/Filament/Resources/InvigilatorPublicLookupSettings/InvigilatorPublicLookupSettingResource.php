<?php

namespace App\Filament\Resources\InvigilatorPublicLookupSettings;

use App\Filament\Resources\InvigilatorPublicLookupSettings\Pages\CreateInvigilatorPublicLookupSetting;
use App\Filament\Resources\InvigilatorPublicLookupSettings\Pages\EditInvigilatorPublicLookupSetting;
use App\Filament\Resources\InvigilatorPublicLookupSettings\Pages\ListInvigilatorPublicLookupSettings;
use App\Filament\Resources\InvigilatorPublicLookupSettings\Schemas\InvigilatorPublicLookupSettingForm;
use App\Filament\Resources\InvigilatorPublicLookupSettings\Tables\InvigilatorPublicLookupSettingsTable;
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

class InvigilatorPublicLookupSettingResource extends Resource
{
    protected static ?string $model = InvigilatorDistributionSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    public static function form(Schema $schema): Schema
    {
        return InvigilatorPublicLookupSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvigilatorPublicLookupSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvigilatorPublicLookupSettings::route('/'),
            'create' => CreateInvigilatorPublicLookupSetting::route('/create'),
            'edit' => EditInvigilatorPublicLookupSetting::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.public_lookup');
    }

    public static function getNavigationSort(): ?int
    {
        return 22;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.invigilator_public_lookup_setting.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.invigilator_public_lookup_setting.plural');
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
                'show_all_invigilator_assignments' => ['boolean'],
                'visibility_before_minutes' => ['required', 'integer', Rule::in([30, 60, 120, 180])],
                'visibility_after_minutes' => ['required', 'integer', 'min:1'],
            ],
            attributes: [
                'college_id' => __('exam.fields.college'),
                'show_all_invigilator_assignments' => __('exam.fields.show_all_invigilator_assignments'),
                'visibility_before_minutes' => __('exam.fields.visibility_before_minutes'),
                'visibility_after_minutes' => __('exam.fields.visibility_after_minutes'),
            ],
        )->validate();
    }
}
