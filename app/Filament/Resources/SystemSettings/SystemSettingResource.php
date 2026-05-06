<?php

namespace App\Filament\Resources\SystemSettings;

use App\Filament\Resources\SystemSettings\Pages\CreateSystemSetting;
use App\Filament\Resources\SystemSettings\Pages\EditSystemSetting;
use App\Filament\Resources\SystemSettings\Pages\ListSystemSettings;
use App\Filament\Resources\SystemSettings\Schemas\SystemSettingForm;
use App\Filament\Resources\SystemSettings\Tables\SystemSettingsTable;
use App\Models\SystemSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SystemSettingResource extends Resource
{
    protected static ?string $model = SystemSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    public static function form(Schema $schema): Schema
    {
        return SystemSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SystemSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSystemSettings::route('/'),
            'create' => CreateSystemSetting::route('/create'),
            'edit' => EditSystemSetting::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.settings');
    }

    public static function getNavigationSort(): ?int
    {
        return 81;
    }

    public static function getModelLabel(): string
    {
        return __('exam.resources.system_setting.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exam.resources.system_setting.plural');
    }

    public static function canCreate(): bool
    {
        return parent::canCreate() && ! static::getModel()::query()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function validateAndNormalizeData(array $data): array
    {
        return Validator::make(
            $data,
            [
                'university_name' => ['required', 'string', 'max:255'],
                'university_logo' => [
                    'nullable',
                    'string',
                    'max:255',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! filled($value) || ! Storage::disk('public')->exists((string) $value)) {
                            return;
                        }

                        $mimeType = Storage::disk('public')->mimeType((string) $value);

                        if (! in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                            $fail(__('validation.mimes', [
                                'attribute' => __('exam.fields.university_logo'),
                                'values' => 'png, jpg, jpeg, webp',
                            ]));
                        }
                    },
                ],
            ],
            attributes: [
                'university_name' => __('exam.fields.university_name'),
                'university_logo' => __('exam.fields.university_logo'),
            ],
        )->validate();
    }
}
