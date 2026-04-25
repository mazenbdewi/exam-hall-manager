<?php

namespace App\Filament\Resources\SystemSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\SystemSettings\SystemSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSystemSetting extends CreateRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = SystemSettingResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return SystemSettingResource::validateAndNormalizeData($data);
    }
}
