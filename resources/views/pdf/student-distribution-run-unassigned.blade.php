<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: notosansarabic, sans-serif; direction: rtl; text-align: right; color: #111827; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 20px; font-weight: bold; }
        .meta { margin-top: 6px; color: #4b5563; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border: 1px solid #d1d5db; padding: 6px; font-size: 10px; }
        th { background: #f3f4f6; font-weight: bold; }
        .warning { border: 1px solid #ef4444; background: #fef2f2; padding: 10px; margin: 12px 0; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ __('exam.global_hall_distribution.unassigned_report_title') }}</div>
        <div class="meta">
            {{ __('exam.fields.college') }}: {{ $run->college?->name ?? '—' }}
            |
            {{ __('exam.fields.period') }}: {{ $run->from_date?->format('Y-m-d') }} - {{ $run->to_date?->format('Y-m-d') }}
        </div>
    </div>

    <div class="warning">
        {{ __('exam.global_hall_distribution.problem_message') }}
        {{ __('exam.global_hall_distribution.summary.unassigned_students_count') }}: {{ count($unassignedStudents ?? []) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('exam.fields.student_number') }}</th>
                <th>{{ __('exam.fields.full_name') }}</th>
                <th>{{ __('exam.fields.subject') }}</th>
                <th>{{ __('exam.fields.exam_date') }}</th>
                <th>{{ __('exam.fields.exam_start_time') }}</th>
                <th>{{ __('exam.fields.reason') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse (($unassignedStudents ?? []) as $student)
                <tr>
                    <td>{{ $student['student_number'] }}</td>
                    <td>{{ $student['full_name'] }}</td>
                    <td>{{ $student['subject_name'] }}</td>
                    <td>{{ $student['exam_date'] }}</td>
                    <td>{{ $student['start_time'] }}</td>
                    <td>{{ $student['reason'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">{{ __('exam.global_hall_distribution.no_unassigned_students') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
