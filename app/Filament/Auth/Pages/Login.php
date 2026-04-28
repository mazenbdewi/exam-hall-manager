<?php

namespace App\Filament\Auth\Pages;

use App\Filament\Auth\Responses\SecurityPinLoginResponse;
use App\Models\User;
use App\Support\SecurityPin;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;

class Login extends \Filament\Auth\Pages\Login
{
    public function authenticate(): ?LoginResponse
    {
        SecurityPin::clearVerification();

        $response = parent::authenticate();

        $user = Filament::auth()->user();

        if ($response && $user instanceof User && $user->requiresSecurityPinChallenge()) {
            return app(SecurityPinLoginResponse::class);
        }

        return $response;
    }
}
