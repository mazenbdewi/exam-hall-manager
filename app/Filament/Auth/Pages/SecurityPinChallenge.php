<?php

namespace App\Filament\Auth\Pages;

use App\Models\User;
use App\Services\AuditLogService;
use App\Support\SecurityPin;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * @property-read Schema $form
 */
class SecurityPinChallenge extends SimplePage
{
    public ?array $data = [];

    public function mount(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->requiresSecurityPinChallenge()) {
            SecurityPin::clearVerification();
            redirect()->intended(Filament::getUrl());

            return;
        }

        if (SecurityPin::isVerifiedForUser($user->getAuthIdentifier())) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function confirm(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->requiresSecurityPinChallenge()) {
            SecurityPin::clearVerification();
            $this->redirectAfterVerification();

            return;
        }

        $rateLimitingKey = $this->rateLimitingKey($user);

        if (RateLimiter::tooManyAttempts($rateLimitingKey, 5)) {
            $this->throwPinValidationError('تم تجاوز عدد المحاولات المسموح. يرجى المحاولة لاحقًا.');
        }

        $data = $this->form->getState();
        $pin = (string) ($data['security_pin'] ?? '');

        if (! Hash::check($pin, (string) $user->security_pin_hash)) {
            RateLimiter::hit($rateLimitingKey, 600);

            app(AuditLogService::class)->log(
                action: 'security_pin.challenge_failed',
                module: 'security',
                auditable: $user,
                description: 'فشل التحقق من رمز الدخول الإضافي',
                status: 'failed',
            );

            if (RateLimiter::tooManyAttempts($rateLimitingKey, 5)) {
                $this->throwPinValidationError('تم تجاوز عدد المحاولات المسموح. يرجى المحاولة لاحقًا.');
            }

            $this->throwPinValidationError('رمز الدخول غير صحيح.');
        }

        RateLimiter::clear($rateLimitingKey);
        SecurityPin::markVerified($user->getAuthIdentifier());

        app(AuditLogService::class)->log(
            action: 'security_pin.challenge_success',
            module: 'security',
            auditable: $user,
            description: 'نجاح التحقق من رمز الدخول الإضافي',
        );

        Notification::make()
            ->success()
            ->title('تم تأكيد الدخول')
            ->send();

        $this->redirectAfterVerification();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('security_pin')
                    ->label('رمز الدخول الإضافي')
                    ->password()
                    ->autocomplete('one-time-code')
                    ->inputMode('numeric')
                    ->required()
                    ->rules(['digits:6'])
                    ->validationMessages([
                        'required' => 'رمز الدخول الإضافي مطلوب.',
                        'digits' => 'يجب أن يتكون رمز الدخول الإضافي من 6 أرقام.',
                    ])
                    ->autofocus(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('confirm')
                    ->footer([
                        Actions::make($this->getFormActions())
                            ->fullWidth()
                            ->key('form-actions'),
                    ]),
            ]);
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('confirm')
                ->label('تأكيد الدخول')
                ->submit('confirm'),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'التحقق من رمز الدخول الإضافي';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'التحقق من رمز الدخول الإضافي';
    }

    protected function rateLimitingKey(User $user): string
    {
        return 'security-pin-challenge:'.$user->getAuthIdentifier().':'.request()->ip();
    }

    protected function throwPinValidationError(string $message): never
    {
        throw ValidationException::withMessages([
            'data.security_pin' => $message,
        ]);
    }

    protected function redirectAfterVerification(): void
    {
        $this->redirect(session()->pull('url.intended', Filament::getUrl()));
    }
}
