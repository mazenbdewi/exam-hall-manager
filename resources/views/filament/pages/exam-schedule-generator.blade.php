<x-filament-panels::page>
    @php
        $draft = $this->currentDraft();
        $validation = $this->validationData();
        $calendar = $this->calendarData();
        $statusLabel = fn (?string $status): string => match ($status) {
            'scheduled' => 'مجدولة',
            'conflict' => 'تحتاج مراجعة',
            'unscheduled' => 'غير مجدولة',
            'manually_adjusted' => 'معدلة يدوياً',
            default => 'غير محدد',
        };
        $statusClasses = fn (?string $status, bool $shared = false): string => match (true) {
            $status === 'unscheduled' || $status === 'conflict' => 'border-danger-300 bg-danger-50 text-danger-900 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-100',
            $shared => 'border-indigo-300 bg-indigo-50 text-indigo-900 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-100',
            $status === 'manually_adjusted' => 'border-warning-300 bg-warning-50 text-warning-900 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-100',
            default => 'border-success-300 bg-success-50 text-success-900 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-100',
        };
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="grid gap-2 text-sm text-gray-600 dark:text-gray-300 md:grid-cols-4 lg:grid-cols-8">
                @foreach ([
                    '1. اختيار الكلية والفصل',
                    '2. تحديد الفترة الامتحانية',
                    '3. تحديد العطل',
                    '4. تحديد الفترات اليومية',
                    '5. القواعد العامة والمواد',
                    '6. توليد المسودة',
                    '7. مراجعة الرزنامة والتعارضات',
                    '8. تثبيت المسودة واعتمادها',
                ] as $step)
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-medium text-gray-800 dark:border-white/10 dark:bg-white/5 dark:text-gray-100">
                        {{ $step }}
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="mb-4 flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-adjustments-horizontal" class="h-5 w-5 text-primary-600 dark:text-primary-300" />
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">إعدادات توليد البرنامج الامتحاني</h2>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @if (\App\Support\ExamCollegeScope::isSuperAdmin())
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الكلية</span>
                        <select wire:model.live="college_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            @foreach ($this->collegeOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <div class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الكلية</span>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-white/10 dark:bg-white/5 dark:text-white">
                            {{ $this->collegeOptions()[$college_id] ?? '—' }}
                        </div>
                    </div>
                @endif

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">العام الدراسي</span>
                    <select wire:model.live="academic_year_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        @foreach ($this->academicYearOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الفصل الدراسي</span>
                    <select wire:model.live="semester_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        @foreach ($this->semesterOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">المرحلة الدراسية</span>
                    <select wire:model.live="study_level_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">كل المراحل</option>
                        @foreach ($this->studyLevelOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">القسم</span>
                    <select wire:model.live="department_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">كل الأقسام</option>
                        @foreach ($this->departmentOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">تاريخ بداية الامتحانات</span>
                    <input type="date" wire:model.live="start_date" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">تاريخ نهاية الامتحانات</span>
                    <input type="date" wire:model.live="end_date" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">عدد الفترات اليومية</span>
                    <select wire:model.live="period_count" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="1">فترة واحدة</option>
                        <option value="2">فترتان</option>
                        <option value="3">ثلاث فترات</option>
                    </select>
                </label>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <h2 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">العطل والأيام المستبعدة</h2>
                <div class="mb-4 rounded-md border border-info-200 bg-info-50 p-3 text-sm text-info-900 dark:border-info-500/20 dark:bg-info-500/10 dark:text-info-100">
                    <strong>ملاحظة:</strong>
                    توليد البرنامج الامتحاني لا يعتمد على أسماء الطلاب. يعتمد فقط على المواد، الأقسام، السنوات، الفترات، والعطل. بعد تثبيت البرنامج ورفع الطلاب يمكن فحص تعارضات الطلاب إن وجدت.
                </div>
                <div class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">أيام العطل الأسبوعية</div>
                <div class="grid gap-2 sm:grid-cols-4">
                    @foreach ([6 => 'السبت', 0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة'] as $day => $label)
                        <label class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm dark:border-white/10">
                            <input type="checkbox" wire:model.live="excluded_weekdays" value="{{ $day }}" class="rounded border-gray-300 text-primary-600">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-5 space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">تواريخ عطل محددة</span>
                        <x-filament::button size="xs" color="gray" icon="heroicon-o-plus" wire:click="addHolidayRow" type="button">إضافة تاريخ</x-filament::button>
                    </div>
                    @foreach ($specific_holidays as $index => $holiday)
                        <div class="grid gap-2 sm:grid-cols-[1fr_2fr_auto]">
                            <label class="space-y-1">
                                <span class="text-xs text-gray-500 dark:text-gray-400">التاريخ</span>
                                <input type="date" wire:model.live="specific_holidays.{{ $index }}.date" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            </label>
                            <label class="space-y-1">
                                <span class="text-xs text-gray-500 dark:text-gray-400">السبب</span>
                                <input type="text" wire:model.live="specific_holidays.{{ $index }}.reason" placeholder="عطلة رسمية" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            </label>
                            <div class="flex items-end">
                                <x-filament::button size="sm" color="danger" icon="heroicon-o-trash" wire:click="removeHolidayRow({{ $index }})" type="button">حذف</x-filament::button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 rounded-md border border-gray-200 p-3 dark:border-white/10">
                    <div class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">إضافة عطلة لمدة عدة أيام</div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="space-y-1">
                            <span class="text-xs text-gray-500 dark:text-gray-400">من تاريخ</span>
                            <input type="date" wire:model.live="holiday_range_start" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        </label>
                        <label class="space-y-1">
                            <span class="text-xs text-gray-500 dark:text-gray-400">إلى تاريخ</span>
                            <input type="date" wire:model.live="holiday_range_end" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        </label>
                        <label class="space-y-1 sm:col-span-2">
                            <span class="text-xs text-gray-500 dark:text-gray-400">السبب</span>
                            <input type="text" wire:model.live="holiday_range_reason" placeholder="عطلة رسمية" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        </label>
                    </div>
                    <div class="mt-3">
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-calendar-days" wire:click="addHolidayRange" type="button">إضافة الفترة إلى العطل</x-filament::button>
                    </div>
                </div>

                <div class="mt-5">
                    <h3 class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">الأيام المستبعدة من البرنامج</h3>
                    <div class="max-h-64 overflow-y-auto rounded-md border border-gray-200 dark:border-white/10">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead>
                                <tr class="text-gray-600 dark:text-gray-300">
                                    <th class="px-3 py-2 text-right">التاريخ</th>
                                    <th class="px-3 py-2 text-right">اليوم</th>
                                    <th class="px-3 py-2 text-right">السبب</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @forelse ($this->excludedDatesPreview() as $excludedDate)
                                    <tr>
                                        <td class="px-3 py-2">{{ $excludedDate['date'] }}</td>
                                        <td class="px-3 py-2">{{ $excludedDate['day'] }}</td>
                                        <td class="px-3 py-2">{{ $excludedDate['reason'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-3 py-4 text-center text-gray-500">لا توجد أيام مستبعدة ضمن الفترة المحددة.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <h2 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">الفترات اليومية والقواعد العامة</h2>
                <div class="space-y-3">
                    @foreach (range(0, $period_count - 1) as $index)
                        <div class="grid gap-2 sm:grid-cols-3">
                            <input type="text" wire:model.live="periods.{{ $index }}.name" class="rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800" aria-label="اسم الفترة">
                            <input type="time" wire:model.live="periods.{{ $index }}.start_time" class="rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800" aria-label="وقت البداية">
                            <input type="time" wire:model.live="periods.{{ $index }}.end_time" class="rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800" aria-label="وقت النهاية">
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">مدة الاستراحة بين الفترات</span>
                        <input type="number" min="0" wire:model.live="break_minutes" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">مدة الامتحان الافتراضية</span>
                        <input type="number" min="30" wire:model.live="default_exam_duration_minutes" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                    </label>
                    <label class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm dark:border-white/10">
                        <input type="checkbox" wire:model.live="prevent_same_day" class="rounded border-gray-300 text-primary-600">
                        <span>منع مادتين لنفس القسم ونفس السنة في نفس اليوم</span>
                    </label>
                </div>

                <div class="mt-5 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
                    <div class="font-medium">فحص تعارضات الطلاب بعد رفع الطلاب</div>
                    <p class="mt-1">بعد اعتماد المسودة ورفع الطلاب المستجدين والحملة لكل برنامج، يمكن فحص تعارضات الطلاب مثل مادتين في نفس الوقت أو نفس اليوم أو تعارضات مواد الحملة بين السنوات.</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-filament::button icon="heroicon-o-sparkles" wire:click="generateDraft" wire:loading.attr="disabled">
                توليد مسودة البرنامج
            </x-filament::button>

            @if ($draft)
                <x-filament::button color="gray" icon="heroicon-o-shield-check" wire:click="checkConflicts" wire:loading.attr="disabled">
                    فحص التعارضات
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="exportConflictPdf" wire:loading.attr="disabled">
                    تصدير تقرير التعارضات PDF
                </x-filament::button>
                <x-filament::button color="success" icon="heroicon-o-check-circle" wire:click="approveDraft" wire:confirm="سيتم اعتماد المسودة ونقلها إلى البرامج الامتحانية. هل تريد المتابعة؟" wire:loading.attr="disabled">
                    تثبيت المسودة واعتماد البرنامج
                </x-filament::button>
            @endif
        </div>

        @if ($draft)
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                @foreach ($this->summaryCards() as $label => $value)
                    <div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">الرزنامة الأسبوعية</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">مسودة البرنامج رقم {{ $draft->id }} - {{ $draft->college?->name }}</p>
                    </div>
                    <div class="flex gap-2">
                        <x-filament::button color="gray" icon="heroicon-o-chevron-right" wire:click="previousWeek">الأسبوع السابق</x-filament::button>
                        <x-filament::button color="gray" icon="heroicon-o-chevron-left" wire:click="nextWeek">الأسبوع التالي</x-filament::button>
                    </div>
                </div>

                <div class="mb-4 grid gap-3 md:grid-cols-4">
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الأسبوع</span>
                        <input type="date" wire:model.live="active_week_start" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">القسم</span>
                        <select wire:model.live="filter_department_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            <option value="">كل الأقسام</option>
                            @foreach ($this->departmentOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">السنة الدراسية</span>
                        <select wire:model.live="filter_study_level_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            <option value="">كل السنوات</option>
                            @foreach ($this->studyLevelOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-col gap-2 pt-6">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model.live="show_shared_only" class="rounded border-gray-300 text-primary-600">
                            <span>إظهار المواد المشتركة فقط</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model.live="show_conflicts_only" class="rounded border-gray-300 text-primary-600">
                            <span>إظهار التعارضات فقط</span>
                        </label>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-separate border-spacing-2 text-sm">
                        <thead>
                            <tr>
                                <th class="w-32 rounded-md bg-gray-100 p-2 text-gray-700 dark:bg-white/5 dark:text-gray-200">الفترة</th>
                                @foreach ($calendar['days'] as $day)
                                    <th class="min-w-48 rounded-md bg-gray-100 p-2 text-gray-700 dark:bg-white/5 dark:text-gray-200">{{ $day['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($calendar['periods'] as $period)
                                <tr>
                                    <th class="align-top rounded-md bg-gray-50 p-2 text-gray-800 dark:bg-white/5 dark:text-gray-100">
                                        <div>{{ $period['name'] }}</div>
                                        <div class="text-xs font-normal text-gray-500">{{ substr($period['start_time'], 0, 5) }} - {{ substr($period['end_time'], 0, 5) }}</div>
                                    </th>
                                    @foreach ($calendar['days'] as $day)
                                        @php
                                            $slotItems = $calendar['items']->get($day['date'].'|'.$period['start_time'], collect());
                                        @endphp
                                        <td class="h-32 align-top rounded-md border border-gray-200 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/5">
                                            <div class="space-y-2">
                                                @forelse ($slotItems as $item)
                                                    <div class="rounded-md border p-2 shadow-sm {{ $statusClasses($item->status, $item->is_shared_subject) }}">
                                                        <div class="font-semibold">{{ $item->subject?->name }}</div>
                                                        <div class="mt-1 text-xs">{{ $item->subject?->department?->name }} · {{ $item->subject?->studyLevel?->name }}</div>
                                                        <div class="mt-1 flex flex-wrap gap-1 text-xs">
                                                            <span class="rounded bg-white/60 px-2 py-0.5 dark:bg-black/20">{{ $statusLabel($item->status) }}</span>
                                                            @if ($item->is_shared_subject)
                                                                <span class="rounded bg-white/60 px-2 py-0.5 dark:bg-black/20">مادة مشتركة</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @empty
                                                    <span class="text-xs text-gray-400">—</span>
                                                @endforelse
                                            </div>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <h2 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">تعارضات البرنامج الامتحاني</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead>
                            <tr class="text-gray-600 dark:text-gray-300">
                                <th class="px-3 py-2 text-right">المادة</th>
                                <th class="px-3 py-2 text-right">القسم</th>
                                <th class="px-3 py-2 text-right">التاريخ</th>
                                <th class="px-3 py-2 text-right">الوقت</th>
                                <th class="px-3 py-2 text-right">نوع التعارض</th>
                                <th class="px-3 py-2 text-right">الأثر</th>
                                <th class="px-3 py-2 text-right">التفاصيل</th>
                                <th class="px-3 py-2 text-right">الإجراء المقترح</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($validation['conflicts'] ?? [] as $conflict)
                                <tr>
                                    <td class="px-3 py-2">{{ $conflict['subject'] }}</td>
                                    <td class="px-3 py-2">{{ $conflict['department'] }}</td>
                                    <td class="px-3 py-2">{{ $conflict['date'] ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $conflict['time'] ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $conflict['type_label'] }}</td>
                                    <td class="px-3 py-2">{{ $conflict['impact'] ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $conflict['details'] }}</td>
                                    <td class="px-3 py-2">{{ $conflict['suggested_action'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-3 py-6 text-center text-gray-500">لا توجد تعارضات حالياً.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <h2 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">التعديل اليدوي قبل الاعتماد</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead>
                            <tr class="text-gray-600 dark:text-gray-300">
                                <th class="px-3 py-2 text-right">المادة</th>
                                <th class="px-3 py-2 text-right">القسم</th>
                                <th class="px-3 py-2 text-right">السنة</th>
                                <th class="px-3 py-2 text-right">التاريخ</th>
                                <th class="px-3 py-2 text-right">الفترة</th>
                                <th class="px-3 py-2 text-right">الحالة</th>
                                <th class="px-3 py-2 text-right">ملاحظات</th>
                                <th class="px-3 py-2 text-right">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($this->draftItems() as $item)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $item->subject?->name }}</td>
                                    <td class="px-3 py-2">{{ $item->subject?->department?->name }}</td>
                                    <td class="px-3 py-2">{{ $item->subject?->studyLevel?->name }}</td>
                                    <td class="px-3 py-2">
                                        <input type="date" wire:model.defer="itemEdits.{{ $item->id }}.exam_date" class="w-36 rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800">
                                    </td>
                                    <td class="px-3 py-2">
                                        <select wire:model.defer="itemEdits.{{ $item->id }}.period_key" class="w-40 rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800">
                                            @foreach ($calendar['periods'] as $period)
                                                <option value="{{ $period['key'] }}">{{ $period['name'] }} - {{ substr($period['start_time'], 0, 5) }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-2 py-1 text-xs {{ $statusClasses($item->status, $item->is_shared_subject) }}">{{ $statusLabel($item->status) }}</span>
                                    </td>
                                    <td class="max-w-xs whitespace-pre-line px-3 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $item->conflict_notes ?: '—' }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-1">
                                            <x-filament::button size="xs" icon="heroicon-o-pencil-square" wire:click="updateDraftItem({{ $item->id }})">تغيير الموعد</x-filament::button>
                                            <x-filament::button size="xs" color="gray" icon="heroicon-o-lock-closed" wire:click="pinDraftItem({{ $item->id }})">تثبيت</x-filament::button>
                                            <x-filament::button size="xs" color="danger" icon="heroicon-o-x-circle" wire:click="cancelDraftItem({{ $item->id }})">إلغاء</x-filament::button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($draft?->status === 'approved')
                <div class="rounded-lg border border-success-200 bg-success-50 p-4 text-success-900 shadow-sm dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-100">
                    <h2 class="text-base font-semibold">الخطوات التالية</h2>
                    <div class="mt-3 grid gap-2 md:grid-cols-4">
                        <a href="{{ $this->officialOfferingsUrl() }}" class="rounded-md bg-white/70 px-3 py-2 text-sm font-medium dark:bg-black/10">البرامج الامتحانية</a>
                        <a href="{{ $this->globalDistributionUrl() }}" class="rounded-md bg-white/70 px-3 py-2 text-sm font-medium dark:bg-black/10">توزيع شامل للطلاب على القاعات</a>
                        <a href="{{ $this->invigilatorDistributionUrl() }}" class="rounded-md bg-white/70 px-3 py-2 text-sm font-medium dark:bg-black/10">توزيع المراقبين</a>
                        <a href="{{ url('/students') }}" class="rounded-md bg-white/70 px-3 py-2 text-sm font-medium dark:bg-black/10">استعلام الطلاب والمراقبين</a>
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
