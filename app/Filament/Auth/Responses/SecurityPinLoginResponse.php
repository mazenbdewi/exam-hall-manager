<?php

namespace App\Filament\Auth\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class SecurityPinLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->route('filament.adminpanel.auth.security-pin.challenge');
    }
}
