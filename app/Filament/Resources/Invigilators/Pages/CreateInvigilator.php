<?php

namespace App\Filament\Resources\Invigilators\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\Invigilators\InvigilatorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvigilator extends CreateRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = InvigilatorResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return InvigilatorResource::validateAndNormalizeData($data);
    }
}
