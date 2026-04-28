<?php

namespace App\Filament\Resources\StudentPublicLookupSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\StudentPublicLookupSettings\StudentPublicLookupSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditStudentPublicLookupSetting extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = StudentPublicLookupSettingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return StudentPublicLookupSettingResource::validateAndNormalizeData($data, $this->getRecord());
    }
}
