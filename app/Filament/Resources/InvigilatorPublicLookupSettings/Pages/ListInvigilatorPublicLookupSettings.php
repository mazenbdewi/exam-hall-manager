<?php

namespace App\Filament\Resources\InvigilatorPublicLookupSettings\Pages;

use App\Filament\Resources\InvigilatorPublicLookupSettings\InvigilatorPublicLookupSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvigilatorPublicLookupSettings extends ListRecords
{
    protected static string $resource = InvigilatorPublicLookupSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
