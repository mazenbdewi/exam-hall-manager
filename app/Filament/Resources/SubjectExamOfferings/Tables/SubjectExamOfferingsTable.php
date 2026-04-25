<?php

namespace App\Filament\Resources\SubjectExamOfferings\Tables;

use App\Enums\ExamOfferingStatus;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\SubjectExamOffering;
use App\Services\ExamHallDistributionService;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
                    ->formatStateUsing(fn ($state): string => $state?->format('Y-m-d') ?? '-')
                    ->badge()
                    ->color(fn (SubjectExamOffering $record): string => $record->exam_status_color)
                    ->description(fn (SubjectExamOffering $record): string => $record->exam_status_label)
                    ->sortable(),
                TextColumn::make('exam_start_time')
                    ->label(__('exam.fields.exam_start_time'))
                    ->time('H:i')
                    ->sortable(),
                TextColumn::make('exam_status_label')
                    ->label('حالة الامتحان')
                    ->badge()
                    ->color(fn (SubjectExamOffering $record): string => $record->exam_status_color),
                TextColumn::make('same_slot_offerings_count')
                    ->label('مواد بنفس الموعد')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ((int) $state) {
                        0 => 'لا يوجد',
                        1 => '1 مادة',
                        default => ((int) $state).' مواد',
                    })
                    ->color(fn ($state): string => ((int) $state) > 1 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('exam.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ExamOfferingStatus ? $state->label() : __("exam.statuses.$state"))
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
                Filter::make('shared_exam_slots')
                    ->label('المواعيد المشتركة فقط')
                    ->query(fn (Builder $query): Builder => $query->whereHasSameSlotOfferings()),
                TrashedFilter::make(),
            ])
            ->groups([
                Group::make('exam_slot')
                    ->label('موعد الامتحان')
                    ->getKeyFromRecordUsing(fn (SubjectExamOffering $record): string => implode('|', [
                        $record->exam_date?->toDateString(),
                        substr((string) $record->exam_start_time, 0, 8),
                        $record->subject?->college_id,
                    ]))
                    ->getTitleFromRecordUsing(fn (SubjectExamOffering $record): string => sprintf(
                        '%s — %s',
                        $record->exam_date?->format('Y-m-d') ?? '-',
                        substr((string) $record->exam_start_time, 0, 5) ?: '-',
                    ))
                    ->getDescriptionFromRecordUsing(fn (SubjectExamOffering $record): ?string => ExamCollegeScope::isSuperAdmin()
                        ? $record->subject?->college?->name
                        : null)
                    ->groupQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query
                        ->join('subjects as grouped_slot_subjects', 'grouped_slot_subjects.id', '=', 'subject_exam_offerings.subject_id')
                        ->groupBy('subject_exam_offerings.exam_date', 'subject_exam_offerings.exam_start_time', 'grouped_slot_subjects.college_id'))
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('exam_date', $direction)
                        ->orderBy('exam_start_time', $direction))
                    ->scopeQueryUsing(fn (Builder $query, SubjectExamOffering $record): Builder => $query
                        ->whereDate('exam_date', $record->exam_date?->toDateString())
                        ->whereTime('exam_start_time', substr((string) $record->exam_start_time, 0, 8))
                        ->whereHas('subject', fn (Builder $subjectQuery): Builder => $subjectQuery->where('college_id', $record->subject?->college_id)))
                    ->scopeQueryByKeyUsing(function (Builder $query, ?string $key): Builder {
                        [$examDate, $examStartTime, $collegeId] = array_pad(explode('|', (string) $key), 3, null);

                        if (blank($examDate) || blank($examStartTime) || blank($collegeId)) {
                            return $query->whereRaw('0 = 1');
                        }

                        return $query
                            ->whereDate('exam_date', $examDate)
                            ->whereTime('exam_start_time', $examStartTime)
                            ->whereHas('subject', fn (Builder $subjectQuery): Builder => $subjectQuery->where('college_id', $collegeId));
                    })
                    ->collapsible(),
            ])
            ->recordActions([
                Action::make('viewSameSlotOfferings')
                    ->label('عرض مواد نفس الموعد')
                    ->icon('heroicon-o-calendar-days')
                    ->modalHeading('المواد الامتحانية في نفس الموعد')
                    ->modalDescription('يعرض هذا الجدول جميع المواد التي لها نفس التاريخ ووقت الامتحان، لأن توزيع القاعات يجب أن يأخذها معًا.')
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (SubjectExamOffering $record): View => view(
                        'filament.resources.subject-exam-offerings.same-slot-offerings-modal',
                        [
                            'summary' => app(ExamHallDistributionService::class)->getSlotSummary($record),
                        ],
                    ))
                    ->extraModalFooterActions([
                        Action::make('manageSlotDistributionFromSameSlotModal')
                            ->label('إدارة توزيع قاعات هذا الموعد')
                            ->icon('heroicon-o-squares-2x2')
                            ->color('primary')
                            ->url(fn (SubjectExamOffering $record): string => SubjectExamOfferingResource::getUrl('distribution', ['record' => $record])),
                    ]),
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
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy('exam_date')
                ->orderBy('exam_start_time'));
    }
}
