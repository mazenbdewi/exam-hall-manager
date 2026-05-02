<x-filament-panels::page>
    <div class="mx-auto max-w-5xl" dir="rtl">
        <div class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="relative isolate overflow-hidden bg-gradient-to-br from-sky-50 via-white to-emerald-50 px-8 py-10 dark:from-gray-950 dark:via-gray-900 dark:to-sky-950">
                <div class="absolute -top-24 end-10 h-52 w-52 rounded-full bg-sky-200/50 blur-3xl dark:bg-sky-700/20"></div>
                <div class="absolute -bottom-28 start-10 h-56 w-56 rounded-full bg-emerald-200/50 blur-3xl dark:bg-emerald-700/20"></div>

                <div class="relative grid gap-8 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div class="space-y-4">
                        <div class="inline-flex items-center gap-2 rounded-full bg-white/80 px-4 py-2 text-sm font-semibold text-sky-700 ring-1 ring-sky-100 dark:bg-gray-900/70 dark:text-sky-300 dark:ring-sky-900">
                            <x-filament::icon icon="heroicon-o-book-open" class="h-5 w-5" />
                            <span>{{ __('help.page.badge') }}</span>
                        </div>

                        <div>
                            <h2 class="text-3xl font-bold tracking-tight text-gray-950 dark:text-white">
                                {{ __('help.page.card_title') }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300">
                                {{ __('help.page.description') }}
                            </p>
                        </div>
                    </div>

                    <x-filament::button
                        tag="a"
                        :href="route('filament.adminpanel.help.user-guide.download')"
                        icon="heroicon-o-arrow-down-tray"
                        color="primary"
                        size="lg"
                        class="justify-center"
                    >
                        {{ __('help.page.download_button') }}
                    </x-filament::button>
                </div>
            </div>

            <div class="grid gap-4 p-8 md:grid-cols-3">
                @foreach (__('help.page.highlights') as $highlight)
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5 dark:border-gray-800 dark:bg-gray-950">
                        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                            <x-filament::icon :icon="$highlight['icon']" class="h-6 w-6" />
                        </div>
                        <h3 class="font-bold text-gray-950 dark:text-white">{{ $highlight['title'] }}</h3>
                        <p class="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">{{ $highlight['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page>
