<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.actions.export_invigilator_pdf_by_invigilator') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        @include('pdf.partials.report-styles')
        .card { border: 1px solid #dbe3ea; padding: 10px; margin-bottom: 12px; background: #ffffff; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .title { font-size: 18px; font-weight: bold; }
        .section-title { font-size: 14px; font-weight: bold; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dbe3ea; padding: 6px 7px; vertical-align: top; }
        th { background: #eef2f7; font-weight: bold; color: #0f172a; }
        .meta-line span { display: inline-block; margin-left: 18px; margin-bottom: 4px; }
    </style>
</head>
<body>
    @include('pdf.partials.report-header', [
        'universityName' => $systemSetting->university_name,
        'universityLogo' => $logoDataUri,
        'facultyName' => $summary['college']->name,
        'reportTitle' => 'تقرير توزيع المراقبين حسب المراقب',
        'reportSubtitle' => __('exam.pages.invigilator_distribution'),
        'dateRange' => $reportDateRange ?? __('exam.fields.period').': —',
    ])

    @forelse ($summary['by_invigilator'] as $invigilator)
        <div class="page">
            <div class="card">
                <div class="section-title">{{ $invigilator['name'] }}</div>
                <div class="meta-line muted">
                    <span>{{ __('exam.fields.staff_category') }}: {{ $invigilator['staff_category'] ?: '—' }}</span>
                    <span>{{ __('exam.fields.invigilation_role') }}: {{ $invigilator['invigilation_role'] ?: '—' }}</span>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('exam.fields.exam_date') }}</th>
                        <th>{{ __('exam.fields.exam_start_time') }}</th>
                        <th>{{ __('exam.fields.hall_name') }}</th>
                        <th>{{ __('exam.fields.hall_location') }}</th>
                        <th>{{ __('exam.fields.role_in_hall') }}</th>
                        <th>{{ __('exam.fields.notes') }}</th>
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
                            <td>{{ $assignment['notes'] ?: '—' }}</td>
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
