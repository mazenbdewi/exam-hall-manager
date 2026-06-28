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
        'reportTitle' => __('exam.reports.invigilator_shortage_report_title'),
        'reportSubtitle' => __('exam.reports.shortage_summary_by_role'),
        'dateRange' => $reportDateRange ?? __('exam.fields.period').': —',
    ])
    <div class="card">
        <div class="title" style="font-size: 14px;">{{ __('exam.reports.shortage_summary_by_role') }}</div>
        <p>{{ __('exam.reports.shortage_metrics_hint') }}</p>
        <table>
            <thead>
                <tr>
                    <th>{{ __('exam.fields.invigilation_role') }}</th>
                    <th>{{ __('exam.fields.required_count') }}</th>
                    <th>{{ __('exam.fields.assigned_count') }}</th>
                    <th>{{ __('exam.fields.missing_assignments_count') }}</th>
                    <th>{{ __('exam.fields.recommended_additional_observers_count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach (($summary['shortage_by_role'] ?? []) as $roleShortage)
                    <tr>
                        <td>{{ $roleShortage['role_label'] }}</td>
                        <td>{{ $roleShortage['required_count'] ?? 0 }}</td>
                        <td>{{ $roleShortage['assigned_count'] ?? 0 }}</td>
                        <td>{{ $roleShortage['shortage_count'] ?? 0 }}</td>
                        <td>{{ $roleShortage['recommended_additional_observers_count'] ?? 0 }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="title" style="font-size: 14px;">{{ __('exam.sections.problem_diagnosis') }}</div>
        <table>
            <tbody>
                @foreach (($summary['diagnosis'] ?? []) as $item)
                    <tr><td>{{ $item['message'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @php($dutyIncreaseReport = $summary['duty_increase_recommendations'] ?? [])
    @if ((int) ($dutyIncreaseReport['total_uncovered_duties'] ?? 0) > 0)
        <div class="card">
            <div class="title" style="font-size: 14px;">{{ __('exam.reports.observer_duty_increase_recommendation_report') }}</div>
            <table>
                <tbody>
                    <tr>
                        <th>{{ __('exam.fields.missing_assignments_count') }}</th>
                        <td>{{ $dutyIncreaseReport['total_uncovered_duties'] ?? 0 }}</td>
                        <th>{{ __('exam.reports.duties_coverable_by_current_observer_limit_increase') }}</th>
                        <td>{{ $dutyIncreaseReport['coverable_by_limit_increase'] ?? 0 }}</td>
                    </tr>
                    <tr>
                        <th>{{ __('exam.reports.duties_requiring_new_observers') }}</th>
                        <td>{{ $dutyIncreaseReport['requires_new_observers'] ?? 0 }}</td>
                        <th>{{ __('exam.reports.recommended_observers_to_increase') }}</th>
                        <td>{{ $dutyIncreaseReport['recommended_observers_count'] ?? 0 }}</td>
                    </tr>
                    <tr>
                        <th>{{ __('exam.reports.max_suggested_increase_per_observer') }}</th>
                        <td colspan="3">{{ $dutyIncreaseReport['max_suggested_increase_per_observer'] ?? 0 }}</td>
                    </tr>
                </tbody>
            </table>

            <table style="margin-top: 8px;">
                <thead>
                    <tr>
                        <th>{{ __('exam.fields.invigilator_name') }}</th>
                        <th>{{ __('exam.fields.invigilation_role') }}</th>
                        <th>{{ __('exam.fields.eligible_roles') }}</th>
                        <th>{{ __('exam.fields.current_duties') }}</th>
                        <th>{{ __('exam.fields.current_duty_limit') }}</th>
                        <th>{{ __('exam.fields.suggested_duty_limit') }}</th>
                        <th>{{ __('exam.fields.suggested_additional_duties') }}</th>
                        <th>{{ __('exam.fields.affected_period') }}</th>
                        <th>{{ __('exam.fields.reason') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($dutyIncreaseReport['recommendations'] ?? []) as $recommendation)
                        <tr>
                            <td>{{ $recommendation['name'] }}</td>
                            <td>{{ $recommendation['observer_type'] }}</td>
                            <td>{{ $recommendation['eligible_roles'] }}</td>
                            <td>{{ $recommendation['current_assigned_duties'] }}</td>
                            <td>{{ $recommendation['current_max_duties'] }}</td>
                            <td>{{ $recommendation['suggested_new_max_duties'] }}</td>
                            <td>{{ $recommendation['suggested_additional_duties'] }}</td>
                            <td>{{ implode('، ', $recommendation['related_slots'] ?? []) }}</td>
                            <td>{{ $recommendation['reason'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9">{{ __('exam.reports.duty_increase_blocked_by_conflicts_or_daily_limits') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            @if (! empty($dutyIncreaseReport['unresolved'] ?? []))
                <table style="margin-top: 8px;">
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
                        @foreach ($dutyIncreaseReport['unresolved'] as $unresolved)
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
            @endif
        </div>
    @endif

    <div class="card">
        <div class="title" style="font-size: 14px;">{{ __('exam.reports.shortage_by_slot') }}</div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('exam.fields.exam_date') }}</th>
                    <th>{{ __('exam.fields.exam_start_time') }}</th>
                    <th>{{ __('exam.fields.invigilation_role') }}</th>
                    <th>{{ __('exam.fields.required_count') }}</th>
                    <th>{{ __('exam.fields.assigned_count') }}</th>
                    <th>{{ __('exam.fields.missing_assignments_count') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($summary['shortage_by_slot'] ?? []) as $slotShortage)
                    <tr>
                        <td class="ltr">{{ $slotShortage['exam_date'] }}</td>
                        <td class="ltr">{{ $slotShortage['start_time'] }}</td>
                        <td>{{ $slotShortage['role_label'] }}</td>
                        <td>{{ $slotShortage['required_count'] }}</td>
                        <td>{{ $slotShortage['assigned_count'] }}</td>
                        <td>{{ $slotShortage['shortage_count'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">{{ __('exam.diagnosis.invigilators_all_distributed') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="title" style="font-size: 14px;">{{ __('exam.reports.shortage_reason_breakdown') }}</div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('exam.fields.invigilation_role') }}</th>
                    <th>{{ __('exam.fields.reason') }}</th>
                    <th>{{ __('exam.fields.missing_assignments_count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach (($summary['shortage_by_role'] ?? []) as $roleShortage)
                    @foreach (($roleShortage['reason_counts'] ?? []) as $reason => $count)
                        <tr>
                            <td>{{ $roleShortage['role_label'] }}</td>
                            <td>{{ $reason }}</td>
                            <td>{{ $count }}</td>
                        </tr>
                    @endforeach
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
                <th>{{ __('exam.fields.missing_assignments_count') }}</th>
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
