<?php

namespace App\Filament\Resources\Invigilators\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\Invigilators\InvigilatorResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditInvigilator extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = InvigilatorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return InvigilatorResource::validateAndNormalizeData($data, $this->getRecord());
    }
}
