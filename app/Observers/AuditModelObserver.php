<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditModelObserver
{
    public function created(Model $model): void
    {
        $this->write($model, 'created', [], $this->auditableAttributes($model));
    }

    public function updated(Model $model): void
    {
        $changes = Arr::except($model->getChanges(), ['updated_at']);

        if ($changes === []) {
            return;
        }

        $oldValues = [];

        foreach (array_keys($changes) as $field) {
            $oldValues[$field] = $model->getOriginal($field);
        }

        $this->write($model, 'updated', $oldValues, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', $this->auditableAttributes($model), []);
    }

    public function restored(Model $model): void
    {
        $this->write($model, 'restored', ['deleted_at' => $model->getOriginal('deleted_at')], ['deleted_at' => null]);
    }

    public function forceDeleted(Model $model): void
    {
        $this->write($model, 'force_deleted', $this->auditableAttributes($model), []);
    }

    protected function write(Model $model, string $event, array $oldValues, array $newValues): void
    {
        if (! config('audit.log_model_changes', true) || $model instanceof AuditLog) {
            return;
        }

        $module = Str::snake(class_basename($model));

        app(AuditLogService::class)->log(
            action: "{$module}.{$event}",
            module: $module,
            auditable: $model,
            description: $this->description($event),
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: [
                'table' => $model->getTable(),
            ],
        );
    }

    protected function auditableAttributes(Model $model): array
    {
        return Arr::except($model->getAttributes(), ['created_at', 'updated_at']);
    }

    protected function description(string $event): string
    {
        return match ($event) {
            'created' => 'إنشاء',
            'updated' => 'تعديل',
            'deleted' => 'حذف',
            'restored' => 'استعادة',
            'force_deleted' => 'حذف نهائي',
            default => $event,
        };
    }
}
