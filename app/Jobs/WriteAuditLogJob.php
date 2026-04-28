<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class WriteAuditLogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        protected array $payload,
    ) {}

    public function handle(): void
    {
        try {
            AuditLog::query()->create($this->payload);
        } catch (Throwable $exception) {
            Log::warning('Unable to write audit log.', [
                'action' => $this->payload['action'] ?? null,
                'module' => $this->payload['module'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
