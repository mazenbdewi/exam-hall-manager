<?php

namespace App\Filament\Resources\SubjectExamOfferings\Tables;

use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SubjectExamOfferingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject.college.name')
                    ->label(__('exam.fields.college'))
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                TextColumn::make('subject.name')
                    ->label(__('exam.fields.subject'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.department.name')
                    ->label(__('exam.fields.department'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('academicYear.name')
                    ->label(__('exam.fields.academic_year'))
                    ->sortable(),
                TextColumn::make('semester.name')
                    ->label(__('exam.fields.semester'))
                    ->sortable(),
                TextColumn::make('exam_date')
                    ->label(__('exam.fields.exam_date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('exam_start_time')
                    ->label(__('exam.fields.exam_start_time'))
                    ->time('H:i')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('exam.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\ExamOfferingStatus ? $state->label() : __("exam.statuses.$state"))
                    ->sortable(),
                TextColumn::make('regular_students_count')
                    ->counts('regularStudents')
                    ->label(__('exam.fields.regular')),
                TextColumn::make('carry_students_count')
                    ->counts('carryStudents')
                    ->label(__('exam.fields.carry')),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('exam.fields.academic_year'))
                    ->relationship('academicYear', 'name'),
                SelectFilter::make('semester_id')
                    ->label(__('exam.fields.semester'))
                    ->relationship('semester', 'name'),
                SelectFilter::make('status')
                    ->label(__('exam.fields.status'))
                    ->options([
                        'draft' => __('exam.statuses.draft'),
                        'ready' => __('exam.statuses.ready'),
                        'distributed' => __('exam.statuses.distributed'),
                    ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('manageDistribution')
                    ->label(__('exam.actions.manage_hall_distribution'))
                    ->icon('heroicon-o-squares-2x2')
                    ->url(fn ($record): string => SubjectExamOfferingResource::getUrl('distribution', ['record' => $record])),
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
