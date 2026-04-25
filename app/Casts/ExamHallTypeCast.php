<?php

namespace App\Casts;

use App\Enums\ExamHallType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ExamHallTypeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ExamHallType
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return ExamHallType::tryFrom($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof ExamHallType) {
            return $value->value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ExamHallType::tryFrom($value)) {
            return $value;
        }

        throw new InvalidArgumentException("Invalid hall type [{$value}] provided.");
    }
}
