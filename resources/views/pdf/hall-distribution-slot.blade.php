<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ __('exam.pages.slot_hall_distribution') }}</title>
    <style>
        body {
            font-family: 'notosansarabic', sans-serif;
            font-size: 11px;
            color: #111827;
            direction: rtl;
            text-align: right;
            unicode-bidi: embed;
        }
        .page {
            width: 100%;
        }
        .page + .page {
            page-break-before: always;
        }
        .header-table,
        .stats-table,
        .subjects-table,
        .students-table,
        .halls-table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-table td {
            vertical-align: top;
        }
        .logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
        }
        .title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .subtitle,
        .muted {
            color: #4b5563;
        }
        .card {
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 14px;
            background: #ffffff;
        }
        .pill {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 6px;
            margin-bottom: 6px;
        }
        .pill.gray {
            background: #f3f4f6;
            color: #374151;
        }
        .pill.success {
            background: #dcfce7;
            color: #166534;
        }
        .pill.warning {
            background: #fef3c7;
            color: #92400e;
        }
        .pill.danger {
            background: #fee2e2;
            color: #991b1b;
        }
        .section-title {
            font-size: 15px;
            font-weight: bold;
            margin: 0 0 10px;
        }
        .mini-grid {
            width: 100%;
            margin-bottom: 14px;
        }
        .mini-grid td {
            width: 33.33%;
            padding: 0 0 8px 8px;
            vertical-align: top;
        }
        .stat-box {
            border: 1px solid #dbe3ea;
            border-radius: 10px;
            padding: 10px;
            background: #f9fafb;
        }
        .stat-label {
            color: #6b7280;
            font-size: 10px;
            margin-bottom: 4px;
        }
        .stat-value {
            font-size: 18px;
            font-weight: bold;
        }
        .progress {
            height: 7px;
            border-radius: 999px;
            background: #e5e7eb;
            overflow: hidden;
            margin-top: 6px;
        }
        .progress-bar {
            height: 7px;
            background: #0f766e;
        }
        .stats-table th,
        .stats-table td,
        .subjects-table th,
        .subjects-table td,
        .students-table th,
        .students-table td,
        .halls-table th,
        .halls-table td {
            border: 1px solid #d1d5db;
            padding: 7px 8px;
            text-align: right;
            vertical-align: top;
        }
        .stats-table th,
        .subjects-table th,
        .students-table th,
        .halls-table th {
            background: #f3f4f6;
            font-weight: bold;
        }
        .diagnosis-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 8px;
        }
        .diagnosis-item.danger {
            border-color: #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }
        .diagnosis-item.warning {
            border-color: #fde68a;
            background: #fffbeb;
            color: #92400e;
        }
        .diagnosis-item.success {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #166534;
        }
        .diagnosis-item.gray {
            background: #f9fafb;
            color: #374151;
        }
        .hall-meta {
            margin-bottom: 10px;
        }
        .hall-meta span {
            display: inline-block;
            margin-left: 14px;
            margin-bottom: 6px;
        }
        .ltr {
            direction: ltr;
            text-align: left;
            unicode-bidi: embed;
            font-family: sans-serif;
        }
        .student-name {
            line-height: 1.7;
        }
        .page-number {
            margin-top: 10px;
            font-size: 9px;
            color: #6b7280;
            text-align: left;
        }
    </style>
</head>
<body>
    @php
        $statusTone = $summary['distribution_status']['tone'] ?? 'gray';
        $statusLabel = $summary['distribution_status']['label'] ?? __('exam.distribution_statuses.not_run');
    @endphp

    <div class="page">
        <div class="card">
            <table class="header-table">
                <tr>
                    <td style="width: 90px;">
                        @if ($logoDataUri)
                            <img src="{{ $logoDataUri }}" alt="University Logo" class="logo">
                        @endif
                    </td>
                    <td>
                        <div class="title">{{ $systemSetting->university_name }}</div>
                        <div class="subtitle">{{ __('exam.pages.slot_hall_distribution') }}</div>
                        <div class="muted" style="margin-top: 6px;">
                            {{ __('exam.fields.college') }}: {{ $summary['context']['college_name'] ?? '' }}
                        </div>
                        <div class="muted" style="margin-top: 3px;">
                            {{ __('exam.fields.exam_date') }}:
                            <span class="ltr">{{ $summary['exam_date'] }}</span>
                            |
                            {{ __('exam.fields.exam_start_time') }}:
                            <span class="ltr">{{ substr((string) $summary['exam_start_time'], 0, 5) }}</span>
                        </div>
                        <div style="margin-top: 8px;">
                            <span class="pill {{ $statusTone }}">{{ $statusLabel }}</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <table class="mini-grid">
            <tr>
                <td>
                    <div class="stat-box">
                        <div class="stat-label">{{ __('exam.fields.total_students') }}</div>
                        <div class="stat-value">{{ $summary['total_students_count'] }}</div>
                    </div>
                </td>
                <td>
                    <div class="stat-box">
                        <div class="stat-label">{{ __('exam.fields.assigned_students') }}</div>
                        <div class="stat-value">{{ $summary['assigned_students_count'] }}</div>
                    </div>
                </td>
                <td>
                    <div class="stat-box">
                        <div class="stat-label">{{ __('exam.fields.unassigned_students') }}</div>
                        <div class="stat-value">{{ $summary['unassigned_students_count'] }}</div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="stat-box">
                        <div class="stat-label">{{ __('exam.fields.available_halls') }}</div>
                        <div class="stat-value">{{ $summary['available_halls_count'] }}</div>
                    </div>
                </td>
                <td>
                    <div class="stat-box">
                        <div class="stat-label">{{ __('exam.fields.used_halls') }}</div>
                        <div class="stat-value">{{ $summary['used_halls_count'] }}</div>
                    </div>
                </td>
                <td>
                    <div class="stat-box">
                        <div class="stat-label">العجز في المقاعد</div>
                        <div class="stat-value">{{ $summary['capacity_shortage'] }}</div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="card">
            <div class="section-title">ملخص السعات</div>
            <table class="stats-table">
                <thead>
                    <tr>
                        <th>السعة الإجمالية المتاحة</th>
                        <th>المقاعد المستخدمة</th>
                        <th>المقاعد المتبقية</th>
                        <th>نسبة التوزيع</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $summary['total_capacity'] }}</td>
                        <td>{{ $summary['used_capacity'] }}</td>
                        <td>{{ $summary['remaining_capacity'] }}</td>
                        <td>{{ $summary['distribution_percentage'] }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="section-title">{{ __('exam.sections.problem_diagnosis') }}</div>
            @foreach (($summary['diagnosis']['items'] ?? []) as $item)
                <div class="diagnosis-item {{ $item['tone'] }}">
                    {{ $item['text'] }}
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="section-title">إحصائيات المواد</div>
            <table class="subjects-table">
                <thead>
                    <tr>
                        <th>{{ __('exam.fields.subject') }}</th>
                        <th>{{ __('exam.fields.total_students') }}</th>
                        <th>{{ __('exam.fields.assigned_students') }}</th>
                        <th>{{ __('exam.fields.unassigned_students') }}</th>
                        <th>نسبة التوزيع</th>
                        <th>{{ __('exam.fields.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['offerings_summary'] as $offeringSummary)
                        <tr>
                            <td>{{ $offeringSummary['subject_name'] }}</td>
                            <td>{{ $offeringSummary['students_count'] }}</td>
                            <td>{{ $offeringSummary['assigned_students_count'] }}</td>
                            <td>{{ $offeringSummary['unassigned_students_count'] }}</td>
                            <td>{{ $offeringSummary['distribution_percentage'] }}%</td>
                            <td>{{ $offeringSummary['status_label'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($summary['hall_assignments'] as $hallAssignment)
        <div class="page">
            <div class="card">
                <div class="title">{{ $hallAssignment['hall_name'] }}</div>
                <div class="muted">{{ $hallAssignment['hall_location'] ?: '—' }}</div>
                <div style="margin-top: 8px;">
                    <span class="pill {{ $hallAssignment['remaining_capacity'] === 0 ? 'danger' : 'success' }}">
                        {{ $hallAssignment['status_label'] }}
                    </span>
                </div>

                <div class="hall-meta" style="margin-top: 12px;">
                    <span>{{ __('exam.fields.capacity') }}: {{ $hallAssignment['total_capacity'] }}</span>
                    <span>{{ __('exam.fields.assigned_students') }}: {{ $hallAssignment['assigned_students_count'] }}</span>
                    <span>{{ __('exam.fields.remaining_capacity') }}: {{ $hallAssignment['remaining_capacity'] }}</span>
                    <span>نسبة الإشغال: {{ $hallAssignment['usage_percentage'] }}%</span>
                </div>

                <div style="margin-bottom: 10px;">
                    @foreach ($hallAssignment['subjects'] as $assignmentSubject)
                        <span class="pill gray">
                            {{ $assignmentSubject['subject_name'] }} ({{ $assignmentSubject['assigned_students_count'] }})
                        </span>
                    @endforeach
                </div>

                <div class="progress">
                    <div class="progress-bar" style="width: {{ min(100, max(0, (int) $hallAssignment['usage_percentage'])) }}%;"></div>
                </div>
            </div>

            <div class="card">
                <div class="section-title">الطلاب داخل القاعة</div>
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>{{ __('exam.fields.student_number') }}</th>
                            <th>{{ __('exam.fields.full_name') }}</th>
                            <th>{{ __('exam.fields.subject') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hallAssignment['students'] as $studentAssignment)
                            <tr>
                                <td class="ltr">{{ $studentAssignment['student_number'] }}</td>
                                <td class="student-name">{{ $studentAssignment['full_name'] }}</td>
                                <td>{{ $studentAssignment['subject_name'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    @if (! empty($summary['unassigned_students']))
        <div class="page">
            <div class="card">
                <div class="title">{{ __('exam.fields.unassigned_students') }}</div>
                <div class="muted">الحالات التي لم تحصل على قاعة بعد التوزيع مع توضيح سبب المشكلة.</div>
            </div>

            <div class="card">
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>{{ __('exam.fields.student_number') }}</th>
                            <th>{{ __('exam.fields.full_name') }}</th>
                            <th>{{ __('exam.fields.subject') }}</th>
                            <th>نوع الطالب</th>
                            <th>سبب عدم التوزيع</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['unassigned_students'] as $student)
                            <tr>
                                <td class="ltr">{{ $student['student_number'] }}</td>
                                <td class="student-name">{{ $student['full_name'] }}</td>
                                <td>{{ $student['subject_name'] }}</td>
                                <td>{{ $student['student_type_label'] }}</td>
                                <td>{{ $student['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</body>
</html>
