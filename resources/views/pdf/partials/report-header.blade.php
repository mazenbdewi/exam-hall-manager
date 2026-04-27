@php
    $universityName = filled($universityName ?? null) ? $universityName : 'الجامعة الافتراضية السورية';
    $facultyName = filled($facultyName ?? null) ? $facultyName : '—';
    $reportTitle = filled($reportTitle ?? null) ? $reportTitle : '';
    $reportSubtitle = filled($reportSubtitle ?? null) ? $reportSubtitle : null;
    $dateRange = filled($dateRange ?? null) ? $dateRange : null;
@endphp

<div class="report-header">
    <table class="report-header-table">
        <tr>
            <td class="report-logo-cell">
                @if (! empty($universityLogo))
                    <img src="{{ $universityLogo }}" alt="University Logo" class="report-logo">
                @else
                    <div class="report-logo-placeholder">شعار الجامعة</div>
                @endif
            </td>
            <td class="report-title-cell">
                <div class="report-kicker">وثيقة رسمية</div>
                <div class="report-university">{{ $universityName }}</div>
                <div class="report-faculty">الكلية: {{ $facultyName }}</div>
                <div class="report-title">{{ $reportTitle }}</div>
                @if ($reportSubtitle)
                    <div class="report-subtitle">{{ $reportSubtitle }}</div>
                @endif
                @if ($dateRange)
                    <div class="report-date">{{ $dateRange }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>
