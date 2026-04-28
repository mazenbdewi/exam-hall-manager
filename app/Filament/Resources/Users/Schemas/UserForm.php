<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Support\AdminPassword;
use App\Support\ExamCollegeScope;
use App\Support\RoleNames;
use App\Support\SecurityPin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('exam.sections.user_details'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('exam.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('exam.fields.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('role_name')
                            ->label(__('exam.fields.role'))
                            ->options(fn (): array => collect(ExamCollegeScope::assignableRoles())
                                ->mapWithKeys(fn (string $role): array => [$role => RoleNames::label($role)])
                                ->all())
                            ->default(RoleNames::ADMIN)
                            ->required()
                            ->live(),
                        Select::make('college_id')
                            ->label(__('exam.fields.college'))
                            ->relationship(
                                name: 'college',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => ExamCollegeScope::currentCollegeId())
                            ->required(fn (Get $get): bool => $get('role_name') === RoleNames::ADMIN)
                            ->hidden(fn (Get $get): bool => ! ExamCollegeScope::isSuperAdmin() || $get('role_name') === RoleNames::SUPER_ADMIN),
                        TextInput::make('password')
                            ->label(__('exam.fields.password'))
                            ->password()
                            ->revealable()
                            ->confirmed()
                            ->helperText(AdminPassword::helperText())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->nullable()
                            ->rule(AdminPassword::rule())
                            ->validationMessages(AdminPassword::validationMessages())
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->label(__('exam.fields.password_confirmation'))
                            ->password()
                            ->revealable()
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255),
                        Toggle::make('security_pin_enabled')
                            ->label('تفعيل رمز الدخول الإضافي')
                            ->helperText('رمز مكون من 6 أرقام يطلب من المستخدم بعد إدخال كلمة السر عند الدخول إلى لوحة التحكم.')
                            ->default(false)
                            ->live()
                            ->inline(false),
                        TextInput::make('security_pin')
                            ->label('رمز الدخول الإضافي')
                            ->password()
                            ->autocomplete('new-password')
                            ->inputMode('numeric')
                            ->confirmed()
                            ->helperText('رمز مكون من 6 أرقام يطلب من المستخدم بعد إدخال كلمة السر عند الدخول إلى لوحة التحكم.')
                            ->required(fn (Get $get, ?User $record): bool => (bool) $get('security_pin_enabled') && blank($record?->security_pin_hash))
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
                            ->autocomplete('new-password')
                            ->inputMode('numeric')
                            ->required(fn (Get $get): bool => (bool) $get('security_pin_enabled') && filled($get('security_pin')))
                            ->dehydrated(false)
                            ->maxLength(6)
                            ->visible(fn (Get $get): bool => (bool) $get('security_pin_enabled')),
                    ]),
            ]);
    }
}
