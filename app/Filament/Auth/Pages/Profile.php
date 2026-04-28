<?php

namespace App\Filament\Auth\Pages;

use App\Models\User;
use App\Services\AuditLogService;
use App\Support\AdminPassword;
use App\Support\SecurityPin;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class Profile extends EditProfile
{
    protected bool $passwordWasChanged = false;

    protected bool $pinWasChanged = false;

    protected bool $pinWasEnabled = false;

    protected bool $pinWasDisabled = false;

    public static function getLabel(): string
    {
        return 'الملف الشخصي';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['password'] = null;
        $data['password_confirmation'] = null;
        $data['current_password'] = null;
        $data['security_pin'] = null;
        $data['security_pin_confirmation'] = null;
        $data['current_password_for_pin'] = null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->passwordWasChanged = array_key_exists('password', $data) && filled($data['password']);
        $this->pinWasChanged = filled($data['security_pin'] ?? null);
        $this->pinWasEnabled = ! $user->security_pin_enabled && (bool) ($data['security_pin_enabled'] ?? false);
        $this->pinWasDisabled = $user->security_pin_enabled && ! (bool) ($data['security_pin_enabled'] ?? false);

        if ($this->pinWasChanged) {
            $data['security_pin_hash'] = Hash::make((string) $data['security_pin']);
            $data['security_pin_set_at'] = now();
        }

        if (! (bool) ($data['security_pin_enabled'] ?? false)) {
            $data['security_pin_hash'] = null;
            $data['security_pin_set_at'] = null;
        }

        unset(
            $data['current_password'],
            $data['password_confirmation'],
            $data['current_password_for_pin'],
            $data['security_pin'],
            $data['security_pin_confirmation'],
        );

        return $data;
    }

    protected function afterSave(): void
    {
        $user = $this->getUser();

        $this->data['current_password'] = null;
        $this->data['password'] = null;
        $this->data['password_confirmation'] = null;
        $this->data['current_password_for_pin'] = null;
        $this->data['security_pin'] = null;
        $this->data['security_pin_confirmation'] = null;

        if ($this->passwordWasChanged) {
            app(AuditLogService::class)->log(
                action: 'password.changed',
                module: 'users',
                auditable: $user,
                description: 'تغيير كلمة السر',
            );
        }

        if ($this->pinWasEnabled) {
            app(AuditLogService::class)->log(
                action: 'security_pin.enabled',
                module: 'security',
                auditable: $user,
                description: 'تم تفعيل رمز الدخول الإضافي',
            );
        }

        if ($this->pinWasDisabled) {
            SecurityPin::clearVerification();

            app(AuditLogService::class)->log(
                action: 'security_pin.disabled',
                module: 'security',
                auditable: $user,
                description: 'تم تعطيل رمز الدخول الإضافي',
            );
        }

        if ($this->pinWasChanged) {
            SecurityPin::markVerified($user->getAuthIdentifier());

            app(AuditLogService::class)->log(
                action: 'security_pin.changed',
                module: 'security',
                auditable: $user,
                description: 'تم تغيير رمز الدخول الإضافي',
            );
        }
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update($data);

        return $record;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('الملف الشخصي')
                    ->columns(2)
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                    ]),
                Section::make('تغيير كلمة المرور')
                    ->columns(2)
                    ->schema([
                        $this->getCurrentPasswordFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
                Section::make('إعدادات رمز الدخول الإضافي')
                    ->columns(2)
                    ->schema([
                        Toggle::make('security_pin_enabled')
                            ->label('تفعيل رمز الدخول الإضافي')
                            ->helperText('رمز مكون من 6 أرقام. لا تستخدم رموزًا سهلة مثل 123456 أو 000000.')
                            ->live()
                            ->inline(false),
                        $this->getCurrentPasswordForPinFormComponent(),
                        TextInput::make('security_pin')
                            ->label('رمز الدخول الإضافي')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->inputMode('numeric')
                            ->confirmed()
                            ->helperText('رمز مكون من 6 أرقام. لا تستخدم رموزًا سهلة مثل 123456 أو 000000.')
                            ->required(fn (Get $get): bool => $this->pinIsRequired($get))
                            ->nullable()
                            ->rules(['digits:6', Rule::notIn(SecurityPin::weakPins())])
                            ->validationMessages([
                                'required' => 'رمز الدخول الإضافي مطلوب.',
                                'digits' => 'يجب أن يتكون رمز الدخول الإضافي من 6 أرقام.',
                                'confirmed' => 'تأكيد رمز الدخول الإضافي غير مطابق.',
                                'not_in' => 'رمز الدخول الإضافي سهل جدًا، يرجى اختيار رمز آخر.',
                            ])
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(6)
                            ->visible(fn (Get $get): bool => (bool) $get('security_pin_enabled')),
                        TextInput::make('security_pin_confirmation')
                            ->label('تأكيد رمز الدخول الإضافي')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->inputMode('numeric')
                            ->required(fn (Get $get): bool => $this->pinConfirmationIsRequired($get))
                            ->dehydrated(false)
                            ->maxLength(6)
                            ->visible(fn (Get $get): bool => (bool) $get('security_pin_enabled')),
                    ]),
            ]);
    }

    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->label('الاسم')
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('البريد الإلكتروني')
            ->email()
            ->required()
            ->maxLength(255)
            ->unique(ignoreRecord: true);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('current_password')
            ->label('كلمة المرور الحالية')
            ->password()
            ->revealable()
            ->autocomplete('current-password')
            ->currentPassword(guard: Filament::getAuthGuard())
            ->required(fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('كلمة المرور الجديدة')
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->helperText(AdminPassword::helperText())
            ->nullable()
            ->rule(AdminPassword::rule())
            ->validationMessages(AdminPassword::validationMessages())
            ->same('password_confirmation')
            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
            ->dehydrated(fn (?string $state): bool => filled($state));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('password_confirmation')
            ->label('تأكيد كلمة المرور الجديدة')
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->required(fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false);
    }

    protected function getCurrentPasswordForPinFormComponent(): Component
    {
        return TextInput::make('current_password_for_pin')
            ->label('كلمة المرور الحالية')
            ->password()
            ->revealable()
            ->autocomplete('current-password')
            ->currentPassword(guard: Filament::getAuthGuard())
            ->required(fn (Get $get): bool => $this->pinSettingsNeedPassword($get))
            ->dehydrated(false);
    }

    protected function pinSettingsNeedPassword(Get $get): bool
    {
        $user = $this->getUser();
        $newEnabled = (bool) $get('security_pin_enabled');

        return filled($get('security_pin'))
            || $this->pinIsRequired($get)
            || $newEnabled !== (bool) $user->security_pin_enabled;
    }

    protected function pinIsRequired(Get $get): bool
    {
        $user = $this->getUser();

        return (bool) $get('security_pin_enabled') && blank($user->security_pin_hash);
    }

    protected function pinConfirmationIsRequired(Get $get): bool
    {
        return filled($get('security_pin')) || $this->pinIsRequired($get);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('تم حفظ إعدادات الملف الشخصي بنجاح.');
    }
}
