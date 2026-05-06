@php
    $subject = $offering->subject;
    $departmentName = $subject?->department?->name;
    $studyLevelName = $subject?->studyLevel?->name;
    $subjectLine = trim(($subject?->name ?? '—').(filled($departmentName) || filled($studyLevelName) ? ' ('.collect([$departmentName, $studyLevelName])->filter()->implode(' - ').')' : ''));
@endphp

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $printTitle }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: #e5e7eb;
            color: #111111;
            direction: rtl;
            font-family: "DejaVu Sans", Tahoma, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            text-align: right;
        }

        .screen-toolbar {
            align-items: center;
            background: #111827;
            box-shadow: 0 8px 22px rgba(17, 24, 39, 0.2);
            color: #ffffff;
            display: flex;
            gap: 10px;
            justify-content: center;
            padding: 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .toolbar-button {
            background: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: #111827;
            cursor: pointer;
            display: inline-block;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            min-width: 140px;
            padding: 8px 14px;
            text-align: center;
            text-decoration: none;
        }

        .toolbar-button.primary {
            background: #0f766e;
            color: #ffffff;
        }

        .sheet {
            background: #ffffff;
            border: 1px solid #d1d5db;
            box-shadow: 0 18px 38px rgba(17, 24, 39, 0.14);
            margin: 14px auto 22px;
            min-height: 297mm;
            padding: 8mm;
            width: 210mm;
        }

        .page-break {
            page-break-before: always;
        }

        .print-header {
            border-bottom: 2px solid #111111;
            margin-bottom: 5mm;
            padding-bottom: 3mm;
            text-align: center;
        }

        .university-name,
        .college-name {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.6;
        }

        .report-title {
            border: 1px solid #111111;
            display: inline-block;
            font-size: 15px;
            font-weight: 700;
            margin-top: 3mm;
            min-width: 58%;
            padding: 3px 18px;
        }

        .subject-title {
            font-size: 14px;
            font-weight: 700;
            margin-top: 3mm;
        }

        .meta-line {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            justify-content: center;
            margin-top: 2mm;
            font-size: 11px;
            font-weight: 700;
        }

        .student-columns {
            direction: rtl;
            width: 100%;
        }

        .student-columns.single > tbody > tr > td {
            width: 100%;
        }

        .student-columns > tbody > tr > td {
            border: 0;
            padding: 0;
            vertical-align: top;
            width: 50%;
        }

        .student-columns > tbody > tr > td + td {
            padding-right: 5mm;
        }

        table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #111111;
            padding: 3px 4px;
            text-align: center;
            vertical-align: middle;
        }

        th {
            background: #e5e5e5;
            font-weight: 700;
        }

        td {
            height: 21px;
        }

        .number-column {
            direction: ltr;
            unicode-bidi: embed;
            width: 25%;
        }

        .name-column {
            text-align: right;
            width: 43%;
        }

        .seat-column {
            width: 14%;
        }

        .hall-column {
            width: 18%;
        }

        .empty-state {
            border: 1px dashed #999999;
            color: #444444;
            font-size: 14px;
            font-weight: 700;
            margin-top: 12mm;
            padding: 12mm;
            text-align: center;
        }

        .page-counter {
            color: #555555;
            font-size: 9px;
            margin-top: 3mm;
            text-align: left;
        }

        @media print {
            html,
            body {
                background: #ffffff;
                margin: 0;
                padding: 0;
            }

            body {
                direction: rtl;
            }

            .no-print,
            .screen-toolbar {
                display: none !important;
            }

            .sheet {
                border: 0;
                box-shadow: none;
                margin: 0;
                min-height: auto;
                padding: 0;
                width: auto;
            }

            tr,
            table {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }

        @if (! empty($isPdfDownload))
            body {
                background: #ffffff;
            }

            .screen-toolbar {
                display: none !important;
            }

            .sheet {
                border: 0;
                box-shadow: none;
                margin: 0;
                min-height: auto;
                padding: 0;
                width: auto;
            }
        @endif
    </style>
</head>
<body>
    @if (empty($isPdfDownload))
        <div class="screen-toolbar no-print">
            <button class="toolbar-button primary" type="button" onclick="window.print()">طباعة</button>
            <a class="toolbar-button" href="{{ $pdfUrl }}">تحميل PDF</a>
            <button class="toolbar-button" type="button" onclick="window.close()">إغلاق</button>
        </div>
    @endif

    @if (filled($emptyMessage))
        <main class="sheet">
            <header class="print-header">
                <div class="university-name">{{ $systemSetting->university_name }}</div>
                <div class="college-name">{{ $subject?->college?->name }}</div>
                <div class="report-title">كشف توزيع الطلاب على القاعات حسب المادة والفترة</div>
                <div class="subject-title">{{ $subjectLine }}</div>
                <div class="meta-line">
                    <span>{{ $offering->exam_date?->format('Y-m-d') }}</span>
                    <span>{{ $periodLabel }}</span>
                    <span>{{ $offering->academicYear?->name }}</span>
                </div>
            </header>
            <div class="empty-state">{{ $emptyMessage }}</div>
        </main>
    @else
        @foreach ($pages as $pageIndex => $page)
            <main class="sheet {{ $pageIndex > 0 ? 'page-break' : '' }}">
                <header class="print-header">
                    <div class="university-name">{{ $systemSetting->university_name }}</div>
                    <div class="college-name">{{ $subject?->college?->name }}</div>
                    <div class="report-title">كشف توزيع الطلاب على القاعات حسب المادة والفترة</div>
                    <div class="subject-title">{{ $subjectLine }}</div>
                    <div class="meta-line">
                        <span>التاريخ: {{ $offering->exam_date?->format('Y-m-d') }}</span>
                        <span>الفترة: {{ $periodLabel }}</span>
                        @if ($offering->academicYear)
                            <span>العام الدراسي: {{ $offering->academicYear->name }}</span>
                        @endif
                        @if ($offering->semester)
                            <span>الفصل الدراسي: {{ $offering->semester->name }}</span>
                        @endif
                    </div>
                </header>

                <table class="student-columns {{ empty($page['left']) ? 'single' : '' }}">
                    <tbody>
                        <tr>
                            @foreach (['right', 'left'] as $side)
                                @if (! empty($page[$side]))
                                    <td>
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th class="number-column">الرقم الجامعي</th>
                                                    <th class="name-column">اسم الطالب</th>
                                                    <th class="seat-column">رقم الجلوس</th>
                                                    <th class="hall-column">القاعة</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($page[$side] as $row)
                                                    <tr>
                                                        <td class="number-column">{{ $row['student_number'] }}</td>
                                                        <td class="name-column">{{ $row['full_name'] }}</td>
                                                        <td class="seat-column">{{ $row['seat_number'] }}</td>
                                                        <td class="hall-column">{{ $row['hall_name'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    </tbody>
                </table>

                @if (count($pages) > 1)
                    <div class="page-counter">صفحة {{ $pageIndex + 1 }} من {{ count($pages) }}</div>
                @endif
            </main>
        @endforeach
    @endif
</body>
</html>
