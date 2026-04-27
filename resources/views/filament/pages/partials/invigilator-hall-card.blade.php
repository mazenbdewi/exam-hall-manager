@php
    $roleNames = fn (string $role): string => collect($hall['assignments_by_role'][$role] ?? [])
        ->map(fn (array $assignment): string => trim(($assignment['name'] ?? '').(filled($assignment['notes'] ?? null) ? ' - '.$assignment['notes'] : '')))
        ->filter()
        ->implode('، ');
    $roleCell = function (string $role) use ($hall, $roleNames): string {
        $names = $roleNames($role);
        $required = (int) ($hall['required_roles'][$role] ?? 0);
        $assigned = count($hall['assignments_by_role'][$role] ?? []);

        if ($required > $assigned) {
            $shortage = $hall['shortages_by_role'][$role] ?? [];
            $shortageCount = (int) ($shortage['shortage_count'] ?? max(0, $required - $assigned));
            $reason = $shortage['reason'] ?? __('exam.reports.required_role_shortage_reason');
            $shortageText = __('exam.reports.has_shortage').': '.$shortageCount.' - '.__('exam.fields.reason').': '.$reason;

            return filled($names) ? $names.' | '.$shortageText : '— '.$shortageText;
        }

        return $names ?: '—';
    };
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
        <div><span class="font-medium text-gray-700 dark:text-gray-200">{{ __('exam.invigilation_roles.hall_head') }}:</span> {{ $roleCell('hall_head') }}</div>
        <div><span class="font-medium text-gray-700 dark:text-gray-200">{{ __('exam.invigilation_roles.secretary') }}:</span> {{ $roleCell('secretary') }}</div>
        <div><span class="font-medium text-gray-700 dark:text-gray-200">{{ __('exam.invigilation_roles.regular') }}:</span> {{ $roleCell('regular') }}</div>
        <div><span class="font-medium text-gray-700 dark:text-gray-200">{{ __('exam.invigilation_roles.reserve') }}:</span> {{ $roleCell('reserve') }}</div>
    </div>
</div>
