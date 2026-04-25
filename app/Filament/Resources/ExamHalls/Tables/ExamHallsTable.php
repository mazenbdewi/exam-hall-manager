<?php

namespace App\Filament\Resources\ExamHalls\Tables;

use App\Enums\ExamHallPriority;
use App\Enums\ExamHallType;
use App\Support\ExamCollegeScope;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ExamHallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('exam.fields.hall_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: ! ExamCollegeScope::isSuperAdmin())
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                TextColumn::make('location')
                    ->label(__('exam.fields.hall_location'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('capacity')
                    ->label(__('exam.fields.capacity'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('hall_type')
                    ->label(__('exam.fields.hall_type'))
                    ->badge()
                    ->formatStateUsing(
                        fn ($state): string => $state instanceof ExamHallType
                            ? $state->label()
                            : (filled($state) ? __("exam.hall_types.{$state}") : '-')
                    )
                    ->sortable(),
                TextColumn::make('priority')
                    ->label(__('exam.fields.priority'))
                    ->badge()
                    ->formatStateUsing(
                        fn ($state): string => $state instanceof ExamHallPriority
                            ? $state->label()
                            : __("exam.priorities.{$state}")
                    )
                    ->color(fn ($state): string => match ($state instanceof ExamHallPriority ? $state->value : $state) {
                        ExamHallPriority::High->value => 'danger',
                        ExamHallPriority::Medium->value => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('exam.fields.status'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('exam.fields.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label(__('exam.fields.college'))
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                SelectFilter::make('priority')
                    ->label(__('exam.fields.priority'))
                    ->options(ExamHallPriority::options()),
                SelectFilter::make('hall_type')
                    ->label(__('exam.fields.hall_type'))
                    ->options(ExamHallType::options()),
                TernaryFilter::make('is_active')
                    ->label(__('exam.fields.status')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
