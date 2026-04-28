<?php

namespace App\Filament\Resources\InvigilatorDistributionSettings\Tables;

use App\Enums\InvigilatorDistributionPattern;
use App\Support\ExamCollegeScope;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvigilatorDistributionSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                TextColumn::make('default_max_assignments_per_invigilator')
                    ->label(__('exam.fields.default_max_assignments_per_invigilator'))
                    ->numeric(),
                IconColumn::make('allow_role_fallback')
                    ->label(__('exam.fields.allow_role_fallback'))
                    ->boolean(),
                TextColumn::make('distribution_pattern')
                    ->label(__('exam.fields.distribution_pattern'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof InvigilatorDistributionPattern ? $state->label() : __("exam.invigilator_distribution_patterns.{$state}")),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
