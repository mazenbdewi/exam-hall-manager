<?php

namespace App\Filament\Resources\StudentPublicLookupSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\StudentPublicLookupSettings\StudentPublicLookupSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStudentPublicLookupSetting extends CreateRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = StudentPublicLookupSettingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return StudentPublicLookupSettingResource::validateAndNormalizeData($data);
    }
}
