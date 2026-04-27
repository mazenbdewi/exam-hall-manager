<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.actions.export_invigilator_pdf_by_invigilator') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        .card { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .title { font-size: 18px; font-weight: bold; }
        .section-title { font-size: 14px; font-weight: bold; margin-bottom: 8px; }
        .muted { color: #4b5563; }
        .logo { width: 56px; height: 56px; object-fit: contain; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        .header-table td { border: 0; }
        .ltr { direction: ltr; unicode-bidi: embed; }
    </style>
</head>
<body>
    <div class="card">
        <table class="header-table">
            <tr>
                <td style="width: 70px;">@if ($logoDataUri)<img src="{{ $logoDataUri }}" class="logo" alt="">@endif</td>
                <td>
                    <div class="title">{{ $systemSetting->university_name }}</div>
                    <div class="muted">{{ __('exam.fields.report_type') }}: {{ __('exam.pages.invigilator_distribution') }} - {{ __('exam.tabs.by_invigilator') }}</div>
                    <div class="muted">{{ __('exam.fields.college') }}: {{ $summary['college']->name }}</div>
                    <div class="muted">{{ __('exam.fields.period') }}: <span class="ltr">{{ $summary['from_date'] ?: '—' }}</span> - <span class="ltr">{{ $summary['to_date'] ?: '—' }}</span></div>
                </td>
            </tr>
        </table>
    </div>

    @forelse ($summary['by_invigilator'] as $invigilator)
        <div class="page">
            <div class="card">
                <div class="section-title">{{ $invigilator['name'] }}</div>
                <div>{{ __('exam.fields.staff_category') }}: {{ $invigilator['staff_category'] ?: '—' }}</div>
                <div>{{ __('exam.fields.invigilation_role') }}: {{ $invigilator['invigilation_role'] ?: '—' }}</div>
                <div>{{ __('exam.fields.phone') }}: <span class="ltr">{{ $invigilator['phone'] ?: '—' }}</span></div>
                <div>{{ __('exam.fields.workload_reduction_percentage') }}: {{ $invigilator['workload_reduction_percentage'] ?? 0 }}%</div>
                <div>{{ __('exam.fields.assignments_count') }}: {{ $invigilator['assignments_count'] }}</div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('exam.fields.exam_date') }}</th>
                        <th>{{ __('exam.fields.exam_start_time') }}</th>
                        <th>{{ __('exam.fields.hall_name') }}</th>
                        <th>{{ __('exam.fields.hall_location') }}</th>
                        <th>{{ __('exam.fields.role_in_hall') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invigilator['assignments'] as $assignment)
                        <tr>
                            <td class="ltr">{{ $assignment['exam_date'] }}</td>
                            <td class="ltr">{{ substr((string) $assignment['start_time'], 0, 5) }}</td>
                            <td>{{ $assignment['hall_name'] }}</td>
                            <td>{{ $assignment['hall_location'] }}</td>
                            <td>{{ $assignment['role_label'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <div class="card muted">{{ __('exam.helpers.no_invigilator_assignments') }}</div>
    @endforelse

    @if (! empty($summary['shortages']))
        <div class="card">
            <div class="section-title">{{ __('exam.reports.shortage_summary_by_role') }}</div>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('exam.fields.invigilation_role') }}</th>
                        <th>{{ __('exam.fields.required_count') }}</th>
                        <th>{{ __('exam.fields.assigned_count') }}</th>
                        <th>{{ __('exam.fields.shortage_count') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (($summary['shortage_by_role'] ?? []) as $roleShortage)
                        <tr>
                            <td>{{ $roleShortage['role_label'] }}</td>
                            <td>{{ $roleShortage['required_count'] ?? 0 }}</td>
                            <td>{{ $roleShortage['assigned_count'] ?? 0 }}</td>
                            <td>{{ $roleShortage['shortage_count'] ?? 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</body>
</html>
