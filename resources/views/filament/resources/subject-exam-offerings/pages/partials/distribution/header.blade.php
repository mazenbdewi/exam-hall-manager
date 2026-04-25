@php
    $statusTone = $distributionStatus['tone'] ?? 'gray';
    $primaryActionLabel = $hasDistribution
        ? __('exam.actions.redistribute')
        : __('exam.actions.run_hall_distribution');
@endphp

<section class="rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-col gap-5 p-5 lg:flex-row lg:items-start lg:justify-between">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="rounded-2xl p-3 {{ $iconClasses[$statusTone] ?? $iconClasses['gray'] }}">
                    <x-filament::icon :icon="$distributionStatus['icon']" class="h-6 w-6" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-950 dark:text-white">{{ __('exam.pages.slot_hall_distribution') }}</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">واجهة مختصرة توضح حالة التوزيع والعجز والمشكلات والإجراء التالي المطلوب.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold {{ $badgeClasses[$statusTone] ?? $badgeClasses['gray'] }}">
                    <x-filament::icon :icon="$distributionStatus['icon']" class="h-4 w-4" />
                    {{ $distributionStatus['label'] }}
                </span>
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold {{ $badgeClasses['gray'] }}">
                    <x-filament::icon icon="heroicon-o-building-library" class="h-4 w-4" />
                    {{ $summary['context']['college_name'] ?: '—' }}
                </span>
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold {{ $badgeClasses['gray'] }}">
                    <x-filament::icon icon="heroicon-o-calendar" class="h-4 w-4" />
                    {{ $summary['exam_date'] ?: '—' }}
                </span>
                <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold {{ $badgeClasses['gray'] }}">
                    <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                    {{ $this->getFormattedExamTime() ?: '—' }}
                </span>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 lg:max-w-md lg:justify-end">
            <x-filament::button color="primary" icon="heroicon-o-sparkles" wire:click="runDistribution">
                {{ $primaryActionLabel }}
            </x-filament::button>

            <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="exportPdf" :disabled="! $hasDistribution">
                {{ __('exam.actions.export_hall_distribution_pdf') }}
            </x-filament::button>

            @if ($createHallUrl)
                <x-filament::button tag="a" :href="$createHallUrl" color="gray" icon="heroicon-o-plus">
                    {{ __('exam.actions.add_exam_hall') }}
                </x-filament::button>
            @endif
        </div>
    </div>
</section>
