<?php

namespace App\Filament\Resources\Users\Tables;

use App\Services\AuditLogService;
use App\Support\ExamCollegeScope;
use App\Support\RoleNames;
use App\Support\SecurityPin;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('exam.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('exam.fields.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('primary_role')
                    ->label(__('exam.fields.role'))
                    ->state(fn ($record): string => $record->roles->pluck('name')->map(fn (string $role): string => RoleNames::label($role))->implode('، '))
                    ->badge(),
                TextColumn::make('college.name')
                    ->label(__('exam.fields.college'))
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
            ])
            ->filters([
                SelectFilter::make('college_id')
                    ->label(__('exam.fields.college'))
                    ->relationship('college', 'name')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin()),
            ])
            ->recordActions([
                Action::make('resetSecurityPin')
                    ->label('إعادة ضبط رمز الدخول الإضافي')
                    ->requiresConfirmation()
                    ->color('warning')
                    ->visible(fn (): bool => ExamCollegeScope::isSuperAdmin())
                    ->action(function ($record): void {
                        $record->forceFill([
                            'security_pin_hash' => null,
                            'security_pin_enabled' => false,
                            'security_pin_set_at' => null,
                        ])->save();

                        app(AuditLogService::class)->log(
                            action: 'security_pin.reset_by_admin',
                            module: 'security',
                            auditable: $record,
                            description: 'إعادة ضبط رمز الدخول الإضافي',
                        );

                        if (auth()->id() === $record->getKey()) {
                            SecurityPin::clearVerification();
                        }

                        Notification::make()
                            ->success()
                            ->title('تمت إعادة ضبط رمز الدخول الإضافي للمستخدم بنجاح.')
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
