<?php

namespace App\Filament\Resources\InvigilatorDistributionSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\InvigilatorDistributionSettings\InvigilatorDistributionSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditInvigilatorDistributionSetting extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = InvigilatorDistributionSettingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return InvigilatorDistributionSettingResource::validateAndNormalizeData($data, $this->getRecord());
    }
}
