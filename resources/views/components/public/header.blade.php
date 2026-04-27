@php
    $fallbackUniversityName = 'جامعة اللاذقية';
    $settings = null;

    try {
        $settings = \App\Models\SystemSetting::query()->first();
    } catch (\Throwable) {
        $settings = null;
    }

    $universityName = filled($settings?->university_name)
        ? $settings->university_name
        : $fallbackUniversityName;

    $universityLogoUrl = filled($settings?->university_logo)
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($settings->university_logo)
        : null;

    $links = [
        ['label' => 'الرئيسية', 'url' => route('home'), 'active' => request()->routeIs('home')],
        ['label' => 'استعلام الطلاب', 'url' => route('students.lookup'), 'active' => request()->routeIs('students.lookup')],
        ['label' => 'استعلام المراقبين', 'url' => route('invigilators.lookup'), 'active' => request()->routeIs('invigilators.lookup')],
    ];
@endphp

<header class="relative z-20 border-b border-white/70 bg-white/85 shadow-sm shadow-blue-950/5 backdrop-blur-xl print:hidden">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
        <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-blue-100 bg-white shadow-sm shadow-blue-950/10">
                @if ($universityLogoUrl)
                    <img src="{{ $universityLogoUrl }}" alt="{{ $universityName }}" class="h-11 w-11 rounded-xl object-contain">
                @else
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-700 text-lg font-bold text-white">
                        {{ mb_substr($universityName, 0, 1) }}
                    </span>
                @endif
            </span>

            <span class="min-w-0">
                <span class="block truncate text-lg font-bold text-slate-950">{{ $universityName }}</span>
                <span class="block truncate text-sm font-medium text-blue-700">نظام توزيع القاعات الامتحانية</span>
            </span>
        </a>

        <nav aria-label="التنقل العام" class="flex gap-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white/80 p-1 shadow-sm">
            @foreach ($links as $link)
                <a
                    href="{{ $link['url'] }}"
                    @class([
                        'whitespace-nowrap rounded-xl px-4 py-2 text-sm font-bold transition',
                        'bg-blue-700 text-white shadow-sm shadow-blue-700/25' => $link['active'],
                        'text-slate-700 hover:bg-blue-50 hover:text-blue-800' => ! $link['active'],
                    ])
                >
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
</header>
