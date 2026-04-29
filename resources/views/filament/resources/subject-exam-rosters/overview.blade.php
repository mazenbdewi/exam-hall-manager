@php
    $summary = $this->rosterSummary();
    $statusBadges = [
        ['label' => 'مسودة', 'count' => $summary['draft_count'], 'classes' => 'border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-100'],
        ['label' => 'جاهزة', 'count' => $summary['ready_count'], 'classes' => 'border-success-200 bg-success-50 text-success-800 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-100'],
        ['label' => 'مستخدمة', 'count' => $summary['used_count'], 'classes' => 'border-info-200 bg-info-50 text-info-800 dark:border-info-500/20 dark:bg-info-500/10 dark:text-info-100'],
        ['label' => 'مؤرشفة', 'count' => $summary['archived_count'], 'classes' => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200'],
    ];
@endphp

<div dir="rtl" class="space-y-4 text-right">
    <div class="rounded-lg border border-info-200 bg-info-50 p-4 text-info-900 shadow-sm dark:border-info-500/20 dark:bg-info-500/10 dark:text-info-100">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-sm font-semibold">الخطوة الأولى قبل توليد البرنامج الامتحاني</div>
                <p class="mt-1 text-sm">هذه القوائم هي مصدر الطلاب قبل توليد البرنامج الامتحاني. يجب رفع الطلاب المستجدين والحملة وتحديد القوائم كجاهزة قبل توليد المسودة.</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs font-medium">
                @foreach ($statusBadges as $badge)
                    <span class="rounded-full border px-3 py-1 {{ $badge['classes'] }}">{{ $badge['label'] }}: {{ $badge['count'] }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">عدد الطلاب</div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['students_count'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">المستجدون</div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['regular_count'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">الحملة</div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['carry_count'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">الحالة</div>
            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">جاهزة: {{ $summary['ready_count'] }} / الكل: {{ $summary['rosters_count'] }}</div>
        </div>
    </div>
</div>
