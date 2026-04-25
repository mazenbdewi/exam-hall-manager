<?php

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

trait UpsertsDemoRecords
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function upsertRecord(string $modelClass, array $attributes, array $values = []): Model
    {
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);

        $query = $modelClass::query();

        if ($usesSoftDeletes) {
            $query->withTrashed();
        }

        /** @var Model $record */
        $record = $query->firstOrNew($attributes);

        if (method_exists($record, 'trashed') && $record->trashed()) {
            $record->restore();
        }

        $record->fill($values);
        $record->save();

        return $record;
    }
}
