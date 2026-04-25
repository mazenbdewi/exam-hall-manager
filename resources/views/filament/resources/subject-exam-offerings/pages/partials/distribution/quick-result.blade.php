@php
    $assignedStudents = (int) ($summary['assigned_students_count'] ?? 0);
    $unassignedStudentsCount = (int) ($summary['unassigned_students_count'] ?? 0);
    $capacityShortage = (int) ($summary['capacity_shortage'] ?? 0);
    $usedHallsCount = (int) ($summary['used_halls_count'] ?? 0);
    $distributionPercentage = (int) ($summary['distribution_percentage'] ?? 0);

    $quickTone = ! $hasDistribution
        ? 'gray'
        : ($unassignedStudentsCount > 0 || $capacityShortage > 0 ? 'danger' : 'success');

    $quickIcon = ! $hasDistribution
        ? 'heroicon-o-clock'
        : ($quickTone === 'success' ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle');

    $quickTitle = ! $hasDistribution
        ? 'لم تظهر نتيجة التوزيع بعد'
        : ($quickTone === 'success' ? 'تم توزيع الطلاب بنجاح' : 'توجد مشكلة في التوزيع');

    $quickMessage = ! $hasDistribution
        ? 'نفّذ التوزيع أولاً ليظهر ملخص واضح للطلاب والقاعات.'
        : ($quickTone === 'success'
            ? 'كل الطلاب لديهم قاعات، ويمكن تصدير النتيجة مباشرة.'
            : 'راجع الطلاب غير الموزعين أو أضف مقاعد قبل اعتماد النتيجة.');
@endphp

<section class="rounded-3xl border p-5 shadow-sm {{ $surfaceClasses[$quickTone] ?? $surfaceClasses['gray'] }}">
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-center">
        <div class="flex items-start gap-4">
            <div class="rounded-2xl p-3 {{ $iconClasses[$quickTone] ?? $iconClasses['gray'] }}">
                <x-filament::icon :icon="$quickIcon" class="h-7 w-7" />
            </div>

            <div class="min-w-0">
                <div class="text-sm font-semibold text-gray-600 dark:text-gray-300">النتيجة السريعة</div>
                <h2 class="mt-1 text-2xl font-bold leading-8 text-gray-950 dark:text-white sm:text-3xl">
                    {{ $quickTitle }}
                </h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-700 dark:text-gray-300">
                    {{ $quickMessage }}
                </p>
            </div>
        </div>

        <div class="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="mb-2 flex items-center justify-between text-sm">
                <span class="font-semibold text-gray-700 dark:text-gray-300">نسبة التوزيع</span>
                <span class="text-xl font-bold text-gray-950 dark:text-white">{{ $distributionPercentage }}%</span>
            </div>
            <div class="h-3 rounded-full bg-gray-100 dark:bg-white/10">
                <div class="h-3 rounded-full {{ $progressClasses[$quickTone] ?? $progressClasses['gray'] }}" style="width: {{ min(100, max(0, $distributionPercentage)) }}%;"></div>
            </div>
        </div>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl bg-white/80 px-4 py-3 dark:bg-white/5">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">الطلاب الموزعون</div>
            <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ $assignedStudents }}</div>
        </div>

        <div class="rounded-2xl bg-white/80 px-4 py-3 dark:bg-white/5">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">غير الموزعين</div>
            <div class="mt-1 text-2xl font-bold {{ $unassignedStudentsCount > 0 ? 'text-danger-700 dark:text-danger-300' : 'text-gray-950 dark:text-white' }}">{{ $unassignedStudentsCount }}</div>
        </div>

        <div class="rounded-2xl bg-white/80 px-4 py-3 dark:bg-white/5">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">القاعات المستخدمة</div>
            <div class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">{{ $usedHallsCount }}</div>
        </div>

        <div class="rounded-2xl bg-white/80 px-4 py-3 dark:bg-white/5">
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">العجز في المقاعد</div>
            <div class="mt-1 text-2xl font-bold {{ $capacityShortage > 0 ? 'text-danger-700 dark:text-danger-300' : 'text-gray-950 dark:text-white' }}">{{ $capacityShortage }}</div>
        </div>
    </div>
</section>
