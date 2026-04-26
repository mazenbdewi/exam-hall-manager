<?php

namespace App\Filament\Resources\InvigilatorHallRequirements\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\InvigilatorHallRequirements\InvigilatorHallRequirementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvigilatorHallRequirement extends CreateRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = InvigilatorHallRequirementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return InvigilatorHallRequirementResource::validateAndNormalizeData($data);
    }
}
