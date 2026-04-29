<?php

namespace App\Filament\Resources\Subjects\Tables;

use App\Filament\Resources\SubjectExamRosters\SubjectExamRosterResource;
use App\Models\Subject;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
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

class SubjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                TextColumn::make('department.name')
                    ->label(__('exam.fields.department'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studyLevel.name')
                    ->label(__('exam.fields.study_level'))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('exam.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('exam.fields.code'))
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('exam.fields.is_active'))
                    ->boolean()
                    ->sortable(),
                IconColumn::make('is_shared_subject')
                    ->label('مادة مشتركة')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_core_subject')
                    ->label('مادة أساسية')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('preferred_exam_period')
                    ->label('الفترة المفضلة')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'morning' => 'صباحية',
                        'mid_day' => 'وسطى',
                        'evening' => 'مسائية',
                        default => 'لا تفضيل',
                    })
                    ->badge()
                    ->toggleable(),
                TextColumn::make('shared_subject_scheduling_mode')
                    ->label('طريقة جدولة المادة المشتركة')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'all_departments_together' => 'نفس الموعد',
                        'separate_departments' => 'أيام مختلفة',
                        'auto' => 'تلقائي',
                        default => '—',
                    })
                    ->badge()
                    ->toggleable(),
                TextColumn::make('subject_exam_offerings_count')
                    ->counts('subjectExamOfferings')
                    ->label(__('exam.fields.offerings')),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label(__('exam.fields.college'))
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                SelectFilter::make('department_id')
                    ->label(__('exam.fields.department'))
                    ->relationship('department', 'name'),
                SelectFilter::make('study_level_id')
                    ->label(__('exam.fields.study_level'))
                    ->relationship('studyLevel', 'name'),
                TernaryFilter::make('is_active'),
                TernaryFilter::make('is_shared_subject')
                    ->label('المواد المشتركة'),
                TernaryFilter::make('is_core_subject')
                    ->label('المواد الأساسية'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('subjectRosters')
                    ->label('قوائم الطلاب')
                    ->icon('heroicon-o-users')
                    ->url(fn (Subject $record): string => SubjectExamRosterResource::getUrl('index', [
                        'tableFilters' => [
                            'subject_id' => [
                                'value' => $record->getKey(),
                            ],
                        ],
                    ])),
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
