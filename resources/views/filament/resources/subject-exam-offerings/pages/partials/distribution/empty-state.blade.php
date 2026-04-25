@php
    $actionType = $actionType ?? null;
@endphp

<div class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-10 text-center shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300">
        <x-filament::icon :icon="$icon" class="h-7 w-7" />
    </div>
    <h3 class="mt-4 text-lg font-bold text-gray-950 dark:text-white">{{ $title }}</h3>
    <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $description }}</p>

    @if (! empty($actionLabel))
        <div class="mt-5">
            @if ($actionType === 'url')
                <x-filament::button tag="a" :href="$actionUrl" color="{{ $actionColor ?? 'primary' }}" :icon="$actionIcon ?? null">
                    {{ $actionLabel }}
                </x-filament::button>
            @elseif ($actionType === 'wire')
                <x-filament::button color="{{ $actionColor ?? 'primary' }}" :icon="$actionIcon ?? null" wire:click="{{ $actionMethod }}">
                    {{ $actionLabel }}
                </x-filament::button>
            @endif
        </div>
    @endif
</div>
