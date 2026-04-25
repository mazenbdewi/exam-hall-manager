<?php

namespace App\Filament\Resources\ExamHalls\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\ExamHalls\ExamHallResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExamHall extends CreateRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = ExamHallResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ExamHallResource::validateAndNormalizeData($data);
    }
}
