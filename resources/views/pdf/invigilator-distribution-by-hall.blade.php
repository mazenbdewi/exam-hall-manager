<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.actions.export_invigilator_pdf_by_hall') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        @include('pdf.partials.report-styles')
        .card { border: 1px solid #dbe3ea; padding: 10px; margin-bottom: 12px; background: #ffffff; }
        .title { font-size: 18px; font-weight: bold; }
        .section-title { font-size: 14px; font-weight: bold; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #dbe3ea; padding: 6px 7px; vertical-align: top; }
        th { background: #eef2f7; font-weight: bold; color: #0f172a; }
        .slot-title { color: #0f172a; border-right: 4px solid #0f766e; padding: 7px 10px; margin: 14px 0 8px; background: #f8fafc; font-weight: bold; }
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

    @foreach ($summary['slots'] as $slot)
        <div class="slot-title">{{ __('exam.fields.exam_date') }}: {{ $slot['exam_date'] }} | {{ __('exam.fields.exam_start_time') }}: <span class="ltr">{{ substr((string) $slot['start_time'], 0, 5) }}</span></div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('exam.fields.hall_name') }}</th>
                    <th>{{ __('exam.fields.hall_location') }}</th>
                    <th>{{ __('exam.fields.hall_type') }}</th>
                    <th>{{ __('exam.invigilation_roles.hall_head') }}</th>
                    <th>{{ __('exam.invigilation_roles.secretary') }}</th>
                    <th>{{ __('exam.invigilation_roles.regular') }}</th>
                    <th>{{ __('exam.invigilation_roles.reserve') }}</th>
                </tr>
            </thead>
            <tbody>
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
                        <td>{{ $hall['name'] }}</td>
                        <td>{{ $hall['location'] }}</td>
                        <td>{{ $hall['hall_type_label'] }}</td>
                        <td>{{ $roleCell('hall_head') }}</td>
                        <td>{{ $roleCell('secretary') }}</td>
                        <td>{{ $roleCell('regular') }}</td>
                        <td>{{ $roleCell('reserve') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>
