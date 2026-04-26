@php
    $roleNames = fn (string $role): string => collect($hall['assignments_by_role'][$role] ?? [])
        ->pluck('name')
        ->filter()
        ->implode('، ');
@endphp

<div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="font-semibold text-gray-950 dark:text-white">{{ $hall['name'] }}</div>
            <div class="text-sm text-gray-500">{{ $hall['hall_type_label'] }} · {{ $hall['location'] }}</div>
        </div>
        <div class="text-sm text-gray-500">{{ $hall['assigned_count'] }} / {{ $hall['required_count'] }}</div>
    </div>
    <div class="mt-3 grid gap-2 text-sm">
        <div><span class="font-medium text-gray-700 dark:text-gray-200">{{ __('exam.invigilation_roles.hall_head') }}:</span> {{ $roleNames('hall_head') ?: '—' }}</div>
        <div><span class="font-medium text-gray-700 dark:text-gray-200">{{ __('exam.invigilation_roles.secretary') }}:</span> {{ $roleNames('secretary') ?: '—' }}</div>
        <div><span class="font-medium text-gray-700 dark:text-gray-200">{{ __('exam.invigilation_roles.regular') }}:</span> {{ $roleNames('regular') ?: '—' }}</div>
        <div><span class="font-medium text-gray-700 dark:text-gray-200">{{ __('exam.invigilation_roles.reserve') }}:</span> {{ $roleNames('reserve') ?: '—' }}</div>
    </div>
</div>
