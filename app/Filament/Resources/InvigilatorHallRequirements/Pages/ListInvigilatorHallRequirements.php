<?php

namespace App\Filament\Resources\InvigilatorHallRequirements\Pages;

use App\Filament\Resources\InvigilatorHallRequirements\InvigilatorHallRequirementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvigilatorHallRequirements extends ListRecords
{
    protected static string $resource = InvigilatorHallRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
