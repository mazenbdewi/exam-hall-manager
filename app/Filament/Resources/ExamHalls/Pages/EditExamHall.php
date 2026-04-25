<?php

namespace App\Filament\Resources\ExamHalls\Pages;

use App\Filament\Concerns\NotifiesValidationErrors;
use App\Filament\Resources\ExamHalls\ExamHallResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditExamHall extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = ExamHallResource::class;

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
        return ExamHallResource::validateAndNormalizeData($data, $this->getRecord());
    }
}
