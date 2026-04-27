<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.actions.export_invigilator_pdf_by_hall') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        .card { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
        .title { font-size: 18px; font-weight: bold; }
        .section-title { font-size: 14px; font-weight: bold; margin-bottom: 8px; }
        .muted { color: #4b5563; }
        .logo { width: 56px; height: 56px; object-fit: contain; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
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
                    <div class="muted">{{ __('exam.fields.report_type') }}: {{ __('exam.pages.invigilator_distribution') }} - {{ __('exam.tabs.by_hall') }}</div>
                    <div class="muted">{{ __('exam.fields.college') }}: {{ $summary['college']->name }}</div>
                    <div class="muted">{{ __('exam.fields.period') }}: <span class="ltr">{{ $summary['from_date'] ?: '—' }}</span> - <span class="ltr">{{ $summary['to_date'] ?: '—' }}</span></div>
                </td>
            </tr>
        </table>
    </div>

    @foreach ($summary['slots'] as $slot)
        <div class="section-title">{{ $slot['exam_date'] }} | <span class="ltr">{{ substr((string) $slot['start_time'], 0, 5) }}</span></div>
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
                    <th>{{ __('exam.fields.phone_numbers') }}</th>
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
                        $phones = collect($hall['assignments_by_role'])->flatten(1)->pluck('phone')->filter()->implode('، ');
                    @endphp
                    <tr>
                        <td>{{ $hall['name'] }}</td>
                        <td>{{ $hall['location'] }}</td>
                        <td>{{ $hall['hall_type_label'] }}</td>
                        <td>{{ $roleCell('hall_head') }}</td>
                        <td>{{ $roleCell('secretary') }}</td>
                        <td>{{ $roleCell('regular') }}</td>
                        <td>{{ $roleCell('reserve') }}</td>
                        <td class="ltr">{{ $phones ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>
