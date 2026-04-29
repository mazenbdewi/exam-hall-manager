@php
    $summary = $this->subjectRosterSummary();
    $lastUpdated = $summary['last_updated_at'] ? \Carbon\Carbon::parse($summary['last_updated_at'])->format('Y-m-d H:i') : '—';
@endphp

<div dir="rtl" class="space-y-4 text-right">
    <div class="rounded-lg border border-info-200 bg-info-50 p-4 text-info-900 shadow-sm dark:border-info-500/20 dark:bg-info-500/10 dark:text-info-100">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">قوائم طلاب هذه المادة</h2>
                <p class="mt-1 text-sm">
                    قوائم الطلاب تُدار من صفحة "قوائم طلاب المواد" لأنها ترتبط بالمادة والكلية والقسم والعام الدراسي والفصل. استخدم الزر التالي لإدارة قوائم هذه المادة.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ $this->manageSubjectRostersUrl() }}" class="rounded-md bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-500">
                    إدارة قوائم طلاب هذه المادة
                </a>
                <a href="{{ $this->createSubjectRosterUrl() }}" class="rounded-md border border-primary-300 bg-white px-3 py-2 text-sm font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-500/30 dark:bg-white/10 dark:text-primary-100">
                    إنشاء قائمة جديدة لهذه المادة
                </a>
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @foreach ([
            'عدد القوائم' => $summary['rosters_count'],
            'القوائم الجاهزة' => $summary['ready_count'],
            'إجمالي الطلاب' => $summary['students_count'],
            'المستجدون' => $summary['regular_count'],
            'الحملة' => $summary['carry_count'],
            'آخر تحديث' => $lastUpdated,
        ] as $label => $value)
            <div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
            </div>
        @endforeach
    </div>
</div>
