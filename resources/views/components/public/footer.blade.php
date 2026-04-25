@php
    $settings = null;

    try {
        $settings = \App\Models\SystemSetting::query()->first();
    } catch (\Throwable) {
        $settings = null;
    }

    $universityName = filled($settings?->university_name)
        ? $settings->university_name
        : 'جامعة اللاذقية';
@endphp

<footer class="relative z-10 border-t border-slate-200 bg-white/85 backdrop-blur print:hidden">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-7 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <div>
            <p class="font-bold text-slate-900">{{ $universityName }}</p>
            <p class="mt-1">© {{ now()->year }} {{ $universityName }} - نظام توزيع القاعات الامتحانية</p>
        </div>

        <nav aria-label="روابط التذييل" class="flex gap-4">
            <a href="{{ route('home') }}" class="font-bold text-slate-700 transition hover:text-blue-700">الرئيسية</a>
            <a href="{{ route('students.lookup') }}" class="font-bold text-slate-700 transition hover:text-blue-700">استعلام الطلاب</a>
        </nav>
    </div>
</footer>
