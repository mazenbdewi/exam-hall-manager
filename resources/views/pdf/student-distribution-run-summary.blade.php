<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: notosansarabic, sans-serif; direction: rtl; text-align: right; color: #111827; }
        @include('pdf.partials.report-styles')
        .header { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 20px; font-weight: bold; }
        .meta { margin-top: 6px; color: #4b5563; font-size: 12px; }
        .grid { width: 100%; border-collapse: collapse; margin: 12px 0; }
        .grid td, .grid th { border: 1px solid #d1d5db; padding: 7px; font-size: 11px; }
        .grid th { background: #f3f4f6; font-weight: bold; }
        .warning { border: 1px solid #f59e0b; background: #fffbeb; padding: 10px; margin: 12px 0; }
        .danger { border: 1px solid #ef4444; background: #fef2f2; padding: 10px; margin: 12px 0; }
        .success { border: 1px solid #22c55e; background: #f0fdf4; padding: 10px; margin: 12px 0; }
    </style>
</head>
<body>
    @php
        $runPeriod = __('exam.fields.period').': '.($run->from_date?->format('Y-m-d') ?? '—').' - '.($run->to_date?->format('Y-m-d') ?? '—');
        $problemSlots = collect($summary['unassigned_by_slot'] ?? [])
            ->filter(fn (array $slot): bool => (int) ($slot['unassigned_count'] ?? 0) > 0 || (int) ($slot['capacity_shortage'] ?? $slot['shortage_count'] ?? 0) > 0)
            ->values();
        $problemSubjects = collect($summary['unassigned_by_subject'] ?? [])
            ->filter(fn (array $subject): bool => (int) ($subject['unassigned_count'] ?? 0) > 0)
            ->values();
    @endphp

    @include('pdf.partials.report-header', [
        'universityName' => $systemSetting->university_name,
        'universityLogo' => $logoDataUri,
        'facultyName' => $run->college?->name ?? '—',
        'reportTitle' => 'تقرير توزيع الطلاب على القاعات',
        'reportSubtitle' => __('exam.fields.status').': '.$run->statusLabel(),
        'dateRange' => $runPeriod,
    ])

    <div class="{{ $run->status === 'success' ? 'success' : ($run->status === 'partial' ? 'warning' : 'danger') }}">
        {{ $run->status === 'success' ? __('exam.global_hall_distribution.success_message') : ($run->status === 'partial' ? __('exam.global_hall_distribution.partial_message') : __('exam.global_hall_distribution.failed_message')) }}
    </div>

    <table class="grid">
        <tbody>
            <tr>
                <th>{{ __('exam.global_hall_distribution.summary.offerings_count') }}</th>
                <td>{{ $run->total_offerings }}</td>
                <th>{{ __('exam.global_hall_distribution.summary.slots_count') }}</th>
                <td>{{ $run->total_slots }}</td>
            </tr>
            <tr>
                <th>{{ __('exam.global_hall_distribution.summary.students_count') }}</th>
                <td>{{ $run->total_students }}</td>
                <th>{{ __('exam.global_hall_distribution.summary.assigned_students_count') }}</th>
                <td>{{ $run->distributed_students }}</td>
            </tr>
            <tr>
                <th>{{ __('exam.global_hall_distribution.summary.unassigned_students_count') }}</th>
                <td>{{ $run->unassigned_students }}</td>
                <th>{{ __('exam.global_hall_distribution.summary.used_halls_count') }}</th>
                <td>{{ $run->used_halls }}</td>
            </tr>
            <tr>
                <th>{{ __('exam.global_hall_distribution.summary.total_capacity') }}</th>
                <td>{{ $run->total_capacity }}</td>
                <th>{{ __('exam.global_hall_distribution.summary.capacity_shortage') }}</th>
                <td>{{ $run->capacity_shortage }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('exam.global_hall_distribution.by_slot') }}</h3>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('exam.fields.exam_date') }}</th>
                <th>{{ __('exam.fields.exam_start_time') }}</th>
                <th>{{ __('exam.fields.unassigned_students') }}</th>
                <th>{{ __('exam.fields.reason') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($problemSlots as $slot)
                <tr>
                    <td>{{ $slot['exam_date'] ?? '—' }}</td>
                    <td>{{ substr((string) ($slot['start_time'] ?? ''), 0, 5) }}</td>
                    <td>{{ $slot['unassigned_count'] ?? 0 }}</td>
                    <td>{{ $slot['reason'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">{{ __('exam.global_hall_distribution.no_grouped_issues') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>{{ __('exam.global_hall_distribution.by_subject') }}</h3>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('exam.fields.subject') }}</th>
                <th>{{ __('exam.fields.exam_date') }}</th>
                <th>{{ __('exam.fields.exam_start_time') }}</th>
                <th>{{ __('exam.fields.unassigned_students') }}</th>
                <th>{{ __('exam.fields.reason') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($problemSubjects as $subject)
                <tr>
                    <td>{{ $subject['subject_name'] ?? '—' }}</td>
                    <td>{{ $subject['exam_date'] ?? '—' }}</td>
                    <td>{{ substr((string) ($subject['start_time'] ?? ''), 0, 5) }}</td>
                    <td>{{ $subject['unassigned_count'] ?? 0 }}</td>
                    <td>{{ $subject['reason'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('exam.global_hall_distribution.no_grouped_issues') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
