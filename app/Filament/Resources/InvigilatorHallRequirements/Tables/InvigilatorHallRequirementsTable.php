<?php

namespace App\Filament\Resources\InvigilatorHallRequirements\Tables;

use App\Enums\ExamHallType;
use App\Support\ExamCollegeScope;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvigilatorHallRequirementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                TextColumn::make('hall_type')
                    ->label(__('exam.fields.hall_type'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ExamHallType ? $state->label() : __("exam.hall_types.{$state}"))
                    ->sortable(),
                TextColumn::make('hall_head_count')
                    ->label(__('exam.invigilation_roles.hall_head'))
                    ->numeric(),
                TextColumn::make('secretary_count')
                    ->label(__('exam.invigilation_roles.secretary'))
                    ->numeric(),
                TextColumn::make('regular_count')
                    ->label(__('exam.invigilation_roles.regular'))
                    ->numeric(),
                TextColumn::make('reserve_count')
                    ->label(__('exam.invigilation_roles.reserve'))
                    ->numeric(),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label(__('exam.fields.college'))
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                SelectFilter::make('hall_type')
                    ->label(__('exam.fields.hall_type'))
                    ->options(ExamHallType::options()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
