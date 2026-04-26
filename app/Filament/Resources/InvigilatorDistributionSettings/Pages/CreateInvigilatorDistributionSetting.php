<?php

namespace App\Filament\Resources\InvigilatorDistributionSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\InvigilatorDistributionSettings\InvigilatorDistributionSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvigilatorDistributionSetting extends CreateRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = InvigilatorDistributionSettingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return InvigilatorDistributionSettingResource::validateAndNormalizeData($data);
    }
}
