<?php

namespace App\Filament\Resources\InvigilatorDistributionSettings\Tables;

use App\Enums\InvigilatorDayPreference;
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
                IconColumn::make('allow_multiple_assignments_per_day')
                    ->label(__('exam.fields.allow_multiple_assignments_per_day'))
                    ->boolean(),
                IconColumn::make('allow_role_fallback')
                    ->label(__('exam.fields.allow_role_fallback'))
                    ->boolean(),
                TextColumn::make('max_assignments_per_day')
                    ->label(__('exam.fields.max_assignments_per_day'))
                    ->numeric(),
                TextColumn::make('distribution_pattern')
                    ->label(__('exam.fields.distribution_pattern'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof InvigilatorDistributionPattern ? $state->label() : __("exam.invigilator_distribution_patterns.{$state}")),
                TextColumn::make('day_preference')
                    ->label(__('exam.fields.day_preference'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof InvigilatorDayPreference ? $state->label() : __("exam.invigilator_day_preferences.{$state}")),
                IconColumn::make('show_all_invigilator_assignments')
                    ->label(__('exam.fields.show_all_invigilator_assignments'))
                    ->boolean(),
                TextColumn::make('visibility_before_minutes')
                    ->label(__('exam.fields.visibility_before_minutes'))
                    ->formatStateUsing(fn ($state): string => __("exam.visibility_before_options.{$state}")),
                TextColumn::make('visibility_after_minutes')
                    ->label(__('exam.fields.visibility_after_minutes'))
                    ->formatStateUsing(fn ($state): string => "{$state} دقيقة"),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
