<?php

namespace App\Filament\Resources\SystemSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\SystemSettings\SystemSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditSystemSetting extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = SystemSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SystemSettingResource::validateAndNormalizeData($data);
    }
}
