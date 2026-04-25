<x-filament-panels::page>
    @php
        $summary = $this->getSummaryData();
        $distributionStatus = $summary['distribution_status'] ?? ['key' => 'not_run', 'label' => __('exam.distribution_statuses.not_run'), 'tone' => 'gray', 'icon' => 'heroicon-o-clock'];
        $hasDistribution = (bool) ($summary['has_distribution'] ?? $this->hasDistribution());
        $createHallUrl = $this->getCreateHallUrl();
        $offeringsIndexUrl = $this->getOfferingsIndexUrl();
        $canExportExcel = $this->canExportExcel();

        $totalStudents = (int) ($summary['total_students_count'] ?? 0);
        $availableHallsCount = (int) ($summary['available_halls_count'] ?? 0);

        $badgeClasses = [
            'gray' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300',
            'success' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-300',
            'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-300',
            'danger' => 'bg-danger-50 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
            'info' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
            'primary' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        ];

        $surfaceClasses = [
            'gray' => 'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900',
            'success' => 'border-success-200 bg-success-50/70 dark:border-success-500/20 dark:bg-success-500/10',
            'warning' => 'border-warning-200 bg-warning-50/80 dark:border-warning-500/20 dark:bg-warning-500/10',
            'danger' => 'border-danger-200 bg-danger-50/80 dark:border-danger-500/20 dark:bg-danger-500/10',
            'info' => 'border-sky-200 bg-sky-50/80 dark:border-sky-500/20 dark:bg-sky-500/10',
            'primary' => 'border-amber-200 bg-amber-50/80 dark:border-amber-500/20 dark:bg-amber-500/10',
        ];

        $iconClasses = [
            'gray' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
            'success' => 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-300',
            'warning' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/15 dark:text-warning-300',
            'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
            'info' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
            'primary' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        ];

        $progressClasses = [
            'gray' => 'bg-gray-500',
            'success' => 'bg-success-500',
            'warning' => 'bg-warning-500',
            'danger' => 'bg-danger-500',
            'info' => 'bg-sky-500',
            'primary' => 'bg-amber-500',
        ];
    @endphp

    <style>
        [x-cloak] { display: none !important; }
    </style>

    <div dir="rtl" class="space-y-5 text-right">
        @include('filament.resources.subject-exam-offerings.pages.partials.distribution.header')
        @include('filament.resources.subject-exam-offerings.pages.partials.distribution.problem-diagnosis')
        @include('filament.resources.subject-exam-offerings.pages.partials.distribution.summary-cards')
        @include('filament.resources.subject-exam-offerings.pages.partials.distribution.subject-stats')
        @include('filament.resources.subject-exam-offerings.pages.partials.distribution.halls-stats')
        @include('filament.resources.subject-exam-offerings.pages.partials.distribution.distribution-results')
        @include('filament.resources.subject-exam-offerings.pages.partials.distribution.unassigned-students')
    </div>
</x-filament-panels::page>
