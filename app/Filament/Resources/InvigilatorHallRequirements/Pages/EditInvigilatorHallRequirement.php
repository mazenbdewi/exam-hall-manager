<?php

namespace App\Filament\Resources\InvigilatorHallRequirements\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\InvigilatorHallRequirements\InvigilatorHallRequirementResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvigilatorHallRequirement extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = InvigilatorHallRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return InvigilatorHallRequirementResource::validateAndNormalizeData($data, $this->getRecord());
    }
}
