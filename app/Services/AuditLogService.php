<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\AuditLog;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonSerializable;
use Throwable;
use UnitEnum;

class AuditLogService
{
    public function log(
        string $action,
        ?string $module = null,
        ?Model $auditable = null,
        ?string $description = null,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
        string $status = 'success',
    ): void {
        if (! config('audit.enabled', true)) {
            return;
        }

        $payload = $this->buildPayload(
            action: $action,
            module: $module,
            auditable: $auditable,
            description: $description,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
            status: $status,
        );

        if (! config('audit.async', true)) {
            $this->insert($payload);

            return;
        }

        if ($this->queueIsConfigured()) {
            try {
                WriteAuditLogJob::dispatch($payload)->onQueue((string) config('audit.queue', 'audit'));

                return;
            } catch (Throwable $exception) {
                Log::warning('Unable to dispatch audit log job; falling back to after-response write.', [
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->insertAfterResponse($payload);
    }

    public function filterValues(array $values): array
    {
        return $this->sanitizeArray($values);
    }

    protected function buildPayload(
        string $action,
        ?string $module,
        ?Model $auditable,
        ?string $description,
        array $oldValues,
        array $newValues,
        array $metadata,
        string $status,
    ): array {
        $user = Auth::user();
        $request = app()->bound('request') ? request() : null;

        return [
            'user_id' => $user?->getAuthIdentifier(),
            'user_name' => $user?->name ? Str::limit((string) $user->name, 255, '') : null,
            'user_email' => $user?->email ? Str::limit((string) $user->email, 255, '') : null,
            'action' => Str::limit($action, 255, ''),
            'module' => $module ? Str::limit($module, 255, '') : null,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'description' => $description ? Str::limit($description, 2000, '') : null,
            'old_values' => $this->emptyToNull($this->sanitizeArray($oldValues)),
            'new_values' => $this->emptyToNull($this->sanitizeArray($newValues)),
            'metadata' => $this->emptyToNull($this->sanitizeArray($metadata)),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? Str::limit((string) $request->userAgent(), 1000, '') : null,
            'url' => $request?->fullUrl() ? Str::limit((string) $request->fullUrl(), 2000, '') : null,
            'method' => $request?->method(),
            'status' => in_array($status, ['success', 'failed', 'warning'], true) ? $status : 'success',
            'created_at' => now(),
        ];
    }

    protected function sanitizeArray(array $values, int $depth = 0): array
    {
        if ($depth >= 4) {
            return ['_truncated' => true];
        }

        $maxItems = (int) config('audit.max_json_items', 80);
        $sanitized = [];
        $index = 0;

        foreach ($values as $key => $value) {
            if ($index >= $maxItems) {
                $sanitized['_truncated'] = true;

                break;
            }

            if ($this->isSensitiveField((string) $key)) {
                $index++;

                continue;
            }

            $sanitized[$key] = $this->sanitizeValue($value, $depth + 1);
            $index++;
        }

        return $sanitized;
    }

    protected function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        if ($value instanceof Model) {
            return [
                'type' => $value::class,
                'id' => $value->getKey(),
            ];
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value, $depth);
        }

        if (is_string($value)) {
            return Str::limit($value, (int) config('audit.max_string_length', 1000), '');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return (string) Str::limit(json_encode($value) ?: get_debug_type($value), 500, '');
    }

    protected function isSensitiveField(string $field): bool
    {
        $field = Str::lower($field);

        if (in_array($field, array_map('strtolower', config('audit.excluded_fields', [])), true)) {
            return true;
        }

        return Str::contains($field, ['password', 'token', 'secret', 'private_key', 'recovery_codes']);
    }

    protected function emptyToNull(array $values): ?array
    {
        return $values === [] ? null : $values;
    }

    protected function queueIsConfigured(): bool
    {
        $driver = (string) config('queue.default', 'sync');

        return ! app()->environment('local')
            && ! in_array($driver, ['sync', 'null'], true);
    }

    protected function insertAfterResponse(array $payload): void
    {
        app()->terminating(fn () => $this->insert($payload));
    }

    protected function insert(array $payload): void
    {
        try {
            AuditLog::query()->create($payload);
        } catch (Throwable $exception) {
            Log::warning('Unable to write audit log.', [
                'action' => $payload['action'] ?? null,
                'module' => $payload['module'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
