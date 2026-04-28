<?php

namespace App\Filament\Resources\InvigilatorPublicLookupSettings\Tables;

use App\Support\ExamCollegeScope;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvigilatorPublicLookupSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
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
