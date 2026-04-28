<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class AdminPassword
{
    public static function rule(): Password
    {
        return Password::min(10)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised();
    }

    /**
     * @return array<string, string>
     */
    public static function validationMessages(): array
    {
        return [
            'required' => 'كلمة السر مطلوبة.',
            'min' => 'يجب أن تحتوي كلمة السر على 10 محارف على الأقل.',
            'password.mixed' => 'يجب أن تحتوي كلمة السر على حرف كبير وحرف صغير.',
            'password.numbers' => 'يجب أن تحتوي كلمة السر على رقم واحد على الأقل.',
            'password.symbols' => 'يجب أن تحتوي كلمة السر على رمز واحد على الأقل.',
        ];
    }

    public static function helperText(): string
    {
        return 'يجب أن تحتوي كلمة السر على 10 محارف على الأقل، وتتضمن حرفًا كبيرًا، حرفًا صغيرًا، رقمًا، ورمزًا.';
    }
}
