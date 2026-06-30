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
        $validation = $summary['validation'] ?? [];
        $problemSlots = collect($summary['unassigned_by_slot'] ?? [])
            ->filter(fn (array $slot): bool => (int) ($slot['unassigned_count'] ?? 0) > 0
                || (int) ($slot['capacity_shortage'] ?? $slot['shortage_count'] ?? 0) > 0
                || (int) ($slot['mixed_halls_count'] ?? 0) > 0)
            ->values();
        $problemSubjects = collect($summary['unassigned_by_subject'] ?? [])
            ->filter(fn (array $subject): bool => (int) ($subject['unassigned_count'] ?? 0) > 0)
            ->values();
        $failureDetails = collect($summary['failure_details'] ?? []);
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
        {{ $run->status === 'success' ? __('exam.global_hall_distribution.success_message') : ($run->status === 'partial' ? __('exam.global_hall_distribution.partial_message') : __('exam.global_hall_distribution.failed_message_detailed')) }}
    </div>

    @if ($failureDetails->isNotEmpty())
        <h3>{{ __('exam.global_hall_distribution.failure_details_title') }}</h3>
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('exam.fields.subject') }}</th>
                    <th>{{ __('exam.fields.department') }}</th>
                    <th>{{ __('exam.fields.students_count') }}</th>
                    <th>{{ __('exam.fields.period') }}</th>
                    <th>{{ __('exam.global_hall_distribution.required_hall_type') }}</th>
                    <th>{{ __('exam.fields.nominal_capacity') }}</th>
                    <th>{{ __('exam.fields.reserved_or_used_capacity') }}</th>
                    <th>{{ __('exam.fields.usable_remaining_capacity') }}</th>
                    <th>{{ __('exam.global_hall_distribution.required_capacity') }}</th>
                    <th>{{ __('exam.fields.actual_shortage') }}</th>
                    <th>{{ __('exam.fields.surplus_capacity') }}</th>
                    <th>{{ __('exam.global_hall_distribution.reason_code') }}</th>
                    <th>{{ __('exam.fields.reason') }}</th>
                    <th>{{ __('exam.global_hall_distribution.suggested_action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($failureDetails as $detail)
                    <tr>
                        <td>{{ $detail['subject_name'] ?? '—' }}</td>
                        <td>{{ $detail['department_name'] ?? '—' }}</td>
                        <td>{{ $detail['students_count'] ?? 0 }}</td>
                        <td>{{ ($detail['exam_date'] ?? '—').' '.substr((string) ($detail['start_time'] ?? ''), 0, 5) }}</td>
                        <td>{{ $detail['required_hall_type'] ?? '—' }}</td>
                        <td>{{ $detail['nominal_capacity'] ?? $detail['available_capacity'] ?? 0 }}</td>
                        <td>{{ $detail['reserved_or_used_capacity'] ?? $detail['used_capacity_in_candidate_halls'] ?? 0 }}</td>
                        <td>{{ $detail['usable_remaining_capacity'] ?? $detail['available_capacity'] ?? 0 }}</td>
                        <td>{{ $detail['required_capacity'] ?? 0 }}</td>
                        <td>{{ $detail['actual_shortage'] ?? $detail['capacity_shortage'] ?? 0 }}</td>
                        <td>{{ $detail['surplus_capacity'] ?? 0 }}</td>
                        <td>{{ $detail['reason_code'] ?? 'unknown_distribution_error' }}</td>
                        <td>{{ $detail['reason_message'] ?? '—' }}</td>
                        <td>{{ $detail['suggested_action'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

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
            <tr>
                <th>{{ __('exam.global_hall_distribution.summary.remaining_capacity') }}</th>
                <td>{{ $validation['remaining_capacity'] ?? 0 }}</td>
                <th>{{ __('exam.global_hall_distribution.validation.data_source') }}</th>
                <td>{{ $validation['data_source'] ?? '—' }}</td>
            </tr>
            <tr>
                <th>{{ __('exam.global_hall_distribution.summary.separate_carry_students') }}</th>
                <td>{{ (bool) ($summary['separate_carry_students'] ?? false) ? 'نعم' : 'لا' }}</td>
                <th>{{ __('exam.global_hall_distribution.summary.mixing_cases_count') }}</th>
                <td>{{ $summary['carry_regular_mixing_cases_count'] ?? 0 }}</td>
            </tr>
            <tr>
                <th>{{ __('exam.global_hall_distribution.summary.carry_students_count') }}</th>
                <td>{{ $summary['carry_students_count'] ?? 0 }}</td>
                <th>{{ __('exam.global_hall_distribution.summary.regular_students_count') }}</th>
                <td>{{ $summary['regular_students_count'] ?? 0 }}</td>
            </tr>
            <tr>
                <th>{{ __('exam.global_hall_distribution.summary.carry_halls_count') }}</th>
                <td>{{ $summary['carry_halls_count'] ?? 0 }}</td>
                <th>{{ __('exam.global_hall_distribution.summary.regular_halls_count') }}</th>
                <td>{{ $summary['regular_halls_count'] ?? 0 }}</td>
            </tr>
        </tbody>
    </table>

    <h3>{{ __('exam.global_hall_distribution.validation_summary_title') }}</h3>
    <table class="grid">
        <tbody>
            <tr>
                <th>{{ __('exam.global_hall_distribution.validation.expected_students') }}</th>
                <td>{{ $validation['expected_students'] ?? $run->total_students }}</td>
                <th>{{ __('exam.global_hall_distribution.validation.assigned_students') }}</th>
                <td>{{ $validation['assigned_students'] ?? $run->distributed_students }}</td>
            </tr>
            <tr>
                <th>{{ __('exam.global_hall_distribution.validation.unassigned_students') }}</th>
                <td>{{ $validation['unassigned_students'] ?? $run->unassigned_students }}</td>
                <th>{{ __('exam.global_hall_distribution.validation.used_hall_capacity') }}</th>
                <td>{{ $validation['used_hall_capacity'] ?? 0 }}</td>
            </tr>
            <tr>
                <th>{{ __('exam.global_hall_distribution.validation.remaining_capacity') }}</th>
                <td>{{ $validation['remaining_capacity'] ?? 0 }}</td>
                <th>{{ __('exam.global_hall_distribution.summary.used_halls_count') }}</th>
                <td>{{ $validation['used_halls_count'] ?? $run->used_halls }}</td>
            </tr>
        </tbody>
    </table>

    @if (! empty($summary['separation_status_message']))
        <div class="{{ (int) ($summary['carry_regular_mixing_cases_count'] ?? 0) > 0 ? 'warning' : 'success' }}">
            {{ $summary['separation_status_message'] }}
        </div>
    @endif

    <h3>{{ __('exam.global_hall_distribution.by_slot') }}</h3>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('exam.fields.exam_date') }}</th>
                <th>{{ __('exam.fields.exam_start_time') }}</th>
                <th>{{ __('exam.fields.unassigned_students') }}</th>
                <th>{{ __('exam.global_hall_distribution.summary.mixing_cases_count') }}</th>
                <th>{{ __('exam.fields.reason') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($problemSlots as $slot)
                <tr>
                    <td>{{ $slot['exam_date'] ?? '—' }}</td>
                    <td>{{ substr((string) ($slot['start_time'] ?? ''), 0, 5) }}</td>
                    <td>{{ $slot['unassigned_count'] ?? 0 }}</td>
                    <td>{{ $slot['mixed_halls_count'] ?? 0 }}</td>
                    <td>{{ $slot['reason'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('exam.global_hall_distribution.no_grouped_issues') }}</td></tr>
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
