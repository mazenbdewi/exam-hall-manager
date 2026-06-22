<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FixedExamPrograms\FixedExamProgramResource;
use App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamScheduleDraft;
use App\Models\ExamScheduleDraftItem;
use App\Models\FixedExamProgram;
use App\Models\Semester;
use App\Models\StudyLevel;
use App\Models\SubjectExamRoster;
use App\Services\AuditLogService;
use App\Services\ExamScheduleGeneratorService;
use App\Support\ExamCollegeScope;
use App\Support\InstitutionSettings;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExamScheduleGenerator extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $slug = 'exam-schedule-generator';

    protected string $view = 'filament.pages.exam-schedule-generator';

    public ?int $college_id = null;

    public ?int $academic_year_id = null;

    public ?int $semester_id = null;

    public ?int $study_level_id = null;

    public ?int $department_id = null;

    public ?string $start_date = null;

    public ?string $end_date = null;

    /** @var array<int> */
    public array $excluded_weekdays = [5, 6];

    /** @var array<int, array{date:?string,reason:?string}> */
    public array $specific_holidays = [
        ['date' => null, 'reason' => null],
    ];

    public int $period_count = 2;

    /** @var array<int, array{name:string,start_time:string,end_time:string,period_type:string}> */
    public array $periods = [
        ['name' => 'الفترة الأولى', 'start_time' => '09:00', 'end_time' => '11:00', 'period_type' => 'morning'],
        ['name' => 'الفترة الثانية', 'start_time' => '12:00', 'end_time' => '14:00', 'period_type' => 'mid_day'],
        ['name' => 'الفترة الثالثة', 'start_time' => '15:00', 'end_time' => '17:00', 'period_type' => 'evening'],
    ];

    public bool $prevent_same_day = false;

    public string $approval_existing_strategy = 'create_missing';

    public ?int $draft_id = null;

    public ?string $active_week_start = null;

    public ?int $filter_department_id = null;

    public ?int $filter_study_level_id = null;

    public bool $show_shared_only = false;

    public bool $show_core_only = false;

    public bool $show_conflicts_only = false;

    /** @var array<int, array{exam_date:?string,period_key:?string}> */
    public array $itemEdits = [];

    protected ?ExamScheduleDraft $cachedDraft = null;

    protected ?FixedExamProgram $cachedFixedProgram = null;

    protected ?array $cachedValidation = null;

    public function mount(): void
    {
        $this->college_id = request()->integer('college_id') ?: (ExamCollegeScope::currentCollegeId()
            ?? College::query()->orderBy('name')->value('id'));
        $this->academic_year_id = AcademicYear::query()->where('is_current', true)->value('id')
            ?? AcademicYear::query()->where('is_active', true)->latest('id')->value('id');
        $this->semester_id = Semester::query()->where('is_active', true)->orderBy('sort_order')->value('id');
        $this->start_date = now()->toDateString();
        $this->end_date = now()->addWeeks(3)->toDateString();
        $this->active_week_start = now()->startOfWeek(Carbon::SUNDAY)->toDateString();

        if (! ExamCollegeScope::isSuperAdmin()) {
            $this->college_id = ExamCollegeScope::currentCollegeId();
        }

        $this->restoreLatestDraftState();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('exam.navigation.core_operations');
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public static function getNavigationLabel(): string
    {
        return 'توليد البرنامج الامتحاني';
    }

    public function getTitle(): string|Htmlable
    {
        return 'توليد البرنامج الامتحاني';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return ExamCollegeScope::isSuperAdmin()
            || (auth()->user()?->can('view_exam_schedule_generator') ?? false)
            || (auth()->user()?->can('generate_exam_schedule_draft') ?? false);
    }

    public function updated(string $property): void
    {
        $this->cachedDraft = null;
        $this->cachedFixedProgram = null;
        $this->cachedValidation = null;

        if ($property === 'college_id' && ! ExamCollegeScope::isSuperAdmin()) {
            $this->college_id = ExamCollegeScope::currentCollegeId();
        }

        if (in_array($property, ['college_id', 'academic_year_id', 'semester_id', 'department_id'], true)) {
            $this->draft_id = null;
            $this->restoreLatestDraftState();
        }

        if (in_array($property, ['start_date', 'end_date'], true)) {
            $this->active_week_start = $this->start_date
                ? Carbon::parse($this->start_date)->startOfWeek(Carbon::SUNDAY)->toDateString()
                : $this->active_week_start;
        }
    }

    public function generateDraft(): void
    {
        Log::info('Exam schedule generator generateDraft reached.', [
            'user_id' => auth()->id(),
            'college_id' => $this->college_id,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'department_id' => $this->department_id,
            'study_level_id' => $this->study_level_id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ]);

        try {
            $this->authorizeGeneratorAction('generate_exam_schedule_draft');

            $draft = app(ExamScheduleGeneratorService::class)->generateDraft($this->settingsPayload());
            $this->draft_id = $draft->id;
            $this->active_week_start = $draft->start_date?->startOfWeek(Carbon::SUNDAY)->toDateString();
            $this->refreshDraftState();

            app(AuditLogService::class)->log(
                action: 'exam_schedule.generate_draft',
                module: 'exam_schedule_generator',
                description: 'توليد مسودة البرنامج الامتحاني',
                metadata: [
                    'draft_id' => $draft->id,
                    'faculty_id' => $draft->faculty_id,
                    'summary' => $draft->summary_json,
                ],
                status: ($draft->summary_json['status'] ?? null) === 'failed' ? 'failed' : 'success',
            );

            Notification::make()
                ->title('تم توليد مسودة البرنامج')
                ->body('تم توليد مسودة البرنامج الامتحاني، يمكنك مراجعتها وتعديلها قبل الاعتماد. '.$this->summaryText($draft->summary_json ?? []))
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            Log::warning('Exam schedule draft generation validation failed.', [
                'user_id' => auth()->id(),
                'errors' => $exception->errors(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            Notification::make()
                ->title('تعذر توليد المسودة')
                ->body($this->validationExceptionMessage($exception))
                ->warning()
                ->persistent()
                ->send();
        } catch (\Throwable $exception) {
            Log::error('Exam schedule draft generation failed.', [
                'user_id' => auth()->id(),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            Notification::make()
                ->title('فشل توليد المسودة')
                ->body('حدث خطأ غير متوقع أثناء توليد مسودة البرنامج. تم تسجيل التفاصيل في سجل النظام للمراجعة.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function checkConflicts(): void
    {
        $this->authorizeGeneratorAction('update_exam_schedule_draft');

        $draft = $this->currentDraft();

        if (! $draft) {
            return;
        }

        $validation = app(ExamScheduleGeneratorService::class)->validateDraft($draft);
        $draft->update(['summary_json' => $validation['summary']]);
        $this->cachedValidation = $validation;
        $this->refreshDraftState();

        Notification::make()
            ->title('تم فحص التعارضات')
            ->body($this->summaryText($validation['summary'] ?? []))
            ->color(($validation['hard_conflicts_count'] ?? 0) > 0 ? 'danger' : (($validation['warnings_count'] ?? 0) > 0 ? 'warning' : 'success'))
            ->send();
    }

    public function updateDraftItem(int $itemId): void
    {
        $this->authorizeGeneratorAction('update_exam_schedule_draft');

        $item = $this->draftItem($itemId);
        $edit = $this->itemEdits[$itemId] ?? [];
        $period = $this->periodByKey((string) ($edit['period_key'] ?? '0'));

        $item->update([
            'exam_date' => filled($edit['exam_date'] ?? null) ? Carbon::parse($edit['exam_date'])->toDateString() : null,
            'start_time' => $period['start_time'] ?? null,
            'end_time' => $period['end_time'] ?? null,
            'period_type' => $period['period_type'] ?? null,
            'status' => 'manually_adjusted',
            'metadata' => array_merge($item->metadata ?? [], [
                'period_name' => $period['name'] ?? null,
                'manually_adjusted_at' => now()->toDateTimeString(),
                'manually_adjusted_by' => auth()->id(),
            ]),
        ]);

        $this->checkConflicts();
    }

    public function cancelDraftItem(int $itemId): void
    {
        $this->authorizeGeneratorAction('update_exam_schedule_draft');

        $item = $this->draftItem($itemId);
        $subjectName = $item->subject?->name;

        $item->delete();
        unset($this->itemEdits[$itemId]);

        $this->refreshDraftValidationSummary();
        $this->refreshDraftState();

        Notification::make()
            ->title('تم حذف المادة من المسودة')
            ->body($subjectName ? 'حُذفت '.$subjectName.' من جدول التعديل اليدوي.' : null)
            ->success()
            ->send();
    }

    public function pinDraftItem(int $itemId): void
    {
        $this->authorizeGeneratorAction('update_exam_schedule_draft');

        $item = $this->draftItem($itemId);
        $metadata = $item->metadata ?? [];
        $isPinned = (bool) ($metadata['pinned'] ?? false);

        if ($isPinned) {
            unset($metadata['pinned'], $metadata['pinned_at'], $metadata['pinned_by']);

            $item->update(['metadata' => $metadata]);
            $this->refreshDraftState();

            Notification::make()
                ->title('تم إلغاء تثبيت موعد المادة')
                ->success()
                ->send();

            return;
        }

        $item->update([
            'metadata' => array_merge($metadata, [
                'pinned' => true,
                'pinned_at' => now()->toDateTimeString(),
                'pinned_by' => auth()->id(),
            ]),
        ]);
        $this->refreshDraftState();

        Notification::make()
            ->title('تم تثبيت موعد المادة بنجاح')
            ->success()
            ->send();
    }

    public function approveDraft(): void
    {
        $this->authorizeGeneratorAction('approve_exam_schedule_draft');

        $draft = $this->currentDraft();

        if (! $draft) {
            return;
        }

        try {
            $result = app(ExamScheduleGeneratorService::class)->approveDraft($draft, $this->approval_existing_strategy);
            $this->refreshDraftState();

            app(AuditLogService::class)->log(
                action: 'exam_schedule.approve_draft',
                module: 'exam_schedule_generator',
                description: 'اعتماد مسودة البرنامج الامتحاني',
                metadata: [
                    'draft_id' => $draft->id,
                    'faculty_id' => $draft->faculty_id,
                    'created_count' => $result['created_count'] ?? 0,
                    'updated_count' => $result['updated_count'] ?? 0,
                ],
            );

            Notification::make()
                ->title($result['message'] ?? 'تم تثبيت برنامج الامتحان وحفظه بنجاح')
                ->body('تم إنشاء '.($result['created_count'] ?? 0).' من البرامج الامتحانية الرسمية، وتجاوز '.($result['skipped_existing_count'] ?? 0).' برنامج موجود مسبقاً. تم حفظ نسخة ثابتة قابلة للطباعة لاحقاً.')
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('لا يمكن اعتماد المسودة')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function exportConflictPdf(): StreamedResponse|Response|null
    {
        $this->authorizeGeneratorAction('export_exam_schedule_conflicts');

        $draft = $this->currentDraft();

        if (! $draft) {
            return null;
        }

        $validation = $this->validationData();
        $studentConflictRows = app(ExamScheduleGeneratorService::class)->studentConflictDetailRows($draft);
        $tempDir = storage_path('app/mpdf-temp');

        if (! File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $defaultConfig = (new ConfigVariables)->getDefaults();
        $defaultFontConfig = (new FontVariables)->getDefaults();

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => $tempDir,
            'fontDir' => array_merge($defaultConfig['fontDir'], [resource_path('fonts')]),
            'fontdata' => $defaultFontConfig['fontdata'] + [
                'notosansarabic' => [
                    'R' => 'NotoSansArabic-Regular.ttf',
                    'B' => 'NotoSansArabic-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'notosansarabic',
        ]);
        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetDirectionality('rtl');
        $institution = InstitutionSettings::make();
        $pdf->WriteHTML(view('pdf.exam-schedule-conflicts', [
            'draft' => $draft,
            'conflicts' => $validation['conflicts'] ?? [],
            'summary' => $validation['summary'] ?? [],
            'studentConflictDetailsCount' => count($studentConflictRows),
            'systemSetting' => $institution->reportContext($draft->college?->name),
            'logoDataUri' => $institution->logoDataUri(),
        ])->render());

        return response()->streamDownload(
            fn () => print ($pdf->Output('', Destination::STRING_RETURN)),
            'exam-schedule-conflicts-'.$draft->id.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function exportStudentConflictDetailsCsv(): StreamedResponse|Response|null
    {
        $this->authorizeGeneratorAction('export_exam_schedule_conflicts');

        $draft = $this->currentDraft();

        if (! $draft) {
            return null;
        }

        $rows = app(ExamScheduleGeneratorService::class)->studentConflictDetailRows($draft);

        if ($rows === []) {
            Notification::make()
                ->title('لا توجد تعارضات طلاب للتصدير')
                ->body('لا يحتوي البرنامج الحالي على طلاب لديهم مواد متعارضة.')
                ->warning()
                ->send();

            return null;
        }

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'رقم الطالب',
                'تاريخ التعارض',
                'وقت التعارض',
                'المادة الأولى',
                'قسم المادة الأولى',
                'المادة الثانية',
                'قسم المادة الثانية',
                'نوع التعارض',
                'رقم المسودة',
                'ملاحظة / تفاصيل',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['student_number'] ?? '',
                    $row['conflict_date'] ?? '',
                    $row['conflict_time'] ?? '',
                    $row['first_subject'] ?? '',
                    $row['first_department'] ?? '',
                    $row['second_subject'] ?? '',
                    $row['second_department'] ?? '',
                    $row['conflict_type'] ?? '',
                    $row['draft_id'] ?? '',
                    $row['details'] ?? '',
                ]);
            }

            fclose($output);
        }, 'student-conflict-details-draft-'.$draft->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function previousWeek(): void
    {
        $this->active_week_start = Carbon::parse($this->active_week_start ?: $this->start_date)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->active_week_start = Carbon::parse($this->active_week_start ?: $this->start_date)->addWeek()->toDateString();
    }

    public function currentDraft(): ?ExamScheduleDraft
    {
        if (! $this->draft_id) {
            $this->restoreLatestDraftState();
        }

        if (! $this->draft_id) {
            return null;
        }

        return $this->cachedDraft ??= ExamScheduleDraft::query()
            ->with(['college', 'academicYear', 'semester', 'items.department', 'items.subject.department', 'items.subject.studyLevel'])
            ->whereKey($this->draft_id)
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->where('faculty_id', ExamCollegeScope::currentCollegeId()))
            ->first();
    }

    public function validationData(): array
    {
        $draft = $this->currentDraft();

        if (! $draft) {
            return [
                'summary' => [],
                'conflicts' => [],
                'hard_conflicts_count' => 0,
                'warnings_count' => 0,
            ];
        }

        return $this->cachedValidation ??= app(ExamScheduleGeneratorService::class)->validateDraft($draft);
    }

    public function summaryCards(): array
    {
        $summary = $this->currentDraft()?->summary_json ?: $this->validationData()['summary'];

        return [
            'عدد المواد' => $summary['subjects_count'] ?? 0,
            'عدد المواد المجدولة' => $summary['scheduled_subjects_count'] ?? 0,
            'عدد المواد غير المجدولة' => $summary['unscheduled_subjects_count'] ?? 0,
            'عدد التعارضات' => $summary['conflicts_count'] ?? 0,
            'عدد الأيام المستخدمة' => $summary['used_days_count'] ?? 0,
            'أكثر يوم ازدحامًا' => $summary['busiest_day'] ?? '-',
            'ملاحظات المواد المشتركة' => $summary['shared_subject_notes_count'] ?? 0,
            'تحذيرات المواد الأساسية' => $summary['core_subject_notes_count'] ?? 0,
        ];
    }

    public function readinessSummary(): array
    {
        $rosters = $this->readyRostersForCurrentSettings();

        return [
            'ready_rosters_count' => $rosters->count(),
            'students_count' => (int) $rosters->sum('eligible_roster_students_count'),
            'carry_count' => (int) $rosters->sum('carry_students_count'),
            'core_subjects_count' => $rosters->filter(fn (SubjectExamRoster $roster): bool => (bool) $roster->subject?->is_core_subject)->pluck('subject_id')->unique()->count(),
            'shared_subjects_count' => $rosters->filter(fn (SubjectExamRoster $roster): bool => (bool) $roster->subject?->is_shared_subject)->pluck('subject_id')->unique()->count(),
        ];
    }

    public function calendarData(): array
    {
        $draft = $this->currentDraft();
        $weekStart = Carbon::parse($this->active_week_start ?: $this->start_date)->startOfWeek(Carbon::SUNDAY);
        $days = collect(range(0, 6))
            ->map(fn (int $offset): array => [
                'date' => $weekStart->copy()->addDays($offset)->toDateString(),
                'label' => $this->arabicDayName($weekStart->copy()->addDays($offset)).' '.$weekStart->copy()->addDays($offset)->format('Y-m-d'),
            ])
            ->all();

        $periods = collect($this->activePeriods())
            ->map(fn (array $period, int $index): array => [
                'key' => (string) $index,
                'name' => $period['name'],
                'start_time' => $this->timeString($period['start_time']),
                'end_time' => $this->timeString($period['end_time']),
                'period_type' => $period['period_type'] ?? 'morning',
            ])
            ->all();

        $items = $draft ? $this->filteredItems($draft->items) : collect();

        return [
            'days' => $days,
            'periods' => $periods,
            'items' => $items->groupBy(fn (ExamScheduleDraftItem $item): string => ($item->exam_date?->toDateString() ?? '').'|'.$this->timeString($item->start_time)),
        ];
    }

    public function draftItems(): Collection
    {
        $draft = $this->currentDraft();

        $items = $draft ? $this->filteredItems($draft->items)->sortBy([['exam_date', 'asc'], ['start_time', 'asc'], ['subject.name', 'asc']])->values() : collect();

        $items->each(function (ExamScheduleDraftItem $item): void {
            logger()->info('Manual adjustment row', [
                'item_id' => $item->id,
                'subject_id' => $item->subject_id,
                'subject_name' => $item->subject?->name,
                'exam_date' => $item->exam_date?->toDateString(),
                'item_edit_exam_date' => $this->itemEdits[$item->id]['exam_date'] ?? null,
            ]);
        });

        return $items;
    }

    public function unscheduledDraftItems(): Collection
    {
        return $this->draftItems()->where('status', 'unscheduled')->values();
    }

    public function coreSubjectsOutsidePreferredPeriod(): Collection
    {
        return collect($this->validationData()['conflicts'] ?? [])
            ->whereIn('type', ['core_subject_not_preferred_period', 'core_subject_strict_period'])
            ->values();
    }

    public function sharedSubjectScheduleSummary(): Collection
    {
        $draft = $this->currentDraft();

        if (! $draft) {
            return collect();
        }

        return $draft->items
            ->where('is_shared_subject', true)
            ->groupBy(fn (ExamScheduleDraftItem $item): string => $item->shared_group_key ?: 'item-'.$item->id)
            ->map(function (Collection $items): array {
                $first = $items->first();
                $metadata = $first?->metadata ?? [];

                return [
                    'subject' => $first?->subject?->name,
                    'mode' => match ($metadata['shared_subject_scheduling_mode'] ?? null) {
                        'all_departments_together' => 'توزيعها لكافة الأقسام في نفس الموعد',
                        'separate_departments' => 'جدولة كل قسم في يوم مختلف إن أمكن',
                        'auto' => 'تلقائي حسب التعارضات والسعة',
                        default => 'مادة واحدة',
                    },
                    'departments_count' => $items->pluck('department_id')->filter()->unique()->count(),
                    'dates' => $items->pluck('exam_date')->filter()->map(fn ($date) => $date->toDateString())->unique()->values()->implode('، '),
                    'times' => $items->pluck('start_time')->filter()->map(fn ($time) => substr((string) $time, 0, 5))->unique()->values()->implode('، '),
                ];
            })
            ->values();
    }

    public function collegeOptions(): array
    {
        return College::query()
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query) => $query->whereKey(ExamCollegeScope::currentCollegeId()))
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function academicYearOptions(): array
    {
        return AcademicYear::query()->where('is_active', true)->orderByDesc('name')->pluck('name', 'id')->all();
    }

    public function semesterOptions(): array
    {
        return Semester::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function studyLevelOptions(): array
    {
        return StudyLevel::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function departmentOptions(): array
    {
        return Department::query()
            ->when($this->college_id, fn (Builder $query) => $query->where('college_id', $this->college_id))
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function officialOfferingsUrl(): string
    {
        return SubjectExamOfferingResource::getUrl('index');
    }

    public function printScheduleUrl(): string
    {
        $draft = $this->currentDraft();
        $settings = $draft?->settings_json ?? [];

        return route('filament.adminpanel.exam-schedules.print', collect([
            'college_id' => $draft?->faculty_id ?: $this->college_id,
            'department_id' => $this->department_id ?: ($settings['department_id'] ?? $this->filter_department_id),
            'academic_year_id' => $draft?->academic_year_id ?: $this->academic_year_id,
            'semester_id' => $draft?->semester_id ?: $this->semester_id,
        ])->filter(fn (mixed $value): bool => filled($value))->all());
    }

    public function printFixedProgramUrl(): string
    {
        $fixedProgram = $this->currentFixedProgram();

        return $fixedProgram
            ? route('filament.adminpanel.fixed-exam-programs.print', ['fixedExamProgram' => $fixedProgram])
            : $this->fixedProgramsUrl();
    }

    public function fixedProgramsUrl(): string
    {
        return FixedExamProgramResource::getUrl('index');
    }

    public function globalDistributionUrl(): string
    {
        return ComprehensiveStudentDistribution::getUrl();
    }

    public function invigilatorDistributionUrl(): string
    {
        return url('/adminpanel/invigilator-distribution');
    }

    public function reportsDashboardUrl(): string
    {
        return ReportsDashboard::getUrl();
    }

    public function studentReviewUrl(): string
    {
        return SubjectExamOfferingResource::getUrl('index');
    }

    protected function settingsPayload(): array
    {
        return [
            'faculty_id' => $this->college_id,
            'academic_year_id' => $this->academic_year_id,
            'semester_id' => $this->semester_id,
            'study_level_id' => $this->study_level_id,
            'department_id' => $this->department_id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'excluded_weekdays' => $this->excluded_weekdays,
            'holidays' => $this->parsedHolidays(),
            'periods' => $this->activePeriods(),
            'previous_draft_id' => $this->draft_id,
            'prevent_same_day' => $this->prevent_same_day,
        ];
    }

    protected function readyRostersForCurrentSettings(): Collection
    {
        if (! $this->college_id || ! $this->academic_year_id || ! $this->semester_id) {
            return collect();
        }

        return SubjectExamRoster::query()
            ->with('subject')
            ->withCount([
                'eligibleRosterStudents',
                'eligibleRosterStudents as carry_students_count' => fn (Builder $query) => $query->where('student_type', 'carry'),
            ])
            ->where('college_id', $this->college_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->where('semester_id', $this->semester_id)
            ->where('status', 'ready')
            ->when($this->department_id, fn (Builder $query) => $query->where('department_id', $this->department_id))
            ->when($this->study_level_id, fn (Builder $query) => $query->where('study_level_id', $this->study_level_id))
            ->get();
    }

    public function currentFixedProgram(): ?FixedExamProgram
    {
        return $this->cachedFixedProgram ??= $this->latestFixedProgram();
    }

    protected function latestFixedProgram(): ?FixedExamProgram
    {
        $draft = $this->currentDraft();

        return $this->fixedProgramsBaseQuery()
            ->when($draft, fn (Builder $query): Builder => $query->where('exam_schedule_draft_id', $draft->id))
            ->latest('fixed_at')
            ->latest('id')
            ->first();
    }

    protected function restoreLatestDraftState(): void
    {
        if ($this->draft_id || ! $this->college_id || ! $this->academic_year_id || ! $this->semester_id) {
            return;
        }

        $draft = ExamScheduleDraft::query()
            ->where('faculty_id', $this->college_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->where('semester_id', $this->semester_id)
            ->when($this->department_id, fn (Builder $query): Builder => $query->where('settings_json->department_id', $this->department_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query): Builder => $query->where('faculty_id', ExamCollegeScope::currentCollegeId()))
            ->whereIn('status', [ExamScheduleDraft::STATUS_COMPLETED, ExamScheduleDraft::STATUS_APPROVED])
            ->latest('approved_at')
            ->latest('id')
            ->first();

        if (! $draft) {
            return;
        }

        $this->draft_id = $draft->id;
        $this->active_week_start = $draft->start_date?->startOfWeek(Carbon::SUNDAY)->toDateString() ?: $this->active_week_start;
        $this->itemEdits = $draft->items()
            ->get()
            ->mapWithKeys(fn (ExamScheduleDraftItem $item): array => [
                $item->id => [
                    'exam_date' => $item->exam_date?->toDateString(),
                    'period_key' => $this->periodKeyForTime($this->timeString($item->start_time)),
                ],
            ])
            ->all();
    }

    protected function fixedProgramsBaseQuery(): Builder
    {
        return FixedExamProgram::query()
            ->where('status', 'fixed')
            ->when($this->college_id, fn (Builder $query): Builder => $query->where('college_id', $this->college_id))
            ->when($this->academic_year_id, fn (Builder $query): Builder => $query->where('academic_year_id', $this->academic_year_id))
            ->when($this->semester_id, fn (Builder $query): Builder => $query->where('semester_id', $this->semester_id))
            ->when($this->department_id, fn (Builder $query): Builder => $query->where('department_id', $this->department_id))
            ->when(! ExamCollegeScope::isSuperAdmin(), fn (Builder $query): Builder => $query->where('college_id', ExamCollegeScope::currentCollegeId()));
    }

    protected function parsedHolidays(): array
    {
        return collect($this->specific_holidays)
            ->filter(fn (array $holiday): bool => filled($holiday['date'] ?? null))
            ->mapWithKeys(fn (array $holiday): array => [
                Carbon::parse($holiday['date'])->toDateString() => [
                    'date' => Carbon::parse($holiday['date'])->toDateString(),
                    'reason' => trim((string) ($holiday['reason'] ?? '')),
                ],
            ])
            ->values()
            ->all();
    }

    public function addHolidayRow(): void
    {
        $this->specific_holidays[] = ['date' => null, 'reason' => null];
    }

    public function removeHolidayRow(int $index): void
    {
        unset($this->specific_holidays[$index]);
        $this->specific_holidays = array_values($this->specific_holidays);

        if ($this->specific_holidays === []) {
            $this->addHolidayRow();
        }
    }

    public function excludedDatesPreview(): array
    {
        if (blank($this->start_date) || blank($this->end_date)) {
            return [];
        }

        $start = Carbon::parse($this->start_date);
        $end = Carbon::parse($this->end_date);

        if ($end->lt($start)) {
            return [];
        }

        $dates = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (in_array((int) $date->dayOfWeek, array_map('intval', $this->excluded_weekdays), true)) {
                $dates[$date->toDateString()] = [
                    'date' => $date->toDateString(),
                    'day' => $this->arabicDayName($date),
                    'reason' => 'عطلة أسبوعية',
                ];
            }
        }

        foreach ($this->parsedHolidays() as $holiday) {
            $date = Carbon::parse($holiday['date']);

            if ($date->lt($start) || $date->gt($end)) {
                continue;
            }

            $dates[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'day' => $this->arabicDayName($date),
                'reason' => filled($holiday['reason'] ?? null) ? $holiday['reason'] : 'عطلة محددة',
            ];
        }

        ksort($dates);

        return array_values($dates);
    }

    protected function upsertHoliday(string $date, string $reason): void
    {
        $normalizedDate = Carbon::parse($date)->toDateString();

        foreach ($this->specific_holidays as $index => $holiday) {
            if (($holiday['date'] ?? null) === $normalizedDate) {
                $this->specific_holidays[$index]['reason'] = $reason;

                return;
            }
        }

        $emptyIndex = collect($this->specific_holidays)
            ->search(fn (array $holiday): bool => blank($holiday['date'] ?? null));

        if ($emptyIndex !== false) {
            $this->specific_holidays[$emptyIndex] = ['date' => $normalizedDate, 'reason' => $reason];

            return;
        }

        $this->specific_holidays[] = ['date' => $normalizedDate, 'reason' => $reason];
    }

    protected function activePeriods(): array
    {
        return collect($this->periods)
            ->take(max(1, min(3, $this->period_count)))
            ->values()
            ->all();
    }

    public function periodTimingPreview(): array
    {
        $periods = collect($this->activePeriods())
            ->map(function (array $period, int $index): array {
                $start = $this->timeString($period['start_time'] ?? null);
                $end = $this->timeString($period['end_time'] ?? null);
                $duration = null;

                if ($start && $end) {
                    $startTime = Carbon::parse($start);
                    $endTime = Carbon::parse($end);
                    $duration = $endTime->gt($startTime) ? (int) $startTime->diffInMinutes($endTime) : null;
                }

                return [
                    'index' => $index,
                    'name' => filled($period['name'] ?? null) ? (string) $period['name'] : 'الفترة '.($index + 1),
                    'start_time' => $start,
                    'end_time' => $end,
                    'duration_minutes' => $duration,
                    'break_after_minutes' => null,
                ];
            })
            ->values();

        return $periods
            ->map(function (array $period, int $index) use ($periods): array {
                $next = $periods->get($index + 1);

                if ($next && filled($period['end_time']) && filled($next['start_time'])) {
                    $endTime = Carbon::parse($period['end_time']);
                    $nextStartTime = Carbon::parse($next['start_time']);
                    $period['break_after_minutes'] = $nextStartTime->gte($endTime)
                        ? (int) $endTime->diffInMinutes($nextStartTime)
                        : null;
                }

                return $period;
            })
            ->all();
    }

    protected function periodByKey(string $key): ?array
    {
        return collect($this->activePeriods())
            ->map(fn (array $period, int $index): array => $period + ['key' => (string) $index])
            ->firstWhere('key', $key);
    }

    protected function draftItem(int $itemId): ExamScheduleDraftItem
    {
        $draft = $this->currentDraft();

        abort_unless($draft, 404);

        $item = $draft->items->firstWhere('id', $itemId);

        abort_unless($item instanceof ExamScheduleDraftItem, 404);

        return $item;
    }

    protected function filteredItems(Collection $items): Collection
    {
        return $items
            ->when($this->filter_department_id, fn (Collection $items) => $items->where('department_id', $this->filter_department_id))
            ->when($this->filter_study_level_id, fn (Collection $items) => $items->filter(fn (ExamScheduleDraftItem $item): bool => (int) $item->subject?->study_level_id === (int) $this->filter_study_level_id))
            ->when($this->show_shared_only, fn (Collection $items) => $items->where('is_shared_subject', true))
            ->when($this->show_core_only, fn (Collection $items) => $items->where('is_core_subject', true))
            ->when($this->show_conflicts_only, fn (Collection $items) => $items->filter(fn (ExamScheduleDraftItem $item): bool => in_array($item->status, ['conflict', 'unscheduled'], true)));
    }

    protected function refreshDraftState(): void
    {
        $this->cachedDraft = null;
        $this->cachedFixedProgram = null;
        $this->cachedValidation = null;
        $draft = $this->currentDraft();

        $this->itemEdits = $draft?->items
            ->mapWithKeys(fn (ExamScheduleDraftItem $item): array => [
                $item->id => [
                    'exam_date' => $item->exam_date?->toDateString(),
                    'period_key' => $this->periodKeyForTime($this->timeString($item->start_time)),
                ],
            ])
            ->all() ?? [];
    }

    protected function refreshDraftValidationSummary(): void
    {
        $this->cachedDraft = null;
        $this->cachedValidation = null;

        $draft = $this->currentDraft();

        if (! $draft) {
            return;
        }

        $validation = app(ExamScheduleGeneratorService::class)->validateDraft($draft);
        $draft->update(['summary_json' => $validation['summary']]);
        $this->cachedValidation = $validation;
    }

    protected function periodKeyForTime(?string $startTime): string
    {
        foreach ($this->activePeriods() as $index => $period) {
            if ($this->timeString($period['start_time'] ?? null) === $startTime) {
                return (string) $index;
            }
        }

        return '0';
    }

    protected function authorizeGeneratorAction(string $permission): void
    {
        if (ExamCollegeScope::isSuperAdmin()) {
            return;
        }

        abort_unless(auth()->user()?->can($permission), 403);
    }

    protected function summaryText(array $summary): string
    {
        $status = match ($summary['status'] ?? null) {
            'success' => 'ناجح',
            'warning' => 'ناجح مع تحذيرات',
            'failed' => 'فشل',
            default => 'غير محدد',
        };

        return collect([
            'الحالة: '.$status,
            'المواد: '.($summary['subjects_count'] ?? 0),
            'المجدولة: '.($summary['scheduled_subjects_count'] ?? 0),
            'التعارضات: '.($summary['conflicts_count'] ?? 0),
            'التحذيرات: '.($summary['warnings_count'] ?? 0),
        ])->implode(' | ');
    }

    protected function validationExceptionMessage(ValidationException $exception): string
    {
        $messages = collect($exception->errors())
            ->flatten()
            ->filter()
            ->values();

        if ($messages->isEmpty()) {
            return 'يرجى مراجعة إعدادات التوليد والمحاولة مرة أخرى.';
        }

        return $messages->take(3)->implode(' ');
    }

    protected function timeString(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        return strlen((string) $time) === 5 ? ((string) $time).':00' : substr((string) $time, 0, 8);
    }

    protected function arabicDayName(Carbon $date): string
    {
        return match ($date->dayOfWeek) {
            Carbon::SUNDAY => 'الأحد',
            Carbon::MONDAY => 'الإثنين',
            Carbon::TUESDAY => 'الثلاثاء',
            Carbon::WEDNESDAY => 'الأربعاء',
            Carbon::THURSDAY => 'الخميس',
            Carbon::FRIDAY => 'الجمعة',
            default => 'السبت',
        };
    }
}
