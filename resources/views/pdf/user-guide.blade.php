<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: notosansarabic;
            color: #111827;
            background: #f8fafc;
            direction: rtl;
            line-height: 1.75;
        }

        .toc {
            page-break-after: always;
            padding: 9mm;
            border-radius: 18px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
        }

        .toc-row {
            padding: 3mm 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
        }

        .section {
            page-break-inside: avoid;
            margin-bottom: 10mm;
            padding: 7.5mm;
            border: 1px solid #d7e7f5;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
        }

        h2 {
            margin: 0 0 4mm;
            padding-bottom: 3mm;
            color: #075985;
            font-size: 19px;
            border-bottom: 2px solid #7dd3fc;
        }

        h3 {
            margin: 5mm 0 2mm;
            color: #0f766e;
            font-size: 14px;
        }

        p {
            margin: 0 0 3mm;
            font-size: 11.5px;
        }

        ol, ul {
            margin: 0 0 4mm;
            padding-right: 7mm;
        }

        li {
            margin-bottom: 2mm;
            font-size: 11.5px;
        }

        .note {
            margin-top: 4mm;
            padding: 4mm;
            border-radius: 12px;
            background: #ecfeff;
            color: #155e75;
            border-right: 4px solid #06b6d4;
            font-size: 11px;
        }

        .example {
            margin-top: 4mm;
            padding: 4mm;
            border-radius: 14px;
            background: #fff7ed;
            color: #7c2d12;
            border-right: 4px solid #f97316;
            font-size: 11px;
        }

        .example-title {
            display: block;
            margin-bottom: 2mm;
            font-weight: bold;
            color: #9a3412;
        }

        .checklist {
            border-collapse: collapse;
            width: 100%;
            margin-top: 4mm;
        }

        .checklist td {
            border: 1px solid #dbeafe;
            padding: 6px 8px;
            font-size: 10.5px;
            background: #ffffff;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <section class="toc">
        <h2>{{ $guide['toc_title'] }}</h2>
        @foreach ($guide['sections'] as $index => $section)
            <div class="toc-row">{{ $index + 1 }}. {{ $section['title'] }}</div>
        @endforeach
    </section>

    @foreach ($guide['sections'] as $index => $section)
        <section class="section {{ $index > 0 && $index % 3 === 0 ? 'page-break' : '' }}">
            <h2>{{ $index + 1 }}. {{ $section['title'] }}</h2>

            @foreach ($section['blocks'] as $block)
                @if (($block['type'] ?? 'paragraph') === 'paragraph')
                    <p>{{ $block['text'] }}</p>
                @elseif ($block['type'] === 'steps')
                    <ol>
                        @foreach ($block['items'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ol>
                @elseif ($block['type'] === 'list')
                    <ul>
                        @foreach ($block['items'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @elseif ($block['type'] === 'subsection')
                    <h3>{{ $block['title'] }}</h3>
                    @if (! empty($block['text']))
                        <p>{{ $block['text'] }}</p>
                    @endif
                    @if (! empty($block['items']))
                        <ul>
                            @foreach ($block['items'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @endif
                @elseif ($block['type'] === 'table')
                    <table class="checklist">
                        @foreach ($block['rows'] as $row)
                            <tr>
                                <td>{{ $row[0] }}</td>
                                <td>{{ $row[1] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @elseif ($block['type'] === 'note')
                    <div class="note">{{ $block['text'] }}</div>
                @elseif ($block['type'] === 'example')
                    <div class="example">
                        <span class="example-title">{{ $block['title'] }}</span>
                        {{ $block['text'] }}
                    </div>
                @endif
            @endforeach
        </section>
    @endforeach
</body>
</html>
