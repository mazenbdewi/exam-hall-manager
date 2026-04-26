<?php

namespace App\Filament\Resources\Invigilators\Tables;

use App\Enums\InvigilationRole;
use App\Enums\StaffCategory;
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

class InvigilatorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('exam.fields.invigilator_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->sortable()
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                TextColumn::make('phone')
                    ->label(__('exam.fields.phone'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('staff_category')
                    ->label(__('exam.fields.staff_category'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof StaffCategory ? $state->label() : __("exam.staff_categories.{$state}"))
                    ->sortable(),
                TextColumn::make('invigilation_role')
                    ->label(__('exam.fields.invigilation_role'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof InvigilationRole ? $state->label() : __("exam.invigilation_roles.{$state}"))
                    ->sortable(),
                TextColumn::make('max_assignments')
                    ->label(__('exam.fields.max_assignments'))
                    ->placeholder(__('exam.fields.default_value'))
                    ->sortable(),
                TextColumn::make('max_assignments_per_day')
                    ->label(__('exam.fields.max_assignments_per_day'))
                    ->placeholder(__('exam.fields.default_value'))
                    ->sortable(),
                TextColumn::make('workload_reduction_percentage')
                    ->label(__('exam.fields.workload_reduction_percentage_short'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ((int) $state).'%')
                    ->color(fn ($state): string => match (true) {
                        (int) $state >= 100 => 'danger',
                        (int) $state > 0 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('exam.fields.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label(__('exam.fields.college'))
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                SelectFilter::make('staff_category')
                    ->label(__('exam.fields.staff_category'))
                    ->options(StaffCategory::options()),
                SelectFilter::make('invigilation_role')
                    ->label(__('exam.fields.invigilation_role'))
                    ->options(InvigilationRole::options()),
                TernaryFilter::make('is_active')
                    ->label(__('exam.fields.is_active')),
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
