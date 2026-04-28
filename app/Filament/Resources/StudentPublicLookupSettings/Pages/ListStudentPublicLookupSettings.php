<?php

namespace App\Filament\Resources\StudentPublicLookupSettings\Pages;

use App\Filament\Resources\StudentPublicLookupSettings\StudentPublicLookupSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudentPublicLookupSettings extends ListRecords
{
    protected static string $resource = StudentPublicLookupSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
