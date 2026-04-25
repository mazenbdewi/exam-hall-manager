<?php

namespace App\Filament\Resources\ExamHalls\Pages;

use App\Filament\Resources\ExamHalls\ExamHallResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExamHalls extends ListRecords
{
    protected static string $resource = ExamHallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
