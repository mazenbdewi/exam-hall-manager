<?php

namespace App\Filament\Resources\InvigilatorDistributionSettings\Pages;

use App\Filament\Resources\InvigilatorDistributionSettings\InvigilatorDistributionSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvigilatorDistributionSettings extends ListRecords
{
    protected static string $resource = InvigilatorDistributionSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
