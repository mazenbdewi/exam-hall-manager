<?php

namespace App\Support;

class SecurityPin
{
    /**
     * @return array<int, string>
     */
    public static function weakPins(): array
    {
        return [
            '000000',
            '111111',
            '222222',
            '333333',
            '444444',
            '555555',
            '666666',
            '777777',
            '888888',
            '999999',
            '123456',
            '654321',
            '121212',
            '112233',
            '123123',
        ];
    }

    public static function sessionKey(): string
    {
        return 'security_pin_verified';
    }

    public static function userSessionKey(): string
    {
        return 'security_pin_verified_user_id';
    }

    public static function isVerifiedForUser(int|string|null $userId): bool
    {
        return session()->get(self::sessionKey()) === true
            && (string) session(self::userSessionKey()) === (string) $userId;
    }

    public static function markVerified(int|string $userId): void
    {
        session([
            self::sessionKey() => true,
            self::userSessionKey() => $userId,
        ]);
    }

    public static function clearVerification(): void
    {
        session()->forget([
            self::sessionKey(),
            self::userSessionKey(),
        ]);
    }
}
