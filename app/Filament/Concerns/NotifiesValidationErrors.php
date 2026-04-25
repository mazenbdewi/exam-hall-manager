<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

trait NotifiesValidationErrors
{
    protected function onValidationError(ValidationException $exception): void
    {
        parent::onValidationError($exception);

        $message = collect($exception->errors())
            ->flatten()
            ->filter()
            ->unique()
            ->take(3)
            ->implode(' | ');

        Notification::make()
            ->danger()
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('danger')
            ->title(__('exam.notifications.save_failed'))
            ->body($message !== '' ? $message : __('exam.notifications.save_failed_body'))
            ->persistent()
            ->send();
    }
}
