@php
    $cards = $summary['summary_cards'] ?? [];
@endphp

<section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
    @foreach ($cards as $card)
        <div class="rounded-2xl border p-4 shadow-sm {{ $surfaceClasses[$card['tone']] ?? $surfaceClasses['gray'] }}">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $card['label'] }}</div>
                    <div class="mt-2 text-3xl font-bold text-gray-950 dark:text-white">{{ $card['value'] }}</div>
                </div>
                <div class="rounded-2xl p-3 {{ $iconClasses[$card['tone']] ?? $iconClasses['gray'] }}">
                    <x-filament::icon :icon="$card['icon']" class="h-5 w-5" />
                </div>
            </div>
        </div>
    @endforeach
</section>
