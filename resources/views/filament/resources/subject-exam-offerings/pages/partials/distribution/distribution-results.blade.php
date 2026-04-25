@php
    $distributionCards = $summary['distribution_results_summaries'] ?? [];
@endphp

<section class="rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/10">
        <div>
            <h2 class="text-lg font-bold text-gray-950 dark:text-white">نتائج التوزيع حسب القاعة</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">يعرض القاعات المستخدمة ونسب إشغالها والمواد الموجودة داخل كل قاعة بالأعداد فقط.</p>
        </div>
        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses['success'] }}">
            {{ count($distributionCards) }} قاعة مستخدمة
        </span>
    </div>

    @if (empty($distributionCards))
        <div class="p-5">
            <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-8 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                لم يتم تنفيذ التوزيع بعد.
            </div>
        </div>
    @else
        <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($distributionCards as $hall)
                @php
                    $tone = ($hall['remaining_capacity'] ?? 0) === 0 ? 'danger' : 'success';
                    $usagePercentage = (int) ($hall['usage_percentage'] ?? 0);
                @endphp

                <article class="rounded-2xl border border-gray-200 bg-gray-50/60 p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-bold text-gray-950 dark:text-white">{{ $hall['hall_name'] }}</h3>
                            @if (! empty($hall['hall_location']))
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hall['hall_location'] }}</p>
                            @endif
                        </div>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses[$tone] ?? $badgeClasses['gray'] }}">
                            {{ $hall['status_label'] }}
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">السعة</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $hall['total_capacity'] }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">عدد الطلاب</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $hall['assigned_students_count'] }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">المقاعد المتبقية</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $hall['remaining_capacity'] }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">نسبة الإشغال</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $usagePercentage }}%</div>
                        </div>
                    </div>

                    <div class="mt-4 h-2 rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-2 rounded-full {{ $progressClasses[$tone] ?? $progressClasses['gray'] }}" style="width: {{ min(100, max(0, $usagePercentage)) }}%;"></div>
                    </div>

                    <div class="mt-4 space-y-2">
                        <div class="text-xs font-semibold text-gray-500 dark:text-gray-400">المواد الموجودة داخل القاعة</div>
                        @foreach ($hall['subjects'] as $subject)
                            <div class="flex items-center justify-between rounded-xl bg-white px-3 py-2 text-sm dark:bg-gray-900">
                                <span class="text-gray-700 dark:text-gray-300">{{ $subject['subject_name'] }}</span>
                                <span class="font-bold text-gray-950 dark:text-white">{{ $subject['assigned_students_count'] }} طالب</span>
                            </div>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
