<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SecurityPin;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecurityPinVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->routeIs('filament.adminpanel.auth.security-pin.challenge')
            || $request->routeIs('filament.adminpanel.auth.logout')
            || $request->routeIs('filament.adminpanel.auth.login')
            || $request->is('students')
            || $request->is('invigilators')
        ) {
            return $next($request);
        }

        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->requiresSecurityPinChallenge()) {
            return $next($request);
        }

        if (SecurityPin::isVerifiedForUser($user->getAuthIdentifier())) {
            return $next($request);
        }

        return redirect()->route('filament.adminpanel.auth.security-pin.challenge');
    }
}
