<?php

namespace App\Filament\Resources\InvigilatorPublicLookupSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\InvigilatorPublicLookupSettings\InvigilatorPublicLookupSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditInvigilatorPublicLookupSetting extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = InvigilatorPublicLookupSettingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return InvigilatorPublicLookupSettingResource::validateAndNormalizeData($data, $this->getRecord());
    }
}
