<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

trait NotifiesValidationErrors
{
    protected bool $hasNotifiedValidationError = false;

    public function create(bool $another = false): void
    {
        $this->runWithValidationErrorNotification(fn (): mixed => parent::create($another));
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->runWithValidationErrorNotification(fn (): mixed => parent::save($shouldRedirect, $shouldSendSavedNotification));
    }

    protected function onValidationError(ValidationException $exception): void
    {
        if ($this->hasNotifiedValidationError) {
            return;
        }

        $this->hasNotifiedValidationError = true;

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

    protected function runWithValidationErrorNotification(callable $callback): void
    {
        $this->hasNotifiedValidationError = false;

        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->onValidationError($exception);
        }
    }
}
