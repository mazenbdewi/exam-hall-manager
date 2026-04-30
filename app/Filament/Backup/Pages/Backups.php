<?php

namespace App\Filament\Backup\Pages;

use App\Services\AppSettingsService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups as BaseBackups;
use Throwable;

class Backups extends BaseBackups
{
    protected string $view = 'filament.backup.pages.backups';

    public ?array $scheduleData = [];

    public function mount(): void
    {
        $this->form->fill([
            'database_backup_time' => $this->getDatabaseBackupTime(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TimePicker::make('database_backup_time')
                    ->label('وقت النسخ الاحتياطي اليومي')
                    ->native(false)
                    ->seconds(false)
                    ->format('H:i')
                    ->displayFormat('H:i')
                    ->required()
                    ->validationMessages([
                        'required' => 'وقت النسخ الاحتياطي اليومي مطلوب.',
                    ]),
            ])
            ->statePath('scheduleData');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createBackup')
                ->label('إنشاء نسخة من قاعدة البيانات')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('إنشاء نسخة من قاعدة البيانات')
                ->modalDescription('سيتم إنشاء ملف ZIP يحتوي على نسخة من قاعدة بيانات MySQL فقط، دون ملفات التطبيق أو المرفقات.')
                ->modalSubmitActionLabel('إنشاء')
                ->visible(fn (): bool => auth()->user()?->can('create-backup') ?? false)
                ->action(fn (): mixed => $this->createBackup()),
        ];
    }

    public function createBackup(): mixed
    {
        abort_unless(auth()->user()?->can('create-backup'), 403);

        try {
            $exitCode = Artisan::call('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => true,
            ]);

            if ($exitCode !== 0) {
                Notification::make()
                    ->title('تعذر إنشاء نسخة قاعدة البيانات')
                    ->body(trim(Artisan::output()) ?: 'راجع سجل التطبيق لمعرفة سبب الخطأ.')
                    ->danger()
                    ->send();

                return null;
            }

            Notification::make()
                ->title('تم إنشاء نسخة قاعدة البيانات بنجاح')
                ->success()
                ->send();

            $this->dispatch('$refresh');
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('تعذر إنشاء نسخة قاعدة البيانات')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        return null;
    }

    public function saveBackupScheduleTime(): void
    {
        abort_unless(auth()->user()?->can('create-backup'), 403);

        $rawTime = $this->scheduleData['database_backup_time'] ?? null;
        $time = $this->normalizeBackupTime((string) $rawTime);

        if (! $this->isValidBackupTime($time)) {
            $this->addError('scheduleData.database_backup_time', 'يجب أن يكون الوقت بصيغة HH:mm مثل 02:00 أو 23:30.');

            return;
        }

        app(AppSettingsService::class)->set(AppSettingsService::DATABASE_BACKUP_TIME, $time);

        $this->form->fill([
            'database_backup_time' => $time,
        ]);

        Notification::make()
            ->title('تم حفظ وقت جدولة النسخ الاحتياطي بنجاح')
            ->success()
            ->send();

        $this->dispatch('$refresh');
    }

    public function getDatabaseBackupTime(): string
    {
        $time = app(AppSettingsService::class)->get(AppSettingsService::DATABASE_BACKUP_TIME, '02:00');
        $time = $this->normalizeBackupTime((string) $time);

        return $this->isValidBackupTime($time) ? $time : '02:00';
    }

    public function getDatabaseBackupTimeLabel(): string
    {
        $time = $this->getDatabaseBackupTime();
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $period = $hour < 12 ? 'صباحًا' : 'مساءً';

        return sprintf('%02d:%02d %s', $hour, $minute, $period);
    }

    public function getLastBackupRunLabel(): string
    {
        try {
            $files = collect(Storage::disk('backups')->allFiles())
                ->filter(fn (string $file): bool => str_ends_with($file, '.zip'));

            if ($files->isEmpty()) {
                return 'لا يوجد';
            }

            $lastModified = $files
                ->map(fn (string $file): int => Storage::disk('backups')->lastModified($file))
                ->max();

            return CarbonImmutable::createFromTimestamp($lastModified, 'Asia/Damascus')
                ->format('d/m/Y H:i');
        } catch (Throwable) {
            return 'غير متاح';
        }
    }

    public function getNextBackupRunLabel(): string
    {
        $now = CarbonImmutable::now('Asia/Damascus');
        [$hour, $minute] = array_map('intval', explode(':', $this->getDatabaseBackupTime()));
        $nextRun = $now->setTime($hour, $minute);

        if ($nextRun->lessThanOrEqualTo($now)) {
            $nextRun = $nextRun->addDay();
        }

        return $nextRun->format('d/m/Y H:i');
    }

    protected function normalizeBackupTime(string $time): string
    {
        $time = trim($time);

        if (preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time) === 1) {
            return substr($time, 0, 5);
        }

        if (preg_match('/(?:^|\D)([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?(?:\D|$)/', $time, $matches) === 1) {
            return "{$matches[1]}:{$matches[2]}";
        }

        return $time;
    }

    protected function isValidBackupTime(string $time): bool
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            return false;
        }

        return true;
    }
}
