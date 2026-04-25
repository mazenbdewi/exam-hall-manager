@php
    $diagnosis = $summary['diagnosis'] ?? [];
    $tone = $diagnosis['tone'] ?? ($distributionStatus['tone'] ?? 'gray');
    $recommendedActions = $summary['recommended_actions'] ?? [];
@endphp

<section class="rounded-3xl border p-5 shadow-sm {{ $surfaceClasses[$tone] ?? $surfaceClasses['gray'] }}">
    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="rounded-2xl p-3 {{ $iconClasses[$tone] ?? $iconClasses['gray'] }}">
                    <x-filament::icon icon="heroicon-o-shield-exclamation" class="h-6 w-6" />
                </div>
                <div>
                    <div class="text-sm font-semibold text-gray-600 dark:text-gray-300">تشخيص التوزيع</div>
                    <h2 class="mt-1 text-xl font-bold text-gray-950 dark:text-white">
                        {{ $diagnosis['headline'] ?? 'لم يتم تنفيذ التوزيع بعد.' }}
                    </h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                        {{ $diagnosis['summary'] ?? 'راجع إعدادات القاعات والطلاب ثم نفّذ التوزيع.' }}
                    </p>
                </div>
            </div>

            @if (! empty($diagnosis['items']))
                <div class="flex flex-wrap gap-2">
                    @foreach ($diagnosis['items'] as $item)
                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold {{ $badgeClasses[$item['tone']] ?? $badgeClasses['gray'] }}">
                            <x-filament::icon :icon="$item['icon']" class="h-4 w-4" />
                            {{ $item['text'] }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-white/60 bg-white/80 p-4 shadow-sm dark:border-white/10 dark:bg-white/5 lg:w-[24rem]">
            <div class="text-sm font-bold text-gray-950 dark:text-white">الإجراء المقترح</div>
            @if (! empty($recommendedActions))
                <ul class="mt-3 space-y-2 text-sm leading-6 text-gray-700 dark:text-gray-300">
                    @foreach ($recommendedActions as $action)
                        <li class="flex items-start gap-2">
                            <x-filament::icon icon="heroicon-o-arrow-left-circle" class="mt-1 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-300" />
                            <span>{{ $action }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">لا يوجد إجراء عاجل حالياً.</div>
            @endif
        </div>
    </div>
</section>
