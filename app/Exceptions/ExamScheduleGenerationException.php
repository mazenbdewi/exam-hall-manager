<?php

namespace App\Exceptions;

use RuntimeException;

class ExamScheduleGenerationException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $details
     * @param  array<string, mixed>  $logContext
     */
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $userTitle,
        public readonly string $userMessage,
        public readonly array $details = [],
        public readonly array $logContext = [],
        ?string $technicalMessage = null,
    ) {
        parent::__construct($technicalMessage ?: $userMessage);
    }
}
