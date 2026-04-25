<?php

namespace App\Filament\Resources\HallSettings\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\HallSettings\HallSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditHallSetting extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = HallSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return HallSettingResource::validateAndNormalizeData($data);
    }
}
