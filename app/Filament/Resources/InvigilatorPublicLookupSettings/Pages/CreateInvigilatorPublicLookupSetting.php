<?php

namespace App\Filament\Resources\InvigilatorPublicLookupSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\InvigilatorPublicLookupSettings\InvigilatorPublicLookupSettingResource;
use App\Models\InvigilatorDistributionSetting;
use Filament\Resources\Pages\CreateRecord;

class CreateInvigilatorPublicLookupSetting extends CreateRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = InvigilatorPublicLookupSettingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = InvigilatorPublicLookupSettingResource::validateAndNormalizeData($data);

        return [
            ...InvigilatorDistributionSetting::defaultsForCollege($data['college_id'])->getAttributes(),
            ...$data,
        ];
    }
}
