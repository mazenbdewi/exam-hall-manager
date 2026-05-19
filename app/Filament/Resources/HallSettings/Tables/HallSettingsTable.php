<?php

namespace App\Filament\Resources\HallSettings\Tables;

use App\Support\ExamCollegeScope;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class HallSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
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
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label(__('exam.fields.college'))
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
