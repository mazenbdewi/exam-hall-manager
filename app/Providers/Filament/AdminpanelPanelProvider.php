<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\File;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminpanelPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('adminpanel')
            ->path('adminpanel')
            ->login()
            ->brandName(config('app.name'))
            ->colors([
                'primary' => Color::Blue,
                'warning' => Color::Orange,
                'danger' => Color::Rose,
                'success' => Color::Emerald,
                'gray' => Color::Gray,
                'info' => Color::Sky,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
            ])
            ->plugin(FilamentShieldPlugin::make())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        if ($this->hasCompiledTheme('resources/css/filament/adminpanel/theme.css')) {
            $panel->viteTheme('resources/css/filament/adminpanel/theme.css');
        }

        return $panel;
    }

    protected function hasCompiledTheme(string $themeEntry): bool
    {
        if (File::exists(public_path('hot'))) {
            return true;
        }

        $manifestPath = public_path('build/manifest.json');

        if (! File::exists($manifestPath)) {
            return false;
        }

        $manifest = json_decode(File::get($manifestPath), true);

        return is_array($manifest) && array_key_exists($themeEntry, $manifest);
    }
}
