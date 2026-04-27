<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.sections.invigilator_shortage') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        .card { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
        .title { font-size: 18px; font-weight: bold; }
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
                    <div class="title">{{ __('exam.reports.invigilator_shortage_report_title') }}</div>
                    <div class="muted">{{ $systemSetting->university_name }}</div>
                    <div class="muted">{{ __('exam.fields.college') }}: {{ $summary['college']->name }}</div>
                    <div class="muted">{{ __('exam.fields.period') }}: <span class="ltr">{{ $summary['from_date'] ?: '—' }}</span> - <span class="ltr">{{ $summary['to_date'] ?: '—' }}</span></div>
                </td>
            </tr>
        </table>
    </div>
    <div class="card">
        <div class="title" style="font-size: 14px;">{{ __('exam.reports.shortage_summary_by_role') }}</div>
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
    <table>
        <thead>
            <tr>
                <th>{{ __('exam.fields.exam_date') }}</th>
                <th>{{ __('exam.fields.exam_start_time') }}</th>
                <th>{{ __('exam.fields.hall_name') }}</th>
                <th>{{ __('exam.fields.hall_type') }}</th>
                <th>{{ __('exam.fields.invigilation_role') }}</th>
                <th>{{ __('exam.fields.required_count') }}</th>
                <th>{{ __('exam.fields.assigned_count') }}</th>
                <th>{{ __('exam.fields.shortage_count') }}</th>
                <th>{{ __('exam.fields.reason') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($summary['shortages'] as $shortage)
                <tr>
                    <td class="ltr">{{ $shortage['exam_date'] }}</td>
                    <td class="ltr">{{ $shortage['start_time'] }}</td>
                    <td>{{ $shortage['hall_name'] }}</td>
                    <td>{{ $shortage['hall_type_label'] ?? '-' }}</td>
                    <td>{{ $shortage['invigilation_role'] }}</td>
                    <td>{{ $shortage['required_count'] }}</td>
                    <td>{{ $shortage['assigned_count'] }}</td>
                    <td>{{ $shortage['shortage_count'] }}</td>
                    <td>{{ $shortage['reason'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9">{{ __('exam.diagnosis.invigilators_all_distributed') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
