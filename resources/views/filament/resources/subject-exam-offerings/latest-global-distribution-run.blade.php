@php
    $run = $this->latestDistributionRun();
    $tone = match ($run?->status) {
        'success' => 'success',
        'partial' => 'warning',
        'failed' => 'danger',
        default => 'gray',
    };
@endphp

<div dir="rtl" class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-right shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('exam.global_hall_distribution.latest_title') }}</h2>
        @if ($run)
            <a
                href="{{ \App\Filament\Resources\SubjectExamOfferings\SubjectExamOfferingResource::getUrl('global-distribution-results', ['run' => $run]) }}"
                class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
            >
                {{ __('exam.actions.view_problem_details') }}
            </a>
        @endif
    </div>

    @if ($run)
        <div class="grid gap-3 md:grid-cols-6">
            <div>
                <div class="text-xs text-gray-500">{{ __('exam.fields.college') }}</div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $run->college?->name }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">{{ __('exam.fields.period') }}</div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $run->from_date?->format('Y-m-d') }} - {{ $run->to_date?->format('Y-m-d') }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">{{ __('exam.fields.status') }}</div>
                <div class="mt-1">
                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $tone === 'success' ? 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300' : ($tone === 'warning' ? 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300' : 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300') }}">
                        {{ $run->statusLabel() }}
                    </span>
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500">{{ __('exam.global_hall_distribution.summary.unassigned_students_count') }}</div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $run->unassigned_students }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">{{ __('exam.fields.executed_at') }}</div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $run->executed_at?->format('Y-m-d H:i') }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">{{ __('exam.fields.executed_by') }}</div>
                <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $run->executor?->name ?? '—' }}</div>
            </div>
        </div>
    @else
        <div class="text-sm text-gray-500">{{ __('exam.global_hall_distribution.no_previous_run') }}</div>
    @endif
</div>
