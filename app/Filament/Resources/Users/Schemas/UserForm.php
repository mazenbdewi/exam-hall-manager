<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Support\ExamCollegeScope;
use App\Support\RoleNames;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                                ->mapWithKeys(fn (string $role): array => [$role => \App\Support\RoleNames::label($role)])
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
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->label(__('exam.fields.password_confirmation'))
                            ->password()
                            ->revealable()
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255),
                    ]),
            ]);
    }
}
