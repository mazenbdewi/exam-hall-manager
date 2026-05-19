<?php

namespace App\Filament\Resources\HallSettings;

use App\Filament\Resources\HallSettings\Pages\CreateHallSetting;
use App\Filament\Resources\HallSettings\Pages\EditHallSetting;
use App\Filament\Resources\HallSettings\Pages\ListHallSettings;
use App\Filament\Resources\HallSettings\Schemas\HallSettingForm;
use App\Filament\Resources\HallSettings\Tables\HallSettingsTable;
use App\Models\College;
use App\Models\ExamHall;
use App\Models\HallSetting;
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

class HallSettingResource extends Resource
{
    protected static ?string $model = HallSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    public static function form(Schema $schema): Schema
    {
        return HallSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HallSettingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHallSettings::route('/'),
            'create' => CreateHallSetting::route('/create'),
            'edit' => EditHallSetting::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.settings');
    }

    public static function getNavigationSort(): ?int
    {
        return 82;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.hall_setting.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.hall_setting.plural');
    }

    public static function canCreate(): bool
    {
        if (! parent::canCreate()) {
            return false;
        }

        if (ExamCollegeScope::isSuperAdmin()) {
            return College::query()
                ->whereNotIn(
                    'id',
                    HallSetting::query()
                        ->whereNotNull('college_id')
                        ->select('college_id'),
                )
                ->exists();
        }

        $collegeId = ExamCollegeScope::currentCollegeId();

        return filled($collegeId)
            && ! HallSetting::query()
                ->where('college_id', $collegeId)
                ->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('college');

        return ExamCollegeScope::isSuperAdmin()
            ? $query
            : ExamCollegeScope::applyCollegeScope($query);
    }

    public static function validateAndNormalizeData(array $data, ?HallSetting $record = null): array
    {
        $data['college_id'] = ExamCollegeScope::enforceCollegeId($data['college_id'] ?? null);

        $validator = Validator::make(
            $data,
            [
                'college_id' => [
                    'required',
                    'integer',
                    'exists:colleges,id',
                    Rule::unique('hall_settings', 'college_id')->ignore($record?->getKey()),
                ],
                'large_hall_min_capacity' => ['required', 'integer', 'min:2'],
                'amphitheater_min_capacity' => ['required', 'integer', 'gt:large_hall_min_capacity'],
            ],
            attributes: [
                'college_id' => __('exam.fields.college'),
                'large_hall_min_capacity' => __('exam.fields.large_hall_min_capacity'),
                'amphitheater_min_capacity' => __('exam.fields.amphitheater_min_capacity'),
            ],
        );

        $validator->after(function ($validator) use ($data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $temporarySettings = new HallSetting([
                'college_id' => (int) $data['college_id'],
                'large_hall_min_capacity' => (int) $data['large_hall_min_capacity'],
                'amphitheater_min_capacity' => (int) $data['amphitheater_min_capacity'],
            ]);

            $conflictingHalls = ExamHall::query()
                ->where('college_id', $data['college_id'])
                ->get(['id', 'name', 'capacity', 'hall_type'])
                ->filter(function (ExamHall $hall) use ($temporarySettings): bool {
                    $expected = HallClassification::expectedTypeForCapacity($hall->capacity, $temporarySettings);

                    return $expected?->value !== $hall->hall_type?->value;
                })
                ->values();

            if ($conflictingHalls->isEmpty()) {
                return;
            }

            $validator->errors()->add(
                'large_hall_min_capacity',
                __('exam.validation.hall_settings_conflict_existing_halls', [
                    'count' => $conflictingHalls->count(),
                    'halls' => $conflictingHalls->take(3)->pluck('name')->implode('، '),
                ]),
            );
        });

        return $validator->validate();
    }
}
