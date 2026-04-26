<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.pages.invigilator_distribution') }}</title>
    <style>
        body { font-family: 'notosansarabic', sans-serif; font-size: 10px; color: #111827; direction: rtl; text-align: right; }
        .card { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
        .title { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        .muted { color: #4b5563; }
        .logo { width: 64px; height: 64px; object-fit: contain; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        .header-table td { border: 0; }
        .section-title { font-size: 14px; font-weight: bold; margin: 0 0 8px; }
        .ltr { direction: ltr; unicode-bidi: embed; }
    </style>
</head>
<body>
    <div class="card">
        <table class="header-table">
            <tr>
                <td style="width: 80px;">
                    @if ($logoDataUri)
                        <img src="{{ $logoDataUri }}" class="logo" alt="">
                    @endif
                </td>
                <td>
                    <div class="title">{{ $systemSetting->university_name }}</div>
                    <div class="muted">{{ __('exam.pages.invigilator_distribution') }}</div>
                    <div class="muted">{{ __('exam.fields.college') }}: {{ $summary['college']->name }}</div>
                </td>
            </tr>
        </table>
    </div>

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
                            $names = fn (string $role): string => collect($hall['assignments_by_role'][$role] ?? [])->pluck('name')->filter()->implode('، ');
                        @endphp
                        <tr>
                            <td class="ltr">{{ $slot['exam_date'] }}</td>
                            <td class="ltr">{{ substr((string) $slot['start_time'], 0, 5) }}</td>
                            <td>{{ $hall['name'] }}</td>
                            <td>{{ $hall['location'] }}</td>
                            <td>{{ $names('hall_head') ?: '—' }}</td>
                            <td>{{ $names('secretary') ?: '—' }}</td>
                            <td>{{ $names('regular') ?: '—' }}</td>
                            <td>{{ $names('reserve') ?: '—' }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    @if (! empty($summary['shortages']))
        <div class="card">
            <div class="section-title">{{ __('exam.reports.shortage_report') }}</div>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('exam.fields.exam_date') }}</th>
                        <th>{{ __('exam.fields.exam_start_time') }}</th>
                        <th>{{ __('exam.fields.hall_name') }}</th>
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
