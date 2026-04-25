<?php

namespace App\Filament\Resources\HallSettings\Pages;

use App\Filament\Resources\HallSettings\HallSettingResource;
use App\Models\HallSetting;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHallSettings extends ListRecords
{
    protected static string $resource = HallSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => ! HallSetting::query()->exists()),
        ];
    }
}
