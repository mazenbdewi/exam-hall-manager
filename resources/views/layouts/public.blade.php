<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'نظام توزيع القاعات الامتحانية' }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased">
        <div class="relative flex min-h-screen flex-col overflow-hidden bg-[linear-gradient(180deg,#eef5ff_0%,#f8fafc_42%,#ffffff_100%)]">
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-72 bg-[linear-gradient(135deg,rgba(37,99,235,0.16),rgba(79,70,229,0.08)_48%,rgba(14,165,233,0.06))]"></div>
            <div aria-hidden="true" class="pointer-events-none absolute left-0 top-28 h-40 w-2/3 -skew-y-6 bg-white/45"></div>
            <div aria-hidden="true" class="pointer-events-none absolute right-0 top-72 h-px w-full bg-gradient-to-l from-transparent via-blue-200/70 to-transparent"></div>

            <x-public.header />

            <main class="relative z-10 flex-1">
                {{ $slot ?? '' }}
                @yield('content')
            </main>

            <x-public.footer />
        </div>

        @livewireScripts
    </body>
</html>
