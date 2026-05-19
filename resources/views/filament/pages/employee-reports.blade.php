<x-filament-panels::page>
    @php
        $report = $this->report();
        $employees = $report['employees'];
        $completion = (float) ($report['completion_percentage'] ?? 0);
        $cardClasses = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900';
        $labelClasses = 'text-sm font-medium text-gray-600 dark:text-gray-300';
        $valueClasses = 'mt-1 text-2xl font-bold text-gray-950 dark:text-white';
        $mutedClasses = 'text-sm leading-6 text-gray-500 dark:text-gray-400';
    @endphp

    <div dir="rtl" class="space-y-5 text-right">
        <section class="rounded-lg border border-primary-200 bg-primary-50 p-4 dark:border-primary-500/20 dark:bg-primary-500/10">
            <div class="grid gap-3 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <h2 class="text-lg font-bold text-primary-950 dark:text-primary-100">تقارير الموظفين</h2>
                    <p class="mt-1 text-sm leading-6 text-primary-800 dark:text-primary-200">
                        متابعة تكليفات الموظفين ونسبة إنجاز التغطية العامة لكل كلية.
                    </p>
                </div>

                <div class="grid gap-3 md:grid-cols-3 lg:w-[46rem]">
                    <label class="space-y-1">
                        <span class="text-sm font-medium text-primary-900 dark:text-primary-100">الكلية</span>
                        <select wire:model.live="college_id" class="w-full rounded-md border-primary-200 bg-white dark:border-white/10 dark:bg-gray-900">
                            @foreach ($this->collegeOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-1">
                        <span class="text-sm font-medium text-primary-900 dark:text-primary-100">من تاريخ</span>
                        <input type="date" wire:model.live="from_date" class="w-full rounded-md border-primary-200 bg-white dark:border-white/10 dark:bg-gray-900">
                    </label>

                    <label class="space-y-1">
                        <span class="text-sm font-medium text-primary-900 dark:text-primary-100">إلى تاريخ</span>
                        <input type="date" wire:model.live="to_date" class="w-full rounded-md border-primary-200 bg-white dark:border-white/10 dark:bg-gray-900">
                    </label>
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="{{ $cardClasses }}">
                <div class="{{ $labelClasses }}">نسبة إنجاز الكلية</div>
                <div class="{{ $valueClasses }}">{{ number_format($completion, 1) }}%</div>
                <div class="mt-3 h-2 rounded-full bg-gray-100 dark:bg-white/10">
                    <div class="h-2 rounded-full bg-success-500" style="width: {{ min(100, max(0, $completion)) }}%"></div>
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $labelClasses }}">المهام المنجزة</div>
                <div class="{{ $valueClasses }}">{{ number_format($report['assigned_tasks_count'] ?? 0) }}</div>
                <p class="{{ $mutedClasses }}">من أصل {{ number_format($report['required_tasks_count'] ?? 0) }} مهمة مطلوبة</p>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $labelClasses }}">النقص</div>
                <div class="{{ $valueClasses }}">{{ number_format($report['shortage_count'] ?? 0) }}</div>
                <p class="{{ $mutedClasses }}">حسب احتياج القاعات المستخدمة</p>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $labelClasses }}">الموظفون الفعالون</div>
                <div class="{{ $valueClasses }}">{{ number_format($report['active_employees'] ?? 0) }}</div>
                <p class="{{ $mutedClasses }}">من أصل {{ number_format($report['total_employees'] ?? 0) }} موظف</p>
            </div>

            <div class="{{ $cardClasses }}">
                <div class="{{ $labelClasses }}">أيام العمل والقاعات</div>
                <div class="{{ $valueClasses }}">{{ number_format($report['days_count'] ?? 0) }}</div>
                <p class="{{ $mutedClasses }}">{{ number_format($report['halls_count'] ?? 0) }} قاعة ضمن التقرير</p>
            </div>
        </section>

        <section class="{{ $cardClasses }}">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h3 class="font-bold text-gray-950 dark:text-white">ترتيب الموظفين حسب إنجاز العمل</h3>
                    <p class="{{ $mutedClasses }}">الترتيب يعتمد على التكليفات المسندة واليدوية ضمن الفترة المحددة.</p>
                </div>

                <x-filament::button icon="heroicon-o-document-arrow-down" wire:click="exportPdf" :disabled="! $report['college']">
                    توليد تقرير PDF
                </x-filament::button>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[980px] text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-white/10 dark:text-gray-300">
                            <th class="px-3 py-2 text-right">الترتيب</th>
                            <th class="px-3 py-2 text-right">الموظف</th>
                            <th class="px-3 py-2 text-right">نوع الكادر</th>
                            <th class="px-3 py-2 text-right">نوع المراقبة</th>
                            <th class="px-3 py-2 text-right">المهام</th>
                            <th class="px-3 py-2 text-right">نسبة من عمل الكلية</th>
                            <th class="px-3 py-2 text-right">استخدام الحد الشخصي</th>
                            <th class="px-3 py-2 text-right">الأيام</th>
                            <th class="px-3 py-2 text-right">الحالة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @forelse ($employees as $employee)
                            <tr class="text-gray-800 dark:text-gray-100">
                                <td class="px-3 py-3 font-bold">#{{ $employee['rank'] }}</td>
                                <td class="px-3 py-3">
                                    <div class="font-semibold">{{ $employee['name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $employee['phone'] ?: '—' }}</div>
                                </td>
                                <td class="px-3 py-3">{{ $employee['staff_category'] ?: '—' }}</td>
                                <td class="px-3 py-3">{{ $employee['invigilation_role'] ?: '—' }}</td>
                                <td class="px-3 py-3">
                                    <div class="font-semibold">{{ $employee['tasks_count'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        آلي {{ $employee['assigned_count'] }} / يدوي {{ $employee['manual_count'] }}
                                    </div>
                                </td>
                                <td class="px-3 py-3">{{ number_format($employee['contribution_percentage'], 1) }}%</td>
                                <td class="px-3 py-3">
                                    {{ number_format($employee['capacity_usage_percentage'], 1) }}%
                                    <div class="text-xs text-gray-500 dark:text-gray-400">الحد: {{ $employee['effective_max_assignments'] }}</div>
                                </td>
                                <td class="px-3 py-3">{{ $employee['days_count'] }}</td>
                                <td class="px-3 py-3">
                                    @if ($employee['is_active'])
                                        <span class="rounded-md bg-success-50 px-2 py-1 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-300">فعال</span>
                                    @else
                                        <span class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">غير فعال</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                    لا توجد بيانات موظفين ضمن الكلية والفترة المحددة.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <div class="{{ $cardClasses }}">
                <h3 class="font-bold text-gray-950 dark:text-white">أفضل الموظفين أداءً</h3>
                <div class="mt-4 space-y-3">
                    @forelse (($report['top_employees'] ?? []) as $employee)
                        <div class="flex items-center justify-between rounded-md border border-gray-100 p-3 dark:border-white/10">
                            <div>
                                <div class="font-semibold text-gray-900 dark:text-white">#{{ $employee['rank'] }} {{ $employee['name'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $employee['staff_category'] ?: '—' }} / {{ $employee['invigilation_role'] ?: '—' }}</div>
                            </div>
                            <div class="text-left">
                                <div class="font-bold text-primary-700 dark:text-primary-300">{{ $employee['tasks_count'] }} مهام</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($employee['contribution_percentage'], 1) }}%</div>
                            </div>
                        </div>
                    @empty
                        <p class="{{ $mutedClasses }}">لا توجد تكليفات بعد.</p>
                    @endforelse
                </div>
            </div>

            <div class="{{ $cardClasses }}">
                <h3 class="font-bold text-gray-950 dark:text-white">ملخص حالات المهام</h3>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach (($report['status_counts'] ?? []) as $status)
                        <div class="rounded-md border border-gray-100 p-3 dark:border-white/10">
                            <div class="{{ $labelClasses }}">{{ $status['label'] }}</div>
                            <div class="mt-1 text-xl font-bold text-gray-950 dark:text-white">{{ number_format($status['count']) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
