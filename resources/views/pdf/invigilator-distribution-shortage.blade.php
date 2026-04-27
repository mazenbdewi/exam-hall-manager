<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.sections.invigilator_shortage') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        @include('pdf.partials.report-styles')
        .card { border: 1px solid #dbe3ea; padding: 10px; margin-bottom: 12px; background: #ffffff; }
        .title { font-size: 18px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dbe3ea; padding: 6px 7px; vertical-align: top; }
        th { background: #eef2f7; font-weight: bold; color: #0f172a; }
    </style>
</head>
<body>
    @include('pdf.partials.report-header', [
        'universityName' => $systemSetting->university_name,
        'universityLogo' => $logoDataUri,
        'facultyName' => $summary['college']->name,
        'reportTitle' => 'تقرير النقص في المراقبين',
        'reportSubtitle' => __('exam.reports.shortage_summary_by_role'),
        'dateRange' => $reportDateRange ?? __('exam.fields.period').': —',
    ])
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
