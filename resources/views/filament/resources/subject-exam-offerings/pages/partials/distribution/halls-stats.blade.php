@php
    $halls = $summary['hall_summaries'] ?? [];
@endphp

<section class="rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/10">
        <div>
            <h2 class="text-lg font-bold text-gray-950 dark:text-white">القاعات</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">بطاقات سريعة توضح السعة والاستخدام الحالي لكل قاعة فعالة.</p>
        </div>
        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses['info'] }}">
            {{ count($halls) }} قاعة
        </span>
    </div>

    @if (empty($halls))
        <div class="p-5">
            <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 dark:border-white/10 dark:bg-white/5">
                <div class="text-base font-bold text-gray-950 dark:text-white">لا توجد قاعات فعالة متاحة لهذه الكلية.</div>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">أضف قاعة امتحانية أولاً ثم نفّذ التوزيع.</p>
                @if ($createHallUrl)
                    <div class="mt-4">
                        <x-filament::button tag="a" :href="$createHallUrl" color="primary" icon="heroicon-o-plus">
                            {{ __('exam.actions.add_exam_hall') }}
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($halls as $hall)
                @php
                    $usagePercentage = (int) ($hall['usage_percentage'] ?? 0);
                    $tone = $hall['is_full'] ? 'danger' : ($hall['is_used'] ? 'warning' : 'gray');
                    $statusLabel = $hall['is_full']
                        ? 'ممتلئة'
                        : ($hall['is_used'] ? 'مستخدمة' : 'غير مستخدمة');
                @endphp

                <article class="rounded-2xl border border-gray-200 bg-gray-50/60 p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-bold text-gray-950 dark:text-white">{{ $hall['name'] }}</h3>
                            @if (! empty($hall['location']))
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hall['location'] }}</p>
                            @endif
                        </div>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses[$tone] ?? $badgeClasses['gray'] }}">
                            {{ $statusLabel }}
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">السعة</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $hall['capacity'] }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">المستخدم</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $hall['used_seats'] }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">المتبقي</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $hall['remaining_seats'] }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">نسبة الإشغال</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $usagePercentage }}%</div>
                        </div>
                    </div>

                    <div class="mt-4 h-2 rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-2 rounded-full {{ $progressClasses[$tone] ?? $progressClasses['gray'] }}" style="width: {{ min(100, max(0, $usagePercentage)) }}%;"></div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
