<?php

namespace App\Filament\Resources\SubjectExamOfferings\Tables;

use App\Enums\ExamOfferingStatus;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\College;
use App\Models\Department;
use App\Models\StudentDistributionRun;
use App\Models\Subject;
use App\Models\SubjectExamOffering;
use App\Services\ExamHallDistributionService;
use App\Services\ExamScheduleGeneratorService;
use App\Support\ExamCollegeScope;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;

class SubjectExamOfferingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject.college.name')
                    ->label(__('exam.fields.college'))
                    ->sortable()
                    ->toggleable(),
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
                TextColumn::make('exam_period')
                    ->label('الفترة الامتحانية')
                    ->state(fn (SubjectExamOffering $record): string => static::periodLabel($record))
                    ->badge()
                    ->color('info')
                    ->toggleable(),
                TextColumn::make('pin_status')
                    ->label('التثبيت')
                    ->state(fn (SubjectExamOffering $record): string => $record->is_pinned ? 'مثبتة' : 'غير مثبتة')
                    ->badge()
                    ->color(fn (SubjectExamOffering $record): string => $record->is_pinned ? 'success' : 'gray'),
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
                    ->formatStateUsing(fn ($state) => static::recordStatusLabel($state))
                    ->color(fn ($state): string => static::recordStatusColor($state))
                    ->sortable(),
                TextColumn::make('hall_distribution_status')
                    ->label(__('exam.fields.hall_distribution_status'))
                    ->state(fn (SubjectExamOffering $record): string => static::hallDistributionStatus($record))
                    ->badge()
                    ->color(fn (SubjectExamOffering $record): string => match (static::hallDistributionStatusKey($record)) {
                        'complete' => 'success',
                        'issue' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('regular_students_count')
                    ->counts('regularStudents')
                    ->label(__('exam.fields.regular')),
                TextColumn::make('carry_students_count')
                    ->counts('carryStudents')
                    ->label(__('exam.fields.carry')),
            ])
            ->filters([
                Filter::make('program_scope')
                    ->label('فلترة البرنامج')
                    ->schema([
                        Select::make('college_id')
                            ->label(__('exam.fields.college'))
                            ->options(fn (): array => College::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('department_id', null);
                                $set('subject_id', null);
                            })
                            ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
                        Select::make('department_id')
                            ->label(__('exam.fields.department'))
                            ->options(fn (Get $get): array => static::departmentOptions($get('college_id')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('subject_id', null)),
                        Select::make('subject_id')
                            ->label(__('exam.fields.subject'))
                            ->options(fn (Get $get): array => static::subjectOptions($get('college_id'), $get('department_id')))
                            ->searchable()
                            ->preload(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['college_id'] ?? null, fn (Builder $query, int|string $collegeId): Builder => $query
                                ->whereHas('subject', fn (Builder $subjectQuery): Builder => $subjectQuery->where('college_id', $collegeId)))
                            ->when($data['department_id'] ?? null, fn (Builder $query, int|string $departmentId): Builder => $query
                                ->whereHas('subject', fn (Builder $subjectQuery): Builder => $subjectQuery->where('department_id', $departmentId)))
                            ->when($data['subject_id'] ?? null, fn (Builder $query, int|string $subjectId): Builder => $query
                                ->where('subject_id', $subjectId));
                    }),
                SelectFilter::make('academic_year_id')
                    ->label(__('exam.fields.academic_year'))
                    ->relationship('academicYear', 'name'),
                SelectFilter::make('semester_id')
                    ->label(__('exam.fields.semester'))
                    ->relationship('semester', 'name'),
                SelectFilter::make('status')
                    ->label(__('exam.fields.status'))
                    ->options([
                        'draft' => 'مسودة',
                        'ready' => 'معتمد',
                        'distributed' => __('exam.statuses.distributed'),
                    ]),
                SelectFilter::make('is_pinned')
                    ->label('حالة التثبيت')
                    ->options([
                        '1' => 'مثبتة',
                        '0' => 'غير مثبتة',
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
                Action::make('editSchedule')
                    ->label('تعديل الموعد')
                    ->icon('heroicon-o-clock')
                    ->modalHeading('تعديل موعد المادة')
                    ->schema([
                        DatePicker::make('exam_date')
                            ->label(__('exam.fields.exam_date'))
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d')
                            ->required(),
                        Select::make('period_key')
                            ->label('الفترة الامتحانية')
                            ->options(fn (SubjectExamOffering $record): array => static::periodOptions($record))
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, SubjectExamOffering $record, Set $set): void {
                                $period = static::periodByKey($record, $state);

                                if ($period) {
                                    $set('exam_start_time', substr((string) ($period['start_time'] ?? ''), 0, 5));
                                }
                            }),
                        TimePicker::make('exam_start_time')
                            ->label(__('exam.fields.exam_start_time'))
                            ->seconds(false)
                            ->required(),
                    ])
                    ->fillForm(fn (SubjectExamOffering $record): array => [
                        'exam_date' => $record->exam_date?->toDateString(),
                        'period_key' => static::periodKeyForRecord($record),
                        'exam_start_time' => substr((string) $record->exam_start_time, 0, 5),
                    ])
                    ->action(function (SubjectExamOffering $record, array $data): void {
                        try {
                            app(ExamScheduleGeneratorService::class)->updateOfferingSchedule($record, $data);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('تعذر حفظ الموعد')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('تم حفظ موعد المادة')
                            ->success()
                            ->send();
                    }),
                Action::make('pinOffering')
                    ->label('تثبيت الموعد')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->visible(fn (SubjectExamOffering $record): bool => ! $record->is_pinned)
                    ->action(function (SubjectExamOffering $record): void {
                        try {
                            app(ExamScheduleGeneratorService::class)->pinOffering($record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('تعذر تثبيت الموعد')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('تم تثبيت موعد المادة')
                            ->success()
                            ->send();
                    }),
                Action::make('unpinOffering')
                    ->label('إلغاء التثبيت')
                    ->icon('heroicon-o-lock-open')
                    ->color('gray')
                    ->visible(fn (SubjectExamOffering $record): bool => $record->is_pinned)
                    ->action(function (SubjectExamOffering $record): void {
                        app(ExamScheduleGeneratorService::class)->unpinOffering($record);

                        Notification::make()
                            ->title('تم إلغاء تثبيت المادة')
                            ->success()
                            ->send();
                    }),
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
                EditAction::make()
                    ->using(function (array $data, SubjectExamOffering $record): void {
                        $record->fill($data);

                        if ((bool) $record->is_pinned) {
                            app(ExamScheduleGeneratorService::class)->ensureOfferingCanBePinned($record);
                        }

                        $record->save();

                        if (array_key_exists('exam_date', $data) || array_key_exists('exam_start_time', $data)) {
                            app(ExamScheduleGeneratorService::class)->updateOfferingSchedule($record->refresh(), [
                                'exam_date' => $data['exam_date'] ?? $record->exam_date,
                                'exam_start_time' => $data['exam_start_time'] ?? $record->exam_start_time,
                            ]);
                        }
                    }),
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

    protected static function hallDistributionStatus(SubjectExamOffering $record): string
    {
        return __('exam.hall_distribution_statuses.'.static::hallDistributionStatusKey($record));
    }

    protected static function recordStatusLabel(mixed $status): string
    {
        $value = $status instanceof ExamOfferingStatus ? $status->value : (string) $status;

        return match ($value) {
            ExamOfferingStatus::Draft->value => 'مسودة',
            ExamOfferingStatus::Ready->value => 'معتمد',
            ExamOfferingStatus::Distributed->value => __('exam.statuses.distributed'),
            default => filled($value) ? __("exam.statuses.$value") : 'غير محدد',
        };
    }

    protected static function recordStatusColor(mixed $status): string
    {
        $value = $status instanceof ExamOfferingStatus ? $status->value : (string) $status;

        return match ($value) {
            ExamOfferingStatus::Draft->value => 'warning',
            ExamOfferingStatus::Ready->value => 'success',
            ExamOfferingStatus::Distributed->value => 'info',
            default => 'gray',
        };
    }

    protected static function periodLabel(SubjectExamOffering $record): string
    {
        $item = $record->examScheduleDraftItem;
        $metadata = $item?->metadata ?? [];
        $periodName = $metadata['period_name'] ?? null;
        $endTime = $item?->end_time ? substr((string) $item->end_time, 0, 5) : null;
        $startTime = substr((string) $record->exam_start_time, 0, 5);

        if (filled($periodName)) {
            return trim($periodName.' '.$startTime.($endTime ? ' - '.$endTime : ''));
        }

        return $startTime ?: 'غير محدد';
    }

    protected static function periodOptions(SubjectExamOffering $record): array
    {
        return collect($record->examScheduleDraft?->settings_json['periods'] ?? $record->examScheduleDraftItem?->draft?->settings_json['periods'] ?? [])
            ->mapWithKeys(fn (array $period, int $index): array => [
                (string) ($period['key'] ?? $index) => collect([
                    $period['name'] ?? 'الفترة '.($index + 1),
                    substr((string) ($period['start_time'] ?? ''), 0, 5).'-'.substr((string) ($period['end_time'] ?? ''), 0, 5),
                ])->filter()->implode(' | '),
            ])
            ->all();
    }

    protected static function periodByKey(SubjectExamOffering $record, ?string $key): ?array
    {
        if (blank($key)) {
            return null;
        }

        return collect($record->examScheduleDraft?->settings_json['periods'] ?? $record->examScheduleDraftItem?->draft?->settings_json['periods'] ?? [])
            ->first(fn (array $period, int $index): bool => (string) ($period['key'] ?? $index) === (string) $key);
    }

    protected static function periodKeyForRecord(SubjectExamOffering $record): ?string
    {
        $startTime = substr((string) $record->exam_start_time, 0, 8);

        foreach ($record->examScheduleDraft?->settings_json['periods'] ?? $record->examScheduleDraftItem?->draft?->settings_json['periods'] ?? [] as $index => $period) {
            if (substr((string) ($period['start_time'] ?? ''), 0, 8) === $startTime) {
                return (string) ($period['key'] ?? $index);
            }
        }

        return null;
    }

    protected static function departmentOptions(mixed $collegeId = null): array
    {
        $effectiveCollegeId = ExamCollegeScope::isSuperAdmin()
            ? $collegeId
            : ExamCollegeScope::currentCollegeId();

        return Department::query()
            ->when($effectiveCollegeId, fn (Builder $query): Builder => $query->where('college_id', $effectiveCollegeId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function subjectOptions(mixed $collegeId = null, mixed $departmentId = null): array
    {
        $effectiveCollegeId = ExamCollegeScope::isSuperAdmin()
            ? $collegeId
            : ExamCollegeScope::currentCollegeId();

        $query = Subject::query()
            ->with(['department', 'studyLevel'])
            ->when($effectiveCollegeId, fn (Builder $query): Builder => $query->where('college_id', $effectiveCollegeId))
            ->when($departmentId, fn (Builder $query): Builder => $query->where('department_id', $departmentId))
            ->orderBy('name');

        $query = ExamCollegeScope::applyCollegeScope($query);

        return $query
            ->get()
            ->mapWithKeys(fn (Subject $subject): array => [
                $subject->id => collect([
                    $subject->name,
                    $subject->department?->name,
                    $subject->studyLevel?->name,
                ])->filter()->implode(' - '),
            ])
            ->all();
    }

    protected static function hallDistributionStatusKey(SubjectExamOffering $record): string
    {
        $total = (int) ($record->exam_students_count ?? $record->examStudents()->count());
        $assigned = (int) ($record->student_hall_assignments_count ?? $record->studentHallAssignments()->count());

        return match (true) {
            $total > 0 && $assigned >= $total => 'complete',
            $total > 0 && static::latestRunShowsUnassignedStudents($record, $assigned, $total) => 'issue',
            $assigned > 0 && $assigned < $total => 'issue',
            default => 'not_distributed',
        };
    }

    protected static function latestRunShowsUnassignedStudents(SubjectExamOffering $record, int $assigned, int $total): bool
    {
        if (! $record->exam_date || $assigned >= $total) {
            return false;
        }

        $collegeId = $record->subject?->college_id;

        if (! $collegeId) {
            return false;
        }

        $run = static::latestDistributionRunForOffering($record, (int) $collegeId);

        return $run !== null && in_array($run->status, ['partial', 'failed'], true);
    }

    protected static function latestDistributionRunForOffering(SubjectExamOffering $record, int $collegeId): ?StudentDistributionRun
    {
        static $runs = [];

        $examDate = $record->exam_date?->toDateString();

        if (! $examDate) {
            return null;
        }

        $key = $collegeId.'|'.$examDate;

        if (! array_key_exists($key, $runs)) {
            $runs[$key] = StudentDistributionRun::query()
                ->where('college_id', $collegeId)
                ->whereDate('from_date', '<=', $examDate)
                ->whereDate('to_date', '>=', $examDate)
                ->latest('executed_at')
                ->latest('id')
                ->first();
        }

        return $runs[$key];
    }
}
