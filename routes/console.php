<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

$backupTime = app(\App\Services\AppSettingsService::class)
    ->get('database_backup_time', '02:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:run --only-db')
    ->dailyAt($backupTime)
    ->timezone('Asia/Damascus')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/database-backup-schedule.log'));

Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->timezone('Asia/Damascus')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/database-backup-clean.log'));
