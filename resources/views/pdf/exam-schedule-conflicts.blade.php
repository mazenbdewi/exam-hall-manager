<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; direction: rtl; text-align: right; font-size: 12px; }
        h1, h2 { margin: 0 0 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
        th { background: #f3f4f6; }
        .summary { margin-top: 8px; }
    </style>
</head>
<body>
    <h1>تقرير تعارضات البرنامج الامتحاني</h1>
    <div class="summary">
        الكلية: {{ $draft->college?->name }} |
        المسودة: {{ $draft->id }} |
        الفترة: {{ $draft->start_date?->format('Y-m-d') }} - {{ $draft->end_date?->format('Y-m-d') }}
    </div>
    <div class="summary">
        عدد المواد: {{ $summary['subjects_count'] ?? 0 }} |
        المجدولة: {{ $summary['scheduled_subjects_count'] ?? 0 }} |
        غير المجدولة: {{ $summary['unscheduled_subjects_count'] ?? 0 }} |
        التعارضات: {{ $summary['conflicts_count'] ?? 0 }} |
        التحذيرات: {{ $summary['warnings_count'] ?? 0 }}
    </div>

    <table>
        <thead>
            <tr>
                <th>المادة</th>
                <th>القسم</th>
                <th>التاريخ</th>
                <th>الوقت</th>
                <th>نوع التعارض</th>
                <th>الأثر</th>
                <th>التفاصيل</th>
                <th>الإجراء المقترح</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($conflicts as $conflict)
                <tr>
                    <td>{{ $conflict['subject'] }}</td>
                    <td>{{ $conflict['department'] }}</td>
                    <td>{{ $conflict['date'] ?? '—' }}</td>
                    <td>{{ $conflict['time'] ?? '—' }}</td>
                    <td>{{ $conflict['type_label'] }}</td>
                    <td>{{ $conflict['impact'] ?? '—' }}</td>
                    <td>{{ $conflict['details'] }}</td>
                    <td>{{ $conflict['suggested_action'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">لا توجد تعارضات حالياً.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
