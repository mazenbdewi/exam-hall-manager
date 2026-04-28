<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLogsCommand extends Command
{
    protected $signature = 'audit:prune {--days= : Override configured retention days}';

    protected $description = 'Delete audit logs older than the configured retention period.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention_days', 180));

        if ($days <= 0) {
            $this->warn('Audit log pruning skipped because retention days is not positive.');

            return self::SUCCESS;
        }

        $deleted = AuditLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} old audit log records.");

        return self::SUCCESS;
    }
}
