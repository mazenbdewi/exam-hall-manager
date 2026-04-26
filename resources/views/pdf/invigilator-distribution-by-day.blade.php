<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.actions.export_invigilator_pdf_by_day') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        .card { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
        .day { page-break-after: always; }
        .day:last-child { page-break-after: auto; }
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
                    <div class="muted">{{ __('exam.fields.report_type') }}: {{ __('exam.pages.invigilator_distribution') }} - {{ __('exam.tabs.by_day') }}</div>
                    <div class="muted">{{ __('exam.fields.college') }}: {{ $summary['college']->name }}</div>
                    <div class="muted">{{ __('exam.fields.period') }}: <span class="ltr">{{ $summary['from_date'] ?: '—' }}</span> - <span class="ltr">{{ $summary['to_date'] ?: '—' }}</span></div>
                </td>
            </tr>
        </table>
    </div>

    @foreach ($summary['by_day'] as $day)
        <div class="day">
            <div class="card">
                <div class="section-title">{{ $day['exam_date'] }}</div>
                <div class="muted">
                    {{ __('exam.fields.slots_count') }}: {{ $day['slots_count'] }} |
                    {{ __('exam.fields.used_halls') }}: {{ $day['halls_count'] }} |
                    {{ __('exam.fields.required_count') }}: {{ $day['required_count'] }} |
                    {{ __('exam.fields.assigned_count') }}: {{ $day['assigned_count'] }} |
                    {{ __('exam.fields.shortage_count') }}: {{ $day['shortage_count'] }}
                </div>
            </div>
            @foreach ($day['slots'] as $slot)
                <div class="section-title">{{ __('exam.fields.exam_start_time') }}: <span class="ltr">{{ substr((string) $slot['start_time'], 0, 5) }}</span></div>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('exam.fields.hall_name') }}</th>
                            <th>{{ __('exam.invigilation_roles.hall_head') }}</th>
                            <th>{{ __('exam.invigilation_roles.secretary') }}</th>
                            <th>{{ __('exam.invigilation_roles.regular') }}</th>
                            <th>{{ __('exam.invigilation_roles.reserve') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($slot['halls'] as $hall)
                            @php
                                $names = fn (string $role): string => collect($hall['assignments_by_role'][$role] ?? [])->pluck('name')->filter()->implode('، ');
                            @endphp
                            <tr>
                                <td>{{ $hall['name'] }} - {{ $hall['location'] }}</td>
                                <td>{{ $names('hall_head') ?: '—' }}</td>
                                <td>{{ $names('secretary') ?: '—' }}</td>
                                <td>{{ $names('regular') ?: '—' }}</td>
                                <td>{{ $names('reserve') ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </div>
    @endforeach
</body>
</html>
