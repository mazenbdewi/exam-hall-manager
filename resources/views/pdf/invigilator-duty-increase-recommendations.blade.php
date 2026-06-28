<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.reports.observer_duty_increase_recommendation_report') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        @include('pdf.partials.report-styles')
        .card { border: 1px solid #dbe3ea; padding: 10px; margin-bottom: 12px; background: #ffffff; }
        .title { font-size: 14px; font-weight: bold; margin-bottom: 8px; }
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
        'reportTitle' => __('exam.reports.observer_duty_increase_recommendation_report'),
        'reportSubtitle' => __('exam.reports.shortage_summary_by_role'),
        'dateRange' => $reportDateRange ?? __('exam.fields.period').': —',
    ])

    @php($report = $summary['duty_increase_recommendations'] ?? [])

    <div class="card">
        <div class="title">{{ __('exam.reports.observer_duty_increase_recommendation_report') }}</div>
        <table>
            <tbody>
                <tr>
                    <th>{{ __('exam.fields.missing_assignments_count') }}</th>
                    <td>{{ $report['total_uncovered_duties'] ?? 0 }}</td>
                    <th>{{ __('exam.reports.duties_coverable_by_current_observer_limit_increase') }}</th>
                    <td>{{ $report['coverable_by_limit_increase'] ?? 0 }}</td>
                </tr>
                <tr>
                    <th>{{ __('exam.reports.duties_requiring_new_observers') }}</th>
                    <td>{{ $report['requires_new_observers'] ?? 0 }}</td>
                    <th>{{ __('exam.reports.recommended_observers_to_increase') }}</th>
                    <td>{{ $report['recommended_observers_count'] ?? 0 }}</td>
                </tr>
                <tr>
                    <th>{{ __('exam.reports.max_suggested_increase_per_observer') }}</th>
                    <td colspan="3">{{ $report['max_suggested_increase_per_observer'] ?? 0 }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="title">{{ __('exam.fields.suggested_additional_duties') }}</div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('exam.fields.invigilator_name') }}</th>
                    <th>{{ __('exam.fields.invigilation_role') }}</th>
                    <th>{{ __('exam.fields.current_duties') }}</th>
                    <th>{{ __('exam.fields.current_duty_limit') }}</th>
                    <th>{{ __('exam.fields.suggested_duty_limit') }}</th>
                    <th>{{ __('exam.fields.suggested_additional_duties') }}</th>
                    <th>{{ __('exam.fields.affected_period') }}</th>
                    <th>{{ __('exam.fields.reason') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($report['recommendations'] ?? []) as $recommendation)
                    <tr>
                        <td>{{ $recommendation['name'] }}</td>
                        <td>{{ $recommendation['observer_type'] }}</td>
                        <td>{{ $recommendation['current_assigned_duties'] }}</td>
                        <td>{{ $recommendation['current_max_duties'] }}</td>
                        <td>{{ $recommendation['suggested_new_max_duties'] }}</td>
                        <td>{{ $recommendation['suggested_additional_duties'] }}</td>
                        <td>{{ implode('، ', $recommendation['related_slots'] ?? []) }}</td>
                        <td>{{ $recommendation['reason'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">{{ __('exam.reports.duty_increase_blocked_by_conflicts_or_daily_limits') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (! empty($report['unresolved'] ?? []))
        <div class="card">
            <div class="title">{{ __('exam.reports.duties_requiring_new_observers') }}</div>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('exam.fields.exam_date') }}</th>
                        <th>{{ __('exam.fields.exam_start_time') }}</th>
                        <th>{{ __('exam.fields.invigilation_role') }}</th>
                        <th>{{ __('exam.fields.missing_assignments_count') }}</th>
                        <th>{{ __('exam.fields.reason') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['unresolved'] as $unresolved)
                        <tr>
                            <td class="ltr">{{ $unresolved['exam_date'] }}</td>
                            <td class="ltr">{{ $unresolved['start_time'] }}</td>
                            <td>{{ $unresolved['role_label'] }}</td>
                            <td>{{ $unresolved['shortage_count'] }}</td>
                            <td>{{ $unresolved['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</body>
</html>
