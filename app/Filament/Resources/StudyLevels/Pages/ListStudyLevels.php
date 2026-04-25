<?php

namespace App\Filament\Resources\StudyLevels\Pages;

use App\Filament\Resources\StudyLevels\StudyLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudyLevels extends ListRecords
{
    protected static string $resource = StudyLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
