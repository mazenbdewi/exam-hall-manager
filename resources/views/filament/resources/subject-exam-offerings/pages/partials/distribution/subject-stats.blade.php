@php
    $subjects = $summary['subject_summaries'] ?? [];
@endphp

<section class="rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/10">
        <div>
            <h2 class="text-lg font-bold text-gray-950 dark:text-white">المواد</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">بطاقات مختصرة توضح حالة التوزيع لكل مادة بدون عرض أسماء الطلاب.</p>
        </div>
        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses['info'] }}">
            {{ count($subjects) }} مادة
        </span>
    </div>

    @if (empty($subjects))
        <div class="p-5">
            <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-8 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                لا يوجد طلاب ضمن هذه الجلسة.
            </div>
        </div>
    @else
        <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($subjects as $subject)
                @php
                    $total = (int) ($subject['students_count'] ?? 0);
                    $assigned = (int) ($subject['assigned_students_count'] ?? 0);
                    $unassigned = (int) ($subject['unassigned_students_count'] ?? 0);
                    $progress = (int) ($subject['distribution_percentage'] ?? 0);
                    $tone = $total === 0 ? 'gray' : ($unassigned > 0 ? 'danger' : ($assigned > 0 ? 'success' : 'gray'));
                    $statusLabel = $total === 0
                        ? 'لم يتم التوزيع'
                        : ($unassigned > 0 ? 'يوجد نقص' : 'مكتمل');
                @endphp

                <article class="rounded-2xl border border-gray-200 bg-gray-50/60 p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-base font-bold text-gray-950 dark:text-white">{{ $subject['subject_name'] }}</h3>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClasses[$tone] ?? $badgeClasses['gray'] }}">
                            {{ $statusLabel }}
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">إجمالي الطلاب</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $total }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">الموزعون</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $assigned }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">غير الموزعين</div>
                            <div class="mt-1 text-lg font-bold {{ $unassigned > 0 ? 'text-danger-700 dark:text-danger-300' : 'text-gray-950 dark:text-white' }}">{{ $unassigned }}</div>
                        </div>
                        <div class="rounded-xl bg-white px-3 py-2 dark:bg-gray-900">
                            <div class="text-xs text-gray-500 dark:text-gray-400">نسبة التوزيع</div>
                            <div class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $progress }}%</div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="mb-2 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                            <span>التقدم</span>
                            <span>{{ $assigned }} / {{ max(1, $total) }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-2 rounded-full {{ $progressClasses[$tone] ?? $progressClasses['gray'] }}" style="width: {{ min(100, max(0, $progress)) }}%;"></div>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
