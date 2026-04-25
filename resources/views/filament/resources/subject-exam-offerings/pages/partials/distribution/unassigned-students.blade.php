@php
    $unassignedStudents = $summary['unassigned_students'] ?? [];
    $unassignedBySubject = $summary['unassigned_summary_by_subject'] ?? [];
    $unassignedCount = count($unassignedStudents);
@endphp

@if ($unassignedCount > 0)
    <section class="rounded-3xl border border-danger-200 bg-white shadow-sm dark:border-danger-500/20 dark:bg-gray-900">
        <div class="border-b border-danger-100 bg-danger-50/70 px-5 py-4 dark:border-danger-500/20 dark:bg-danger-500/10">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-gray-950 dark:text-white">الطلاب غير الموزعين</h2>
                    <p class="mt-1 text-sm text-danger-700 dark:text-danger-300">هذه بطاقة تنبيه سريعة. القائمة التفصيلية الكاملة مخصصة للتصدير وليس للصفحة الرئيسية.</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-filament::button color="danger" icon="heroicon-o-arrow-down-tray" wire:click="exportUnassignedPdf">
                        تحميل كشف الطلاب غير الموزعين PDF
                    </x-filament::button>

                    @if ($canExportExcel)
                        <x-filament::button color="gray" icon="heroicon-o-table-cells">
                            تحميل كشف الطلاب غير الموزعين Excel
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid gap-5 p-5 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <div class="rounded-2xl border border-danger-100 bg-danger-50/60 p-4 dark:border-danger-500/20 dark:bg-danger-500/10">
                <div class="text-sm text-danger-700 dark:text-danger-300">عدد الطلاب غير الموزعين</div>
                <div class="mt-2 text-4xl font-bold text-danger-700 dark:text-danger-300">{{ $unassignedCount }}</div>
            </div>

            <div class="space-y-3">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">المواد المتأثرة</div>
                @foreach ($unassignedBySubject as $subject)
                    <div class="flex items-center justify-between rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $subject['subject_name'] }}</span>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses['danger'] }}">
                            {{ $subject['students_count'] }} طالب
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        @if ($unassignedCount <= 20)
            <div class="border-t border-gray-100 px-5 py-4 dark:border-white/10">
                <div class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">معاينة سريعة</div>
                <div class="grid gap-3 md:hidden">
                    @foreach ($unassignedStudents as $student)
                        <article class="rounded-2xl border border-danger-100 bg-danger-50/50 p-4 dark:border-danger-500/20 dark:bg-danger-500/10">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-base font-bold text-gray-950 dark:text-white">{{ $student['full_name'] }}</div>
                                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $student['student_number'] }}</div>
                                </div>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses['danger'] }}">
                                    غير موزع
                                </span>
                            </div>

                            <div class="mt-3 grid gap-2 text-sm">
                                <div class="rounded-xl bg-white/80 px-3 py-2 dark:bg-white/5">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">المادة</div>
                                    <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $student['subject_name'] }}</div>
                                </div>
                                <div class="rounded-xl bg-white/80 px-3 py-2 dark:bg-white/5">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">السبب</div>
                                    <div class="mt-1 font-semibold text-danger-700 dark:text-danger-300">{{ $student['reason'] }}</div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">الرقم الجامعي</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">اسم الطالب</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">المادة</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">سبب عدم التوزيع</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($unassignedStudents as $student)
                                <tr class="bg-white dark:bg-gray-900">
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $student['student_number'] }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $student['full_name'] }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $student['subject_name'] }}</td>
                                    <td class="px-4 py-3 text-danger-700 dark:text-danger-300">{{ $student['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>
@endif
