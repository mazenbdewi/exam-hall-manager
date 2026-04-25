@php
    $examDate = filled($summary['exam_date'] ?? null)
        ? \Illuminate\Support\Carbon::parse($summary['exam_date'])->format('Y-m-d')
        : '-';

    $examStartTime = substr((string) ($summary['exam_start_time'] ?? ''), 0, 5) ?: '-';
    $offerings = collect($summary['offerings_summary'] ?? []);

    $cards = [
        ['label' => 'التاريخ', 'value' => $examDate, 'tone' => 'gray'],
        ['label' => 'وقت الامتحان', 'value' => $examStartTime, 'tone' => 'gray'],
        ['label' => 'عدد المواد في نفس الموعد', 'value' => $summary['context']['offerings_count'] ?? $offerings->count(), 'tone' => 'info'],
        ['label' => 'إجمالي الطلاب في الموعد', 'value' => $summary['total_students_count'] ?? 0, 'tone' => 'info'],
        ['label' => 'عدد القاعات المتاحة', 'value' => $summary['available_halls_count'] ?? 0, 'tone' => (($summary['available_halls_count'] ?? 0) > 0) ? 'success' : 'danger'],
        ['label' => 'السعة الإجمالية للقاعات', 'value' => $summary['total_capacity'] ?? 0, 'tone' => 'info'],
        ['label' => 'العجز المتوقع', 'value' => $summary['capacity_shortage'] ?? 0, 'tone' => (($summary['capacity_shortage'] ?? 0) > 0) ? 'danger' : 'success'],
    ];

    $toneClasses = [
        'danger' => 'border-danger-200 bg-danger-50 text-danger-700 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-300',
        'success' => 'border-success-200 bg-success-50 text-success-700 dark:border-success-800 dark:bg-success-950 dark:text-success-300',
        'warning' => 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300',
        'info' => 'border-info-200 bg-info-50 text-info-700 dark:border-info-800 dark:bg-info-950 dark:text-info-300',
        'gray' => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200',
    ];
@endphp

<div class="space-y-5" dir="rtl">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($cards as $card)
            <div class="{{ $toneClasses[$card['tone']] ?? $toneClasses['gray'] }} rounded-lg border p-3">
                <div class="text-xs font-medium opacity-75">{{ $card['label'] }}</div>
                <div class="mt-1 text-xl font-semibold">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 text-xs font-semibold text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                <tr>
                    <th class="whitespace-nowrap px-4 py-3 text-right">المادة</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">الكلية</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">القسم</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">السنة الدراسية</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">الفصل</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">عدد الطلاب</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">حالة التوزيع</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">الطلاب غير الموزعين</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-950">
                @forelse ($offerings as $offering)
                    @php
                        $statusTone = $offering['status_tone'] ?? 'gray';
                    @endphp
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $offering['subject_name'] ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-200">{{ $offering['college_name'] ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-200">{{ $offering['department_name'] ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-200">{{ $offering['academic_year_name'] ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-200">{{ $offering['semester_name'] ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-200">{{ $offering['students_count'] ?? 0 }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="{{ $toneClasses[$statusTone] ?? $toneClasses['gray'] }} inline-flex rounded-md border px-2 py-1 text-xs font-medium">
                                {{ $offering['status_label'] ?? '-' }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-200">{{ $offering['unassigned_students_count'] ?? 0 }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">لا توجد مواد في هذا الموعد.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
