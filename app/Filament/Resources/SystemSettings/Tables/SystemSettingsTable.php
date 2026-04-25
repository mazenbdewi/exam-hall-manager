<?php

namespace App\Filament\Resources\SystemSettings\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SystemSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('university_logo')
                    ->label(__('exam.fields.university_logo'))
                    ->disk('public')
                    ->circular(),
                TextColumn::make('university_name')
                    ->label(__('exam.fields.university_name'))
                    ->searchable(),
                TextColumn::make('updated_at')
                    ->label(__('exam.fields.updated_at'))
                    ->dateTime('Y-m-d H:i'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
