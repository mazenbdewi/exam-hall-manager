<?php

namespace App\Filament\Resources\HallSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\HallSettings\HallSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHallSetting extends CreateRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = HallSettingResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return HallSettingResource::validateAndNormalizeData($data);
    }
}
