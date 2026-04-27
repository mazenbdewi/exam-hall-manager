<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.pages.invigilator_distribution') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        @include('pdf.partials.report-styles')
        .card { border: 1px solid #dbe3ea; padding: 10px; margin-bottom: 12px; background: #ffffff; }
        .title { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dbe3ea; padding: 6px 7px; vertical-align: top; }
        th { background: #eef2f7; font-weight: bold; color: #0f172a; }
        .section-title { font-size: 14px; font-weight: bold; margin: 0 0 8px; }
    </style>
</head>
<body>
    @include('pdf.partials.report-header', [
        'universityName' => $systemSetting->university_name,
        'universityLogo' => $logoDataUri,
        'facultyName' => $summary['college']->name,
        'reportTitle' => 'تقرير توزيع المراقبين حسب القاعات',
        'reportSubtitle' => __('exam.pages.invigilator_distribution'),
        'dateRange' => $reportDateRange ?? __('exam.fields.period').': —',
    ])

    <div class="card">
        <div class="section-title">{{ __('exam.reports.hall_report') }}</div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('exam.fields.exam_date') }}</th>
                    <th>{{ __('exam.fields.exam_start_time') }}</th>
                    <th>{{ __('exam.fields.hall_name') }}</th>
                    <th>{{ __('exam.fields.hall_location') }}</th>
                    <th>{{ __('exam.invigilation_roles.hall_head') }}</th>
                    <th>{{ __('exam.invigilation_roles.secretary') }}</th>
                    <th>{{ __('exam.invigilation_roles.regular') }}</th>
                    <th>{{ __('exam.invigilation_roles.reserve') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['slots'] as $slot)
                    @foreach ($slot['halls'] as $hall)
                        @php
                            $names = fn (string $role): string => collect($hall['assignments_by_role'][$role] ?? [])
                                ->map(fn (array $assignment): string => trim(($assignment['name'] ?? '').(filled($assignment['notes'] ?? null) ? ' - '.$assignment['notes'] : '')))
                                ->filter()
                                ->implode('، ');
                            $roleCell = function (string $role) use ($hall, $names): string {
                                $roleNames = $names($role);
                                $required = (int) ($hall['required_roles'][$role] ?? 0);
                                $assigned = count($hall['assignments_by_role'][$role] ?? []);

                                if ($required > $assigned) {
                                    $shortage = $hall['shortages_by_role'][$role] ?? [];
                                    $shortageCount = (int) ($shortage['shortage_count'] ?? max(0, $required - $assigned));
                                    $reason = $shortage['reason'] ?? __('exam.reports.required_role_shortage_reason');
                                    $shortageText = __('exam.reports.has_shortage').': '.$shortageCount.' - '.__('exam.fields.reason').': '.$reason;

                                    return filled($roleNames) ? $roleNames.' | '.$shortageText : '— '.$shortageText;
                                }

                                return $roleNames ?: '—';
                            };
                        @endphp
                        <tr>
                            <td class="ltr">{{ $slot['exam_date'] }}</td>
                            <td class="ltr">{{ substr((string) $slot['start_time'], 0, 5) }}</td>
                            <td>{{ $hall['name'] }}</td>
                            <td>{{ $hall['location'] }}</td>
                            <td>{{ $roleCell('hall_head') }}</td>
                            <td>{{ $roleCell('secretary') }}</td>
                            <td>{{ $roleCell('regular') }}</td>
                            <td>{{ $roleCell('reserve') }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

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
            <div class="section-title">{{ __('exam.reports.shortage_report') }}</div>
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
                    @foreach ($summary['shortages'] as $shortage)
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
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</body>
</html>
