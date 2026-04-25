<?php

namespace App\Filament\Resources\HallSettings\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HallSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('large_hall_min_capacity')
                    ->label(__('exam.fields.large_hall_min_capacity'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('amphitheater_min_capacity')
                    ->label(__('exam.fields.amphitheater_min_capacity'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('exam.fields.updated_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
