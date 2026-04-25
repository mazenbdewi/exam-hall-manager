<?php

namespace App\Filament\Resources\Users\Tables;

use App\Support\ExamCollegeScope;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('exam.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('exam.fields.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('primary_role')
                    ->label(__('exam.fields.role'))
                    ->state(fn ($record): string => $record->roles->pluck('name')->map(fn (string $role): string => \App\Support\RoleNames::label($role))->implode('، '))
                    ->badge(),
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label(__('exam.fields.college'))
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
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
