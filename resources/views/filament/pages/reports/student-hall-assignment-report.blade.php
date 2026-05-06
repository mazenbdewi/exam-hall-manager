<x-filament-panels::page>
    @php
        $offeringOptions = $this->offeringOptions();
        $previewRows = $this->previewRows();
        $printUrl = $this->printUrl();
        $pdfUrl = $this->pdfUrl();
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="mb-4 flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-adjustments-horizontal" class="h-5 w-5 text-primary-600 dark:text-primary-300" />
                <h2 class="text-base font-bold text-gray-950 dark:text-white">فلاتر الكشف</h2>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @if (\App\Support\ExamCollegeScope::isSuperAdmin())
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الكلية</span>
                        <select wire:model.live="college_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                            @foreach ($this->collegeOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

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
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">التاريخ</span>
                    <select wire:model.live="exam_date" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">اختر التاريخ</option>
                        @foreach ($this->dateOptions() as $date => $label)
                            <option value="{{ $date }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">الفترة</span>
                    <select wire:model.live="exam_start_time" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">اختر الفترة</option>
                        @foreach ($this->timeOptions() as $time => $label)
                            <option value="{{ $time }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-1 md:col-span-2 xl:col-span-4">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">المادة</span>
                    <select wire:model.live="subject_exam_offering_id" class="w-full rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800">
                        <option value="">اختر المادة</option>
                        @foreach ($offeringOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-filament::button tag="a" :href="$printUrl ?: '#'" target="_blank" rel="noopener" icon="heroicon-o-printer" :disabled="! $printUrl">
                    طباعة
                </x-filament::button>
                <x-filament::button tag="a" :href="$pdfUrl ?: '#'" target="_blank" rel="noopener" color="gray" icon="heroicon-o-arrow-down-tray" :disabled="! $pdfUrl">
                    تحميل PDF
                </x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="resetFilters">
                    إعادة ضبط
                </x-filament::button>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="mb-3">
                <h3 class="text-base font-bold text-gray-950 dark:text-white">معاينة سريعة</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">تظهر أول 12 نتيجة فقط. التقرير المطبوع يعرض جميع الطلاب بعد الفرز الأبجدي وإعادة ترقيم الجلوس.</p>
            </div>

            @if (! $subject_exam_offering_id)
                <div class="rounded-md border border-dashed border-gray-300 p-5 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                    اختر المادة والتاريخ والفترة لعرض الكشف.
                </div>
            @elseif ($previewRows === [])
                <div class="rounded-md border border-dashed border-warning-300 bg-warning-50 p-5 text-sm font-semibold text-warning-900 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-100">
                    {{ $this->previewEmptyMessage() }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
                                <th class="px-3 py-2 text-right">الرقم الجامعي</th>
                                <th class="px-3 py-2 text-right">اسم الطالب</th>
                                <th class="px-3 py-2 text-right">رقم الجلوس</th>
                                <th class="px-3 py-2 text-right">القاعة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($previewRows as $row)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="px-3 py-2">{{ $row['student_number'] }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $row['full_name'] }}</td>
                                    <td class="px-3 py-2">{{ $row['seat_number'] }}</td>
                                    <td class="px-3 py-2">{{ $row['hall_name'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
